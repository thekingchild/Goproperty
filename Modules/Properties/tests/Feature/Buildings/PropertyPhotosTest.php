<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationRole;
use App\Media\Media;
use App\Media\PerformMediaConversions;
use App\Models\Organization;
use App\Models\User;
use App\Platform\Impersonation\ImpersonationMode;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Properties\Buildings\PropertyPhotos;
use Modules\Properties\Livewire\PropertyShow;
use Modules\Properties\Models\Property;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../../../../../tests/Support/media.php';
require_once __DIR__.'/../../../../../tests/Support/impersonation.php';

/*
 * P02-01b: building photos go into the media library only through
 * App\Media\MediaUploads, checked by UploadValidator (ARCHITECTURE.md § 9):
 * a renamed script or an SVG is refused, a real photo gets its queued
 * thumbnail and WebP versions and is shown through signed, expiring links.
 * The first photo is the cover; photos are found among the building's own,
 * so another building's or organization's file answers 404.
 */

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('media');
    seedOrganizationRoles();
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Acme Homes']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Other Realty']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
    $this->building = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
});

afterEach(fn () => freshRequest());

/** The building page as a Livewire test, inside the organization (file uploads need Livewire's test client). */
function buildingPhotosPage(User $user, Organization $organization, Property $building): mixed
{
    test()->actingAs($user);
    app(OrganizationContext::class)->set($organization);

    return Livewire::test(PropertyShow::class, ['property' => $building]);
}

/** A real image as Livewire's test uploader wants it (a fake file with real bytes). */
function photoUpload(string $name, int $width = 640, int $height = 480, string $type = 'jpeg'): UploadedFile
{
    $image = mediaImage($name, $width, $height, $type);

    return UploadedFile::fake()->createWithContent($name, (string) file_get_contents((string) $image->getRealPath()));
}

/** The building's photos, as stored. */
function photosOf(Organization $organization, Property $building): Collection
{
    return inOrganization($organization, fn () => Property::query()->findOrFail($building->id)->photos());
}

it('refuses a script renamed .jpg, and an SVG, whatever the browser says, storing nothing', function (string $case) {
    $file = match ($case) {
        'php' => UploadedFile::fake()->createWithContent('front.jpg', "<?php system(\$_GET['c']);"),
        'php-as-jpeg' => UploadedFile::fake()->createWithContent('front.jpg', "<?php echo 'x';")->mimeType('image/jpeg'),
        'svg' => UploadedFile::fake()->createWithContent('front.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        'svg-as-png' => UploadedFile::fake()->createWithContent('front.png', '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>'),
        'pdf' => mediaPdf('plan.pdf'),
    };

    // Refused as soon as it is chosen (and the choice cleared)...
    buildingPhotosPage($this->owner, $this->acme, $this->building)
        ->set('photos', [$file])
        ->assertHasErrors('photos.0')
        ->assertSet('photos', [])
        // ...and saving it anyway stores nothing.
        ->call('savePhotos')
        ->assertHasErrors('photos');

    // The writer checks it again, whatever the page did.
    expect(fn () => inOrganization($this->acme, fn () => app(PropertyPhotos::class)->add($this->owner, $this->building, [$file])))
        ->toThrow(ValidationException::class);

    expect(inOrganization($this->acme, fn () => Media::query()->count()))->toBe(0)
        ->and(Storage::disk('media')->allFiles())->toBe([])
        ->and(AuditEntry::query()->where('event', 'property.photo_added')->exists())->toBeFalse();
})->with([
    'PHP renamed .jpg' => ['php'],
    'PHP sent as image/jpeg' => ['php-as-jpeg'],
    'SVG' => ['svg'],
    'SVG renamed .png' => ['svg-as-png'],
    'PDF' => ['pdf'],
]);

it('refuses the whole upload when one file is refused, so nothing is half added', function () {
    // Checked by the writer too, not only by the page's rules.
    $files = [mediaImage('front.jpg'), UploadedFile::fake()->createWithContent('back.jpg', '<?php echo 1;')];

    expect(fn () => inOrganization($this->acme, fn () => app(PropertyPhotos::class)->add($this->owner, $this->building, $files)))
        ->toThrow(ValidationException::class);

    expect(photosOf($this->acme, $this->building))->toHaveCount(0)
        ->and(Storage::disk('media')->allFiles())->toBe([]);
});

it('stores a valid photo through the media library with its conversions, makes it the cover and shows it through signed links', function () {
    buildingPhotosPage($this->owner, $this->acme, $this->building)
        ->set('photos', [photoUpload('Front view.jpg', 1200, 900)])
        ->assertHasNoErrors()
        ->call('savePhotos')
        ->assertHasNoErrors()
        ->assertSee('1 photo was added.');

    $photo = photosOf($this->acme, $this->building)->sole();
    expect($photo->collection_name)->toBe('photos')
        ->and($photo->organization_id)->toBe($this->acme->id)
        ->and($photo->model_type)->toBe(Property::class)
        ->and($photo->model_id)->toBe($this->building->id)
        ->and($photo->mime_type)->toBe('image/jpeg')
        ->and($photo->name)->toBe('Front view')
        ->and($photo->file_name)->not->toContain('Front')
        ->and($photo->checksum())->toHaveLength(64)
        // The queue is sync in tests: the media-queue job made both versions.
        ->and($photo->hasGeneratedConversion('thumb'))->toBeTrue()
        ->and($photo->hasGeneratedConversion('large'))->toBeTrue()
        ->and($this->building->fresh()->photo_media_id)->toBe($photo->id);

    $audit = AuditEntry::query()->where('event', 'property.photo_added')->sole();
    expect($audit->subject_id)->toBe($this->building->id)
        ->and($audit->properties->get('media_uuid'))->toBe($photo->uuid);

    // The page shows the signed thumbnail; the link serves the WebP, and dies when tampered with.
    freshRequest();
    $html = (string) buildingsPage($this->owner, '/palm-court')->assertOk()->getContent();
    expect(preg_match('#src="(http://localhost/media/'.$this->acme->id.'/'.$photo->uuid.'/thumb\?[^"]+)"#', $html, $match))->toBe(1);
    $thumb = html_entity_decode($match[1]);
    expect($thumb)->toContain('signature=')->toContain('expires=');

    auth()->logout();
    $this->get($thumb)->assertOk()->assertHeader('Content-Type', 'image/webp');
    $this->get(str_replace('signature=', 'signature=0', $thumb))->assertNotFound();
    $this->travel(16)->minutes();
    $this->get($thumb)->assertNotFound();

    // The list shows the cover thumbnail.
    $this->travelBack();
    buildingsPage($this->owner)->assertOk()->assertSee('data-test="thumbnail-'.$this->building->id.'"', false)->assertSee('/media/'.$this->acme->id.'/'.$photo->uuid.'/thumb', false);
});

it('queues the conversions on the media queue', function () {
    Queue::fake();

    buildingPhotosPage($this->owner, $this->acme, $this->building)
        ->set('photos', [photoUpload('front.jpg'), photoUpload('back.png', 640, 480, 'png')])
        ->call('savePhotos')
        ->assertHasNoErrors();

    Queue::assertPushedOn('media', PerformMediaConversions::class);
    expect(photosOf($this->acme, $this->building))->toHaveCount(2);
});

it('changes the cover, and moves it to the next photo when the cover is removed, auditing both', function () {
    $page = buildingPhotosPage($this->owner, $this->acme, $this->building)
        ->set('photos', [photoUpload('one.jpg'), photoUpload('two.jpg'), photoUpload('three.jpg')])
        ->call('savePhotos');
    [$one, $two, $three] = photosOf($this->acme, $this->building)->all();
    expect($this->building->fresh()->photo_media_id)->toBe($one->id);

    $page->call('makeCover', $three->id)->assertHasNoErrors();
    expect($this->building->fresh()->photo_media_id)->toBe($three->id);

    $page->call('removePhoto', $three->id)->assertHasNoErrors();
    expect($this->building->fresh()->photo_media_id)->toBe($one->id)
        ->and(photosOf($this->acme, $this->building)->pluck('id')->all())->toBe([$one->id, $two->id])
        ->and(Storage::disk('media')->exists($three->getPathRelativeToRoot()))->toBeFalse();

    expect(AuditEntry::query()->where('log_name', 'properties')->where('event', '!=', 'property.photo_added')->orderBy('id')->pluck('event')->all())
        ->toBe(['property.cover_changed', 'property.photo_removed']);
});

it('keeps at most 20 photos a building and 10 an upload', function () {
    $page = buildingPhotosPage($this->owner, $this->acme, $this->building);

    $page->set('photos', array_map(fn (int $i) => photoUpload("p{$i}.jpg", 64, 48), range(1, 11)))->call('savePhotos')->assertHasErrors('photos');
    expect(photosOf($this->acme, $this->building))->toHaveCount(0);

    $page->set('photos', array_map(fn (int $i) => photoUpload("p{$i}.jpg", 64, 48), range(1, 10)))->call('savePhotos')->assertHasNoErrors();
    $page->set('photos', array_map(fn (int $i) => photoUpload("q{$i}.jpg", 64, 48), range(1, 10)))->call('savePhotos')->assertHasNoErrors();
    $page->set('photos', [photoUpload('extra.jpg', 64, 48)])->call('savePhotos')->assertHasErrors('photos');

    expect(photosOf($this->acme, $this->building))->toHaveCount(PropertyPhotos::MAX_PHOTOS);
});

it('answers 404 for a photo of another building or another organization', function () {
    $sibling = buildingIn($this->acme, ['slug' => 'sibling']);
    $foreign = buildingIn($this->other, ['slug' => 'foreign']);
    $siblingPhoto = inOrganization($this->acme, fn () => app(PropertyPhotos::class)->add($this->owner, $sibling, [mediaImage('s.jpg')])[0]);
    $otherOwner = joinAs(User::factory()->create(), $this->other);
    $foreignPhoto = inOrganization($this->other, fn () => app(PropertyPhotos::class)->add($otherOwner, $foreign, [mediaImage('f.jpg')])[0]);
    freshRequest();

    $show = buildingsSnapshot($this->owner, '/palm-court');
    foreach ([$siblingPhoto->id, $foreignPhoto->id] as $mediaId) {
        LivewireHttp::update($this, $show, [], [['removePhoto', [$mediaId]]])->assertNotFound();
        LivewireHttp::update($this, $show, [], [['makeCover', [$mediaId]]])->assertNotFound();
    }

    expect(photosOf($this->acme, $sibling))->toHaveCount(1)
        ->and(photosOf($this->other, $foreign))->toHaveCount(1)
        ->and($this->building->fresh()->photo_media_id)->toBeNull();
});

it('lets agents manage photos, but no one changes the photos of an archived building', function () {
    $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);
    buildingPhotosPage($agent, $this->acme, $this->building)->set('photos', [photoUpload('a.jpg')])->call('savePhotos')->assertHasNoErrors();
    $photo = photosOf($this->acme, $this->building)->sole();

    inOrganization($this->acme, fn () => $this->building->forceFill(['status' => 'archived'])->save());
    freshRequest();

    buildingsPage($this->owner, '/palm-court')->assertOk()->assertDontSee('data-test="photo-input"', false)->assertDontSee('data-test="remove-photo-', false);
    buildingPhotosPage($this->owner, $this->acme, $this->building->fresh())->set('photos', [photoUpload('b.jpg')])->call('savePhotos')->assertForbidden();
    $show = buildingsSnapshot($this->owner, '/palm-court');
    LivewireHttp::update($this, $show, [], [['removePhoto', [$photo->id]]])->assertForbidden();

    expect(photosOf($this->acme, $this->building))->toHaveCount(1);
});

it('never lets impersonating staff remove a photo, even in write mode', function () {
    $photo = inOrganization($this->acme, fn () => app(PropertyPhotos::class)->add($this->owner, $this->building, [mediaImage('a.jpg')])[0]);
    seedStaffRoles();
    $staff = staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']);
    enterSupport(startSupport($staff, $this->owner, $this->acme, ImpersonationMode::Write))->assertRedirect('/o/acme-homes');

    $show = LivewireHttp::snapshot(asSupport()->get('/o/acme-homes/buildings/palm-court')->assertOk()->assertDontSee('data-test="remove-photo-', false));
    freshRequest();
    LivewireHttp::update(test(), $show, [], [['removePhoto', [$photo->id]]])->assertForbidden();

    expect(photosOf($this->acme, $this->building))->toHaveCount(1);
});

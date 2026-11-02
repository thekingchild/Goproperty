<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Media\Media;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\BuildingGroup\Enums\AuthorKind;
use Modules\BuildingGroup\Models\GroupPost;

require_once __DIR__.'/group-world.php';

/*
 * P07-02 acceptance: "images (validated + thumbnails)" and "disallowed
 * upload types rejected" (ARCHITECTURE.md § 6.8: "Images validated (type,
 * size ≤ 2 MB, dimension caps) and thumbnailed via media library"; § 10).
 * A post takes up to 4 JPEG, PNG or WebP photos of at most 2 MB each,
 * every file judged from its bytes (App\Media\UploadValidator), never from
 * its name or the browser's word. Anything else is refused with a
 * message, before anything is written: no post, no file.
 */

beforeEach(function () {
    Storage::fake('media');
    groupWorld();
});

afterEach(fn () => groupsFreshRequest());

/** Bytes as an upload with this name. */
function fakeUpload(string $name, string $bytes): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $bytes);
}

/** A real image of $type made with GD (gif too), as bytes. */
function gdImageBytes(string $type, int $width = 40, int $height = 30): string
{
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    match ($type) {
        'gif' => imagegif($image),
        'bmp' => imagebmp($image),
        'png' => imagepng($image),
        default => throw new InvalidArgumentException($type),
    };

    return (string) ob_get_clean();
}

/** The group's media rows and stored files. */
function groupImageFiles(): array
{
    return [
        'rows' => inOrganization(test()->acme, fn (): int => Media::query()->where('collection_name', GroupPost::IMAGES)->count()),
        'files' => count(Storage::disk('media')->allFiles()),
    ];
}

dataset('refused uploads', [
    'an SVG with a script' => [fn () => fakeUpload('plan.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(1)</script></svg>'), 'SVG files are not accepted. Upload a JPEG, PNG or WebP file.'],
    'an SVG named .jpg' => [fn () => fakeUpload('photo.jpg', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><circle r="4"/></svg>'), 'SVG files are not accepted. Upload a JPEG, PNG or WebP file.'],
    'a PHP script named .jpg' => [fn () => fakeUpload('photo.jpg', "<?php echo shell_exec(\$_GET['c']); ?>"), 'Upload a JPEG, PNG or WebP file. This file is not one, whatever its name says.'],
    'an HTML page named .png' => [fn () => fakeUpload('photo.png', '<!doctype html><html><body><script>alert(1)</script></body></html>'), 'Upload a JPEG, PNG or WebP file. This file is not one, whatever its name says.'],
    'a GIF' => [fn () => fakeUpload('dance.gif', gdImageBytes('gif')), 'Upload a JPEG, PNG or WebP file. This file is not one, whatever its name says.'],
    'a GIF named .jpg' => [fn () => fakeUpload('dance.jpg', gdImageBytes('gif')), 'Upload a JPEG, PNG or WebP file. This file is not one, whatever its name says.'],
    'a BMP' => [fn () => fakeUpload('scan.bmp', gdImageBytes('bmp')), 'Upload a JPEG, PNG or WebP file. This file is not one, whatever its name says.'],
    'a PDF' => [fn () => mediaPdf('notice.pdf'), 'Upload a JPEG, PNG or WebP file. This file is not one, whatever its name says.'],
    'an iPhone HEIC photo' => [fn () => fakeUpload('IMG_0042.HEIC', heicBytes()), 'Upload a JPEG, PNG or WebP file. This file is not one, whatever its name says.'],
    'a WebP header with no picture' => [fn () => fakeUpload('photo.webp', 'RIFF0000WEBPVP8 not really'), 'The image could not be read. Save it again as JPEG or PNG.'],
    'a JPEG over 2 MB' => [fn () => fakeUpload('big.jpg', file_get_contents(mediaImage('big.jpg')->getRealPath()).str_repeat("\0", 2 * 1024 * 1024)), 'The file is larger than 2 MB. Upload a smaller one.'],
    'a PNG wider than 8,000 pixels' => [fn () => groupPhoto('banner.png', 'png', 8100, 10), 'The image is too large (8100 × 10 pixels). Upload one of at most 25 megapixels and 8000 pixels a side.'],
    'an empty file' => [fn () => fakeUpload('empty.jpg', ''), 'The file is empty. Choose it again.'],
]);

it('acceptance: refuses a disallowed upload with its reason, and stores neither the post nor any file', function (Closure $file, string $reason) {
    expect(fn () => postIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->palmGroup, 'Look at this', [groupPhoto('ok.jpg'), $file()]))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['images' => [$reason]]));

    expect(inOrganization($this->acme, fn (): int => GroupPost::query()->withTrashed()->count()))->toBe(0)
        ->and(groupImageFiles())->toBe(['rows' => 0, 'files' => 0]);
})->with('refused uploads');

it('acceptance: refuses a disallowed upload chosen on the portal\'s post form, and shows why', function () {
    $page = choosePostImages(portalFeed($this->kemi, $this->palmGroup), [fakeUpload('photo.jpg', '<?php phpinfo(); ?>')])
        ->set('body', 'My new curtains')
        ->call('publish')
        ->assertHasErrors(['images'])
        ->assertSee('Upload a JPEG, PNG or WebP file. This file is not one, whatever its name says.')
        // New choices add to the chosen ones, so the refused file is taken off the form.
        ->assertSet('images', []);

    expect(inOrganization($this->acme, fn (): int => GroupPost::query()->count()))->toBe(0)
        ->and(groupImageFiles())->toBe(['rows' => 0, 'files' => 0]);

    // Fixed: the words are still there and a real photo goes.
    choosePostImages($page, [groupPhoto('curtains.webp', 'webp')])->call('publish')->assertHasNoErrors()->assertSee('Posted.')->assertSee('My new curtains');
    expect(groupImageFiles()['rows'])->toBe(1);
});

it('refuses a fifth photo', function () {
    $photos = [groupPhoto('1.jpg'), groupPhoto('2.jpg'), groupPhoto('3.png', 'png'), groupPhoto('4.webp', 'webp'), groupPhoto('5.jpg')];

    expect(fn () => postIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->palmGroup, 'Party pictures', $photos))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['images' => ['Add at most 4 photos to a post.']]));
    expect(groupImageFiles())->toBe(['rows' => 0, 'files' => 0]);
});

it('acceptance: keeps JPEG, PNG and WebP photos as the post\'s, with a thumbnail and a large version, shown through short-lived signed links', function () {
    $post = postIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->palmGroup, null, [
        groupPhoto('Front gate.jpg'), groupPhoto('garden.png', 'png'), groupPhoto('pool.webp', 'webp', 2400, 1600),
    ]);

    expect($post->body)->toBeNull()->and($post->image_count)->toBe(3);

    $images = inOrganization($this->acme, fn () => Media::query()->where('model_type', $post->getMorphClass())->where('model_id', $post->id)->orderBy('id')->get());
    expect($images->pluck('collection_name')->unique()->all())->toBe([GroupPost::IMAGES])
        ->and($images->pluck('mime_type')->all())->toBe(['image/jpeg', 'image/png', 'image/webp'])
        ->and($images->pluck('organization_id')->unique()->all())->toBe([$this->acme->id])
        ->and($images->first()->name)->toBe('Front gate')
        ->and($images->first()->file_name)->not->toContain('Front');

    foreach ($images as $image) {
        // The queue is sync in tests: the media-queue job made both versions, WebP, within their boxes.
        expect($image->hasGeneratedConversion('thumb'))->toBeTrue()
            ->and($image->hasGeneratedConversion('large'))->toBeTrue()
            ->and($image->checksum())->toHaveLength(64);
    }

    $large = getimagesizefromstring((string) Storage::disk('media')->get($images[2]->getPathRelativeToRoot('large')));
    expect($large[0])->toBe(1600)->and($large['mime'])->toBe('image/webp');

    // The page links the thumbnails, signed and expiring; the link opens the image.
    $html = htmlOf(portalGroupPage($this->tunde, $this->palmGroup)->assertOk());
    preg_match_all('/data-test="post-image"/', $html, $shown);
    preg_match('/<img src="([^"]+)"[^>]*data-test="post-image"/', $html, $first);
    expect(count($shown[0]))->toBe(3)
        ->and(html_entity_decode($first[1]))->toContain('/media/'.$this->acme->id.'/')->toContain('/thumb?')->toContain('signature=')->toContain('expires=');

    $this->actingAs($this->tunde)->get(html_entity_decode($first[1]))->assertOk();

    $audit = inOrganization($this->acme, fn () => AuditEntry::query()->where('event', 'group.post_published')->sole());
    expect(collect($audit->properties->get('images'))->pluck('media_id')->all())->toBe($images->modelKeys());
});

it('takes no photos where the group is set to words only, and none while GoProperty staff impersonate', function () {
    inOrganization($this->acme, fn () => $this->palmGroup->forceFill(['settings' => ['images' => false]])->save());

    expect(fn () => postIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->palmGroup, 'Look', [groupPhoto()]))
        ->toThrow(AuthorizationException::class, 'This group does not take photos.');
    expect(fn () => postIn($this->acme, $this->agent, AuthorKind::Staff, $this->palmGroup, 'Look', [groupPhoto()]))
        ->toThrow(AuthorizationException::class, 'This group does not take photos.');

    // The form offers no photos there; words still go.
    portalFeed($this->kemi, $this->palmGroup)->assertDontSee('data-test="post-images-input"', false)
        ->set('body', 'Words only')->call('publish')->assertHasNoErrors();
    expect(groupImageFiles())->toBe(['rows' => 0, 'files' => 0]);
});

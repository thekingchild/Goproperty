<?php

declare(strict_types=1);

use App\Media\Media;
use App\Media\MediaUrls;
use App\Media\PerformMediaConversions;
use App\Models\Organization;
use App\Tenancy\MissingOrganizationContext;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Spatie\MediaLibrary\Conversions\FileManipulator;

require_once __DIR__.'/../../Support/media.php';

/*
 * P02-05 acceptance: files are reached only through short-lived signed
 * links (ARCHITECTURE.md § 9). /media/{organization}/{media}/{conversion?}
 * sets the organization from the signed URL and finds the file through the
 * organization scope, so signed URLs expire, a tampered signature gets 404,
 * and another organization's media is not reachable.
 */

beforeEach(function () {
    Storage::fake('media');
    Queue::fake();
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty']);
    $this->photo = uploadMedia($this->acme, mediaImage('kitchen.jpg', 800, 600));
    $this->bytes = (string) Storage::disk('media')->get($this->photo->getPathRelativeToRoot());
    $this->urls = app(MediaUrls::class);
});

afterEach(fn () => app(OrganizationContext::class)->clear());

/** A signed media URL for any organization id and media uuid, as only the app key can make. */
function signedMediaUrl(int $organization, string $uuid, ?string $conversion = null, int $minutes = 15): string
{
    return URL::temporarySignedRoute('media.show', now()->addMinutes($minutes), array_filter([
        'organization' => $organization,
        'media' => $uuid,
        'conversion' => $conversion,
    ]));
}

/** The URL with one query parameter changed, the signature left as it was. */
function withQuery(string $url, string $key, string $value): string
{
    $parts = parse_url($url);
    parse_str($parts['query'] ?? '', $query);
    $query[$key] = $value;

    return $parts['scheme'].'://'.$parts['host'].($parts['path'] ?? '').'?'.http_build_query($query);
}

describe('serving a file', function () {
    it('streams the original privately to anyone holding a valid link', function () {
        $url = $this->urls->temporary($this->photo);

        expect($url)->toStartWith('http://localhost/media/'.$this->acme->id.'/'.$this->photo->uuid.'?expires=')
            ->and($url)->toContain('signature=');

        /** @var TestResponse $response */
        $response = $this->get($url);
        $response->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('Content-Length', (string) strlen($this->bytes))
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Content-Disposition', 'inline; filename="kitchen.jpg"');

        expect($response->streamedContent())->toBe($this->bytes)
            ->and((string) $response->headers->get('Cache-Control'))->toContain('private')->toContain('max-age=900')
            // No session is started and no cookie is set for a file.
            ->and($response->headers->getCookies())->toBe([]);
    });

    it('gives the same signed link from the media library\'s own getUrl()', function () {
        $fromLibrary = $this->photo->getUrl();
        $temporary = $this->photo->getTemporaryUrl(now()->addMinutes(2));

        expect($fromLibrary)->toStartWith('http://localhost/media/'.$this->acme->id.'/'.$this->photo->uuid.'?')
            ->and($temporary)->toContain('expires='.now()->addMinutes(2)->getTimestamp());

        $this->get($fromLibrary)->assertOk();
        $this->get($temporary)->assertOk();
    });

    it('downloads files that are not images instead of showing them', function () {
        $pdf = uploadMedia($this->acme, mediaPdf('receipt.pdf'), 'documents');

        $this->get($this->urls->temporary($pdf))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="receipt.pdf"');
    });

    it('serves a conversion once it has been made, and 404 until then', function () {
        $thumbUrl = $this->urls->temporary($this->photo, 'thumb');

        expect($this->photo->hasGeneratedConversion('thumb'))->toBeFalse()
            ->and($this->urls->preview($this->photo, 'thumb'))->not->toContain('/thumb?');
        $this->get($thumbUrl)->assertNotFound();

        Queue::assertPushedOn('media', PerformMediaConversions::class);
        Queue::pushed(PerformMediaConversions::class)->first()->handle(app(FileManipulator::class));
        $photo = inOrganization($this->acme, fn () => Media::query()->findOrFail($this->photo->id));

        $response = $this->get($thumbUrl)->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeader('Content-Disposition', 'inline; filename="kitchen.webp"');
        expect(getimagesizefromstring($response->streamedContent())[0])->toBeLessThanOrEqual(400)
            ->and($this->urls->preview($photo, 'thumb'))->toContain('/thumb?');
    });

    it('answers 404 for a conversion that does not exist', function () {
        $this->get(signedMediaUrl($this->acme->id, $this->photo->uuid, 'original'))->assertNotFound();
        $this->get('/media/'.$this->acme->id.'/'.$this->photo->uuid.'/..%2F..%2F.env')->assertNotFound();
    });

    it('answers 404 when the file is missing from the disk', function () {
        Storage::disk('media')->delete($this->photo->getPathRelativeToRoot());

        $this->get($this->urls->temporary($this->photo))->assertNotFound();
    });
});

describe('signatures', function () {
    it('expires signed URLs', function () {
        $url = $this->urls->temporary($this->photo);

        $this->travel(14)->minutes();
        $this->get($url)->assertOk();

        $this->travel(2)->minutes();
        $this->get($url)->assertNotFound();
    });

    it('lives as long as config/media.php says', function () {
        config(['media.url_lifetime_minutes' => 2]);
        $url = $this->urls->temporary($this->photo);

        $this->travel(3)->minutes();
        $this->get($url)->assertNotFound();
    });

    it('answers 404 to a tampered signature', function () {
        $url = $this->urls->temporary($this->photo);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $signature = (string) $query['signature'];
        $tampered = substr($signature, 0, -1).($signature[-1] === 'a' ? 'b' : 'a');

        $this->get(withQuery($url, 'signature', $tampered))->assertNotFound();
        $this->get(withQuery($url, 'signature', ''))->assertNotFound();
    });

    it('answers 404 when anything the signature covers is changed', function () {
        $url = $this->urls->temporary($this->photo);
        $other = uploadMedia($this->acme, mediaImage('garden.jpg'));

        // A longer life, another file of the same organization, another organization, a conversion.
        $this->get(withQuery($url, 'expires', (string) now()->addYear()->getTimestamp()))->assertNotFound();
        $this->get(str_replace($this->photo->uuid, $other->uuid, $url))->assertNotFound();
        $this->get(str_replace('/media/'.$this->acme->id.'/', '/media/'.$this->other->id.'/', $url))->assertNotFound();
        $this->get(str_replace($this->photo->uuid.'?', $this->photo->uuid.'/thumb?', $url))->assertNotFound();
    });

    it('answers 404 without a signature', function () {
        $this->get('/media/'.$this->acme->id.'/'.$this->photo->uuid)->assertNotFound();
        $this->get('/media/'.$this->acme->id.'/'.$this->photo->uuid.'?expires='.now()->addHour()->getTimestamp())->assertNotFound();
    });
});

describe('organizations', function () {
    it('never reaches another organization\'s media through the route', function () {
        $theirs = uploadMedia($this->other, mediaImage('their-lobby.jpg'));

        // Their own link works.
        $this->get($this->urls->temporary($theirs))->assertOk();

        // Even a correctly signed link that pairs Acme with their file finds nothing:
        // the file is looked up inside Acme only.
        $this->get(signedMediaUrl($this->acme->id, $theirs->uuid))->assertNotFound();
        $this->get(signedMediaUrl($this->other->id, $this->photo->uuid))->assertNotFound();
    });

    it('never reaches another organization\'s media through the model scope', function () {
        $theirs = uploadMedia($this->other, mediaImage('their-lobby.jpg'));

        inOrganization($this->acme, function () use ($theirs) {
            expect(Media::query()->pluck('id')->all())->toBe([$this->photo->id])
                ->and(Media::query()->find($theirs->id))->toBeNull()
                ->and(Media::query()->where('uuid', $theirs->uuid)->exists())->toBeFalse()
                ->and($this->acme->getMedia('photos')->pluck('id')->all())->toBe([$this->photo->id]);
        });

        expect(fn () => Media::query()->count())->toThrow(MissingOrganizationContext::class);
    });

    it('answers 404 for an organization that no longer exists', function () {
        $url = $this->urls->temporary($this->photo);
        $this->acme->delete();

        $this->get($url)->assertNotFound();
    });

    it('keeps each organization\'s files under its own folder', function () {
        $theirs = uploadMedia($this->other, mediaImage('their-lobby.jpg'));

        expect(Storage::disk('media')->allFiles((string) $this->acme->id))->toBe([$this->photo->getPathRelativeToRoot()])
            ->and(Storage::disk('media')->allFiles((string) $this->other->id))->toBe([$theirs->getPathRelativeToRoot()]);
    });
});

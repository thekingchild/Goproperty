<?php

declare(strict_types=1);

use App\Media\CollectionRules;
use App\Media\Media;
use App\Media\MediaUploads;
use App\Media\UploadRefused;
use App\Media\UploadValidator;
use App\Models\Organization;
use App\Tenancy\CrossOrganizationWrite;
use App\Tenancy\MissingOrganizationContext;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

require_once __DIR__.'/../../Support/media.php';

/*
 * P02-05 acceptance: uploads are validated (ARCHITECTURE.md § 10: mime
 * sniffing, size caps, no SVG). The type comes from the bytes, never from
 * the name or the browser; each collection has its own types and size
 * cap; images must decode and fit GD's memory. Nothing refused reaches the
 * disk or the media table.
 */

beforeEach(function () {
    Storage::fake('media');
    Queue::fake();
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes']);
    $this->validator = app(UploadValidator::class);
});

afterEach(fn () => app(OrganizationContext::class)->clear());

/** Asserts the upload is refused with this message and nothing was stored. */
function expectRefused(Organization $organization, UploadedFile $file, string $message, string $collection = 'photos'): void
{
    expect(fn () => uploadMedia($organization, $file, $collection))->toThrow(UploadRefused::class, $message)
        ->and(mediaCount($organization))->toBe(0)
        ->and(Storage::disk('media')->allFiles())->toBe([]);
}

describe('mime sniffing', function () {
    it('rejects a PHP script renamed .jpg', function () {
        $file = UploadedFile::fake()->createWithContent('house.jpg', "<?php system(\$_GET['c']); ?>");

        expect($this->validator->sniff((string) $file->getRealPath()))->toBe('text/x-php');
        expectRefused($this->acme, $file, 'Upload a JPEG, PNG or WebP file');
    });

    it('rejects a PHP script renamed .jpg even when the browser says it is a JPEG', function () {
        $path = tempnam(sys_get_temp_dir(), 'gp-php-');
        file_put_contents((string) $path, "<?php echo 'pwned'; ?>");
        $file = new UploadedFile((string) $path, 'photo.jpg', 'image/jpeg', null, true);

        expect($file->getClientMimeType())->toBe('image/jpeg');
        expectRefused($this->acme, $file, 'whatever its name says');
    });

    it('rejects SVG images, whatever they are called', function (string $name, string $content) {
        expectRefused($this->acme, UploadedFile::fake()->createWithContent($name, $content), 'SVG files are not accepted');
    })->with([
        'an .svg' => ['plan.svg', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
        'an SVG renamed .png' => ['plan.png', '<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"/></svg>'],
        'an SVG renamed .jpg' => ['plan.jpg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'],
        'an SVG with a doctype' => ['plan.webp', "<!DOCTYPE svg PUBLIC \"-//W3C//DTD SVG 1.1//EN\" \"http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd\">\n<svg xmlns=\"http://www.w3.org/2000/svg\"/>"],
    ]);

    it('refuses SVG in every collection, even one configured to list it', function () {
        config(['media.collections.photos.mimes' => ['image/svg+xml', 'image/jpeg']]);

        expectRefused($this->acme, UploadedFile::fake()->createWithContent('a.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'), 'SVG files are not accepted');
    });

    it('rejects other files dressed up as images', function (string $name, string $content) {
        expectRefused($this->acme, UploadedFile::fake()->createWithContent($name, $content), 'Upload a JPEG, PNG or WebP file');
    })->with([
        'HTML renamed .jpg' => ['page.jpg', '<html><body><script>alert(1)</script></body></html>'],
        'a PDF in photos' => ['photo.png', "%PDF-1.4\n%%EOF\n"],
        'a zip renamed .webp' => ['photo.webp', "PK\x03\x04".str_repeat("\0", 64)],
        'plain text renamed .png' => ['photo.png', 'just some words'],
    ]);

    it('rejects a file that starts like a JPEG but is not a readable image', function () {
        expectRefused($this->acme, UploadedFile::fake()->createWithContent('broken.jpg', "\xFF\xD8\xFF\xE0".str_repeat("\0", 200)), 'could not be read');
    });

    it('names the stored file after the sniffed type, keeping the upload\'s name only as a label', function () {
        $png = mediaImage('front view.jpg', 320, 200, 'png');
        $bytes = (string) file_get_contents((string) $png->getRealPath());

        $media = uploadMedia($this->acme, $png);

        expect($media->mime_type)->toBe('image/png')
            ->and($media->file_name)->toMatch('/^[a-z0-9]{40}\.png$/')
            ->and($media->name)->toBe('front view')
            ->and($media->size)->toBe(strlen($bytes))
            ->and($media->checksum())->toBe(hash('sha256', $bytes))
            ->and($media->getCustomProperty('width'))->toBe(320)
            ->and($media->getCustomProperty('height'))->toBe(200)
            ->and($media->organization_id)->toBe($this->acme->id)
            ->and($media->getPathRelativeToRoot())->toBe($this->acme->id.'/'.$media->uuid.'/'.$media->file_name)
            ->and(Storage::disk('media')->get($media->getPathRelativeToRoot()))->toBe($bytes);
    });

    it('accepts JPEG, PNG and WebP photos and a PDF in documents', function () {
        uploadMedia($this->acme, mediaImage('a.jpg'));
        uploadMedia($this->acme, mediaImage('b.png', type: 'png'));
        uploadMedia($this->acme, mediaImage('c.webp', type: 'webp'));
        $pdf = uploadMedia($this->acme, mediaPdf(), 'documents');

        expect(inOrganization($this->acme, fn () => Media::query()->orderBy('id')->pluck('mime_type')->all()))
            ->toBe(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
            ->and($pdf->file_name)->toEndWith('.pdf');
    });

    it('keeps an iPhone HEIC photo as uploaded where a collection takes HEIC, and refuses a fake one (P04-02)', function () {
        config(['media.collections.heic_test' => ['mimes' => ['image/jpeg', 'image/heic', 'image/heif'], 'max_kb' => 1024, 'conversions' => ['thumb'], 'kind' => 'evidence']]);

        // An ISO media file of another brand (an MP4 video) is not a photo, and HEIC is refused where a collection does not list it.
        expectRefused($this->acme, UploadedFile::fake()->createWithContent('clip.heic', "\0\0\0\x18ftypisom\0\0\0\0isomiso2".str_repeat('x', 64)), 'Upload a JPEG or HEIC file', 'heic_test');
        expectRefused($this->acme, UploadedFile::fake()->createWithContent('IMG_0043.HEIC', heicBytes()), 'Upload a JPEG, PNG or WebP file');

        $bytes = heicBytes();
        $media = uploadMedia($this->acme, UploadedFile::fake()->createWithContent('IMG_0042.HEIC', $bytes), 'heic_test');

        expect($media->mime_type)->toBe('image/heic')
            ->and($media->file_name)->toMatch('/^[a-z0-9]{40}\.heic$/')
            ->and($media->getCustomProperty('width'))->toBeNull()
            ->and($media->checksum())->toBe(hash('sha256', $bytes))
            ->and(CollectionRules::for('heic_test')->typesLabel())->toBe('JPEG or HEIC');
    });

    it('keeps a collection to its own types', function () {
        expectRefused($this->acme, mediaImage('logo.jpg'), 'Upload a PNG file', 'branding');
        expectRefused($this->acme, mediaImage('photo.webp', type: 'webp'), 'Upload a PDF, JPEG or PNG file', 'documents');
    });
});

describe('size caps', function () {
    it('refuses a file over its collection\'s cap', function () {
        config(['media.collections.photos.max_kb' => 20]);
        $file = mediaImage('big.jpg', 1200, 900);

        expect(filesize((string) $file->getRealPath()))->toBeGreaterThan(20 * 1024);
        expectRefused($this->acme, $file, 'larger than 20 KB');
    });

    it('has a cap for each collection', function () {
        $documents = 'documents';
        config(["media.collections.{$documents}.max_kb" => 1]);

        expectRefused($this->acme, UploadedFile::fake()->createWithContent('proof.pdf', "%PDF-1.4\n".str_repeat('x', 2048)."\n%%EOF\n"), 'larger than 1 KB', $documents);

        // The photos cap is untouched.
        expect(uploadMedia($this->acme, mediaImage('ok.jpg'))->exists)->toBeTrue();
    });

    it('measures the file itself, not what the browser claimed', function () {
        config(['media.collections.photos.max_kb' => 20]);
        $real = mediaImage('big.jpg', 1200, 900);
        $file = new UploadedFile((string) $real->getRealPath(), 'small.jpg', 'image/jpeg', UPLOAD_ERR_OK, true);

        expectRefused($this->acme, $file, 'larger than 20 KB');
    });

    it('refuses empty files', function () {
        expectRefused($this->acme, UploadedFile::fake()->createWithContent('empty.jpg', ''), 'empty');
    });

    it('refuses images too large to convert within a shared host\'s memory', function () {
        config(['media.max_megapixels' => 1]);

        expectRefused($this->acme, mediaImage('huge.jpg', 1400, 1000), 'at most 1 megapixels');
    });

    it('refuses images wider than the longest side allowed', function () {
        config(['media.max_side' => 1000]);

        expectRefused($this->acme, mediaImage('wide.png', 1200, 100, 'png'), '1000 pixels a side');
    });
});

describe('where uploads are checked', function () {
    it('gives Laravel validation the same answer', function () {
        $rule = app(UploadValidator::class)->rule('photos');

        $refused = Validator::make(['photo' => UploadedFile::fake()->createWithContent('x.jpg', '<?php ?>')], ['photo' => ['required', 'file', $rule]]);
        $accepted = Validator::make(['photo' => mediaImage('y.jpg')], ['photo' => ['required', 'file', $rule]]);
        $missing = Validator::make(['photo' => 'not a file'], ['photo' => [$rule]]);

        expect($refused->fails())->toBeTrue()
            ->and($refused->errors()->first('photo'))->toContain('Upload a JPEG, PNG or WebP file')
            ->and($accepted->fails())->toBeFalse()
            ->and($missing->errors()->first('photo'))->toBe('Choose a file to upload.');
    });

    it('refuses an unknown collection', function () {
        expect(fn () => uploadMedia($this->acme, mediaImage('a.jpg'), 'secrets'))->toThrow(InvalidArgumentException::class, 'not configured');
    });

    it('needs an organization, and never attaches a file to another organization\'s record', function () {
        $other = Organization::factory()->create(['slug' => 'other-realty']);

        expect(fn () => app(MediaUploads::class)->add($this->acme, 'photos', mediaImage('a.jpg')))->toThrow(MissingOrganizationContext::class)
            ->and(fn () => app(OrganizationContext::class)->run($this->acme, fn () => app(MediaUploads::class)->add($other, 'photos', mediaImage('b.jpg'))))
            ->toThrow(CrossOrganizationWrite::class)
            ->and(mediaCount($this->acme))->toBe(0)
            ->and(mediaCount($other))->toBe(0);
    });
});

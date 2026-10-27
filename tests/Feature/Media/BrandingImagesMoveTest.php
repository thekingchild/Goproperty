<?php

declare(strict_types=1);

use App\Branding\Branding;
use App\Branding\BrandingFiles;
use App\Media\Media;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/media.php';

/*
 * P02-05 absorbs the P01-05 branding images (ARCHITECTURE.md § 9): the
 * migration copies each logo, signature and stamp from the branding disk
 * into the media library and points `organizations.branding` (and
 * `logo_media_id`) at the media rows; down() goes back to the paths. The
 * old files stay where they were.
 */

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('media');
    seedOrganizationRoles();
    $this->migration = require database_path('migrations/2026_11_02_050002_move_branding_images_to_media.php');
});

afterEach(fn () => app(OrganizationContext::class)->clear());

/** A PNG stored the P01-05 way, and its path. */
function legacyBrandingImage(Organization $organization, string $role, int $width = 400, int $height = 200): string
{
    $bytes = (string) file_get_contents((string) mediaImage("{$role}.png", $width, $height, 'png')->getRealPath());
    $path = 'organization-branding/'.$organization->id.'/'.$role.'/'.substr(hash('sha256', $bytes), 0, 32).'.png';
    Storage::disk('local')->put($path, $bytes);

    return $path;
}

it('moves P01-05 branding images into the media library, and back on rollback', function () {
    expect($this->migration)->toBeInstanceOf(Migration::class);

    $acme = Organization::factory()->create(['slug' => 'acme-homes']);
    $owner = joinAs(User::factory()->create(), $acme);
    $logo = legacyBrandingImage($acme, 'logo');
    $signature = legacyBrandingImage($acme, 'signature', 300, 120);
    $logoBytes = Storage::disk('local')->get($logo);
    $acme->forceFill(['branding' => [
        'trading_name' => 'Adeyemi Realty',
        'primary_color' => '#0E6B5C',
        'logo_file' => $logo,
        'signature_file' => $signature,
        'stamp_file' => null,
    ]])->save();

    $this->migration->up();

    $acme->refresh();
    $branding = Branding::fromOrganization($acme);
    $media = inOrganization($acme, fn () => Media::query()->orderBy('id')->get());

    expect($media)->toHaveCount(2)
        ->and($media->pluck('collection_name')->unique()->all())->toBe(['branding'])
        ->and($media->map(fn (Media $item) => $item->getCustomProperty('role'))->all())->toBe(['logo', 'signature'])
        ->and($media[0]->model_type)->toBe(Organization::class)
        ->and($media[0]->model_id)->toBe($acme->id)
        ->and($media[0]->mime_type)->toBe('image/png')
        ->and($media[0]->checksum())->toBe(hash('sha256', (string) $logoBytes))
        ->and($acme->logo_media_id)->toBe($media[0]->id)
        ->and($branding->logoMediaId)->toBe($media[0]->id)
        ->and($branding->signatureMediaId)->toBe($media[1]->id)
        ->and($branding->stampMediaId)->toBeNull()
        ->and($branding->tradingName)->toBe('Adeyemi Realty')
        ->and(array_keys($acme->branding))->not->toContain('logo_file', 'signature_file', 'stamp_file')
        ->and(Storage::disk('media')->get($media[0]->getPathRelativeToRoot()))->toBe($logoBytes)
        // The old files are kept.
        ->and(Storage::disk('local')->exists($logo))->toBeTrue();

    // Everything that shows or embeds the images now reads the media library.
    $files = app(BrandingFiles::class);
    expect($files->dataUri($acme, 'logo', $branding->logoMediaId))->toBe('data:image/png;base64,'.base64_encode((string) $logoBytes));
    $this->actingAs($owner)->get((string) $files->url($acme, 'logo', $branding->logoMediaId))->assertOk()->assertHeader('Content-Type', 'image/png');

    $this->migration->down();

    $acme->refresh();
    expect($acme->branding['logo_file'] ?? null)->toBe($logo)
        ->and($acme->branding['signature_file'] ?? null)->toBe($signature)
        ->and(array_keys($acme->branding))->not->toContain('logo_media_id', 'signature_media_id')
        ->and($acme->logo_media_id)->toBeNull()
        ->and(mediaCount($acme))->toBe(0)
        ->and(Storage::disk('media')->allFiles())->toBe([])
        ->and(Storage::disk('local')->exists($logo))->toBeTrue();
});

it('leaves paths it cannot move as they were, and does nothing without branding', function () {
    $acme = Organization::factory()->create(['slug' => 'acme-homes']);
    $other = Organization::factory()->create(['slug' => 'other-realty']);
    $plain = Organization::factory()->create(['slug' => 'plain-homes']);
    $theirs = legacyBrandingImage($other, 'logo');

    // A file that is gone, and a path into another organization's folder.
    $acme->forceFill(['branding' => [
        'logo_file' => 'organization-branding/'.$acme->id.'/logo/'.str_repeat('a', 32).'.png',
        'stamp_file' => $theirs,
    ]])->save();

    $this->migration->up();

    expect($acme->fresh()->branding)->toBe([
        'logo_file' => 'organization-branding/'.$acme->id.'/logo/'.str_repeat('a', 32).'.png',
        'stamp_file' => $theirs,
    ])
        ->and($acme->fresh()->logo_media_id)->toBeNull()
        ->and($plain->fresh()->branding)->toBeNull()
        ->and(mediaCount($acme))->toBe(0)
        ->and(mediaCount($other))->toBe(0);
});

<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * The media library takes over organization branding images
 * (ARCHITECTURE.md § 9, P01-05 → P02-05): each logo, signature and stamp
 * that P01-05 stored on the branding disk (`organizations.branding`
 * `logo_file` / `signature_file` / `stamp_file`) is copied into the media
 * library as a `branding` media row of its organization, and the json then
 * holds `logo_media_id` / `signature_media_id` / `stamp_media_id` (the
 * logo's id also in `organizations.logo_media_id`).
 *
 * Self-contained on purpose (query builder and disks only, no app
 * classes), so it means the same thing on every future release. The file
 * layout is App\Media\MediaPathGenerator's: {organization id}/{uuid}/{file}.
 * The original files stay on the branding disk (issued documents may
 * point at them later; nothing is deleted), which is what down() goes back
 * to. A path that is not a P01-05 branding path or whose file is missing is
 * left in the json untouched. Nothing to do on an empty database.
 */
return new class extends Migration
{
    private const array ROLES = ['logo', 'signature', 'stamp'];

    public function up(): void
    {
        $source = $this->disk(config('branding.disk'), 'local');
        $targetName = $this->diskName(config('media-library.disk_name'), 'media');
        $target = Storage::disk($targetName);

        foreach (DB::table('organizations')->whereNotNull('branding')->orderBy('id')->get(['id', 'branding', 'logo_media_id']) as $organization) {
            $branding = is_string($organization->branding) ? json_decode($organization->branding, true) : null;

            if (! is_array($branding)) {
                continue;
            }

            $logoMediaId = $organization->logo_media_id;
            $changed = false;
            $order = 0;

            foreach (self::ROLES as $role) {
                $path = $branding["{$role}_file"] ?? null;

                // P01-05 wrote an empty image as null; the media ids replace the key.
                if (array_key_exists("{$role}_file", $branding) && $path === null) {
                    unset($branding["{$role}_file"]);
                    $changed = true;

                    continue;
                }

                if (! is_string($path) || preg_match('#^[A-Za-z0-9_-]+/'.(int) $organization->id.'/'.$role.'/[a-f0-9]{32}\.png$#D', $path) !== 1 || ! $source->exists($path)) {
                    continue;
                }

                $bytes = (string) $source->get($path);
                $size = @getimagesizefromstring($bytes);
                $uuid = (string) Str::uuid();
                $fileName = Str::lower(Str::random(40)).'.png';
                $target->put((int) $organization->id.'/'.$uuid.'/'.$fileName, $bytes);

                $mediaId = DB::table('media')->insertGetId([
                    'organization_id' => $organization->id,
                    'model_type' => 'App\\Models\\Organization',
                    'model_id' => $organization->id,
                    'uuid' => $uuid,
                    'collection_name' => 'branding',
                    'name' => $role,
                    'file_name' => $fileName,
                    'mime_type' => 'image/png',
                    'disk' => $targetName,
                    'conversions_disk' => $targetName,
                    'size' => strlen($bytes),
                    'manipulations' => '[]',
                    'custom_properties' => json_encode(array_filter([
                        'role' => $role,
                        'sha256' => hash('sha256', $bytes),
                        'width' => $size !== false ? $size[0] : null,
                        'height' => $size !== false ? $size[1] : null,
                        'legacy_path' => $path,
                    ], static fn (mixed $value): bool => $value !== null), JSON_THROW_ON_ERROR),
                    'generated_conversions' => '[]',
                    'responsive_images' => '[]',
                    'order_column' => ++$order,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                unset($branding["{$role}_file"]);
                $branding["{$role}_media_id"] = $mediaId;
                $changed = true;

                if ($role === 'logo') {
                    $logoMediaId = $mediaId;
                }
            }

            if ($changed) {
                DB::table('organizations')->where('id', $organization->id)->update([
                    'branding' => json_encode($branding, JSON_THROW_ON_ERROR),
                    'logo_media_id' => $logoMediaId,
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('media')->where('collection_name', 'branding')->orderBy('id')->get() as $media) {
            $properties = is_string($media->custom_properties) ? json_decode($media->custom_properties, true) : null;
            $role = is_array($properties) ? ($properties['role'] ?? null) : null;
            $legacyPath = is_array($properties) ? ($properties['legacy_path'] ?? null) : null;

            if (! is_string($legacyPath) || ! in_array($role, self::ROLES, true)) {
                continue;
            }

            $organization = DB::table('organizations')->where('id', $media->organization_id)->first(['id', 'branding', 'logo_media_id']);
            $branding = $organization !== null && is_string($organization->branding) ? json_decode($organization->branding, true) : null;

            if (is_array($branding) && ($branding["{$role}_media_id"] ?? null) === (int) $media->id) {
                unset($branding["{$role}_media_id"]);
                $branding["{$role}_file"] = $legacyPath;

                DB::table('organizations')->where('id', $media->organization_id)->update(array_filter([
                    'branding' => json_encode($branding, JSON_THROW_ON_ERROR),
                    'logo_media_id' => $role === 'logo' && (int) $organization->logo_media_id === (int) $media->id ? null : false,
                ], static fn (mixed $value): bool => $value !== false));
            }

            Storage::disk($this->diskName($media->disk, 'media'))->deleteDirectory((int) $media->organization_id.'/'.$media->uuid);
            DB::table('media')->where('id', $media->id)->delete();
        }
    }

    private function disk(mixed $name, string $default): Filesystem
    {
        return Storage::disk($this->diskName($name, $default));
    }

    private function diskName(mixed $name, string $default): string
    {
        return is_string($name) && $name !== '' ? $name : $default;
    }
};

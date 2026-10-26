<?php

declare(strict_types=1);

namespace App\Theme;

use GdImage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * The platform's brand images (PLATFORM_CONSOLE.md § 9.2): checks uploads,
 * re-encodes photos, stores files under content hashes on the `brand` disk
 * and builds their /brand/{role} URLs. A setting holds the stored path,
 * e.g. "hero/3f…9a.webp"; the hash doubles as the cache-busting version.
 */
final readonly class BrandAssets
{
    /**
     * URL role => setting key, folder, kind, largest upload in KB, allowed
     * extensions. Photos are re-encoded; logos and icons are kept as sent.
     */
    public const array ROLES = [
        'logo-light' => ['key' => 'theme.logo_light', 'folder' => 'logo_light', 'kind' => 'logo', 'max_kb' => 2048, 'types' => ['png', 'jpg', 'webp', 'svg']],
        'logo-dark' => ['key' => 'theme.logo_dark', 'folder' => 'logo_dark', 'kind' => 'logo', 'max_kb' => 2048, 'types' => ['png', 'jpg', 'webp', 'svg']],
        'icon' => ['key' => 'theme.icon', 'folder' => 'icon', 'kind' => 'icon', 'max_kb' => 1024, 'types' => ['png', 'webp', 'svg']],
        'favicon' => ['key' => 'theme.favicon', 'folder' => 'favicon', 'kind' => 'favicon', 'max_kb' => 1024, 'types' => ['png', 'svg', 'ico']],
        'hero' => ['key' => 'theme.hero_image', 'folder' => 'hero', 'kind' => 'photo', 'max_kb' => 5120, 'types' => ['jpg', 'png', 'webp']],
        'auth' => ['key' => 'theme.auth_image', 'folder' => 'auth', 'kind' => 'photo', 'max_kb' => 5120, 'types' => ['jpg', 'png', 'webp']],
        'share-image' => ['key' => 'seo.share_image', 'folder' => 'share', 'kind' => 'share', 'max_kb' => 3072, 'types' => ['jpg', 'png', 'webp']],
    ];

    /** Widths stored for photos: the original slot and a smaller srcset variant. */
    public const int PHOTO_WIDTH = 2400;

    public const int PHOTO_SMALL_WIDTH = 1200;

    /** apple-touch-icon size. */
    public const int TOUCH_ICON = 180;

    /** Decoding bigger images could exhaust a shared host's memory. */
    private const int MAX_SIDE = 8000;

    private const array MIME = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon'];

    /** Never allowed inside an uploaded SVG. */
    private const array SVG_FORBIDDEN = [
        '/<script/i', '/\son[a-z]+\s*=/i', '/javascript:/i', '/foreignObject/i', '/<iframe/i', '/<embed/i', '/<object/i', '/<!ENTITY/i', '/<!DOCTYPE/i', '/data:/i', '/<\?xml-stylesheet/i',
    ];

    public function __construct(private FilesystemManager $filesystems) {}

    public function disk(): Filesystem
    {
        return $this->filesystems->disk('brand');
    }

    /** @return array{key: string, folder: string, kind: string, max_kb: int, types: list<string>} */
    public static function role(string $role): array
    {
        return self::ROLES[$role] ?? throw new RuntimeException("Unknown brand image {$role}.");
    }

    /** The role whose setting is $key. */
    public static function roleForKey(string $key): ?string
    {
        foreach (self::ROLES as $role => $spec) {
            if ($spec['key'] === $key) {
                return $role;
            }
        }

        return null;
    }

    /**
     * What is wrong with an upload, in words for the person uploading, or
     * null when it can be stored.
     */
    public function problem(string $role, UploadedFile $file): ?string
    {
        $spec = self::role($role);
        $bytes = (string) file_get_contents($file->getRealPath() ?: '');

        if (strlen($bytes) > $spec['max_kb'] * 1024) {
            return sprintf('The file is larger than %s MB.', rtrim(rtrim(number_format($spec['max_kb'] / 1024, 1), '0'), '.'));
        }

        $type = $this->sniff($bytes);
        $extension = strtolower($file->getClientOriginalExtension());
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;

        if ($type === null || ! in_array($type, $spec['types'], true)) {
            return 'Upload a '.$this->typeList($spec['types']).' file.';
        }

        if ($extension !== $type) {
            return 'The file name ends in .'.$extension.' but the file is '.strtoupper($type).'.';
        }

        if ($type === 'svg') {
            foreach (self::SVG_FORBIDDEN as $pattern) {
                if (preg_match($pattern, $bytes) === 1) {
                    return 'This SVG contains scripts, links or embedded content, which are not allowed. Export it again as a plain SVG.';
                }
            }

            return null;
        }

        if ($type === 'ico') {
            return null;
        }

        $size = @getimagesizefromstring($bytes);

        if ($size === false) {
            return 'The image could not be read.';
        }

        [$width, $height] = $size;

        if ($width > self::MAX_SIDE || $height > self::MAX_SIDE) {
            return 'The image is larger than '.self::MAX_SIDE.' px on a side.';
        }

        return match ($spec['kind']) {
            'icon' => $width !== $height || $width < 512 ? 'The icon must be square and at least 512 × 512 px.' : null,
            'favicon' => $width < 48 || $height < 48 ? 'The favicon must be at least 48 × 48 px.' : null,
            'share' => $width < 200 || $height < 200 || $width > 4096 || $height > 4096 ? 'The share image must be 200 to 4096 px on each side.' : null,
            'photo' => $width < 600 ? 'The photo must be at least 600 px wide.' : null,
            default => null,
        };
    }

    /**
     * Stores a checked upload and returns the path for the setting. Photos
     * are re-encoded (which drops EXIF) to WebP at quality 82 in two
     * widths; the share image becomes a JPEG for link previews.
     */
    public function store(string $role, UploadedFile $file): string
    {
        $problem = $this->problem($role, $file);

        if ($problem !== null) {
            throw new RuntimeException($problem);
        }

        $spec = self::role($role);
        $bytes = (string) file_get_contents($file->getRealPath() ?: '');
        $type = (string) $this->sniff($bytes);

        if ($spec['kind'] === 'photo') {
            $image = $this->decode($bytes);
            $large = $this->encode($this->resize($image, self::PHOTO_WIDTH), 'webp');
            $small = $this->encode($this->resize($image, self::PHOTO_SMALL_WIDTH), 'webp');
            $path = $spec['folder'].'/'.$this->hash($large).'.webp';
            $this->disk()->put($path, $large);
            $this->disk()->put($this->variantPath($path, self::PHOTO_SMALL_WIDTH), $small);

            return $path;
        }

        if ($spec['kind'] === 'share') {
            $jpeg = $this->encode($this->resize($this->decode($bytes), 1200), 'jpg');
            $path = $spec['folder'].'/'.$this->hash($jpeg).'.jpg';
            $this->disk()->put($path, $jpeg);

            return $path;
        }

        $path = $spec['folder'].'/'.$this->hash($bytes).'.'.$type;
        $this->disk()->put($path, $bytes);

        if ($spec['kind'] === 'icon' && $type !== 'svg') {
            // Home-screen icon for iOS (PLATFORM_CONSOLE.md § 9.2).
            $this->disk()->put($this->touchIconPath($path), $this->encode($this->resize($this->decode($bytes), self::TOUCH_ICON), 'png'));
        }

        return $path;
    }

    /** The stored file for a role in a theme, or null when none is set. */
    public function path(Theme $theme, string $role): ?string
    {
        $path = $theme->string(self::role($role)['key']);

        return $path !== '' && $this->isStoredPath($path) ? $path : null;
    }

    /** /brand/{role}?v={hash}, or null when the theme has no image there. */
    public function url(Theme $theme, string $role, ?int $width = null): ?string
    {
        $path = $this->path($theme, $role);

        if ($path === null) {
            return null;
        }

        return route('brand.asset', array_filter(['role' => $role, 'v' => $this->version($path), 'w' => $width]));
    }

    /** The uploaded favicon, else the icon, else the generated mark. */
    public function faviconUrl(Theme $theme): string
    {
        return $this->url($theme, 'favicon') ?? $this->url($theme, 'icon') ?? route('brand.asset', 'favicon');
    }

    /** "…/hero/abc.webp 2400w, …?w=1200 1200w" for a photo. */
    public function srcset(Theme $theme, string $role): ?string
    {
        $large = $this->url($theme, $role);

        return $large === null ? null : $this->url($theme, $role, self::PHOTO_SMALL_WIDTH).' '.self::PHOTO_SMALL_WIDTH.'w, '.$large.' '.self::PHOTO_WIDTH.'w';
    }

    /** The uploaded icon's 180 px PNG, or null (none, or an SVG icon). */
    public function touchIcon(Theme $published): ?string
    {
        $icon = $this->path($published, 'icon');

        if ($icon === null || str_ends_with($icon, '.svg')) {
            return null;
        }

        $path = $this->touchIconPath($icon);

        return $this->disk()->exists($path) ? $path : null;
    }

    /**
     * The generated mark as a PNG (iOS takes no SVG): primary ground,
     * white house, accent dot. iOS rounds the corners itself.
     */
    public function generatedTouchIcon(Theme $theme): string
    {
        $size = self::TOUCH_ICON;
        $scale = $size / 34;
        $image = imagecreatetruecolor($size, $size);
        $colour = function (string $hex) use ($image): int {
            $hex = ltrim(Color::normalize($hex), '#');
            // Two hex digits each, so always 0–255.
            $channel = fn (int $offset): int => max(0, min(255, (int) hexdec(substr($hex, $offset, 2))));

            return (int) imagecolorallocate($image, $channel(0), $channel(2), $channel(4));
        };

        imagefill($image, 0, 0, $colour($theme->primaryPalette()[700]));
        $house = [9, 16.2, 17, 10, 25, 16.2, 25, 26, 19.5, 26, 19.5, 20.5, 14.5, 20.5, 14.5, 26, 9, 26];
        imagefilledpolygon($image, array_map(fn (float|int $point): int => (int) round($point * $scale), $house), $colour('#ffffff'));
        imagefilledellipse($image, (int) round(24.5 * $scale), (int) round(10.5 * $scale), (int) round(6 * $scale), (int) round(6 * $scale), $colour($theme->accent()));

        return $this->encode($image, 'png');
    }

    /**
     * The stored file a /brand request asks for: the one whose hash is $version
     * (drafts in the console preview use this), else the published one.
     */
    public function locate(string $role, ?string $version, ?int $width, Theme $published): ?string
    {
        $folder = self::role($role)['folder'];

        if ($version !== null) {
            if (preg_match('/^[a-f0-9]{16,64}$/D', $version) !== 1) {
                return null;
            }

            $path = null;

            foreach ($this->disk()->files($folder) as $file) {
                if (preg_match('/^'.preg_quote($folder.'/'.$version, '/').'\.(png|jpg|webp|svg|ico)$/D', $file) === 1) {
                    $path = $file;
                }
            }
        } else {
            $path = $this->path($published, $role);
        }

        if ($path === null) {
            return null;
        }

        if ($width === self::PHOTO_SMALL_WIDTH && self::role($role)['kind'] === 'photo') {
            $path = $this->variantPath($path, self::PHOTO_SMALL_WIDTH);
        }

        return $this->disk()->exists($path) ? $path : null;
    }

    public function mimeType(string $path): string
    {
        return self::MIME[pathinfo($path, PATHINFO_EXTENSION)] ?? 'application/octet-stream';
    }

    public function version(string $path): string
    {
        return pathinfo($path, PATHINFO_FILENAME);
    }

    /**
     * Deletes stored files no value set names any more (PLATFORM_CONSOLE.md
     * § 9.2: replacing an image deletes the old file). Files in the draft,
     * the published theme or any version are kept, so restore still works.
     *
     * @param  list<array<string, mixed>>  $valueSets
     * @return int files deleted
     */
    public function prune(array $valueSets): int
    {
        $kept = [];

        foreach ($valueSets as $values) {
            foreach (self::ROLES as $spec) {
                $path = $values[$spec['key']] ?? null;

                if (is_string($path) && $this->isStoredPath($path)) {
                    $kept[$this->version($path)] = true;
                }
            }
        }

        $deleted = 0;

        foreach (self::ROLES as $spec) {
            foreach ($this->disk()->files($spec['folder']) as $file) {
                // Variants share the original's hash: "<hash>-1200.webp".
                $hash = strtok(pathinfo($file, PATHINFO_FILENAME), '-');

                if (! isset($kept[$hash])) {
                    $this->disk()->delete($file);
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    /**
     * Contrast of white text over a photo under its overlay (§ 9.2: warn
     * below 4.5:1), from the photo's average colour.
     */
    public function overlayContrast(string $path, string $overlayHex, int $opacityPercent): ?float
    {
        if (! $this->disk()->exists($path) || $this->mimeType($path) === 'image/svg+xml') {
            return null;
        }

        $image = @imagecreatefromstring((string) $this->disk()->get($path));

        if (! $image instanceof GdImage) {
            return null;
        }

        $pixel = imagecreatetruecolor(1, 1);
        imagecopyresampled($pixel, $image, 0, 0, 0, 0, 1, 1, imagesx($image), imagesy($image));
        $rgb = imagecolorat($pixel, 0, 0);
        $overlay = sscanf(ltrim(Color::normalize($overlayHex), '#'), '%2x%2x%2x') ?? [0, 0, 0];
        $alpha = max(0, min(100, $opacityPercent)) / 100;

        $mixed = '#';

        foreach ([($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF] as $index => $channel) {
            $value = (int) round($channel * (1 - $alpha) + ((int) ($overlay[$index] ?? 0)) * $alpha);
            $mixed .= str_pad(dechex($value), 2, '0', STR_PAD_LEFT);
        }

        return round(Color::contrast('#ffffff', $mixed), 2);
    }

    public function isStoredPath(string $path): bool
    {
        return preg_match('/^[a-z_]+\/[a-f0-9]{16,64}\.(png|jpg|webp|svg|ico)$/D', $path) === 1;
    }

    private function touchIconPath(string $path): string
    {
        return preg_replace('/\.\w+$/', '-'.self::TOUCH_ICON.'.png', $path) ?? $path;
    }

    private function variantPath(string $path, int $width): string
    {
        return preg_replace('/\.(\w+)$/', '-'.$width.'.$1', $path) ?? $path;
    }

    /** The file type from its first bytes, never from its name. */
    private function sniff(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => 'png',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'jpg',
            strlen($bytes) > 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP' => 'webp',
            str_starts_with($bytes, "\x00\x00\x01\x00") => 'ico',
            // An XML prolog, comments and a doctype may come first; the
            // doctype is then refused by SVG_FORBIDDEN with a clear message.
            preg_match('/^\s*(<\?xml[^>]*>\s*)?(<!--.*?-->\s*|<!DOCTYPE[^\[>]*(\[.*?\])?\s*>\s*)*<svg[\s>]/is', $bytes) === 1 => 'svg',
            default => null,
        };
    }

    /** @param  list<string>  $types */
    private function typeList(array $types): string
    {
        $names = array_map(strtoupper(...), $types);
        $last = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names).' or '.$last;
    }

    private function decode(string $bytes): GdImage
    {
        $image = @imagecreatefromstring($bytes);

        if (! $image instanceof GdImage) {
            throw new RuntimeException('The image could not be read.');
        }

        return $image;
    }

    private function resize(GdImage $image, int $maxWidth): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $targetWidth = min($width, $maxWidth);
        $targetHeight = max(1, (int) round($height * $targetWidth / max(1, $width)));

        $resized = imagecreatetruecolor(max(1, $targetWidth), $targetHeight);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, max(1, $targetWidth), $targetHeight, $width, $height);

        return $resized;
    }

    private function encode(GdImage $image, string $type): string
    {
        if ($type === 'jpg') {
            // JPEG has no transparency: flatten onto white, not black.
            $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
            imagefill($flat, 0, 0, (int) imagecolorallocate($flat, 255, 255, 255));
            imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
            $image = $flat;
        }

        ob_start();
        match ($type) {
            'webp' => imagewebp($image, null, 82),
            'png' => imagepng($image, null, 9),
            default => imagejpeg($image, null, 85),
        };

        return (string) ob_get_clean();
    }

    private function hash(string $bytes): string
    {
        return substr(hash('sha256', $bytes), 0, 32);
    }
}

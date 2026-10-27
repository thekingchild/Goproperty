<?php

declare(strict_types=1);

namespace App\Media;

use InvalidArgumentException;

/**
 * What one media collection accepts (config/media.php `collections`): the
 * sniffed types, the size cap and the conversions made for its images.
 */
final readonly class CollectionRules
{
    /**
     * The only types a collection can accept, with the extension a stored
     * file gets from its sniffed type (never from the uploaded name). SVG,
     * HTML, scripts and anything else are not here, so no configuration can
     * let them in.
     */
    public const array EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    private const array LABELS = [
        'image/jpeg' => 'JPEG',
        'image/png' => 'PNG',
        'image/webp' => 'WebP',
        'application/pdf' => 'PDF',
    ];

    /**
     * @param  list<string>  $mimes
     * @param  list<string>  $conversions
     */
    public function __construct(
        public string $name,
        public array $mimes,
        public int $maxKilobytes,
        public array $conversions,
    ) {}

    /** @throws InvalidArgumentException for a collection that is not configured */
    public static function for(string $collection): self
    {
        $config = config("media.collections.{$collection}");

        if (! is_array($config)) {
            throw new InvalidArgumentException("Media collection \"{$collection}\" is not configured in config/media.php.");
        }

        $mimes = array_values(array_filter(
            is_array($config['mimes'] ?? null) ? $config['mimes'] : [],
            static fn (mixed $mime): bool => is_string($mime) && isset(self::EXTENSIONS[$mime]),
        ));
        $conversions = array_values(array_filter(
            is_array($config['conversions'] ?? null) ? $config['conversions'] : [],
            static fn (mixed $name): bool => is_string($name) && in_array($name, self::conversionNames(), true),
        ));
        $maxKilobytes = $config['max_kb'] ?? null;

        if ($mimes === [] || ! is_int($maxKilobytes) || $maxKilobytes < 1) {
            throw new InvalidArgumentException("Media collection \"{$collection}\" needs known types and a max_kb of 1 or more.");
        }

        return new self($collection, $mimes, $maxKilobytes, $conversions);
    }

    /** @return list<string> every configured collection */
    public static function names(): array
    {
        $collections = config('media.collections');

        return is_array($collections) ? array_values(array_filter(array_keys($collections), is_string(...))) : [];
    }

    /** @return list<string> */
    public static function conversionNames(): array
    {
        $conversions = config('media.conversions');

        return is_array($conversions) ? array_values(array_filter(array_keys($conversions), is_string(...))) : [];
    }

    /** @return list<string> the collections whose images get this conversion */
    public static function collectionsWithConversion(string $conversion): array
    {
        return array_values(array_filter(
            self::names(),
            static fn (string $collection): bool => in_array($conversion, self::for($collection)->conversions, true),
        ));
    }

    public function accepts(string $mime): bool
    {
        return in_array($mime, $this->mimes, true);
    }

    public function maxBytes(): int
    {
        return $this->maxKilobytes * 1024;
    }

    /** "10 MB", "800 KB" for messages. */
    public function maxSizeLabel(): string
    {
        return $this->maxKilobytes % 1024 === 0 ? ($this->maxKilobytes / 1024).' MB' : $this->maxKilobytes.' KB';
    }

    /** "JPEG, PNG or PDF" for messages. */
    public function typesLabel(): string
    {
        $labels = array_map(static fn (string $mime): string => self::LABELS[$mime], $this->mimes);
        $last = array_pop($labels);

        return $labels === [] ? (string) $last : implode(', ', $labels).' or '.$last;
    }
}

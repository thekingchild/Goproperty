<?php

declare(strict_types=1);

namespace App\Installer\Upgrades;

use App\Installer\AppVersion;
use Carbon\CarbonImmutable;

/** The newest published release, or why it is not known (Console → Operations → Version). */
final readonly class ReleaseCheck
{
    public function __construct(
        public ReleaseCheckStatus $status,
        public string $message,
        public ?string $version = null,
        public ?string $url = null,
        public ?CarbonImmutable $publishedAt = null,
        public ?CarbonImmutable $checkedAt = null,
    ) {}

    /** The published release is newer than $current (false when either is not a release number). */
    public function isNewerThan(string $current): bool
    {
        return $this->version !== null && AppVersion::isRelease($current) && AppVersion::compare($this->version, $current) > 0;
    }

    /** @return array{status: string, message: string, version: string|null, url: string|null, published_at: string|null, checked_at: string|null} */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'message' => $this->message,
            'version' => $this->version,
            'url' => $this->url,
            'published_at' => $this->publishedAt?->toIso8601String(),
            'checked_at' => $this->checkedAt?->toIso8601String(),
        ];
    }

    /** @param  array<mixed>  $data */
    public static function fromArray(array $data): ?self
    {
        $status = ReleaseCheckStatus::tryFrom(is_string($data['status'] ?? null) ? $data['status'] : '');

        if ($status === null || ! is_string($data['message'] ?? null)) {
            return null;
        }

        $time = static fn (mixed $value): ?CarbonImmutable => is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;

        return new self(
            $status,
            $data['message'],
            is_string($data['version'] ?? null) ? $data['version'] : null,
            is_string($data['url'] ?? null) ? $data['url'] : null,
            $time($data['published_at'] ?? null),
            $time($data['checked_at'] ?? null),
        );
    }
}

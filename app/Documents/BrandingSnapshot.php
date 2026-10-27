<?php

declare(strict_types=1);

namespace App\Documents;

use App\Branding\Branding;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * An organization's branding as it was when a document was issued
 * (BRANDING_DOCUMENTS.md § 1, § 5; AGENT.md § 4: "agency branding is
 * snapshotted on each issued document"): every setting, the platform's
 * product name for the "Generated with" line, and the logo, signature and
 * stamp themselves as PNG data URIs, not media ids, so a later change of
 * settings, a replaced image or a plan change never alters a document
 * already issued.
 *
 * Made by BrandingSnapshots::take(); the issuing module stores toArray() in
 * the document's json column (e.g. `bills.branding_snapshot`) and rebuilds
 * it with fromArray() whenever the document is shown or printed. Images are
 * stored as written (each at most about 1 MB as PNG, usually far less):
 * P03-05c may dedupe them if the column grows.
 */
final readonly class BrandingSnapshot
{
    public const int VERSION = 1;

    private const string PNG_DATA_URI = 'data:image/png;base64,';

    /** @param  array<string, string|null>  $images  role (logo, signature, stamp) => PNG data URI */
    public function __construct(
        public Branding $branding,
        public string $productName,
        public array $images,
        public CarbonImmutable $takenAt,
    ) {
        foreach ($images as $role => $image) {
            if (! in_array($role, Branding::IMAGE_ROLES, true) || ($image !== null && ! self::isPngDataUri($image))) {
                throw new InvalidArgumentException('A branding snapshot holds only PNG data URIs for the logo, signature and stamp.');
            }
        }
    }

    public function image(string $role): ?string
    {
        return $this->images[$role] ?? null;
    }

    /** What the document templates take (resources/views/documents). */
    public function documentBranding(): DocumentBranding
    {
        return new DocumentBranding(
            $this->branding,
            $this->productName,
            ! $this->branding->hidePoweredBy,
            $this->image('logo'),
            $this->image('signature'),
            $this->image('stamp'),
        );
    }

    /**
     * For the document's json column.
     *
     * @return array{version: int, taken_at: string, product_name: string, branding: array<string, string|int|bool|null>, images: array<string, string|null>}
     */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'taken_at' => $this->takenAt->toIso8601String(),
            'product_name' => $this->productName,
            'branding' => ['company_name' => $this->branding->companyName] + $this->branding->toArray(),
            'images' => [
                'logo' => $this->image('logo'),
                'signature' => $this->image('signature'),
                'stamp' => $this->image('stamp'),
            ],
        ];
    }

    /** @param  array<array-key, mixed>  $stored  toArray() as read back from the json column */
    public static function fromArray(array $stored): self
    {
        $branding = $stored['branding'] ?? null;
        $images = is_array($stored['images'] ?? null) ? $stored['images'] : [];
        $takenAt = $stored['taken_at'] ?? null;

        if (! is_array($branding) || ! is_string($takenAt)) {
            throw new InvalidArgumentException('This is not a branding snapshot.');
        }

        $image = static fn (string $role): ?string => isset($images[$role]) && is_string($images[$role]) && self::isPngDataUri($images[$role]) ? $images[$role] : null;

        return new self(
            Branding::fromSnapshot($branding),
            is_string($stored['product_name'] ?? null) ? $stored['product_name'] : '',
            ['logo' => $image('logo'), 'signature' => $image('signature'), 'stamp' => $image('stamp')],
            CarbonImmutable::parse($takenAt),
        );
    }

    private static function isPngDataUri(string $value): bool
    {
        return str_starts_with($value, self::PNG_DATA_URI)
            && base64_decode(substr($value, strlen(self::PNG_DATA_URI)), true) !== false;
    }
}

<?php

declare(strict_types=1);

namespace Modules\Properties\Models;

use App\Media\HasOrganizationMedia;
use App\Media\Media;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Properties\Database\Factories\PropertyFactory;
use Modules\Properties\Enums\PropertyStatus;
use Modules\Properties\Enums\PropertyType;
use Spatie\MediaLibrary\HasMedia;

/**
 * A building (DATABASE_SCHEMA.md § 3 `properties`, ARCHITECTURE.md § 6.2):
 * organization-scoped, with an optional owner profile, amenities and photos
 * (media collection `photos`; `photo_media_id` is the cover). Written only
 * through Modules\Properties\Buildings\Properties and PropertyPhotos, which
 * check PropertyPolicy and audit every change. Archiving sets `status`
 * (never a delete, AGENT.md § 3 rule 7); soft deletes are for import
 * rollback only. `import_batch_id` and `external_ref` are import provenance
 * (§ 8A), set only by the importer. Other modules read buildings through
 * Modules\Properties\Contracts\PropertyDirectory.
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $owner_profile_id
 * @property string $name
 * @property string $slug unique per organization, kept when the building is renamed
 * @property PropertyType $property_type
 * @property string $address
 * @property string $state
 * @property string|null $lga
 * @property string|null $landmark
 * @property string|null $description
 * @property list<string>|null $amenities
 * @property int|null $year_built
 * @property int|null $photo_media_id
 * @property PropertyStatus $status
 * @property int|null $import_batch_id
 * @property string|null $external_ref
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read OwnerProfile|null $owner
 * @property-read Media|null $coverPhoto
 */
final class Property extends Model implements HasMedia
{
    /** @use HasFactory<PropertyFactory> */
    use BelongsToOrganization, HasFactory, HasOrganizationMedia, SoftDeletes;

    /** The media collection of a building's photos (config/media.php). */
    public const string PHOTOS = 'photos';

    /** @var list<string> */
    protected $fillable = ['owner_profile_id', 'name', 'property_type', 'address', 'state', 'lga', 'landmark', 'description', 'amenities', 'year_built'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'active'];

    /**
     * The owner profile, archived ones included (a building keeps its owner
     * when the owner is archived).
     *
     * @return BelongsTo<OwnerProfile, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(OwnerProfile::class, 'owner_profile_id')->withTrashed();
    }

    /** @return BelongsTo<Media, $this> */
    public function coverPhoto(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'photo_media_id');
    }

    /** Pages address a building by its slug (/o/{organization}/buildings/{slug}). */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function isArchived(): bool
    {
        return $this->status === PropertyStatus::Archived;
    }

    /**
     * The building's photos in their order, read fresh (not the media
     * library's cached relation).
     *
     * @return Collection<int, Media>
     */
    public function photos(): Collection
    {
        /** @var Collection<int, Media> */
        return Media::query()
            ->where('model_type', $this->getMorphClass())
            ->where('model_id', $this->getKey())
            ->where('collection_name', self::PHOTOS)
            ->orderBy('order_column')
            ->orderBy('id')
            ->get();
    }

    protected static function newFactory(): PropertyFactory
    {
        return PropertyFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'property_type' => PropertyType::class,
            'status' => PropertyStatus::class,
            'amenities' => 'array',
            'year_built' => 'integer',
        ];
    }
}

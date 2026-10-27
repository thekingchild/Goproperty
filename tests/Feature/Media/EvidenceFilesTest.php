<?php

declare(strict_types=1);

use App\Media\CollectionRules;
use App\Media\EvidenceFileProtected;
use App\Media\Media;
use App\Media\MediaUploads;
use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/media.php';
require_once __DIR__.'/../../Support/compliance.php';

/*
 * Evidence files are records (AGENT.md § 3 rule 7, ARCHITECTURE.md § 9
 * "Attachments vs evidence"): every collection says which kind it is, and
 * a file in an evidence collection cannot be deleted (P03-05a adds the
 * first generated evidence, issued PDFs).
 */

beforeEach(function () {
    Storage::fake('media');
    $this->acme = Organization::factory()->create();
});

afterEach(fn () => app(OrganizationContext::class)->clear());

it('classes every media collection as an attachment or as evidence', function () {
    $kinds = collect(CollectionRules::names())->mapWithKeys(fn (string $name): array => [$name => CollectionRules::for($name)->evidence ? 'evidence' : 'attachment'])->all();

    expect($kinds)->toBe([
        'photos' => 'attachment',
        'documents' => 'evidence',
        'branding' => 'evidence',
        'imports' => 'attachment',
        'issued_documents' => 'evidence',
    ]);
});

it('refuses a collection without a kind', function () {
    config(['media.collections.photos.kind' => null]);

    CollectionRules::for('photos');
})->throws(InvalidArgumentException::class, 'needs a kind');

it('refuses to delete an evidence file and keeps the file on the disk', function () {
    $media = inOrganization($this->acme, fn () => app(MediaUploads::class)->add($this->acme, 'documents', mediaImage('proof.jpg')));

    expect(fn () => inOrganization($this->acme, fn () => $media->delete()))->toThrow(EvidenceFileProtected::class)
        ->and(inOrganization($this->acme, fn () => Media::query()->whereKey($media->id)->exists()))->toBeTrue()
        ->and(Storage::disk('media')->exists($media->getPathRelativeToRoot()))->toBeTrue();
});

it('still deletes attachments, and evidence only inside purging() (the NDPA erasure tooling)', function () {
    $photo = inOrganization($this->acme, fn () => app(MediaUploads::class)->add($this->acme, 'photos', mediaImage('front.jpg')));
    $proof = inOrganization($this->acme, fn () => app(MediaUploads::class)->add($this->acme, 'documents', mediaImage('proof.jpg')));

    inOrganization($this->acme, fn () => $photo->delete());
    Media::purging(fn () => inOrganization($this->acme, fn () => $proof->delete()));

    expect(inOrganization($this->acme, fn () => Media::query()->count()))->toBe(0)
        ->and(fn () => inOrganization($this->acme, fn () => app(MediaUploads::class)->add($this->acme, 'documents', mediaImage('again.jpg'))->delete()))->toThrow(EvidenceFileProtected::class);
});

<?php

declare(strict_types=1);

namespace App\Documents;

use App\Media\Media;
use App\Media\MediaUploads;
use App\Pdf\PdfManager;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Spatie\MediaLibrary\HasMedia;

/**
 * Renders documents (BRANDING_DOCUMENTS.md § 5, ARCHITECTURE.md § 6.5): the
 * responsive web page and the PDF from one Blade view, the PDF through the
 * configured driver (App\Pdf\PdfManager: dompdf, or Chrome through
 * Gotenberg).
 *
 * An issued document's PDF is rendered once and kept as an evidence file
 * (media collection `issued_documents`, owned by the document's record):
 * stored() returns the kept file whenever there is one and renders only
 * when there is none, so a printed document never changes, whatever
 * happens to the templates, the driver or the branding afterwards.
 */
final readonly class DocumentRenderer
{
    public const string COLLECTION = 'issued_documents';

    public function __construct(
        private ViewFactory $views,
        private PdfManager $pdf,
        private MediaUploads $uploads,
        private OrganizationContext $context,
    ) {}

    /** The document as a web page. */
    public function web(Document $document): string
    {
        return $this->html($document, RenderTarget::Web);
    }

    /** The HTML for one target; $pageCount is the total the dompdf footer prints. */
    public function html(Document $document, RenderTarget $target, int $pageCount = 1): string
    {
        return $this->views->make($document->view(), [
            ...$document->data(),
            'brand' => $document->brand(),
            'target' => $target,
            'pageCount' => max(1, $pageCount),
        ])->render();
    }

    /**
     * PDF bytes from the given driver (default: `pdf.driver`). Not kept:
     * issued documents go through stored().
     *
     * dompdf cannot count pages while it lays them out, so its footer's
     * "of y" comes from a first pass: rendered as one page, and again with
     * the real total when the document runs longer. Chrome counts natively.
     */
    public function pdf(Document $document, ?string $driver = null): string
    {
        $driver ??= $this->pdf->getDefaultDriver();
        $target = RenderTarget::forDriver($driver);
        $renderer = $this->pdf->renderer($driver);

        $bytes = $renderer->render($this->html($document, $target));

        if ($target === RenderTarget::Dompdf && ($pages = PdfPages::count($bytes)) > 1) {
            $bytes = $renderer->render($this->html($document, $target, $pages));
        }

        return $bytes;
    }

    /**
     * The kept PDF of $owner's document $key (e.g. "invoice", or
     * "receipt-void" for a voided receipt's re-issue), rendered and stored
     * the first time only. Runs in the owner's organization, holding a lock
     * on the owner's row so two queue workers never render it twice.
     * $owner must be a saved model of the current organization.
     */
    public function stored(HasMedia $owner, string $key, Document $document): Media
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,39}$/', $key) !== 1) {
            throw new InvalidArgumentException('A document key is a short lower-case name such as "invoice".');
        }

        if (! $owner instanceof Model || ! $owner->exists) {
            throw new InvalidArgumentException('A stored PDF belongs to a saved record.');
        }

        $this->context->get();

        return DB::transaction(function () use ($owner, $key, $document): Media {
            $owner->newQuery()->whereKey($owner->getKey())->lockForUpdate()->firstOrFail();

            $kept = $this->kept($owner, $key);

            if ($kept instanceof Media) {
                return $kept;
            }

            $driver = $this->pdf->getDefaultDriver();
            $bytes = $this->pdf($document, $driver);
            $temporary = tempnam(sys_get_temp_dir(), 'gp-document-');

            if ($temporary === false || file_put_contents($temporary, $bytes) === false) {
                throw new RuntimeException('The PDF could not be written to a temporary file.');
            }

            try {
                return $this->uploads->add($owner, self::COLLECTION, $temporary, $document->fileName(), [
                    'document_key' => $key,
                    'pdf_driver' => $driver,
                    'pages' => PdfPages::count($bytes),
                ]);
            } finally {
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }
        });
    }

    /** The kept PDF of $owner's document $key, if it was rendered already. */
    public function kept(HasMedia $owner, string $key): ?Media
    {
        if (! $owner instanceof Model) {
            return null;
        }

        return Media::query()
            ->where('model_type', $owner->getMorphClass())
            ->where('model_id', $owner->getKey())
            ->where('collection_name', self::COLLECTION)
            ->orderBy('id')
            ->get()
            ->first(static fn (Media $media): bool => $media->getCustomProperty('document_key') === $key);
    }
}

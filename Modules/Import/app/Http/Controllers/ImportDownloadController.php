<?php

declare(strict_types=1);

namespace Modules\Import\Http\Controllers;

use App\Models\Organization;
use Illuminate\Support\Facades\Gate;
use Modules\Import\Batches\ErrorWorkbook;
use Modules\Import\Contracts\ImportTargets;
use Modules\Import\Models\ImportBatch;
use Modules\Import\Reading\LocalCopy;
use Modules\Import\Templates\TemplateWriter;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The two spreadsheets the import pages offer (DATA_IMPORT.md § 2): the
 * template with a sheet per registered target, and a batch's error
 * workbook (the dry run again, every problem next to its row). Both are
 * written to a temporary file and deleted once sent. Inside
 * /o/{organization}: {batch} resolves only in the organization.
 */
final class ImportDownloadController
{
    public function template(ImportTargets $targets, TemplateWriter $writer): BinaryFileResponse
    {
        Gate::authorize('viewAny', ImportBatch::class);

        $path = self::temporaryFile();
        $writer->write($path, $targets->all());

        return response()->download($path, 'import-template.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    /** A read (GET): also open in a suspended organization and to impersonating staff. */
    public function errors(Organization $organization, ImportBatch $batch, ErrorWorkbook $workbook): BinaryFileResponse
    {
        Gate::authorize('view', $batch);

        $media = $batch->dry_run_at !== null ? $batch->file() : null;
        abort_if($media === null, 404);

        $path = self::temporaryFile();
        LocalCopy::using($media, fn (string $source) => $workbook->write($batch, $source, $path));

        return response()->download($path, "import-{$batch->id}-problems.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    private static function temporaryFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'gp-xlsx-');

        if ($path === false) {
            throw new RuntimeException('No temporary file could be made.');
        }

        return $path;
    }
}

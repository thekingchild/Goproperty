<?php

declare(strict_types=1);

namespace App\Privacy\Export;

use App\Media\Media;
use App\Models\DataRequest;
use App\Privacy\Data\Person;
use App\Privacy\Data\PersonalDataMap;
use App\Privacy\Data\PersonSelection;
use App\Privacy\Data\RowSerializer;
use App\Privacy\Data\TableRule;
use App\Privacy\DataRequestScope;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Builds a data export's archive (P09-02; NDPA 2023 s. 36 portability):
 * `data/<table>.json` (a JSON array of the rows, RowSerializer), the
 * originals of the files those rows hold (`files/<table>/<uuid>-<name>`),
 * `manifest.json` (what was asked, when, rows per table, which tables were
 * left out and why) and `README.txt`. A tenant's export holds the rows
 * PersonalDataMap finds for them in their organization; an organization's
 * export holds every row of every listed table with its `organization_id`,
 * except the tables the map leaves out of it.
 *
 * Shared hosting runs jobs of at most ~50 seconds (AGENT.md § 4): work()
 * stops at the deadline and keeps its place in `data_requests.progress`
 * (phase, table, last id), so the job queues itself again and carries on.
 * Rows go to a working folder on the `data-exports` disk; the archive is
 * written at the end and the folder removed.
 */
final readonly class DataExportBuilder
{
    public const string DISK = 'data-exports';

    private const int ROWS_PER_CHUNK = 500;

    public function __construct(
        private PersonalDataMap $map,
        private RowSerializer $serializer,
    ) {}

    /** Works until the archive is finished (true) or the deadline (microtime) passes (false). */
    public function work(DataRequest $request, float $deadline): bool
    {
        $progress = $request->progress ?? [];
        $progress += ['phase' => 'rows', 'table' => 0, 'after' => 0, 'counts' => [], 'file_after' => 0, 'files' => 0, 'missing_files' => 0];
        $person = $request->scope === DataRequestScope::Tenant ? Person::load($request->organization_id, (int) $request->tenant_profile_id) : null;
        $selection = $person !== null ? PersonSelection::of($person, $this->map) : null;
        $tables = $this->tables($request);
        $work = 'work/'.$request->id;

        try {
            while (microtime(true) < $deadline) {
                $done = match ($progress['phase']) {
                    'rows' => $this->rows($request, $tables, $selection, $work, $progress),
                    'files' => $this->files($request, $selection, $work, $progress),
                    'zip' => $this->zip($request, $person, $work, $progress),
                    default => true,
                };

                if ($done && $progress['phase'] === 'done') {
                    return true;
                }
            }
        } finally {
            $request->forceFill(['progress' => $progress])->save();
        }

        return false;
    }

    /** Removes the working folder (a failed or cancelled export). */
    public function discard(DataRequest $request): void
    {
        $this->disk()->deleteDirectory('work/'.$request->id);
    }

    /**
     * The tables this export writes, in the map's order.
     *
     * @return array<string, TableRule>
     */
    public function tables(DataRequest $request): array
    {
        return array_filter(
            $this->map->rules(),
            static fn (TableRule $rule): bool => $request->scope === DataRequestScope::Tenant ? $rule->person !== null : $rule->notInOrganizationExport === null,
        );
    }

    /**
     * One chunk of one table's rows.
     *
     * @param  array<string, TableRule>  $tables
     * @param  array<string, mixed>  $progress
     */
    private function rows(DataRequest $request, array $tables, ?PersonSelection $selection, string $work, array &$progress): bool
    {
        $names = array_keys($tables);
        $index = (int) $progress['table'];

        if (! isset($names[$index])) {
            $progress['phase'] = 'files';

            return false;
        }

        $table = $names[$index];
        $rule = $tables[$table];
        $path = "{$work}/data/{$table}.json";
        $after = (int) $progress['after'];
        $count = (int) ($progress['counts'][$table] ?? 0);

        if ($after === 0 && $count === 0) {
            $this->disk()->put($path, '[');
        }

        $query = DB::table($table)->where('organization_id', $request->organization_id)->where('id', '>', $after)->orderBy('id')->limit(self::ROWS_PER_CHUNK);

        if ($selection !== null) {
            $query->whereIn('id', $selection->ids($table));
        }

        $rows = $query->get();
        $lines = [];

        foreach ($rows as $row) {
            $lines[] = ($count === 0 && $lines === [] ? "\n" : ",\n").json_encode($this->serializer->row($row, $rule), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
            $after = (int) $row->id;
        }

        if ($lines !== []) {
            $this->append($path, implode('', $lines));
        }

        $count += count($lines);
        $progress['counts'][$table] = $count;

        if ($rows->count() < self::ROWS_PER_CHUNK) {
            $this->append($path, $count === 0 ? ']' : "\n]\n");
            $progress['table'] = $index + 1;
            $progress['after'] = 0;
        } else {
            $progress['after'] = $after;
        }

        return false;
    }

    /**
     * A few of the files' originals (never the thumbnails).
     *
     * @param  array<string, mixed>  $progress
     */
    private function files(DataRequest $request, ?PersonSelection $selection, string $work, array &$progress): bool
    {
        $query = Media::query()->where('id', '>', (int) $progress['file_after'])->orderBy('id')->limit(20);

        if ($selection !== null) {
            $query->whereIn('id', $selection->ids('media'));
        }

        $files = $query->get();

        foreach ($files as $media) {
            $progress['file_after'] = $media->id;
            $source = Storage::disk($media->disk);
            $from = $media->getPathRelativeToRoot();

            if (! $source->exists($from)) {
                $progress['missing_files']++;

                continue;
            }

            $owner = PersonalDataMap::tableOfMorph((string) $media->model_type) ?? 'other';
            $stream = $source->readStream($from);

            if ($stream === null) {
                $progress['missing_files']++;

                continue;
            }

            $this->disk()->writeStream("{$work}/files/{$owner}/{$media->uuid}-".$this->safeName((string) $media->file_name), $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            $progress['files']++;
        }

        if ($files->count() < 20) {
            $progress['phase'] = 'zip';
        }

        return false;
    }

    /** @param  array<string, mixed>  $progress */
    private function zip(DataRequest $request, ?Person $person, string $work, array &$progress): bool
    {
        $disk = $this->disk();
        $name = 'exports/'.$request->id.'-'.Str::random(24).'.zip';
        $disk->makeDirectory('exports');
        $disk->put("{$work}/manifest.json", json_encode($this->manifest($request, $person, $progress), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $disk->put("{$work}/README.txt", $this->readme($request));

        $zip = new ZipArchive;

        if ($zip->open($disk->path($name), ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The export archive could not be created.');
        }

        foreach ($disk->allFiles($work) as $file) {
            $inside = substr($file, strlen($work) + 1);
            $zip->addFile($disk->path($file), $inside);

            // Photos and PDFs are compressed already: storing them keeps the last step short.
            if (str_starts_with($inside, 'files/')) {
                $zip->setCompressionName($inside, ZipArchive::CM_STORE);
            }
        }

        if (! $zip->close()) {
            throw new RuntimeException('The export archive could not be written.');
        }

        $disk->deleteDirectory($work);

        $request->forceFill([
            'file_path' => $name,
            'file_size' => $disk->size($name),
            'file_sha256' => hash_file('sha256', $disk->path($name)) ?: null,
            'result' => ['rows' => $progress['counts'], 'files' => $progress['files'], 'missing_files' => $progress['missing_files']],
        ]);
        $progress['phase'] = 'done';

        return true;
    }

    /**
     * @param  array<string, mixed>  $progress
     * @return array<string, mixed>
     */
    private function manifest(DataRequest $request, ?Person $person, array $progress): array
    {
        $left = [];

        foreach ($this->map->rules() as $table => $rule) {
            $why = $request->scope === DataRequestScope::Tenant ? $rule->notAboutTenant : $rule->notInOrganizationExport;

            if ($why !== null) {
                $left[$table] = $why;
            }
        }

        return [
            'export' => $request->scope === DataRequestScope::Tenant ? 'One tenant\'s data in one organization' : 'One organization\'s data',
            'request' => $request->id,
            'organization_id' => $request->organization_id,
            'tenant_profile_id' => $person?->tenantProfileId,
            'made_at' => now()->toIso8601String(),
            'times' => 'Times in the data are UTC, as stored.',
            'rows' => $progress['counts'],
            'files' => $progress['files'],
            'files_missing_from_storage' => $progress['missing_files'],
            'masked' => 'Encrypted values (ID numbers, bank account numbers) show only their last four characters; passwords, keys and tokens are never exported.',
            'tables_left_out' => $left,
        ];
    }

    private function readme(DataRequest $request): string
    {
        $what = $request->scope === DataRequestScope::Tenant
            ? 'the personal data one organization holds about one of its tenants in GoProperty'
            : 'the data one organization holds in GoProperty';

        return <<<TXT
            This archive holds {$what}, as asked in data request #{$request->id}.

            data/       one JSON file per table: an array of its rows, every column but secrets.
            files/      the original uploaded or issued files those rows hold (proofs, receipts, attachments).
            manifest.json   when it was made, how many rows each table has, and the tables left out and why.

            Amounts are in kobo (100 kobo = 1 naira). Times are UTC.
            Encrypted values such as ID and bank account numbers show only their last four characters.
            Keep this file safe: it contains personal data (Nigeria Data Protection Act 2023).
            TXT;
    }

    private function append(string $path, string $text): void
    {
        file_put_contents($this->disk()->path($path), $text, FILE_APPEND | LOCK_EX);
    }

    private function safeName(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? 'file';

        return mb_substr(trim($name, '-.') ?: 'file', 0, 80);
    }

    private function disk(): FilesystemAdapter
    {
        return Storage::disk(self::DISK);
    }
}

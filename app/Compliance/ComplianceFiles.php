<?php

declare(strict_types=1);

namespace App\Compliance;

use finfo;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The private store for compliance documents (ONBOARDING_COMPLIANCE.md
 * § 5.3, ARCHITECTURE.md § 9–10): its own disk (config/compliance.php),
 * never public and never the media library's. Each upload is checked on
 * the server (size cap; type sniffed from the bytes: PDF, JPEG or PNG
 * only, whatever the name or the browser says) and encrypted with the
 * application key before it is written, so the disk or bucket only holds
 * ciphertext under a random name. Reading back is for the console's
 * signed, audited route only.
 *
 * Kept apart from the media library (P02-05) on purpose: compliance files
 * have their own bucket, their own encryption and their own access rules.
 */
final readonly class ComplianceFiles
{
    public function __construct(private Encrypter $encrypter) {}

    /** @throws TierApplicationRefused when the file is empty, too big or not an allowed type */
    public function store(UploadedFile $file, int $organizationId): StoredFile
    {
        $bytes = $file->get();

        if (! is_string($bytes) || $bytes === '') {
            throw new TierApplicationRefused('The file is empty or could not be read. Choose it again.');
        }

        $maxKb = $this->maxKilobytes();

        if (strlen($bytes) > $maxKb * 1024) {
            throw new TierApplicationRefused('The file is larger than '.intdiv($maxKb, 1024).' MB. Upload a smaller scan or photo.');
        }

        $mime = $this->sniff($bytes);
        $allowed = $this->allowedMimes();

        if (! isset($allowed[$mime])) {
            throw new TierApplicationRefused('Upload a PDF, JPEG or PNG file.');
        }

        $disk = $this->diskName();
        $path = $organizationId.'/'.Str::uuid()->toString().'.enc';
        $this->disk()->put($path, $this->encrypter->encryptString($bytes));

        return new StoredFile(
            disk: $disk,
            path: $path,
            originalName: $this->cleanName($file->getClientOriginalName(), $allowed[$mime]),
            mime: $mime,
            sizeBytes: strlen($bytes),
            sha256: hash('sha256', $bytes),
        );
    }

    /** The file's content, decrypted. */
    public function read(string $disk, string $path): string
    {
        $stored = Storage::disk($disk)->get($path);

        if (! is_string($stored)) {
            throw new TierApplicationRefused('The document file is missing.');
        }

        return $this->encrypter->decryptString($stored);
    }

    /** Removes a file that was stored but never recorded (a failed save). */
    public function discard(StoredFile $file): void
    {
        Storage::disk($file->disk)->delete($file->path);
    }

    public function sniff(string $bytes): string
    {
        return (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
    }

    public function maxKilobytes(): int
    {
        $max = config('compliance.max_upload_kb', 8192);

        return is_numeric($max) ? max(1, (int) $max) : 8192;
    }

    /** @return array<string, string> mime => extension */
    public function allowedMimes(): array
    {
        $allowed = config('compliance.allowed_mimes', []);

        return is_array($allowed) ? array_filter($allowed, 'is_string') : [];
    }

    public function diskName(): string
    {
        $disk = config('compliance.disk', 'compliance');

        return is_string($disk) && $disk !== '' ? $disk : 'compliance';
    }

    private function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    /** A plain file name for display, with the extension of the sniffed type. */
    private function cleanName(string $name, string $extension): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = Str::limit(trim((string) preg_replace('/[^\pL\pN ._-]+/u', '-', $base), ' .-'), 120, '');

        return ($base === '' ? 'document' : $base).'.'.$extension;
    }
}

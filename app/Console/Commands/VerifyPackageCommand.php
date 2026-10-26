<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Release\DirectoryTree;
use App\Release\PackageVerifier;
use App\Release\ReleaseZip;
use App\Release\VerificationReport;
use App\Release\VerifyMode;
use Illuminate\Console\Command;

/**
 * Checks a release zip, an unpacked layout or this installation against the
 * packaging rules and SHA256SUMS (INSTALLER.md § 2). CI runs it on every
 * release; owners run it after uploading (docs/runbooks/release.md).
 */
final class VerifyPackageCommand extends Command
{
    private const int SHOWN_PROBLEMS = 50;

    protected $signature = 'goproperty:verify-package
        {path? : A release zip or an unpacked layout folder (default: this installation)}
        {--public= : The public folder of an unpacked split layout, when it is not the sibling public_html/}';

    protected $description = 'Verify a release package, or this installation, against its checksums and packaging rules';

    public function handle(PackageVerifier $verifier, ReleaseZip $zips): int
    {
        $target = $this->argument('path');

        if (is_string($target) && $target !== '' && ! is_dir($target)) {
            $label = $this->absolute($target);
            $report = $zips->verify($label);
        } else {
            $public = $this->option('public');

            $tree = is_string($target) && $target !== ''
                ? DirectoryTree::forLayout($this->absolute($target), is_string($public) && $public !== '' ? $this->absolute($public) : null)
                : new DirectoryTree(base_path(), public_path());

            $label = $tree->describe();
            $report = $verifier->verify($tree, VerifyMode::Installation);
        }

        return $this->render($label, $report);
    }

    private function render(string $label, VerificationReport $report): int
    {
        $this->components->twoColumnDetail('Checked', $label);
        $this->components->twoColumnDetail('Version', $report->version ?? 'unknown');
        $this->components->twoColumnDetail('Files', number_format($report->files));

        foreach ($report->notes as $note) {
            $this->components->warn($note);
        }

        if ($report->passed()) {
            $this->components->info('Package verified: every file matches SHA256SUMS and the packaging rules.');

            return self::SUCCESS;
        }

        $count = count($report->problems);
        $this->components->error("Verification failed with {$count} ".($count === 1 ? 'problem' : 'problems').'.');
        $this->components->bulletList(array_slice($report->problems, 0, self::SHOWN_PROBLEMS));

        if ($count > self::SHOWN_PROBLEMS) {
            $this->line('  …and '.($count - self::SHOWN_PROBLEMS).' more.');
        }

        return self::FAILURE;
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, '/') ? $path : getcwd().'/'.$path;
    }
}

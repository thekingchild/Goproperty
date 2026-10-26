<?php

declare(strict_types=1);

namespace App\Http\Controllers\Install;

use App\Installer\InstallationSteps;
use App\Installer\Migrations\BatchedMigrator;
use App\Installer\Migrations\FreshDatabaseReset;
use App\Installer\Web\WizardProgress;
use App\Installer\Web\WizardStep;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Step 4 (INSTALLER.md § 5–6): the migrations in small batches, one batch
 * per request so each stays well inside shared hosts' time limits, with a
 * progress bar ("34 of 112") that carries on by itself. Laravel's own
 * `migrations` table says what is done, so a closed tab or a timeout simply
 * continues. Then the idempotent install seeders.
 *
 * A migration that fails halfway on a brand-new install is fixed by
 * emptying GoProperty's tables (after typing the database name) and
 * building again.
 */
final class DatabaseController extends WizardController
{
    public const string FAILED = 'installer.migration_failed';

    public function show(Request $request, BatchedMigrator $migrator, DatabaseManager $db): Response
    {
        if (($blocked = $this->blocked($request, WizardStep::Database)) !== null) {
            return $blocked;
        }

        try {
            $progress = $migrator->progress();
        } catch (Throwable $exception) {
            return $this->screen($request, WizardStep::Database, 'installer.steps.database', $this->messages($request) + [
                'unreachable' => 'The database cannot be reached: '.$exception->getMessage(),
                'done' => 0, 'total' => 0, 'finished' => false, 'failed' => null, 'database' => '',
            ]);
        }

        $failed = $request->session()->get(self::FAILED);

        return $this->screen($request, WizardStep::Database, 'installer.steps.database', $this->messages($request) + [
            'unreachable' => null,
            'done' => $progress->done,
            'total' => $progress->total,
            'finished' => $progress->finished(),
            'failed' => is_array($failed) ? $failed : null,
            'database' => $db->connection()->getDatabaseName(),
        ]);
    }

    /** One batch; after the last, the seeders. */
    public function store(Request $request, InstallationSteps $steps): RedirectResponse|Response
    {
        if (($blocked = $this->blocked($request, WizardStep::Database)) !== null) {
            return $blocked;
        }

        $request->session()->forget(self::FAILED);

        try {
            $batch = $steps->migrateBatch();
        } catch (Throwable $exception) {
            return $this->back($request, WizardStep::Database, 'The database cannot be reached: '.$exception->getMessage());
        }

        if ($batch->failed()) {
            $request->session()->put(self::FAILED, ['migration' => (string) $batch->failedMigration, 'error' => (string) $batch->error]);

            return $this->to($request, WizardStep::Database);
        }

        // Every batch is a completed step: a long build never ends the grant.
        $this->grant->renew();

        if (! $batch->progress->finished()) {
            return $this->to($request, WizardStep::Database);
        }

        try {
            $steps->seed();
        } catch (Throwable $exception) {
            return $this->back($request, WizardStep::Database, 'The default data could not be added: '.$exception->getMessage().' Try again.');
        }

        $request->session()->put(WizardProgress::SEEDED, true);

        return $this->completed($request, WizardStep::Services);
    }

    /** "Empty the database and start this step again" (§ 6). */
    public function reset(Request $request, FreshDatabaseReset $reset): RedirectResponse|Response
    {
        if (($blocked = $this->blocked($request, WizardStep::Database)) !== null) {
            return $blocked;
        }

        $confirm = $request->input('confirm_database');

        try {
            $dropped = $reset->reset(is_string($confirm) ? trim($confirm) : '');
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return $this->back($request, WizardStep::Database, $exception->getMessage(), 'reset');
        }

        $request->session()->forget([self::FAILED, WizardProgress::SEEDED]);
        $this->grant->renew();

        return $this->back($request, WizardStep::Database, 'Emptied: '.count($dropped).' GoProperty tables dropped. Build the database again.', 'reset', error: false);
    }
}

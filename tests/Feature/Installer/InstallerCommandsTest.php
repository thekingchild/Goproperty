<?php

declare(strict_types=1);

use App\Hosting\Heartbeats;
use App\Installer\Database\DatabaseSettings;
use App\Installer\EnvironmentFile;
use App\Installer\Finishing\ApplicationOptimizer;
use App\Installer\InstallationException;
use App\Installer\InstallationMarkers;
use App\Installer\InstallationSteps;
use App\Installer\InstallStage;
use App\Installer\MailSettings;
use App\Installer\Requirements\ServerEnvironment;
use App\Installer\SetupToken;
use App\Installer\SiteSettings;
use App\Installer\SiteState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\FakeServer;

/*
 * P00-06: goproperty:setup-token and goproperty:check-installation
 * (INSTALLER.md § 4, § 7), and the steps the commands share.
 */

beforeEach(function () {
    $this->app->instance(ServerEnvironment::class, FakeServer::make());
    // A real install queues on the database (phpunit.xml runs jobs inline).
    config(['queue.default' => 'database']);
});

function finishMarkers(): void
{
    app(InstallationMarkers::class)->checkpoint()->write(
        InstallStage::Finalized,
        DatabaseSettings::fromConnection(config('database.connections.'.config('database.default'))),
    );
}

function beatEverything(): void
{
    app(Heartbeats::class)->beat(Heartbeats::SCHEDULER);
    app(Heartbeats::class)->beat(Heartbeats::QUEUE_TICK);
}

it('prints a setup token once and stores only its hash', function () {
    $this->markNotInstalled();

    Artisan::call('goproperty:setup-token');
    preg_match('/^\s+([A-Za-z0-9]{40})$/m', Artisan::output(), $match);
    $token = $match[1] ?? '';
    $path = config('installer.paths.setup_token');

    expect($token)->toHaveLength(40)
        ->and(app(SetupToken::class)->verify($token))->toBeTrue()
        ->and(app(SetupToken::class)->verify(strrev($token)))->toBeFalse()
        ->and(app(SetupToken::class)->verify(''))->toBeFalse()
        ->and(file_get_contents($path))->not->toContain($token)
        ->and(substr(sprintf('%o', fileperms($path)), -4))->toBe('0600');
});

it('replaces the previous setup token', function () {
    $this->markNotInstalled();
    $first = app(SetupToken::class)->issue();
    $second = app(SetupToken::class)->issue();

    expect(app(SetupToken::class)->verify($first))->toBeFalse()
        ->and(app(SetupToken::class)->verify($second))->toBeTrue();
});

it('refuses a setup token once installed', function () {
    $this->artisan('goproperty:setup-token')
        ->expectsOutputToContain('already installed')
        ->assertFailed();

    expect(file_exists(config('installer.paths.setup_token')))->toBeFalse();
});

it('passes a healthy installation', function () {
    finishMarkers();
    beatEverything();
    config(['app.admin_path' => 'gp-control-k7m2qx']);

    $this->artisan('goproperty:check-installation')
        ->expectsOutputToContain('Installation markers agree')
        ->expectsOutputToContain('Every migration has run')
        ->expectsOutputToContain('The installation is healthy.')
        ->assertSuccessful();
});

it('prints JSON', function () {
    finishMarkers();
    beatEverything();

    $this->artisan('goproperty:check-installation', ['--json' => true])
        ->expectsOutputToContain('"passed": true')
        ->assertSuccessful();
});

it('fails when cron has never run', function () {
    finishMarkers();

    $this->artisan('goproperty:check-installation')
        ->expectsOutputToContain('It has never run. Add the cron line')
        ->assertFailed();
});

it('fails when the scheduler stopped', function () {
    finishMarkers();
    beatEverything();
    $this->travel(10)->minutes();

    $this->artisan('goproperty:check-installation')
        ->expectsOutputToContain('Last run 10 minutes ago')
        ->assertFailed();
});

it('fails when the markers disagree', function () {
    beatEverything();

    $this->artisan('goproperty:check-installation')
        ->expectsOutputToContain('lock file: present, database row: finalized, checkpoint: missing')
        ->assertFailed();
});

it('fails on pending migrations', function () {
    finishMarkers();
    beatEverything();
    DB::table('migrations')->where('migration', 'like', '%platform_staff%')->delete();

    $this->artisan('goproperty:check-installation')
        ->expectsOutputToContain('1 pending')
        ->assertFailed();
});

it('fails on production settings left unsafe', function () {
    finishMarkers();
    beatEverything();
    config(['app.env' => 'production', 'app.debug' => true, 'app.admin_path' => 'gp-control', 'app.url' => 'http://goproperty.ng']);

    $this->artisan('goproperty:check-installation')
        ->expectsOutputToContain('APP_DEBUG is off')
        ->expectsOutputToContain('ADMIN_PATH is still the shared default')
        ->assertFailed();
});

it('fails the server check on a poor host', function () {
    finishMarkers();
    beatEverything();
    $this->app->instance(ServerEnvironment::class, FakeServer::make(['missing' => ['bcmath']]));

    $this->artisan('goproperty:check-installation')
        ->expectsOutputToContain('PHP extension: bcmath')
        ->assertFailed();
});

it('sends the test email', function () {
    config(['mail.default' => 'array']);

    app(InstallationSteps::class)->sendTestEmail('ada@goproperty.ng');

    $messages = app('mailer')->getSymfonyTransport()->messages();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->getOriginalMessage()->getTo()[0]->getAddress())->toBe('ada@goproperty.ng');
});

it('refuses a bad test email address', function () {
    app(InstallationSteps::class)->sendTestEmail('not-an-address');
})->throws(InstallationException::class, 'The test email address is not valid.');

it('writes SMTP settings and uses them at once', function () {
    $dir = sys_get_temp_dir().'/goproperty-mail-'.Str::random(8);
    File::ensureDirectoryExists($dir);
    $env = new EnvironmentFile($dir.'/.env');

    try {
        app(InstallationSteps::class)->configureMail($env, new MailSettings('smtp.postmarkapp.com', 465, 'token', 'pa$$ word', 'no-reply@goproperty.ng'));

        expect($env->get('MAIL_MAILER'))->toBe('smtp')
            ->and($env->get('MAIL_SCHEME'))->toBe('smtps')
            ->and($env->get('MAIL_PASSWORD'))->toBe('pa$$ word')
            ->and(config('mail.mailers.smtp.host'))->toBe('smtp.postmarkapp.com')
            ->and(config('mail.from.address'))->toBe('no-reply@goproperty.ng');
    } finally {
        File::deleteDirectory($dir);
    }
});

it('validates the site address and environment', function (string $url, string $environment, ?string $error) {
    if ($error !== null) {
        expect(fn () => new SiteSettings($url, $environment))->toThrow(InvalidArgumentException::class, $error);
    } else {
        expect((new SiteSettings($url, $environment))->url)->toBe(rtrim($url, '/'));
    }
})->with([
    ['https://goproperty.ng', 'production', null],
    ['https://goproperty.ng/', 'staging', null],
    ['https://staging.goproperty.ng:8443', 'staging', null],
    ['http://localhost:8000', 'production', null],
    ['http://goproperty.test', 'staging', null],
    ['http://goproperty.ng', 'production', 'must start with https://'],
    ['https://goproperty.ng/app', 'production', 'no path'],
    ['https://goproperty.ng?x=1', 'production', 'no path'],
    ['https://user@goproperty.ng', 'production', 'no path'],
    ['goproperty.ng', 'production', 'no path'],
    ['https://goproperty.ng', 'local', 'production or staging'],
]);

it('completes an interrupted finish from the command line', function () {
    $this->markNotInstalled();
    app(InstallationMarkers::class)->checkpoint()->write(
        InstallStage::CleanupPending,
        DatabaseSettings::fromConnection(config('database.connections.'.config('database.default'))),
    );
    $dir = sys_get_temp_dir().'/goproperty-finish-'.Str::random(8);
    File::ensureDirectoryExists($dir);
    file_put_contents($dir.'/.env', "ADMIN_PATH=gp-control\n");
    $this->app->instance(EnvironmentFile::class, new EnvironmentFile($dir.'/.env'));
    $this->app->instance(ApplicationOptimizer::class, new class implements ApplicationOptimizer
    {
        public function cache(): void {}
    });

    try {
        $this->artisan('goproperty:finish-install')
            ->expectsOutputToContain('The install is finished.')
            ->assertSuccessful();

        expect(app(InstallationMarkers::class)->siteState())->toBe(SiteState::Open);
    } finally {
        File::deleteDirectory($dir);
    }
});

it('finishes nothing unless the checkpoint says cleanup_pending', function () {
    $this->artisan('goproperty:finish-install')
        ->expectsOutputToContain('Nothing to finish')
        ->assertFailed();
});

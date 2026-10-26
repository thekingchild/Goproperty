<?php

declare(strict_types=1);

use App\Installer\Checkpoint;
use App\Installer\Database\DatabaseSettings;
use App\Installer\InstallStage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/*
 * P00-05: the checkpoint records the stage and an HMAC fingerprint of the
 * database settings (INSTALLER.md § 3, § 6).
 */

beforeEach(function () {
    $this->path = sys_get_temp_dir().'/goproperty-checkpoint-'.Str::random(10).'/install.checkpoint';
    $this->key = 'base64:'.base64_encode(random_bytes(32));
    $this->settings = new DatabaseSettings('mysql', 'localhost', 3306, 'acct_gp', 'acct_gp', 's3cret pass');
});

afterEach(function () {
    File::deleteDirectory(dirname($this->path));
});

it('reads back what it wrote', function () {
    $checkpoint = new Checkpoint($this->path, $this->key);
    $checkpoint->write(InstallStage::Configured, $this->settings);

    $state = $checkpoint->read();

    expect($state->exists)->toBeTrue()
        ->and($state->corrupt)->toBeFalse()
        ->and($state->stage)->toBe(InstallStage::Configured)
        ->and($state->saysFinished())->toBeFalse()
        ->and(substr(sprintf('%o', fileperms($this->path)), -4))->toBe('0600')
        ->and(file_get_contents($this->path))->not->toContain('s3cret');
});

it('keeps the fingerprint when advancing', function () {
    $checkpoint = new Checkpoint($this->path, $this->key);
    $checkpoint->write(InstallStage::Configured, $this->settings);
    $checkpoint->advance(InstallStage::Migrating);

    expect($checkpoint->read()->stage)->toBe(InstallStage::Migrating)
        ->and($checkpoint->matches($this->settings))->toBeTrue();
});

it('notices database settings that changed mid-install', function (DatabaseSettings $changed) {
    $checkpoint = new Checkpoint($this->path, $this->key);
    $checkpoint->write(InstallStage::Migrating, $this->settings);

    expect($checkpoint->matches($changed))->toBeFalse();
})->with([
    'host' => fn () => new DatabaseSettings('mysql', '127.0.0.1', 3306, 'acct_gp', 'acct_gp', 's3cret pass'),
    'database' => fn () => new DatabaseSettings('mysql', 'localhost', 3306, 'acct_other', 'acct_gp', 's3cret pass'),
    'password' => fn () => new DatabaseSettings('mysql', 'localhost', 3306, 'acct_gp', 'acct_gp', 'other'),
]);

it('says finished for the last two stages', function (InstallStage $stage, bool $finished) {
    $checkpoint = new Checkpoint($this->path, $this->key);
    $checkpoint->write($stage, $this->settings);

    expect($checkpoint->read()->saysFinished())->toBe($finished);
})->with([
    [InstallStage::Configured, false],
    [InstallStage::Migrating, false],
    [InstallStage::OwnerReady, false],
    [InstallStage::CleanupPending, true],
    [InstallStage::Finalized, true],
]);

it('counts an edited checkpoint as corrupt', function () {
    $checkpoint = new Checkpoint($this->path, $this->key);
    $checkpoint->write(InstallStage::CleanupPending, $this->settings);
    file_put_contents($this->path, str_replace('cleanup_pending', 'configured', (string) file_get_contents($this->path)));

    expect($checkpoint->read()->corrupt)->toBeTrue()
        ->and($checkpoint->read()->saysFinished())->toBeTrue();
});

it('cannot be written before APP_KEY exists', function () {
    (new Checkpoint($this->path, ''))->write(InstallStage::Configured, $this->settings);
})->throws(RuntimeException::class, 'APP_KEY must exist');

it('reports a missing checkpoint', function () {
    $state = (new Checkpoint($this->path, $this->key))->read();

    expect($state->exists)->toBeFalse()->and($state->saysFinished())->toBeFalse();
});

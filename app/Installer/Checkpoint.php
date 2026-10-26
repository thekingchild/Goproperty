<?php

declare(strict_types=1);

namespace App\Installer;

use App\Installer\Database\DatabaseSettings;
use App\Installer\Support\AtomicFile;
use RuntimeException;
use SensitiveParameter;

/**
 * The checkpoint marker (INSTALLER.md § 3): the installer's stage and a
 * fingerprint of its database settings, signed with APP_KEY. A checkpoint
 * that cannot be read or verified counts as finished (fail closed).
 */
final class Checkpoint
{
    public function __construct(
        private readonly string $path,
        #[SensitiveParameter] private readonly string $key,
    ) {}

    public function read(): CheckpointState
    {
        if (! is_file($this->path)) {
            return CheckpointState::missing();
        }

        $data = json_decode((string) @file_get_contents($this->path), true);

        if (! is_array($data) || $this->key === '') {
            return CheckpointState::corrupt();
        }

        $stage = InstallStage::tryFrom(is_string($data['stage'] ?? null) ? $data['stage'] : '');
        $fingerprint = $data['fingerprint'] ?? null;
        $updatedAt = $data['updated_at'] ?? null;
        $mac = $data['mac'] ?? null;

        if ($stage === null || ! is_string($fingerprint) || ! is_string($updatedAt) || ! is_string($mac)
            || ! hash_equals($this->mac($stage, $fingerprint, $updatedAt), $mac)) {
            return CheckpointState::corrupt();
        }

        return CheckpointState::valid($stage, $fingerprint);
    }

    public function write(InstallStage $stage, DatabaseSettings $settings): void
    {
        $this->store($stage, $settings->fingerprint($this->requireKey()));
    }

    /** Moves to another stage and keeps the recorded database settings. */
    public function advance(InstallStage $stage): void
    {
        $state = $this->read();

        if ($state->fingerprint === null) {
            throw new RuntimeException('There is no valid checkpoint to advance.');
        }

        $this->store($stage, $state->fingerprint);
    }

    /** False when the database settings changed since the checkpoint was written. */
    public function matches(DatabaseSettings $settings): bool
    {
        $state = $this->read();

        return $state->fingerprint !== null
            && hash_equals($state->fingerprint, $settings->fingerprint($this->requireKey()));
    }

    public function delete(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }

    private function store(InstallStage $stage, string $fingerprint): void
    {
        $updatedAt = now()->toIso8601String();

        AtomicFile::write($this->path, (string) json_encode([
            'stage' => $stage->value,
            'fingerprint' => $fingerprint,
            'updated_at' => $updatedAt,
            'mac' => $this->mac($stage, $fingerprint, $updatedAt),
        ], JSON_PRETTY_PRINT));
    }

    private function mac(InstallStage $stage, string $fingerprint, string $updatedAt): string
    {
        return hash_hmac('sha256', "{$stage->value}|{$fingerprint}|{$updatedAt}", 'install-checkpoint|'.$this->requireKey());
    }

    private function requireKey(): string
    {
        if ($this->key === '') {
            throw new RuntimeException('APP_KEY must exist before the installer writes a checkpoint.');
        }

        return $this->key;
    }
}

<?php

declare(strict_types=1);

use App\Backup\Restorer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * P09-04 drill on the pilot (2026-11-10): a backup taken from the console
 * holds the scheduler's 12-hour overlap lock of `backup-operations` and its
 * own run and operation still "running". Restored as it was, that blocked
 * every console backup for 12 hours and raised a false "scheduler stopped".
 * A restore now empties the cache and marks what was still running failed.
 * In tests/Drill: CREATE DATABASE commits, so no RefreshDatabase here.
 */

beforeEach(function () {
    $this->restoreDb = DB::connection()->getDatabaseName().'_settle';
    DB::statement('CREATE DATABASE IF NOT EXISTS `'.$this->restoreDb.'`');
    $this->sql = sys_get_temp_dir().'/goproperty-settle-'.Str::random(8).'.sql';
});

afterEach(function () {
    DB::statement('DROP DATABASE IF EXISTS `'.$this->restoreDb.'`');
    @unlink($this->sql);
});

it('clears the cache and its locks, and marks backups still running in the dump failed', function () {
    file_put_contents($this->sql, <<<'SQL'
        -- A dump taken while a console backup was running.
        CREATE TABLE `cache` (`key` varchar(255) NOT NULL PRIMARY KEY, `value` mediumtext NOT NULL, `expiration` int NOT NULL);
        INSERT INTO `cache` VALUES ('goproperty-cache-hosting:heartbeat:scheduler','i:1;',2000000000);
        CREATE TABLE `cache_locks` (`key` varchar(255) NOT NULL PRIMARY KEY, `owner` varchar(255) NOT NULL, `expiration` int NOT NULL);
        INSERT INTO `cache_locks` VALUES ('goproperty-cache-framework/schedule-fc32d4b7','XAR1puIfD8RNA1CU',2000000000);
        CREATE TABLE `backup_runs` (`id` bigint PRIMARY KEY, `status` varchar(20) NOT NULL, `finished_at` timestamp NULL, `notes` text NULL, `updated_at` timestamp NULL);
        INSERT INTO `backup_runs` VALUES (1,'success','2026-11-10 01:30:05',NULL,NULL),(2,'running',NULL,NULL,NULL);
        CREATE TABLE `backup_operations` (`id` bigint PRIMARY KEY, `status` varchar(20) NOT NULL, `finished_at` timestamp NULL, `result` text NULL, `updated_at` timestamp NULL);
        INSERT INTO `backup_operations` VALUES (1,'succeeded','2026-11-09 12:00:00','ok',NULL),(2,'running',NULL,NULL,NULL),(3,'queued',NULL,NULL,NULL);
        SQL);

    expect(app(Restorer::class)->import($this->sql, $this->restoreDb))->toBe(8);

    $table = fn (string $name) => DB::table(DB::raw('`'.$this->restoreDb.'`.`'.$name.'`'));
    $reason = 'Still running when this backup was taken: how it ended is not in this copy.';

    expect($table('cache')->count())->toBe(0)
        ->and($table('cache_locks')->count())->toBe(0)
        ->and($table('backup_runs')->orderBy('id')->pluck('status')->all())->toBe(['success', 'failed'])
        ->and($table('backup_runs')->where('id', 2)->value('notes'))->toBe($reason)
        ->and($table('backup_runs')->where('id', 1)->value('notes'))->toBeNull()
        ->and($table('backup_operations')->orderBy('id')->pluck('status')->all())->toBe(['succeeded', 'failed', 'queued'])
        ->and($table('backup_operations')->where('id', 2)->value('result'))->toBe($reason);
});

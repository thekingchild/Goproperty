<?php

declare(strict_types=1);

namespace App\Audit;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The audit log's hash chain (PLATFORM_CONSOLE.md § 7): each entry stores
 * the previous entry's hash and an HMAC of its own content plus that
 * previous hash, keyed from APP_KEY. Changing, removing or reordering a
 * row breaks the chain, and rows cannot be forged without the key.
 *
 * Appends are serialised by locking the single row of audit_chain_head.
 */
final readonly class AuditChain
{
    public const string HEAD_TABLE = 'audit_chain_head';

    public const string TABLE = 'activity_log';

    /** The columns a hash covers, in this order. */
    public const array HASHED = [
        'log_name', 'description', 'subject_type', 'subject_id', 'event', 'causer_type', 'causer_id',
        'attribute_changes', 'properties', 'impersonator_staff_id', 'ip', 'user_agent', 'reason', 'created_at',
    ];

    private const array JSON_COLUMNS = ['attribute_changes', 'properties'];

    public function __construct(
        private DatabaseManager $db,
        private Config $config,
    ) {}

    /**
     * Inserts a row as the next link: $insert receives the chain columns to
     * add and must insert the row, inside the lock.
     *
     * @param  array<string, mixed>  $attributes  the row as it will be stored
     * @param  callable(array{prev_hash: string|null, hash: string}): void  $insert
     */
    public function append(array $attributes, callable $insert): void
    {
        $this->connection()->transaction(function () use ($attributes, $insert): void {
            $head = $this->lockedHead();
            $prev = is_string($head->last_hash ?? null) ? $head->last_hash : null;
            $hash = $this->hash($attributes, $prev, $this->keys()[0]);

            $insert(['prev_hash' => $prev, 'hash' => $hash]);

            $this->connection()->table(self::HEAD_TABLE)->where('id', 1)->update(['last_hash' => $hash, 'updated_at' => now()]);
        });
    }

    /**
     * Seals rows written before the chain existed (hash null), in id order.
     *
     * @return int rows sealed
     */
    public function sealUnchained(): int
    {
        $sealed = 0;

        $this->connection()->transaction(function () use (&$sealed): void {
            $head = $this->lockedHead();
            $prev = is_string($head->last_hash ?? null) ? $head->last_hash : null;

            foreach ($this->connection()->table(self::TABLE)->whereNull('hash')->orderBy('id')->cursor() as $row) {
                $hash = $this->hash((array) $row, $prev, $this->keys()[0]);
                $this->connection()->table(self::TABLE)->where('id', $row->id)->update(['prev_hash' => $prev, 'hash' => $hash]);
                $prev = $hash;
                $sealed++;
            }

            $this->connection()->table(self::HEAD_TABLE)->where('id', 1)->update(['last_hash' => $prev, 'updated_at' => now()]);
        });

        return $sealed;
    }

    /** Walks the whole chain from the first row. */
    public function verify(): ChainVerification
    {
        $prev = null;
        $checked = 0;
        $last = null;

        foreach ($this->connection()->table(self::TABLE)->orderBy('id')->lazyById(500) as $row) {
            $id = (int) $row->id;

            if (($row->prev_hash ?? null) !== $prev) {
                return ChainVerification::broken($checked, $id, 'its link to the entry before it does not match (an entry was removed, added or reordered)');
            }

            if (! is_string($row->hash ?? null) || ! $this->matches((array) $row, $prev, $row->hash)) {
                return ChainVerification::broken($checked, $id, 'its content does not match its hash (the entry was changed)');
            }

            $prev = $row->hash;
            $last = $row->hash;
            $checked++;
        }

        $head = $this->connection()->table(self::HEAD_TABLE)->where('id', 1)->value('last_hash');

        if ($head !== $last) {
            return ChainVerification::broken($checked, null, 'the newest entries are missing (the last entry is not the recorded end of the chain)');
        }

        return ChainVerification::intact($checked);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function hash(array $row, ?string $prev, string $key): string
    {
        $payload = [];

        foreach (self::HASHED as $column) {
            $value = $row[$column] ?? null;

            if (in_array($column, self::JSON_COLUMNS, true)) {
                $value = $this->canonical(is_string($value) ? json_decode($value, true) : $value);
            } elseif ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d H:i:s');
            } elseif ($value !== null) {
                $value = (string) $value;
            }

            $payload[$column] = $value;
        }

        return hash_hmac('sha256', ($prev ?? '').'|'.json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $key);
    }

    /** @param  array<string, mixed>  $row */
    private function matches(array $row, ?string $prev, string $hash): bool
    {
        foreach ($this->keys() as $key) {
            if (hash_equals($this->hash($row, $prev, $key), $hash)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keys sort recursively so the hash survives MySQL's JSON column, which
     * reorders object keys; numbers and strings keep their JSON form.
     */
    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map($this->canonical(...), $value);
    }

    private function lockedHead(): object
    {
        $head = $this->connection()->table(self::HEAD_TABLE)->where('id', 1)->lockForUpdate()->first();

        if ($head === null) {
            throw new RuntimeException('The audit chain head row is missing; run the migrations.');
        }

        return $head;
    }

    /**
     * The chain key from APP_KEY (and earlier keys, for verification after
     * a key rotation). Never the raw APP_KEY.
     *
     * @return non-empty-list<string>
     */
    private function keys(): array
    {
        $keys = [];

        foreach ([$this->config->get('app.key'), ...(array) $this->config->get('app.previous_keys', [])] as $appKey) {
            if (is_string($appKey) && $appKey !== '') {
                $raw = Str::startsWith($appKey, 'base64:') ? (string) base64_decode(substr($appKey, 7), true) : $appKey;
                $keys[] = hash_hmac('sha256', 'goproperty-audit-chain', $raw);
            }
        }

        if ($keys === []) {
            throw new RuntimeException('APP_KEY is not set; the audit chain cannot be written.');
        }

        return $keys;
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection();
    }
}

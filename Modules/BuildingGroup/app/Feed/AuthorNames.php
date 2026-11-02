<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Feed;

use Modules\BuildingGroup\Enums\AuthorKind;

/**
 * Authors' names for the feed (docs/plans/P07-subtasks.md default 16).
 *
 * @internal
 */
final readonly class AuthorNames
{
    /**
     * @param  array<int, string>  $tenants  tenant record id => full name
     * @param  array<int, string>  $staff  user id => name
     */
    public function __construct(
        private array $tenants,
        private array $staff,
        private string $agency,
    ) {}

    /** @return array{0: string, 1: string|null} name, badge */
    public function name(AuthorKind $kind, ?int $userId, ?int $tenantId): array
    {
        if ($kind === AuthorKind::Tenant) {
            return [self::short($tenantId !== null ? ($this->tenants[$tenantId] ?? '') : ''), null];
        }

        $name = $userId !== null ? ($this->staff[$userId] ?? '') : '';

        return [$name !== '' ? $name : __('A former member of staff'), $this->agency];
    }

    /** "Kemi Adebayo" → "Kemi A."; one word stays as it is. */
    public static function short(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($parts === []) {
            return __('A neighbour');
        }

        if (count($parts) === 1) {
            return $parts[0];
        }

        return $parts[0].' '.mb_strtoupper(mb_substr((string) end($parts), 0, 1)).'.';
    }
}

<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A list built on one Eloquent query. query() is the list: the page
 * paginates it, the export reads all of it in chunks of CHUNK rows in the
 * same order (Builder::lazy, so memory stays flat however long the list
 * is). exportQuery() may add what the columns need (eager loads, counts)
 * without changing which rows there are.
 *
 * @template TModel of Model
 */
abstract class QueryListExport implements ListExport
{
    public const int CHUNK = 500;

    /**
     * The list's rows: organization scope (the model's), narrowing,
     * filters, search, order with a unique last key.
     *
     * @param  array<string, string|int|bool>  $filters  cleaned
     * @return Builder<TModel>
     */
    abstract public function query(User $user, array $filters): Builder;

    /**
     * @param  array<string, string|int|bool>  $filters
     * @return Builder<TModel>
     */
    protected function exportQuery(User $user, array $filters): Builder
    {
        return $this->query($user, $filters);
    }

    /** @return iterable<TModel> */
    public function rows(User $user, array $filters): iterable
    {
        return $this->exportQuery($user, $filters)->lazy(static::CHUNK);
    }

    public function count(User $user, array $filters): int
    {
        return $this->query($user, $filters)->toBase()->getCountForPagination();
    }
}

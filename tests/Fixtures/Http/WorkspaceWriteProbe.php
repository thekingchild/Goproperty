<?php

declare(strict_types=1);

namespace Tests\Fixtures\Http;

use App\Tenancy\AllowedWhileSuspended;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/** Stand-in workspace actions for the suspension tests: a write and an export. */
final class WorkspaceWriteProbe
{
    public function store(): JsonResponse
    {
        Cache::store('array')->increment('probe.http-writes');

        return new JsonResponse(['saved' => true]);
    }

    #[AllowedWhileSuspended]
    public function export(): JsonResponse
    {
        return new JsonResponse(['exported' => true]);
    }
}

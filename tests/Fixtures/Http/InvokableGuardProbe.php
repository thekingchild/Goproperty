<?php

declare(strict_types=1);

namespace Tests\Fixtures\Http;

use App\Tenancy\AllowedWhileSuspended;
use App\Tenancy\BlockedWhileImpersonating;
use Illuminate\Http\JsonResponse;

/** An invokable controller marked like a guarded action, for RouteActionTest. */
final class InvokableGuardProbe
{
    #[BlockedWhileImpersonating]
    #[AllowedWhileSuspended]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }
}

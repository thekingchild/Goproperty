<?php

declare(strict_types=1);

namespace App\Auth\SignIn;

use App\Models\User;
use App\Tenancy\WorkspaceLanding;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as Contract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/** After a password sign-in: the page the user meant to open, else their workspace (WorkspaceLanding). */
final readonly class LoginResponse implements Contract
{
    public function __construct(private WorkspaceLanding $landing) {}

    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        return $request->wantsJson()
            ? response()->json(['two_factor' => false])
            : redirect()->intended(self::landingFor($request, $this->landing));
    }

    public static function landingFor(Request $request, WorkspaceLanding $landing): string
    {
        $user = $request->user();

        return $user instanceof User ? $landing->url($user) : Fortify::redirects('login');
    }
}

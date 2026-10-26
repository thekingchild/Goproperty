<?php

declare(strict_types=1);

namespace App\Auth\SignIn;

use App\Tenancy\WorkspaceLanding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as Contract;
use Symfony\Component\HttpFoundation\Response;

/** After a passkey sign-in: as LoginResponse; the sign-in page's script follows `redirect`. */
final readonly class PasskeyLoginResponse implements Contract
{
    public function __construct(private WorkspaceLanding $landing) {}

    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        $redirect = redirect()->intended(LoginResponse::landingFor($request, $this->landing));

        return $request->wantsJson() ? new JsonResponse(['redirect' => $redirect->getTargetUrl()]) : $redirect;
    }
}

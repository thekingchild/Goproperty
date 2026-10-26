<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Auth\SignIn\Identifier;
use App\Models\User;
use Illuminate\Contracts\Auth\PasswordBrokerFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Forgot password" (UX_DESIGN.md § 3.3): one field, email or phone number,
 * and the same reply whatever is typed, so it never reveals an account.
 * The link goes to the account's email; phone-only accounts get one from
 * their agency (WhatsApp delivery comes with the notification module).
 */
final class PasswordResetLinkController
{
    public const string REPLY = "If an account matches, we've sent a reset link by email. No email? Ask your agency for a new sign-in link.";

    public function __invoke(Request $request, PasswordBrokerFactory $passwords): RedirectResponse
    {
        $request->validate(['identifier' => ['required', 'string', 'max:255']]);

        $identifier = Identifier::from($request->input('identifier'));
        $user = match (true) {
            $identifier->email !== null => User::query()->where('email', $identifier->email)->first(),
            $identifier->phone !== null => User::query()->where('phone', $identifier->phone)->first(),
            default => null,
        };

        if ($user?->email !== null) {
            // Throttled or not, the reply is the same.
            $passwords->broker(config('fortify.passwords'))->sendResetLink(['email' => $user->email]);
        }

        return back()->with('status', self::REPLY);
    }
}

<?php

declare(strict_types=1);

namespace App\Auth\SignIn;

use App\Models\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Http\Request;

/**
 * Fortify's authenticateUsing callback: the account by email or phone
 * number, and only with the right password. An unknown identifier still
 * costs one password hash, so timing does not reveal which accounts exist.
 */
final readonly class FindUser
{
    public function __construct(
        private Hasher $hasher,
        private Cache $cache,
    ) {}

    public function __invoke(Request $request): ?User
    {
        $identifier = Identifier::from($request->input('identifier'));
        $password = (string) $request->input('password');

        $user = match (true) {
            $identifier->email !== null => User::query()->where('email', $identifier->email)->first(),
            $identifier->phone !== null => User::query()->where('phone', $identifier->phone)->first(),
            default => null,
        };

        $hash = $user?->password;

        if (! is_string($hash) || $hash === '') {
            // Made once and kept, so this costs one check like a real account.
            $dummy = $this->cache->rememberForever('auth:timing-hash', fn (): string => $this->hasher->make('timing-equaliser'));
            $this->hasher->check($password, $dummy);

            return null;
        }

        return $this->hasher->check($password, $hash) ? $user : null;
    }
}

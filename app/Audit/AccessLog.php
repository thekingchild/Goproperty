<?php

declare(strict_types=1);

namespace App\Audit;

use App\Models\PlatformStaff;
use App\Models\User;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Writes the authentication log (PLATFORM_CONSOLE.md § 8) for both guards.
 * The location is the country Cloudflare reports (CF-IPCountry) when the
 * site sits behind it; nothing is looked up elsewhere.
 */
final readonly class AccessLog
{
    public function __construct(
        private Request $request,
        private Config $config,
    ) {}

    public function record(string $event, User|PlatformStaff|string $who, ?int $accountId = null, ?string $attempted = null, ?bool $newDevice = null): void
    {
        if (! in_array($event, AuthenticationLog::EVENTS, true)) {
            throw new InvalidArgumentException("Unknown authentication event {$event}.");
        }

        [$type, $id] = match (true) {
            $who instanceof User => ['user', $who->id],
            $who instanceof PlatformStaff => ['platform_staff', $who->id],
            default => [$who, $accountId],
        };

        $country = $this->request->header('CF-IPCountry');

        AuthenticationLog::query()->create([
            'authenticatable_type' => $type,
            'authenticatable_id' => $id,
            'attempted_identifier' => $attempted !== null && $attempted !== '' ? $this->hashIdentifier($attempted) : null,
            'event' => $event,
            'ip' => $this->request->ip(),
            'user_agent' => $this->request->userAgent() !== null ? Str::limit((string) $this->request->userAgent(), 250, '') : null,
            'location' => is_string($country) && preg_match('/^[A-Z]{2}$/D', $country) === 1 && $country !== 'XX' ? $country : null,
            'is_new_device' => $newDevice,
            'created_at' => now(),
        ]);
    }

    /**
     * HMAC of a normalised email or phone, keyed from APP_KEY: searchable by
     * someone who knows the address, unreadable otherwise.
     */
    public function hashIdentifier(string $identifier): string
    {
        $key = (string) $this->config->get('app.key');
        $raw = Str::startsWith($key, 'base64:') ? (string) base64_decode(substr($key, 7), true) : $key;

        return hash_hmac('sha256', Str::lower(trim($identifier)), hash_hmac('sha256', 'goproperty-authentication-log', $raw));
    }
}

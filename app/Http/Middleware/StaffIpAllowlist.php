<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\PlatformStaff;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * A staff member's optional IP allowlist (UX_DESIGN.md § 3.2,
 * platform_staff.ip_allowlist: addresses or CIDR ranges). From anywhere
 * else the session ends and the request gets a plain 404 with no branding.
 */
final class StaffIpAllowlist
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $staff = Filament::auth()->user();
        $allowed = $staff instanceof PlatformStaff ? array_values(array_filter($staff->ip_allowlist ?? [], 'is_string')) : [];

        if ($allowed !== [] && ! IpUtils::checkIp((string) $request->ip(), $allowed)) {
            Filament::auth()->logout();
            $request->session()->invalidate();

            return response('Not Found', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return $next($request);
    }
}

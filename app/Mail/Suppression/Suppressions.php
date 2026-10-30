<?php

declare(strict_types=1);

namespace App\Mail\Suppression;

use App\Models\EmailSuppression;
use App\Models\PlatformStaff;
use App\Models\User;
use App\Notifications\EmailAddressSuppressed;
use Illuminate\Support\Facades\DB;

/**
 * The suppression list (P05-04; PLATFORM_CONSOLE.md § 5 "Delivery"): the
 * only writer of `email_suppressions`. Every outgoing email is checked
 * against it (Listeners in App\Providers\EmailServiceProvider), so no
 * code path can mail a suppressed address. Addresses are compared
 * lower-cased. Changes are audited (log `email`); the people whose account
 * uses a newly suppressed address get an in-app notice to fix it.
 */
final class Suppressions
{
    /** @var array<string, bool> per-request answers, cleared on every change */
    private array $known = [];

    public function isSuppressed(string $email): bool
    {
        $email = self::normalize($email);

        return $this->known[$email] ??= EmailSuppression::query()->where('email', $email)->whereNull('lifted_at')->exists();
    }

    /**
     * The suppressed ones among these addresses (lower-cased).
     *
     * @param  iterable<string>  $emails
     * @return list<string>
     */
    public function among(iterable $emails): array
    {
        $suppressed = [];

        foreach ($emails as $email) {
            if ($this->isSuppressed($email)) {
                $suppressed[] = self::normalize($email);
            }
        }

        return array_values(array_unique($suppressed));
    }

    /** Suppresses the address (again, if it was lifted). Returns false when it already was. */
    public function suppress(string $email, SuppressionReason $reason, ?string $provider = null, ?string $detail = null, ?PlatformStaff $staff = null): bool
    {
        $email = self::normalize($email);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 191) {
            return false;
        }

        $added = DB::transaction(function () use ($email, $reason, $provider, $detail, $staff): bool {
            $row = EmailSuppression::query()->where('email', $email)->lockForUpdate()->first();

            if ($row !== null && $row->isActive()) {
                return false;
            }

            $values = [
                'reason' => $reason,
                'provider' => $provider,
                'detail' => $detail !== null ? mb_substr($detail, 0, 255) : null,
                'suppressed_at' => now(),
                'lifted_at' => null,
                'lifted_by_staff_id' => null,
            ];

            $row = $row !== null
                ? tap($row->forceFill($values))->save()
                : EmailSuppression::query()->forceCreate(['email' => $email] + $values);

            $log = activity('email')->event('email.suppressed')->performedOn($row)
                ->withProperties(['reason' => $reason->value, 'provider' => $provider]);
            ($staff !== null ? $log->causedBy($staff) : $log->causedByAnonymous())
                ->log('Email address suppressed ('.$reason->label().')');

            return true;
        }, 3);

        unset($this->known[$email]);

        if ($added) {
            User::query()->where('email', $email)->get()
                ->each(fn (User $user) => $user->notify(new EmailAddressSuppressed($reason->value)));
        }

        return $added;
    }

    /** Lets email go to the address again. Returns false when it was not suppressed. */
    public function lift(EmailSuppression $suppression, PlatformStaff $staff): bool
    {
        $lifted = DB::transaction(function () use ($suppression, $staff): bool {
            $row = EmailSuppression::query()->whereKey($suppression->id)->lockForUpdate()->first();

            if ($row === null || ! $row->isActive()) {
                return false;
            }

            $row->forceFill(['lifted_at' => now(), 'lifted_by_staff_id' => $staff->id])->save();

            activity('email')->event('email.suppression_lifted')->performedOn($row)->causedBy($staff)
                ->withProperties(['reason' => $row->reason->value])
                ->log('Email address allowed again');

            return true;
        }, 3);

        unset($this->known[self::normalize($suppression->email)]);

        return $lifted;
    }

    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}

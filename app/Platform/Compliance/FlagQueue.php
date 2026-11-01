<?php

declare(strict_types=1);

namespace App\Platform\Compliance;

use App\Compliance\ComplianceAudit;
use App\Compliance\Monitoring\MonitoringRules;
use App\Compliance\Monitoring\TierRestrictions;
use App\Enums\ComplianceFlagStatus;
use App\Enums\ComplianceFlagType;
use App\Models\ComplianceFlag;
use App\Models\Organization;
use App\Models\PlatformStaff;
use App\Platform\Staff\StaffPermission;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Staff work the compliance flag queue (P06-09; ONBOARDING_COMPLIANCE.md
 * § 5.4–5.5, PLATFORM_CONSOLE.md § 1A, defaults 95 and 99), the console's
 * only writer of `compliance_flags`:
 *
 * - acknowledge(): an open flag is being looked at;
 * - assignToMe(): the staff member takes it;
 * - resolve() / dismiss(): closes a flag with a note (not the volume flags,
 *   which close themselves when the measure falls back);
 * - extendGrace(): moves a `volume_100` flag's grace end to a later Lagos
 *   day, up to `max_grace_extension_days` from today, with a reason; a
 *   restriction the ended grace caused is lifted at once.
 *
 * All need `compliance.flags` (extending: `compliance.restrict` too),
 * re-checked here, and run in the flag's organization under its row lock.
 * Each is one audit entry (log `compliance`, `compliance_flag.*`, the staff
 * member, the note or reason, before and after).
 */
final readonly class FlagQueue
{
    public const int NOTE_MIN = 3;

    public const int NOTE_MAX = 1000;

    public function __construct(
        private OrganizationContext $context,
        private ComplianceAudit $audit,
        private TierRestrictions $restrictions,
        private MonitoringRules $rules,
    ) {}

    /** @throws TierSettingsRefused */
    public function acknowledge(PlatformStaff $staff, ReviewFlag $record): void
    {
        $this->authorize($staff, StaffPermission::ComplianceFlags);

        $this->within($record, function (ComplianceFlag $flag) use ($staff): void {
            if ($flag->status !== ComplianceFlagStatus::Open) {
                throw new TierSettingsRefused('Only an open flag can be acknowledged.');
            }

            $flag->forceFill(['status' => ComplianceFlagStatus::Acknowledged, 'assigned_staff_id' => $flag->assigned_staff_id ?? $staff->id])->save();
            $this->audit->record('compliance_flag.acknowledged', $flag, $staff, "Acknowledged: {$flag->type->label()}", [
                'old' => ['status' => ComplianceFlagStatus::Open->value],
                'attributes' => ['status' => ComplianceFlagStatus::Acknowledged->value, 'assigned_staff_id' => $flag->assigned_staff_id],
            ]);
        });
    }

    /** @throws TierSettingsRefused */
    public function assignToMe(PlatformStaff $staff, ReviewFlag $record): void
    {
        $this->authorize($staff, StaffPermission::ComplianceFlags);

        $this->within($record, function (ComplianceFlag $flag) use ($staff): void {
            $this->ensureOpen($flag);
            $from = $flag->assigned_staff_id;
            $flag->forceFill(['assigned_staff_id' => $staff->id])->save();
            $this->audit->record('compliance_flag.assigned', $flag, $staff, "Took: {$flag->type->label()}", [
                'old' => ['assigned_staff_id' => $from],
                'attributes' => ['assigned_staff_id' => $staff->id],
            ]);
        });
    }

    /** @throws TierSettingsRefused */
    public function resolve(PlatformStaff $staff, ReviewFlag $record, string $note): void
    {
        $this->close($staff, $record, $note, ComplianceFlagStatus::Resolved);
    }

    /** @throws TierSettingsRefused */
    public function dismiss(PlatformStaff $staff, ReviewFlag $record, string $note): void
    {
        $this->close($staff, $record, $note, ComplianceFlagStatus::Dismissed);
    }

    /**
     * @param  string  $until  the Lagos day the grace now ends at the start of, Y-m-d
     *
     * @throws TierSettingsRefused
     */
    public function extendGrace(PlatformStaff $staff, ReviewFlag $record, string $until, string $reason): CarbonImmutable
    {
        $this->authorize($staff, StaffPermission::ComplianceFlags);
        $this->authorize($staff, StaffPermission::ComplianceRestrict);
        $reason = $this->note($reason, 'reason');
        $day = $this->graceDay($until);

        $this->within($record, function (ComplianceFlag $flag) use ($staff, $day, $reason): void {
            $this->ensureOpen($flag);

            if ($flag->type !== ComplianceFlagType::Volume100) {
                throw new TierSettingsRefused('Only a yearly-limit flag has a grace period.');
            }

            if ($flag->grace_ends_at !== null && ! $day->greaterThan($flag->grace_ends_at)) {
                throw new TierSettingsRefused('The new end must be later than '.$flag->grace_ends_at->setTimezone(MonitoringRules::day()->getTimezone())->format('j M Y').'.');
            }

            $from = $flag->grace_ends_at;
            $flag->forceFill(['grace_ends_at' => $day->setTimezone(date_default_timezone_get())])->save();
            $this->audit->record('compliance_flag.grace_extended', $flag, $staff, 'Extended the grace period to '.$day->format('j M Y'), [
                'old' => ['grace_ends_at' => $from?->toIso8601String()],
                'attributes' => ['grace_ends_at' => $day->toIso8601String()],
            ], $reason);
        });

        $this->restrictions->reconcileNow($record->customer->organization());

        return $day;
    }

    /** The first and last days a grace may be extended to today (Lagos). */
    public static function firstGraceDay(): CarbonImmutable
    {
        return MonitoringRules::day()->addDay();
    }

    public function lastGraceDay(): CarbonImmutable
    {
        return MonitoringRules::day()->addDays($this->rules->maxGraceExtensionDays());
    }

    /** @throws TierSettingsRefused */
    private function close(PlatformStaff $staff, ReviewFlag $record, string $note, ComplianceFlagStatus $to): void
    {
        $this->authorize($staff, StaffPermission::ComplianceFlags);
        $note = $this->note($note, 'note');

        $this->within($record, function (ComplianceFlag $flag) use ($staff, $note, $to): void {
            $this->ensureOpen($flag);

            if ($flag->type->isVolume()) {
                throw new TierSettingsRefused('This flag closes itself when the collections fall back under the line, or the organization moves to a higher tier or limit.');
            }

            $from = $flag->status;
            $flag->forceFill([
                'status' => $to,
                'resolution_note' => $note,
                'resolved_by_staff_id' => $staff->id,
                'resolved_at' => now(),
            ])->save();

            $event = $to === ComplianceFlagStatus::Resolved ? 'compliance_flag.resolved' : 'compliance_flag.dismissed';
            $this->audit->record($event, $flag, $staff, ($to === ComplianceFlagStatus::Resolved ? 'Resolved' : 'Dismissed').": {$flag->type->label()}", [
                'old' => ['status' => $from->value],
                'attributes' => ['status' => $to->value],
                'note' => $note,
            ], $note);
        });
    }

    /** @param  Closure(ComplianceFlag): void  $change */
    private function within(ReviewFlag $record, Closure $change): void
    {
        $organization = Organization::query()->withTrashed()->findOrFail($record->organization_id);

        $this->context->run($organization, fn () => DB::transaction(function () use ($record, $change): void {
            $flag = ComplianceFlag::query()->whereKey($record->id)->lockForUpdate()->first()
                ?? throw new TierSettingsRefused('That flag does not exist.');

            $change($flag);
        }, 3));
    }

    /** @throws TierSettingsRefused */
    private function ensureOpen(ComplianceFlag $flag): void
    {
        if (! $flag->isOpen()) {
            throw new TierSettingsRefused("This flag is {$flag->status->label()}: it cannot change.");
        }
    }

    /** @throws TierSettingsRefused */
    private function graceDay(string $date): CarbonImmutable
    {
        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, MonitoringRules::day()->getTimezone());
        } catch (Throwable) {
            $day = null;
        }

        if (! $day instanceof CarbonImmutable || $day->format('Y-m-d') !== $date) {
            throw new TierSettingsRefused('Give the day the grace period ends.');
        }

        if ($day->lessThan(self::firstGraceDay()) || $day->greaterThan($this->lastGraceDay())) {
            throw new TierSettingsRefused('The grace period can end from tomorrow up to '.$this->rules->maxGraceExtensionDays().' days from today.');
        }

        return $day;
    }

    /** @throws TierSettingsRefused */
    private function note(string $text, string $what): string
    {
        $text = trim($text);

        if (mb_strlen($text) < self::NOTE_MIN || mb_strlen($text) > self::NOTE_MAX) {
            throw new TierSettingsRefused('Give a '.$what.' of '.self::NOTE_MIN.' to '.self::NOTE_MAX.' characters: it is kept in the audit log.');
        }

        return $text;
    }

    /** @throws TierSettingsRefused */
    private function authorize(PlatformStaff $staff, StaffPermission $permission): void
    {
        if (! $staff->holdsStaffPermission($permission)) {
            throw new TierSettingsRefused('You do not have permission to work compliance flags.');
        }
    }
}

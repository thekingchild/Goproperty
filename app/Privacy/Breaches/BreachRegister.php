<?php

declare(strict_types=1);

namespace App\Privacy\Breaches;

use App\Models\DataBreach;
use App\Models\PlatformStaff;
use App\Platform\Staff\StaffPermission;
use App\Privacy\DataRequestRefused;
use App\Privacy\PrivacyAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The personal data breach register (P09-02; NDPA 2023 s. 40;
 * docs/runbooks/breach-response.md): the only writer of `data_breaches`.
 * Staff with `privacy.breaches` record an incident from the moment
 * GoProperty becomes aware of it, which starts the 72-hour clock for the
 * Nigeria Data Protection Commission, then record containment and each
 * notice (the Commission, the customers whose data it is, the people
 * affected) or why one is not needed, and close it. Every step is one
 * audit entry (log `privacy`), with the reference and title only.
 */
final readonly class BreachRegister
{
    /**
     * @param  array<string, mixed>  $data  title, description and risk, and optionally aware_at, occurred_at, data_involved, people_affected, organizations_affected, likely_consequences, measures_taken
     */
    public function record(PlatformStaff $staff, array $data): DataBreach
    {
        $this->authorize($staff);

        return DB::transaction(function () use ($staff, $data): DataBreach {
            $breach = new DataBreach;
            $breach->forceFill($this->details($data) + [
                'status' => BreachStatus::Open,
                'recorded_by_staff_id' => $staff->id,
                'aware_at' => $this->time($data['aware_at'] ?? null) ?? now(),
            ])->save();
            $breach->forceFill(['reference' => sprintf('BR-%s-%04d', $breach->aware_at?->format('Y') ?? now()->format('Y'), $breach->id)])->save();
            $this->audit($breach, $staff, 'breach.recorded', 'Breach recorded');

            return $breach;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(DataBreach $breach, PlatformStaff $staff, array $data): void
    {
        $this->authorize($staff);
        $this->refuseClosed($breach);

        $breach->forceFill($this->details($data + ['title' => $breach->title, 'description' => $breach->description, 'risk' => $breach->risk->value]))->save();
        $this->audit($breach, $staff, 'breach.updated', 'Breach details changed');
    }

    public function contained(DataBreach $breach, PlatformStaff $staff, mixed $at = null): void
    {
        $this->step($breach, $staff, ['status' => BreachStatus::Contained, 'contained_at' => $this->time($at) ?? now()], 'breach.contained', 'Breach contained');
    }

    public function commissionNotified(DataBreach $breach, PlatformStaff $staff, mixed $at, ?string $reference): void
    {
        $this->step($breach, $staff, ['commission_notified_at' => $this->time($at) ?? now(), 'commission_reference' => $this->text($reference, 100)], 'breach.commission_notified', 'The Nigeria Data Protection Commission was told');
    }

    public function customersNotified(DataBreach $breach, PlatformStaff $staff, mixed $at): void
    {
        $this->step($breach, $staff, ['customers_notified_at' => $this->time($at) ?? now()], 'breach.customers_notified', 'The customers whose data it is were told');
    }

    public function peopleNotified(DataBreach $breach, PlatformStaff $staff, mixed $at): void
    {
        $this->step($breach, $staff, ['people_notified_at' => $this->time($at) ?? now()], 'breach.people_notified', 'The people affected were told');
    }

    /** Why a notice is not given (e.g. the breach is unlikely to harm anyone): kept on the register, as the Act asks. */
    public function notNotifying(DataBreach $breach, PlatformStaff $staff, string $reason): void
    {
        $this->step($breach, $staff, ['not_notified_reason' => $this->text($reason, 1000)], 'breach.not_notified', 'Recorded why no notice is given');
    }

    public function close(DataBreach $breach, PlatformStaff $staff, string $note): void
    {
        $this->step($breach, $staff, ['status' => BreachStatus::Closed, 'closed_at' => now(), 'close_note' => $this->text($note, 1000)], 'breach.closed', 'Breach closed');
    }

    /** @param  array<string, mixed>  $changes */
    private function step(DataBreach $breach, PlatformStaff $staff, array $changes, string $event, string $description): void
    {
        $this->authorize($staff);
        $this->refuseClosed($breach);

        $breach->forceFill($changes)->save();
        $this->audit($breach, $staff, $event, $description);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function details(array $data): array
    {
        return [
            'title' => $this->text($data['title'] ?? null, 160) ?? throw new DataRequestRefused('A breach needs a title.'),
            'description' => $this->text($data['description'] ?? null, 5000) ?? throw new DataRequestRefused('Say what happened.'),
            'occurred_at' => $this->time($data['occurred_at'] ?? null),
            'data_involved' => $this->text($data['data_involved'] ?? null, 5000),
            'people_affected' => isset($data['people_affected']) && is_numeric($data['people_affected']) ? max(0, (int) $data['people_affected']) : null,
            'organizations_affected' => $this->text($data['organizations_affected'] ?? null, 1000),
            'likely_consequences' => $this->text($data['likely_consequences'] ?? null, 5000),
            'measures_taken' => $this->text($data['measures_taken'] ?? null, 5000),
            'risk' => BreachRisk::tryFrom(is_string($data['risk'] ?? null) ? $data['risk'] : '') ?? throw new DataRequestRefused('Say how likely it is to harm people.'),
        ];
    }

    private function authorize(PlatformStaff $staff): void
    {
        if (! $staff->holdsStaffPermission(StaffPermission::PrivacyBreaches)) {
            throw new DataRequestRefused('You may not change the breach register.');
        }
    }

    private function refuseClosed(DataBreach $breach): void
    {
        if ($breach->status === BreachStatus::Closed) {
            throw new DataRequestRefused('This breach is closed.');
        }
    }

    private function audit(DataBreach $breach, PlatformStaff $staff, string $event, string $description): void
    {
        activity(PrivacyAudit::LOG)->event($event)->performedOn($breach)->causedBy($staff)
            ->withProperties(['reference' => $breach->reference, 'risk' => $breach->risk->value, 'status' => $breach->status->value])
            ->log("{$description}: {$breach->reference}");
    }

    private function time(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        return is_string($value) && trim($value) !== '' ? CarbonImmutable::parse($value) : null;
    }

    private function text(mixed $value, int $max): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}

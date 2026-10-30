<?php

declare(strict_types=1);

namespace App\Platform\Customers;

use App\Models\Organization;
use App\Models\PlatformStaff;
use App\Platform\Staff\StaffPermission;
use App\Settings\SettingsStore;
use App\Sms\Credits\SmsCreditRefused;
use App\Sms\Credits\SmsCredits;
use App\Sms\Credits\SmsCreditSummary;
use App\Tenancy\OrganizationContext;

/**
 * A customer's SMS credits in the console (P05-03; PLATFORM_CONSOLE.md § 1:
 * "Plans, prices, included units, features, SMS credits" are
 * `finance-admin`'s, `plans.manage`): the balance on the organization's
 * page, a manual adjustment with a reason (App\Sms\Credits\SmsCredits::adjust,
 * audited with the staff member) and a custom monthly allowance instead of
 * the plan's (Enterprise), the organization-scoped setting
 * `sms_credits.monthly_allowance` (audited by the settings store and here).
 * Each runs inside the organization's context.
 */
final readonly class CustomerSmsCredits
{
    public function __construct(
        private OrganizationContext $context,
        private SmsCredits $credits,
        private SettingsStore $settings,
    ) {}

    public function summary(Organization $organization): SmsCreditSummary
    {
        return $this->context->run($organization, fn (): SmsCreditSummary => $this->credits->summary());
    }

    public function customAllowance(Organization $organization): ?int
    {
        $value = $this->settings->get('sms_credits.monthly_allowance', $organization);

        return is_int($value) ? $value : null;
    }

    /** @throws CustomerChangeRefused */
    public function adjust(PlatformStaff $staff, Organization $organization, int $credits, string $reason): int
    {
        $this->authorize($staff);

        try {
            return $this->context->run($organization, fn (): int => $this->credits->adjust($staff, $credits, $reason)->balance_after);
        } catch (SmsCreditRefused $refused) {
            throw new CustomerChangeRefused($refused->getMessage());
        }
    }

    /**
     * A custom monthly allowance (null: back to the plan's). A bigger one
     * adds the difference this month; a smaller one applies from next month.
     *
     * @throws CustomerChangeRefused
     */
    public function setAllowance(PlatformStaff $staff, Organization $organization, ?int $allowance): void
    {
        $this->authorize($staff);

        if ($allowance !== null && ($allowance < 0 || $allowance > 1_000_000)) {
            throw new CustomerChangeRefused('A monthly allowance is between 0 and 1,000,000 credits.');
        }

        $old = $this->customAllowance($organization);

        $this->context->run($organization, function () use ($staff, $organization, $allowance, $old): void {
            if ($allowance === null) {
                $this->settings->forget('sms_credits.monthly_allowance', $organization);
            } else {
                $this->settings->set('sms_credits.monthly_allowance', $allowance, $organization);
            }

            $this->credits->refreshAllowance();

            activity(SmsCredits::AUDIT_LOG)
                ->event('sms_credits.allowance_set')
                ->performedOn($organization)
                ->causedBy($staff)
                ->withProperties(['old' => $old, 'new' => $allowance, 'plan_allowance_used' => $allowance === null])
                ->log('SMS monthly allowance set');
        });
    }

    /** @throws CustomerChangeRefused */
    private function authorize(PlatformStaff $staff): void
    {
        if (! $staff->holdsStaffPermission(StaffPermission::CustomersView) || ! $staff->holdsStaffPermission(StaffPermission::PlansManage)) {
            throw new CustomerChangeRefused('You do not have permission to change SMS credits.');
        }
    }
}

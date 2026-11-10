<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ChecklistItem;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Marketing\Plan;
use App\Marketing\PlanCatalogue;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\User;
use App\Onboarding\OnboardingChecklist;
use App\Onboarding\OrganizationDetails;
use App\Onboarding\OrganizationSignup;
use App\Sms\DeliveryStatus;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use LogicException;
use Modules\Billing\Bills\RentBillGenerator;
use Modules\Billing\Jobs\FlagOverdueBills;
use Modules\Billing\Models\Bill;
use Modules\BuildingGroup\Enums\AuthorKind;
use Modules\BuildingGroup\Jobs\SyncBuildingGroups;
use Modules\BuildingGroup\Models\BuildingGroup;
use Modules\BuildingGroup\Posts\GroupPosts;
use Modules\Leasing\Leases\LeaseService;
use Modules\Payments\Payments\PaymentService;
use Modules\Properties\Buildings\Properties;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\Unit;
use Modules\Properties\Units\Units;
use Modules\Tenants\Profiles\TenantProfiles;
use Modules\Tickets\Tickets\TicketService;

/**
 * GoProperty's demo organization for staff walkthroughs (P09-05;
 * BILLING_PAYMENTS.md § 1.4), run by `goproperty:demo-organization`, in
 * production too. A small Lagos agency with six months of history: three
 * buildings, 20 units (2 vacant), 18 tenants on monthly leases, rent bills
 * mostly paid on their due date with three tenants in arrears, three
 * maintenance tickets at different stages and a few Building Group
 * notices. Everything goes through the modules' own writers, so the rows
 * are the ones real use makes, audit log included.
 *
 * Marked `is_demo` and given no subscription: no billing job ever selects
 * it, its SMS are never sent and the console's analytics leave it out. Its
 * tenants have made-up 0700 numbers (a phone is required) and no email;
 * the owner, a GoProperty staff mailbox, is its only member. No Faker: it
 * runs from a release package.
 */
final class DemoOrganizationSeeder extends Seeder
{
    public const string SLUG = 'demo-homes';

    public const string NAME = 'Demo Homes Ltd';

    public const int MONTHS = 6;

    /** name, address, state, units (rent in naira a month by unit) */
    private const array BUILDINGS = [
        ['Palm Court', '12 Admiralty Way, Lekki Phase 1', 'Lagos', [180_000, 180_000, 220_000, 220_000, 250_000, 250_000, 180_000, 220_000]],
        ['Marina View Apartments', '4 Broad Street, Lagos Island', 'Lagos', [150_000, 150_000, 175_000, 175_000, 200_000, 200_000]],
        ['Unity Gardens', '27 Allen Avenue, Ikeja', 'Lagos', [85_000, 85_000, 95_000, 95_000, 110_000, 110_000]],
    ];

    /** Units left vacant: building index => unit index. */
    private const array VACANT = [[1, 5], [2, 3]];

    private const array PEOPLE = [
        ['Adaeze', 'Okafor'], ['Babatunde', 'Adeyemi'], ['Chidinma', 'Eze'], ['Damilola', 'Fashola'], ['Emeka', 'Nwosu'],
        ['Folake', 'Bakare'], ['Garba', 'Ibrahim'], ['Halima', 'Danjuma'], ['Ifeanyi', 'Kalu'], ['Jumoke', 'Lawal'],
        ['Kunle', 'Olawale'], ['Ngozi', 'Chukwu'], ['Oluwaseun', 'Sanni'], ['Rasheedat', 'Usman'], ['Segun', 'Johnson'],
        ['Temitope', 'Williams'], ['Uchenna', 'Peters'], ['Yetunde', 'Mohammed'],
    ];

    /** Tenants (by number, from 1) whose last bills are unpaid: number => unpaid months. */
    private const array ARREARS = [4 => 1, 9 => 2, 15 => 1];

    private ?string $ownerEmail = null;

    private ?string $ownerPassword = null;

    public function forOwner(string $email, string $password): self
    {
        $this->ownerEmail = $email;
        $this->ownerPassword = $password;

        return $this;
    }

    public function run(OrganizationContext $context): void
    {
        if ($this->ownerEmail === null || $this->ownerPassword === null) {
            throw new LogicException('Run it with php artisan goproperty:demo-organization <owner email>.');
        }

        // Rows only while it runs: no mail to the owner for every payment,
        // no PDFs; the SMS its tenants would get are refused anyway (SendSms).
        $mail = config('mail.default');
        $queue = config('queue.default');
        config(['mail.default' => 'array', 'queue.default' => 'null']);

        try {
            $organization = $this->build($context);
        } finally {
            self::at(null);
            config(['mail.default' => $mail, 'queue.default' => $queue]);
        }

        // As the daily jobs would: groups for the buildings, overdue badges.
        dispatch_sync(new SyncBuildingGroups(fromOrganizationId: $organization->id, budgetSeconds: 600));
        dispatch_sync(new FlagOverdueBills);
        $context->run($organization, function (): void {
            $this->notices();
            // SMS queued while the queue was off end as SendSms ends them.
            NotificationDelivery::query()->where('channel', 'sms')->where('status', DeliveryStatus::Queued->value)
                ->update(['status' => DeliveryStatus::Failed->value, 'failed_at' => now(), 'error' => 'Not sent: a demo organization never sends SMS.']);
        });
    }

    private function build(OrganizationContext $context): Organization
    {
        $today = CarbonImmutable::now('Africa/Lagos');
        $start = $today->startOfMonth()->subMonths(self::MONTHS - 1);
        self::at($start->subDays(10)->setTime(9, 0));

        $owner = new User;
        $owner->forceFill(['name' => 'Demo Homes (GoProperty demo)', 'email' => $this->ownerEmail, 'password' => $this->ownerPassword, 'email_verified_at' => now()])->save();

        $organization = app(OrganizationSignup::class)->start($owner, new OrganizationDetails(OrganizationType::Agency, self::NAME, 'Lagos', 'Eti-Osa', null), self::SLUG);
        $plan = $this->showcasePlan();
        $organization->forceFill([
            'status' => OrganizationStatus::Active,
            'is_demo' => true,
            'signup_plan_key' => $plan?->key,
        ])->save();

        $context->run($organization, function () use ($organization, $owner, $plan, $start, $today): void {
            DB::table('organization_onboardings')->where('organization_id', $organization->id)->update(['completed_at' => now()]);
            $checklist = app(OnboardingChecklist::class);
            $checklist->complete(ChecklistItem::Tier, $owner);

            if ($plan !== null) {
                $checklist->complete(ChecklistItem::Plan, $owner, ['plan' => $plan->key, 'demo' => true]);
            }

            $units = $this->buildings($owner, $start);

            // Month by month, as the daily jobs would have: rent bills a week
            // ahead (billing.lead_days), payments confirmed on the due date.
            for ($m = 0; $m < self::MONTHS; $m++) {
                $month = $start->addMonthsNoOverflow($m);
                self::at($month->subDays(7)->setTime(0, 10));
                app(RentBillGenerator::class)->generate();

                if ($month->setTime(10, 0)->lessThanOrEqualTo($today)) {
                    self::at($month->setTime(10, 0));
                    $this->pay($owner, $month, self::MONTHS - 1 - $m);
                }
            }

            $this->tickets($owner, $units, $today);
        });

        return $organization;
    }

    /**
     * The buildings, their units and the tenants on monthly leases from $start.
     *
     * @return list<array{unit: Unit, tenant: int|null}>
     */
    private function buildings(User $owner, CarbonImmutable $start): array
    {
        $let = [];
        $person = 0;

        foreach (self::BUILDINGS as $b => [$name, $address, $state, $rents]) {
            $building = app(Properties::class)->create($owner, [
                'name' => $name,
                'propertyType' => 'block_of_flats',
                'address' => $address,
                'state' => $state,
            ]);

            foreach ($rents as $u => $rent) {
                $unit = app(Units::class)->create($owner, $building, [
                    'unitLabel' => 'Flat '.($u + 1),
                    'unitType' => 'apartment',
                    'bedrooms' => 1 + $u % 3,
                    'baseRent' => (string) $rent,
                ]);

                if (in_array([$b, $u], self::VACANT, true)) {
                    $let[] = ['unit' => $unit, 'tenant' => null];

                    continue;
                }

                $let[] = ['unit' => $unit, 'tenant' => $this->lease($owner, $building, $unit, ++$person, $rent, $start)];
            }
        }

        return $let;
    }

    private function lease(User $owner, Property $building, Unit $unit, int $person, int $rent, CarbonImmutable $start): int
    {
        [$first, $last] = self::PEOPLE[($person - 1) % count(self::PEOPLE)];
        $profile = app(TenantProfiles::class)->create($owner, [
            'firstName' => $first,
            'lastName' => $last,
            // Made up (a phone is required); the demo organization never sends SMS.
            'phone' => '0700 000 '.str_pad((string) $person, 4, '0', STR_PAD_LEFT),
            'email' => null,
            'idType' => 'none',
        ]);

        $leases = app(LeaseService::class);
        $lease = $leases->draft($owner, [
            'tenantId' => $profile->id,
            'unitId' => $unit->id,
            'billingCycle' => 'monthly',
            'startDate' => $start->toDateString(),
            'endDate' => $start->addYears(2)->subDay()->toDateString(),
            'rent' => (string) $rent,
            'deposit' => (string) $rent,
            'dueDayOffset' => '0',
            'paidUntil' => '',
            'autoRenew' => false,
            'notes' => "Demo lease at {$building->name}.",
        ]);
        $leases->activate($owner, $lease);

        return $profile->id;
    }

    /** The month's bills paid and confirmed on their due date (now), except the last ones of the tenants in ARREARS. */
    private function pay(User $owner, CarbonImmutable $month, int $monthsAfter): void
    {
        $payments = app(PaymentService::class);
        $order = $this->tenantOrder();

        Bill::query()->whereBetween('due_date', [$month->toDateString(), $month->endOfMonth()->toDateString()])
            ->orderBy('id')
            ->get()
            ->each(function (Bill $bill) use ($payments, $owner, $monthsAfter, $order): void {
                $person = $order[$bill->tenant_profile_id] ?? 0;

                if (isset(self::ARREARS[$person]) && $monthsAfter < self::ARREARS[$person]) {
                    return;
                }

                $payments->recordAndConfirm($owner, [
                    'tenantId' => (string) $bill->tenant_profile_id,
                    'billId' => (string) $bill->id,
                    'amount' => (string) intdiv($bill->amount_kobo->kobo, 100),
                    'method' => $person % 3 === 0 ? 'cash' : 'bank_transfer',
                    'paidAt' => now('Africa/Lagos')->toDateString(),
                    'payerName' => '',
                    'payerType' => 'individual',
                    'reference' => '',
                    'note' => $person % 3 === 0 ? 'Paid at the estate office.' : '',
                ]);
            });
    }

    /** @return array<int, int> tenant profile id => the tenant's number (from 1, in the order they were added) */
    private function tenantOrder(): array
    {
        return DB::table('tenant_profiles')
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->orderBy('id')
            ->pluck('id')
            ->values()
            ->mapWithKeys(static fn (mixed $id, int $i): array => [(int) $id => $i + 1])
            ->all();
    }

    /**
     * Three maintenance tickets: one resolved last month, one in progress,
     * one just raised.
     *
     * @param  list<array{unit: Unit, tenant: int|null}>  $units
     */
    private function tickets(User $owner, array $units, CarbonImmutable $today): void
    {
        $tickets = app(TicketService::class);
        $raise = function (int $i, CarbonImmutable $at, array $input) use ($tickets, $owner, $units) {
            self::at($at);

            return $tickets->createOnBehalf($owner, ['unitId' => $units[$i]['unit']->id, 'tenantId' => $units[$i]['tenant']] + $input);
        };

        $fixed = $raise(2, $today->subDays(24)->setTime(8, 40), [
            'category' => 'plumbing', 'priority' => 'high',
            'title' => 'Kitchen sink leaking',
            'description' => 'Water drips under the kitchen sink and the cabinet floor is wet.',
        ]);
        self::at($today->subDays(23)->setTime(11, 0));
        $tickets->startWork($owner, $fixed);
        self::at($today->subDays(21)->setTime(16, 30));
        $tickets->resolve($owner, $fixed, 'Plumber replaced the trap and the seal. No more drips.');

        $working = $raise(9, $today->subDays(3)->setTime(19, 5), [
            'category' => 'electrical', 'priority' => 'medium',
            'title' => 'Corridor lights off on the second floor',
            'description' => 'The corridor lights outside flats 3 and 4 have not come on for two nights.',
        ]);
        self::at($today->subDays(2)->setTime(9, 15));
        $tickets->startWork($owner, $working);

        $raise(16, $today->subHours(5), [
            'category' => 'security', 'priority' => 'urgent',
            'title' => 'Gate intercom not working',
            'description' => 'Visitors cannot call the flats from the gate; the intercom screen stays dark.',
        ]);
    }

    /** Notices from the agency in Palm Court's Building Group, at today's time. */
    private function notices(): void
    {
        $group = BuildingGroup::query()->orderBy('id')->first();
        $owner = User::query()->where('email', $this->ownerEmail)->first();

        if ($group === null || $owner === null) {
            return;
        }

        $today = CarbonImmutable::now('Africa/Lagos');
        $writer = app(GroupPosts::class);

        foreach ([
            [9, 'Welcome to the Palm Court group. Notices from the management and replies from neighbours appear here.'],
            [4, 'The water tank on the roof will be cleaned on Saturday morning. Please store some water the night before.'],
            [1, 'Rent reminders now go out a week before each due date. Pay by transfer to the account on your bill and upload the receipt in the portal.'],
        ] as [$daysAgo, $text]) {
            self::at($today->subDays($daysAgo)->setTime(10, 0));
            $writer->post($owner, AuthorKind::Staff, $group, $text);
            RateLimiter::clear('group-posts:'.$owner->id);
        }

        self::at(null);
    }

    /** The most complete standard plan, so every feature can be shown. */
    private function showcasePlan(): ?Plan
    {
        $plans = array_values(array_filter(app(PlanCatalogue::class)->plans(), static fn (Plan $plan): bool => ! $plan->custom));

        return $plans === [] ? null : $plans[count($plans) - 1];
    }

    /** Moves the clock the writers read (null: back to the real time). */
    private static function at(?CarbonImmutable $moment): void
    {
        Carbon::setTestNow($moment);
        CarbonImmutable::setTestNow($moment);
    }
}

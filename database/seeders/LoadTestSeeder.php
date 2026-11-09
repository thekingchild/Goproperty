<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\User;
use App\Onboarding\OrganizationDetails;
use App\Onboarding\OrganizationSignup;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Billing\Bills\RentBillGenerator;
use Modules\Billing\Jobs\FlagOverdueBills;
use Modules\Billing\Models\Bill;
use Modules\BuildingGroup\Enums\AuthorKind;
use Modules\BuildingGroup\Enums\ReactableType;
use Modules\BuildingGroup\Enums\Reaction;
use Modules\BuildingGroup\Jobs\SyncBuildingGroups;
use Modules\BuildingGroup\Models\BuildingGroup;
use Modules\BuildingGroup\Posts\GroupPosts;
use Modules\Leasing\Leases\LeaseService;
use Modules\Payments\Payments\PaymentService;
use Modules\Properties\Assignments\AgentAssignments;
use Modules\Properties\Buildings\Properties;
use Modules\Properties\Models\Property;
use Modules\Properties\Units\Units;
use Modules\Tenants\Models\TenantProfile;
use Modules\Tenants\Profiles\TenantProfiles;
use RuntimeException;

/**
 * Data for the load test (P09-03) and the restore drill (P09-04): one large
 * agency at the top of the shared-hosting profile (ARCHITECTURE.md § 12:
 * move to a VPS at ~5,000 units across ~50 customers), plus smaller ones
 * so the tables hold other organizations' rows too. Everything goes
 * through the modules' own writers (buildings, units, tenants, leases,
 * rent bills, confirmed payments, Building Group posts), so the rows are
 * the ones real use makes, audit log included. No Faker: it runs from a
 * release package on staging.
 *
 *   php artisan db:seed --class=LoadTestSeeder
 *
 * Sizes from the environment (defaults in brackets): LOADTEST_BUILDINGS
 * [40] with LOADTEST_UNITS [25] each, except the first building, which has
 * LOADTEST_GROUP_UNITS [100] and the busy Building Group; LOADTEST_MONTHS
 * [12] of monthly rent; LOADTEST_POSTS [300] posts with 5 comments and 10
 * reactions each; LOADTEST_OTHER_AGENCIES [4] of 10 buildings × 25 units
 * and 6 months. Refused in production. Queued work (PDFs, notices) is
 * dropped while it runs; group memberships are synced at the end.
 *
 * Sign-ins (password LOADTEST_PASSWORD, default "load-test-passphrase"):
 * owner.load@example.com, admin.load@example.com, agent.load@example.com
 * (assigned to 5 buildings), and tenant{n}.load@example.com for the
 * tenants of the first building (n from 1).
 */
final class LoadTestSeeder extends Seeder
{
    public const string SLUG = 'load-agency';

    private const array STATES = ['Lagos', 'FCT', 'Rivers', 'Oyo', 'Kano'];

    private const array FIRST = ['Ada', 'Bola', 'Chika', 'Dayo', 'Emeka', 'Funmi', 'Gbenga', 'Halima', 'Ifeoma', 'Jide', 'Kemi', 'Lanre', 'Musa', 'Ngozi', 'Ope', 'Remi', 'Segun', 'Tunde', 'Uche', 'Yemi'];

    private const array LAST = ['Adebayo', 'Bakare', 'Chukwu', 'Danjuma', 'Eze', 'Fashola', 'Garba', 'Ibrahim', 'Johnson', 'Kalu', 'Lawal', 'Mohammed', 'Nwosu', 'Okafor', 'Olawale', 'Peters', 'Sanni', 'Usman', 'Williams', 'Yusuf'];

    private string $password;

    private int $phone = 0;

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('LoadTestSeeder makes thousands of made-up records; it never runs in production.');
        }

        // Rows only: PDFs, notices and conversions are not part of the load.
        config(['queue.default' => 'null', 'mail.default' => 'array']);
        $this->password = Hash::make((string) (getenv('LOADTEST_PASSWORD') ?: 'load-test-passphrase'));
        $this->phone = 1000000 + (int) DB::table('tenant_profiles')->count();

        try {
            $this->agencies();
        } finally {
            self::at(null);
        }
    }

    private function agencies(): void
    {
        $this->agency(
            slug: self::SLUG,
            name: 'Load Test Realty Ltd',
            people: 'load',
            buildings: self::int('LOADTEST_BUILDINGS', 40),
            units: self::int('LOADTEST_UNITS', 25),
            groupUnits: self::int('LOADTEST_GROUP_UNITS', 100),
            months: self::int('LOADTEST_MONTHS', 12),
            posts: self::int('LOADTEST_POSTS', 300),
        );

        for ($i = 1; $i <= self::int('LOADTEST_OTHER_AGENCIES', 4); $i++) {
            $this->agency("load-agency-{$i}", "Load Neighbour {$i} Ltd", "load{$i}", 10, 25, 25, 6, 20);
        }

        dispatch_sync(new SyncBuildingGroups(budgetSeconds: 3600));
        dispatch_sync(new FlagOverdueBills);
    }

    private function agency(string $slug, string $name, string $people, int $buildings, int $units, int $groupUnits, int $months, int $posts): void
    {
        $this->command?->info("{$name}: {$buildings} buildings, {$months} months");
        $context = app(OrganizationContext::class);
        $today = CarbonImmutable::now('Africa/Lagos');
        $start = $today->startOfMonth()->subMonths($months - 1);
        // The agency and its leases are set up ten days before the first month.
        self::at($start->subDays(10)->setTime(9, 0));

        $owner = $this->user("Owner {$people}", "owner.{$people}@example.com");
        $organization = app(OrganizationSignup::class)->start($owner, new OrganizationDetails(OrganizationType::Agency, $name, 'Lagos', null, null), $slug);
        $organization->forceFill(['status' => OrganizationStatus::Active])->save();
        $context->run($organization, fn () => DB::table('organization_onboardings')
            ->where('organization_id', $organization->id)->update(['completed_at' => now()]));

        $admin = $this->user("Admin {$people}", "admin.{$people}@example.com");
        $admin->joinOrganization($organization, OrganizationRole::Admin);
        $agent = $this->user("Agent {$people}", "agent.{$people}@example.com");
        $agent->joinOrganization($organization, OrganizationRole::Agent);

        $context->run($organization, function () use ($organization, $owner, $agent, $people, $buildings, $units, $groupUnits, $start, $months, $today, $posts): void {
            $tenant = 0;

            for ($b = 1; $b <= $buildings; $b++) {
                $building = app(Properties::class)->create($owner, [
                    'name' => ($b === 1 ? 'Palm Court' : "Block {$b}").($people === 'load' ? '' : " ({$people})"),
                    'propertyType' => 'block_of_flats',
                    'address' => "{$b} Admiralty Way",
                    'state' => self::STATES[$b % count(self::STATES)],
                ]);

                if ($b >= 2 && $b <= 6) {
                    app(AgentAssignments::class)->assign($owner, $building, $agent->id);
                }

                $count = $b === 1 ? $groupUnits : $units;

                for ($u = 1; $u <= $count; $u++) {
                    $tenant++;
                    $this->let($organization, $owner, $building, $u, $tenant, $start, portal: $b === 1 ? $people : null);
                }

                $this->command?->getOutput()->write('.');
            }

            $this->command?->newLine();

            // Month by month, as the daily jobs would have: rent bills a week
            // ahead (billing.lead_days), payments confirmed on the due date.
            for ($m = 0; $m < $months; $m++) {
                $month = $start->addMonthsNoOverflow($m);
                self::at($month->subDays(7)->setTime(0, 10));
                app(RentBillGenerator::class)->generate();

                if ($month->setTime(10, 0)->greaterThan($today)) {
                    continue;
                }

                self::at($month->setTime(10, 0));
                $this->pay($owner, $month, $months - 1 - $m);
            }

            $this->talk($owner, $posts, $today);
            self::at(null);
        });
    }

    /** One unit, let to one tenant from $start (95% of units). */
    private function let(Organization $organization, User $owner, Property $building, int $u, int $n, CarbonImmutable $start, ?string $portal): void
    {
        $unit = app(Units::class)->create($owner, $building, [
            'unitLabel' => 'Flat '.$u,
            'unitType' => 'apartment',
            'bedrooms' => 1 + $u % 3,
            'baseRent' => (string) (80_000 + ($u % 5) * 20_000),
        ]);

        if ($u % 20 === 0) {
            return; // vacant
        }

        $first = self::FIRST[$n % count(self::FIRST)];
        $last = self::LAST[intdiv($n, count(self::FIRST)) % count(self::LAST)];
        $email = $portal !== null ? "tenant{$u}.{$portal}@example.com" : null;
        $profile = app(TenantProfiles::class)->create($owner, [
            'firstName' => $first,
            'lastName' => $last,
            'phone' => '+234803'.str_pad((string) $this->phone++, 7, '0', STR_PAD_LEFT),
            'email' => $email,
        ]);

        if ($email !== null) {
            $user = $this->user("{$first} {$last}", $email);
            $profile->forceFill(['user_id' => $user->id])->save();
        }

        $leases = app(LeaseService::class);
        $lease = $leases->draft($owner, [
            'tenantId' => $profile->id,
            'unitId' => $unit->id,
            'billingCycle' => 'monthly',
            'startDate' => $start->toDateString(),
            'endDate' => $start->addYears(2)->subDay()->toDateString(),
            'rent' => (string) (80_000 + ($u % 5) * 20_000),
            'deposit' => '100,000',
            'dueDayOffset' => '0',
            'paidUntil' => '',
            'autoRenew' => false,
            'notes' => '',
        ]);
        $leases->activate($owner, $lease);
    }

    /**
     * The month's bills paid and confirmed on their due date (now), except
     * the last bill of one tenant in four and the last three of one in ten
     * (arrears for the aging report and the OVERDUE badge).
     */
    private function pay(User $owner, CarbonImmutable $month, int $monthsAfter): void
    {
        $payments = app(PaymentService::class);

        Bill::query()->whereBetween('due_date', [$month->toDateString(), $month->endOfMonth()->toDateString()])
            ->lazyById(200)
            ->each(function (Bill $bill) use ($payments, $owner, $monthsAfter): void {
                $tenant = $bill->tenant_profile_id;

                if (($tenant % 10 === 0 && $monthsAfter < 3) || ($tenant % 4 === 0 && $monthsAfter < 1)) {
                    return;
                }

                $payments->recordAndConfirm($owner, [
                    'tenantId' => (string) $tenant,
                    'billId' => (string) $bill->id,
                    'amount' => (string) intdiv($bill->amount_kobo->kobo, 100),
                    'method' => $bill->id % 3 === 0 ? 'cash' : 'bank_transfer',
                    'paidAt' => now('Africa/Lagos')->toDateString(),
                    'payerName' => '',
                    'payerType' => 'individual',
                    'reference' => '',
                    'note' => $bill->id % 3 === 0 ? 'Paid at the estate office.' : '',
                ]);
            });
    }

    /** Posts, comments and reactions in the first building's group, from its tenants and the owner, one post every 4 hours up to today. */
    private function talk(User $owner, int $posts, CarbonImmutable $today): void
    {
        $group = BuildingGroup::query()->orderBy('id')->first();
        $people = TenantProfile::query()->whereNotNull('user_id')->orderBy('id')->pluck('user_id')->all();

        if ($group === null || $people === [] || $posts === 0) {
            return;
        }

        dispatch_sync(new SyncBuildingGroups(budgetSeconds: 3600));
        $writer = app(GroupPosts::class);
        $users = User::query()->whereIn('id', $people)->get()->keyBy('id');
        $emoji = Reaction::cases();

        for ($p = 0; $p < $posts; $p++) {
            self::at($today->subHours(4 * ($posts - $p)));
            $by = $users[$people[$p % count($people)]];
            $staff = $p % 15 === 0;
            $post = $writer->post($staff ? $owner : $by, $staff ? AuthorKind::Staff : AuthorKind::Tenant, $group,
                "Notice {$p}: the water tank on the roof will be cleaned on Saturday morning. Please store some water the night before and keep the stairway clear for the cleaners.");
            $this->clearLimits($staff ? $owner : $by);

            for ($c = 1; $c <= 5; $c++) {
                $commenter = $users[$people[($p + $c * 7) % count($people)]];
                $writer->comment($commenter, AuthorKind::Tenant, $post, "Thanks for letting us know ({$c}).");
                $this->clearLimits($commenter);
            }

            for ($r = 1; $r <= 10; $r++) {
                $reactor = $users[$people[($p + $r * 3) % count($people)]];
                $writer->react($reactor, AuthorKind::Tenant, ReactableType::Post, $post->id, $emoji[($p + $r) % count($emoji)]);
                $this->clearLimits($reactor);
            }
        }
    }

    /** The writers' own flood limits are per person per hour; this makes a year's posts in minutes. */
    private function clearLimits(User $user): void
    {
        foreach (['group-posts:', 'group-comments:', 'group-reactions:'] as $key) {
            RateLimiter::clear($key.$user->id);
        }
    }

    /** Moves the clock the writers read (null: back to the real time). */
    private static function at(?CarbonImmutable $moment): void
    {
        Carbon::setTestNow($moment);
        CarbonImmutable::setTestNow($moment);
    }

    private function user(string $name, string $email): User
    {
        $user = User::query()->firstOrNew(['email' => $email]);
        $user->forceFill(['name' => $name, 'password' => $this->password, 'email_verified_at' => now()])->save();

        return $user;
    }

    private static function int(string $name, int $default): int
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $default : max(0, (int) $value);
    }
}

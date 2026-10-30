<?php

declare(strict_types=1);

use App\Enums\OnboardingStep;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Enums\TransactionTier;
use App\Marketing\PlanCatalogue;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\PlatformStaff;
use App\Models\SmsCreditBalance;
use App\Models\SmsCreditEntry;
use App\Models\User;
use App\Notifications\SmsCreditsRunningLow;
use App\Onboarding\OnboardingWizard;
use App\Settings\SettingsStore;
use App\Sms\Credits\CreditPool;
use App\Sms\Credits\CreditReason;
use App\Sms\Credits\GrantSmsAllowances;
use App\Sms\Credits\SmsCreditRefused;
use App\Sms\Credits\SmsCredits;
use App\Sms\DeliveryStatus;
use App\Sms\SendSms;
use App\Sms\SmsFailed;
use App\Sms\SmsOutbox;
use App\Sms\SmsReceipt;
use App\Sms\SmsReport;
use App\Sms\SmsSender;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/fake-sms.php';

/*
 * P05-03: SMS credits. Acceptance (IMPLEMENTATION_PLAN.md § 3): "Sending
 * stops at zero credit; ledger balances to allowance + top-ups − sends".
 * Defaults: docs/plans/P05-subtasks.md "Defaults taken in P05-03".
 */

beforeEach(function () {
    // 30 November 2026, 10:00 Lagos: the last day of a month.
    $this->travelTo(CarbonImmutable::parse('2026-11-30 10:00', 'Africa/Lagos'));
    $this->acme = Organization::factory()->create(['name' => 'Adeyemi Realty Ltd', 'slug' => 'acme-homes', 'signup_plan_key' => 'starter']);
});

/** Queues (and, on the sync queue, sends) one SMS reminder. */
function textTenant(Organization $organization, int $n): NotificationDelivery
{
    return inOrganization($organization, fn (): NotificationDelivery => app(SmsOutbox::class)
        ->queue('reminder.rent_due', '+234803111'.str_pad((string) $n, 4, '0', STR_PAD_LEFT), 'Adeyemi Realty: rent of NGN 1,500,000 is due tomorrow (1 Jan).', 'reminder:'.$n, sourceType: 'reminder', sourceId: $n));
}

function creditBalance(Organization $organization): SmsCreditBalance
{
    return inOrganization($organization, fn (): SmsCreditBalance => SmsCreditBalance::query()->sole());
}

/** @return list<SmsCreditEntry> */
function creditEntries(Organization $organization): array
{
    return inOrganization($organization, fn (): array => SmsCreditEntry::query()->orderBy('id')->get()->all());
}

function ledgerSum(Organization $organization, ?CreditReason $reason = null): int
{
    return (int) inOrganization($organization, fn () => SmsCreditEntry::query()->when($reason !== null, fn ($query) => $query->where('reason', $reason))->sum('credits'));
}

function customAllowance(Organization $organization, ?int $credits): void
{
    $credits === null
        ? app(SettingsStore::class)->forget('sms_credits.monthly_allowance', $organization)
        : app(SettingsStore::class)->set('sms_credits.monthly_allowance', $credits, $organization);
}

function lagos(string $moment): void
{
    test()->travelTo(CarbonImmutable::parse($moment, 'Africa/Lagos'));
}

describe('acceptance: sending stops at zero credit', function () {
    it('sends while there is credit, then marks the rest no_credit without calling GoText', function () {
        Notification::fake();
        customAllowance($this->acme, 3);
        $sms = scriptedSms();

        foreach (range(1, 5) as $n) {
            textTenant($this->acme, $n);
        }

        $statuses = inOrganization($this->acme, fn (): array => NotificationDelivery::query()->orderBy('id')->pluck('status')->all());

        expect($sms->calls)->toBe(3)
            ->and($statuses)->toBe([DeliveryStatus::Delivered, DeliveryStatus::Delivered, DeliveryStatus::Delivered, DeliveryStatus::NoCredit, DeliveryStatus::NoCredit])
            ->and(creditBalance($this->acme)->total())->toBe(0)
            ->and(ledgerSum($this->acme, CreditReason::Usage))->toBe(-3);

        $refused = inOrganization($this->acme, fn (): NotificationDelivery => NotificationDelivery::query()->where('dedupe_key', 'reminder:4')->sole());
        expect($refused->error)->toContain('No SMS credit left')
            ->and($refused->attempts)->toBe(0)
            ->and($refused->provider_message_id)->toBeNull()
            ->and($refused->status->isFinal())->toBeTrue()
            ->and($refused->status->label())->toBe('Not sent: no SMS credit');

        // Running the job again never sends it later.
        app()->call([new SendSms($this->acme->id, $refused->id), 'handle']);
        expect($sms->calls)->toBe(3);
    });

    it('sends again once credits are bought', function () {
        Notification::fake();
        customAllowance($this->acme, 1);
        $sms = scriptedSms();
        textTenant($this->acme, 1);
        textTenant($this->acme, 2);

        inOrganization($this->acme, fn () => app(SmsCredits::class)->purchase(500, 41, 'Invoice PR-2026-000041'));
        textTenant($this->acme, 3);

        expect($sms->calls)->toBe(2)
            ->and(creditBalance($this->acme)->only(['allowance_credits', 'purchased_credits']))->toBe(['allowance_credits' => 0, 'purchased_credits' => 499]);
    });

    it('charges a delivery once however often its job runs, and charges one organization only', function () {
        Notification::fake();
        $other = Organization::factory()->create(['signup_plan_key' => 'growth']);
        Queue::fake();
        $sms = scriptedSms();
        $delivery = textTenant($this->acme, 1);
        inOrganization($other, fn () => app(SmsCredits::class)->refreshAllowance());

        // Charged, then the worker died before GoText answered: charged once, never sent twice.
        inOrganization($this->acme, fn () => app(SmsCredits::class)->take($delivery));
        inOrganization($this->acme, fn () => app(SmsCredits::class)->take($delivery));
        app()->call([new SendSms($this->acme->id, $delivery->id), 'handle']);

        expect(ledgerSum($this->acme, CreditReason::Usage))->toBe(-1)
            ->and(creditBalance($this->acme)->total())->toBe(99)
            ->and(creditBalance($other)->total())->toBe(500)
            ->and($sms->calls)->toBe(1);
    });
});

describe('acceptance: the ledger balances to allowance + top-ups − sends', function () {
    it('keeps the balance equal to the sum of the ledger through a month of grants, purchases and sends', function () {
        Notification::fake();
        $sms = scriptedSms();
        // November: Starter's 100, a 500 bundle, 7 SMS.
        inOrganization($this->acme, fn () => app(SmsCredits::class)->refreshAllowance());
        inOrganization($this->acme, fn () => app(SmsCredits::class)->purchase(500, 7, 'Invoice PR-2026-000007'));

        foreach (range(1, 7) as $n) {
            textTenant($this->acme, $n);
        }

        $balance = creditBalance($this->acme);
        $allowance = ledgerSum($this->acme, CreditReason::MonthlyAllowance);
        $topUps = ledgerSum($this->acme, CreditReason::Purchase);
        $sends = -ledgerSum($this->acme, CreditReason::Usage);

        expect([$allowance, $topUps, $sends])->toBe([100, 500, 7])
            ->and($balance->total())->toBe($allowance + $topUps - $sends)
            ->and($balance->total())->toBe(ledgerSum($this->acme))
            ->and($balance->only(['allowance_credits', 'purchased_credits']))->toBe(['allowance_credits' => 93, 'purchased_credits' => 500])
            ->and($sms->calls)->toBe(7)
            // Each entry's running balance.
            ->and(array_map(fn (SmsCreditEntry $entry): int => $entry->balance_after, creditEntries($this->acme)))->toBe([100, 600, 599, 598, 597, 596, 595, 594, 593]);

        // December: November's 93 unused allowance credits expire, Starter's 100 arrive, the 500 bought stay.
        lagos('2026-12-01 00:05');
        app()->call([new GrantSmsAllowances, 'handle']);
        textTenant($this->acme, 8);

        $expired = -ledgerSum($this->acme, CreditReason::Expiry);
        expect($expired)->toBe(93)
            ->and(ledgerSum($this->acme, CreditReason::MonthlyAllowance))->toBe(200)
            ->and(creditBalance($this->acme)->total())->toBe(ledgerSum($this->acme))
            ->and(creditBalance($this->acme)->total())->toBe(200 + 500 - 8 - $expired)
            ->and(creditBalance($this->acme)->only(['allowance_credits', 'purchased_credits', 'allowance_month', 'allowance_granted']))
            ->toBe(['allowance_credits' => 99, 'purchased_credits' => 500, 'allowance_month' => '2026-12', 'allowance_granted' => 100]);
    });

    it('never changes or deletes a ledger entry', function () {
        inOrganization($this->acme, fn () => app(SmsCredits::class)->refreshAllowance());
        $entry = creditEntries($this->acme)[0];

        expect(fn () => inOrganization($this->acme, fn () => $entry->forceFill(['credits' => 1_000])->save()))->toThrow(LogicException::class)
            ->and(fn () => inOrganization($this->acme, fn () => $entry->delete()))->toThrow(LogicException::class)
            ->and(fn () => inOrganization($this->acme, fn () => creditBalance($this->acme)->delete()))->toThrow(LogicException::class);
    });
});

describe('the monthly allowance', function () {
    it('grants each organization its plan\'s allowance once a month, however often the job runs, and skips cancelled ones', function () {
        $growth = Organization::factory()->create(['signup_plan_key' => 'growth']);
        $scale = Organization::factory()->create(['signup_plan_key' => 'scale']);
        $noPlan = Organization::factory()->create(['signup_plan_key' => null]);
        $cancelled = Organization::factory()->create(['signup_plan_key' => 'growth', 'status' => OrganizationStatus::Cancelled]);
        $suspended = Organization::factory()->create(['signup_plan_key' => 'growth', 'status' => OrganizationStatus::Suspended]);

        lagos('2026-12-01 00:05');
        app()->call([new GrantSmsAllowances, 'handle']);
        app()->call([new GrantSmsAllowances, 'handle']);
        lagos('2026-12-01 01:05');
        app()->call([new GrantSmsAllowances, 'handle']);

        expect(creditBalance($this->acme)->total())->toBe(100)
            ->and(creditBalance($growth)->total())->toBe(500)
            ->and(creditBalance($scale)->total())->toBe(2_000)
            ->and(creditBalance($noPlan)->total())->toBe(100)
            ->and(creditBalance($suspended)->total())->toBe(500)
            ->and(inOrganization($cancelled, fn (): bool => SmsCreditBalance::query()->exists()))->toBeFalse()
            ->and(count(creditEntries($growth)))->toBe(1)
            ->and(creditEntries($growth)[0]->only(['reason', 'pool', 'credits', 'month']))->toEqual(['reason' => CreditReason::MonthlyAllowance, 'pool' => CreditPool::Allowance, 'credits' => 500, 'month' => '2026-12']);

        // January: December's unused allowance expires once, January's arrives once.
        lagos('2027-01-01 00:05');
        app()->call([new GrantSmsAllowances, 'handle']);
        app()->call([new GrantSmsAllowances, 'handle']);

        expect(array_map(fn (SmsCreditEntry $entry): array => [$entry->reason->value, $entry->credits, $entry->month], creditEntries($growth)))->toBe([
            ['monthly_allowance', 500, '2026-12'],
            ['expiry', -500, '2026-12'],
            ['monthly_allowance', 500, '2027-01'],
        ]);
    });

    it('is scheduled hourly in-process on the billing queue', function () {
        $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => $event->description === 'sms-credits-allowance');

        expect($event)->not->toBeNull()
            ->and($event->expression)->toBe('5 * * * *')
            ->and((new GrantSmsAllowances)->queue)->toBe('billing');
    });

    it('takes allowance credits first and never expires purchased ones', function () {
        Notification::fake();
        scriptedSms();
        customAllowance($this->acme, 2);
        inOrganization($this->acme, fn () => app(SmsCredits::class)->purchase(10, 3));

        foreach (range(1, 3) as $n) {
            textTenant($this->acme, $n);
        }

        expect(array_map(fn (SmsCreditEntry $entry): array => [$entry->reason->value, $entry->pool->value, $entry->credits], array_slice(creditEntries($this->acme), 2)))->toBe([
            ['usage', 'allowance', -1],
            ['usage', 'allowance', -1],
            ['usage', 'purchased', -1],
        ]);

        lagos('2026-12-01 09:00');
        textTenant($this->acme, 4);

        // No allowance was left to expire; December's 2 arrive and are used first.
        expect(creditBalance($this->acme)->only(['allowance_credits', 'purchased_credits']))->toBe(['allowance_credits' => 1, 'purchased_credits' => 9])
            ->and(ledgerSum($this->acme, CreditReason::Expiry))->toBe(0);
    });

    it('gives a new organization its first allowance at signup and the difference when it picks a bigger plan', function () {
        Notification::fake();
        seedOrganizationRoles();
        $owner = signUpAndVerify();
        $organization = signupOrganization();

        expect(creditBalance($organization)->only(['allowance_credits', 'allowance_granted']))->toBe(['allowance_credits' => 100, 'allowance_granted' => 100]);

        inOrganization($organization, fn () => app(OnboardingWizard::class)->chooseTier($owner, Money::naira(10_000_000), TransactionTier::One));
        inOrganization($organization, fn () => app(OnboardingWizard::class)->choosePlan($owner, collect(app(PlanCatalogue::class)->plans())->firstWhere('key', 'growth')));

        expect(creditBalance($organization)->only(['allowance_credits', 'allowance_granted']))->toBe(['allowance_credits' => 500, 'allowance_granted' => 500])
            ->and(array_map(fn (SmsCreditEntry $entry): int => $entry->credits, creditEntries($organization)))->toBe([100, 400]);

        // A smaller plan waits for next month.
        inOrganization($organization, fn () => app(OnboardingWizard::class)->goBackTo(OnboardingStep::Plan));
        inOrganization($organization, fn () => app(OnboardingWizard::class)->choosePlan($owner, collect(app(PlanCatalogue::class)->plans())->firstWhere('key', 'starter')));
        expect(creditBalance($organization)->allowance_granted)->toBe(500);
    });

    it('uses a custom allowance instead of the plan\'s', function () {
        customAllowance($this->acme, 7_500);
        inOrganization($this->acme, fn () => app(SmsCredits::class)->refreshAllowance());

        expect(creditBalance($this->acme)->total())->toBe(7_500)
            ->and(inOrganization($this->acme, fn () => app(SmsCredits::class)->summary())->monthlyAllowance)->toBe(7_500);
    });
});

describe('giving credits back', function () {
    it('gives the credit back when GoText refused the message on every attempt', function () {
        config(['queue.default' => 'database']);
        $sms = scriptedSms(failures: 10);
        textTenant($this->acme, 1);

        foreach ([0, 61, 301, 1801] as $wait) {
            $this->travel($wait)->seconds();
            Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'notifications', '--stop-when-empty' => true]);
        }

        expect($sms->calls)->toBe(4)
            ->and(inOrganization($this->acme, fn () => NotificationDelivery::query()->sole()->status))->toBe(DeliveryStatus::Failed)
            ->and(array_map(fn (SmsCreditEntry $entry): array => [$entry->reason->value, $entry->credits], creditEntries($this->acme)))->toBe([
                ['monthly_allowance', 100], ['usage', -1], ['adjustment', 1],
            ])
            ->and(creditEntries($this->acme)[2]->note)->toContain('GoText refused the SMS')
            ->and(creditBalance($this->acme)->total())->toBe(100);
    });

    it('keeps the credit when an attempt\'s outcome is unknown', function () {
        config(['queue.default' => 'database']);
        app()->instance(SmsSender::class, new class implements SmsSender
        {
            public int $calls = 0;

            public function name(): string
            {
                return 'scripted';
            }

            public function send(string $to, string $text): SmsReceipt
            {
                // First GoText cannot be reached (it may have taken it), then it refuses.
                throw ++$this->calls === 1 ? SmsFailed::unreachable('GoText could not be reached.') : new SmsFailed('GoText refused the request (HTTP 503).');
            }

            public function status(string $messageId): SmsReport
            {
                return new SmsReport(null, null);
            }
        });
        textTenant($this->acme, 1);

        foreach ([0, 61, 301, 1801] as $wait) {
            $this->travel($wait)->seconds();
            Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'notifications', '--stop-when-empty' => true]);
        }

        $delivery = inOrganization($this->acme, fn () => NotificationDelivery::query()->sole());
        expect($delivery->status)->toBe(DeliveryStatus::Failed)
            ->and($delivery->outcome_unknown)->toBeTrue()
            ->and(ledgerSum($this->acme, CreditReason::Adjustment))->toBe(0)
            ->and(creditBalance($this->acme)->total())->toBe(99);
    });

    it('keeps the credit of an attempt that died mid-send', function () {
        Queue::fake();
        $sms = scriptedSms();
        $delivery = textTenant($this->acme, 1);
        app()->call([new SendSms($this->acme->id, $delivery->id), 'handle']);
        inOrganization($this->acme, fn () => $delivery->fresh()?->forceFill(['status' => DeliveryStatus::Sending])->save());

        app()->call([new SendSms($this->acme->id, $delivery->id), 'handle']);
        (new SendSms($this->acme->id, $delivery->id))->failed(new SmsFailed('GoText refused the request (HTTP 503).'));

        expect(ledgerSum($this->acme, CreditReason::Usage))->toBe(-1)
            ->and(ledgerSum($this->acme, CreditReason::Adjustment))->toBe(0)
            ->and($sms->calls)->toBe(1);
    });
});

describe('telling owners and administrators', function () {
    it('tells owners and administrators once at 20 % and once at zero each month, in the app and by email', function () {
        Notification::fake();
        scriptedSms();
        $owner = joinAs(User::factory()->create(['name' => 'Ada Owner']), $this->acme);
        $admin = joinAs(User::factory()->create(['name' => 'Bola Admin']), $this->acme, OrganizationRole::Admin);
        $phoneOnlyAdmin = joinAs(User::factory()->create(['name' => 'Chidi Admin', 'email' => null, 'phone' => '+2348039990000']), $this->acme, OrganizationRole::Admin);
        $agent = joinAs(User::factory()->create(['name' => 'Dayo Agent']), $this->acme, OrganizationRole::Agent);
        customAllowance($this->acme, 10);

        foreach (range(1, 7) as $n) {
            textTenant($this->acme, $n);
        }
        Notification::assertNothingSent();

        textTenant($this->acme, 8);
        Notification::assertSentTo([$owner, $admin, $phoneOnlyAdmin], SmsCreditsRunningLow::class, fn (SmsCreditsRunningLow $notice): bool => ! $notice->empty && $notice->balance === 2 && $notice->allowance === 10);
        Notification::assertNotSentTo($agent, SmsCreditsRunningLow::class);

        textTenant($this->acme, 9);
        textTenant($this->acme, 10);
        textTenant($this->acme, 11);
        textTenant($this->acme, 12);

        Notification::assertSentToTimes($owner, SmsCreditsRunningLow::class, 2);
        Notification::assertSentTo($owner, SmsCreditsRunningLow::class, fn (SmsCreditsRunningLow $notice): bool => $notice->empty && $notice->balance === 0);

        $notice = new SmsCreditsRunningLow($this->acme->id, true, 0, 10);
        expect($notice->via($owner))->toBe(['database', 'mail'])
            ->and($notice->via($phoneOnlyAdmin))->toBe(['database'])
            ->and($notice->toArray($owner))->toMatchArray(['event' => 'sms_credits.empty', 'url' => url('/o/acme-homes/settings/sms-credits')])
            ->and($notice->toMail($owner)->subject)->toBe('SMS credits used up: SMS reminders have stopped')
            ->and((string) $notice->toMail($owner)->render())->toContain('1 December 2026');

        // December: told again when it runs low again.
        lagos('2026-12-01 09:00');
        foreach (range(13, 20) as $n) {
            textTenant($this->acme, $n);
        }
        Notification::assertSentToTimes($owner, SmsCreditsRunningLow::class, 3);
    });
});

describe('adjustments', function () {
    it('lets staff add and remove credits with a reason, never below zero, audited', function () {
        seedStaffRoles();
        $staff = staffNamed('Funmi', ['finance-admin']);
        inOrganization($this->acme, fn () => app(SmsCredits::class)->refreshAllowance());
        inOrganization($this->acme, fn () => app(SmsCredits::class)->purchase(50, 9));

        inOrganization($this->acme, fn () => app(SmsCredits::class)->adjust($staff, 200, 'Goodwill after the GoText outage'));
        inOrganization($this->acme, fn () => app(SmsCredits::class)->adjust($staff, -300, 'Bundle bought twice by mistake'));

        expect(creditBalance($this->acme)->only(['allowance_credits', 'purchased_credits']))->toBe(['allowance_credits' => 50, 'purchased_credits' => 0])
            ->and(creditBalance($this->acme)->total())->toBe(ledgerSum($this->acme))
            ->and(fn () => inOrganization($this->acme, fn () => app(SmsCredits::class)->adjust($staff, -51, 'Too many')))->toThrow(SmsCreditRefused::class)
            ->and(fn () => inOrganization($this->acme, fn () => app(SmsCredits::class)->adjust($staff, 10, '  ')))->toThrow(SmsCreditRefused::class)
            ->and(fn () => inOrganization($this->acme, fn () => app(SmsCredits::class)->adjust($staff, 0, 'Nothing')))->toThrow(SmsCreditRefused::class);

        $audit = DB::table('activity_log')->where('log_name', 'sms_credits')->where('event', 'sms_credits.adjusted')->orderBy('id')->get();
        expect($audit)->toHaveCount(2)
            ->and($audit[0]->causer_type)->toBe((new PlatformStaff)->getMorphClass())
            ->and(json_decode((string) $audit[1]->properties, true))->toMatchArray(['credits' => -300, 'reason' => 'Bundle bought twice by mistake', 'balance_after' => 50]);
    });
});

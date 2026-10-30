<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\StaffRole;
use App\Platform\Deliveries\Health\ChannelFigures;
use App\Platform\Deliveries\Health\MessagingHealth;
use App\Platform\Deliveries\Health\ProviderHealth;
use App\Platform\Filament\Pages\SystemOperations;
use App\Platform\Filament\Resources\DeliveryLog\Pages\ListDeliveryLog;
use App\Platform\Filament\Widgets\Operations\MessagingDeliveriesWidget;
use App\Platform\Filament\Widgets\Operations\MessagingProvidersWidget;
use App\Platform\Filament\Widgets\Operations\SmsSpendWidget;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * P05-05 acceptance: "Dashboard figures match the `notification_deliveries`
 * log for a fixture" (PLATFORM_CONSOLE.md § 2 "Messaging"). A fixed set of
 * deliveries across channels, providers, statuses, organizations and times
 * (some exactly on, some a second past the 24-hour and 7-day edges), credit
 * ledger entries, suppressions, email webhook calls and WhatsApp
 * connections, at a frozen clock. Every figure on the page is read back
 * from the rendered widgets and compared with a count made in PHP straight
 * from the rows; each linked figure's delivery log lists exactly that many.
 */

const DASHBOARD_NOW = '2026-12-10 12:00:00';

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('console'));
    seedStaffRoles();
    $this->now = CarbonImmutable::parse(DASHBOARD_NOW, 'UTC');
    $this->travelTo($this->now);
});

/** Seconds before the frozen clock, by name. */
function dashboardAt(string $when): CarbonImmutable
{
    $now = CarbonImmutable::parse(DASHBOARD_NOW, 'UTC');

    return match ($when) {
        'recent' => $now->subHour(),
        'edge_in_24h' => $now->subHours(24),
        'edge_out_24h' => $now->subHours(24)->subSecond(),
        'mid_week' => $now->subDays(3),
        'edge_in_7d' => $now->subDays(7),
        'edge_out_7d' => $now->subDays(7)->subSecond(),
        'old' => $now->subDays(30),
    };
}

/** @return array<string, int> delivery ids by fixture name */
function deliveryFixture(Organization $a, Organization $b): array
{
    $now = CarbonImmutable::parse(DASHBOARD_NOW, 'UTC');
    // name => [org, channel, provider, status, queued, pages, cost_kobo, failed_at, error, status_checked_at]
    $rows = [
        'sms_delivered_recent' => [$a, 'sms', 'gotext', 'delivered', 'recent', 1, 250, null, null, $now->subMinutes(30)],
        'sms_delivered_edge' => [$b, 'sms', 'gotext', 'delivered', 'edge_in_24h', 1, null, null, null, null],
        'sms_sent_recent' => [$a, 'sms', 'gotext', 'sent', 'recent', 1, 250, null, null, null],
        'sms_undelivered_recent' => [$a, 'sms', 'gotext', 'undelivered', 'recent', 1, 250, $now->subMinutes(55), 'Handset switched off', null],
        'sms_failed_edge' => [$b, 'sms', 'gotext', 'failed', 'edge_in_24h', null, null, $now->subMinutes(10), 'GoText refused +2348031234567: number on DND', null],
        'sms_no_credit_recent' => [$a, 'sms', 'gotext', 'no_credit', 'recent', null, null, null, 'No SMS credit left: not sent.', null],
        'sms_queued_recent' => [$b, 'sms', 'gotext', 'queued', 'recent', null, null, null, null, null],
        'sms_sending_recent' => [$a, 'sms', 'gotext', 'sending', 'recent', null, null, null, null, null],
        'sms_failed_out_24h' => [$a, 'sms', 'gotext', 'failed', 'edge_out_24h', null, null, dashboardAt('edge_out_24h'), 'GoText refused the request (HTTP 503).', null],
        'sms_delivered_week' => [$b, 'sms', 'gotext', 'delivered', 'mid_week', 2, 500, null, null, dashboardAt('mid_week')->addHour()],
        'sms_no_credit_edge_7d' => [$a, 'sms', 'gotext', 'no_credit', 'edge_in_7d', null, null, null, null, null],
        'sms_delivered_out_7d' => [$a, 'sms', 'gotext', 'delivered', 'edge_out_7d', 1, 250, null, null, null],
        'sms_failed_old' => [$b, 'sms', 'gotext', 'failed', 'old', null, null, dashboardAt('old'), 'Old failure', null],
        'sms_log_week' => [$a, 'sms', 'log', 'delivered', 'mid_week', 1, null, null, null, null],
        'wa_delivered_recent' => [$a, 'whatsapp', 'meta', 'delivered', 'recent', null, null, null, null, null],
        'wa_undelivered_recent' => [$b, 'whatsapp', 'meta', 'undelivered', 'recent', null, null, $now->subMinutes(50), 'Meta reported failed (131026)', null],
        'wa_sent_week' => [$a, 'whatsapp', 'meta', 'sent', 'mid_week', null, null, null, null, null],
        'wa_failed_out_24h' => [$b, 'whatsapp', 'meta', 'failed', 'edge_out_24h', null, null, dashboardAt('edge_out_24h'), 'Meta refused the send', null],
        'wa_delivered_out_7d' => [$a, 'whatsapp', 'meta', 'delivered', 'edge_out_7d', null, null, null, null, null],
        'wa_queued_edge' => [$a, 'whatsapp', 'meta', 'queued', 'edge_in_24h', null, null, null, null, null],
    ];
    $ids = [];

    foreach ($rows as $name => [$organization, $channel, $provider, $status, $when, $pages, $cost, $failedAt, $error, $checkedAt]) {
        $queued = dashboardAt($when);
        $ids[$name] = DB::table('notification_deliveries')->insertGetId([
            'organization_id' => $organization->id,
            'notification_type' => 'reminder.rent_due',
            'channel' => $channel,
            'recipient_contact' => '+23480311'.str_pad((string) count($ids), 5, '0', STR_PAD_LEFT),
            'body' => 'SECRET-BODY-'.$name,
            'status' => $status,
            'provider' => $provider,
            'error' => $error,
            'attempts' => in_array($status, ['queued', 'no_credit'], true) ? 0 : 1,
            'pages' => $pages,
            'cost_kobo' => $cost,
            'dedupe_key' => 'fixture:'.$name,
            'queued_at' => $queued,
            'sent_at' => in_array($status, ['sent', 'delivered', 'undelivered'], true) ? $queued->addMinute() : null,
            'delivered_at' => $status === 'delivered' ? $queued->addMinutes(2) : null,
            'failed_at' => $failedAt,
            'status_checked_at' => $checkedAt,
            'created_at' => $queued,
            'updated_at' => $queued,
        ]);
    }

    return $ids;
}

/** @param  array<string, int>  $deliveries */
function creditFixture(Organization $a, Organization $b, array $deliveries): void
{
    $staff = staffNamed('Funmi', ['finance-admin']);
    // [org, reason, pool, credits, delivery, staff, when]
    $entries = [
        [$a, 'usage', 'allowance', -1, 'sms_delivered_recent', false, 'recent'],
        [$b, 'usage', 'allowance', -1, 'sms_delivered_edge', false, 'edge_in_24h'],
        [$a, 'usage', 'purchased', -1, 'sms_sent_recent', false, 'recent'],
        [$a, 'usage', 'allowance', -1, 'sms_undelivered_recent', false, 'recent'],
        [$b, 'usage', 'allowance', -1, 'sms_failed_edge', false, 'edge_in_24h'],
        [$b, 'adjustment', 'allowance', 1, 'sms_failed_edge', false, 'recent'],
        [$a, 'usage', 'allowance', -1, 'sms_failed_out_24h', false, 'edge_out_24h'],
        [$b, 'usage', 'allowance', -2, 'sms_delivered_week', false, 'mid_week'],
        [$a, 'usage', 'allowance', -1, 'sms_delivered_out_7d', false, 'edge_out_7d'],
        [$a, 'adjustment', 'purchased', 5, null, true, 'recent'],
        [$b, 'adjustment', 'purchased', -3, null, true, 'recent'],
        [$a, 'purchase', 'purchased', 500, null, false, 'mid_week'],
        [$b, 'purchase', 'purchased', 1000, null, false, 'edge_out_7d'],
        [$a, 'monthly_allowance', 'allowance', 100, null, false, 'edge_in_7d'],
        [$b, 'monthly_allowance', 'allowance', 500, null, false, 'recent'],
        [$a, 'expiry', 'allowance', -40, null, false, 'recent'],
    ];

    foreach ($entries as $i => [$organization, $reason, $pool, $credits, $delivery, $byStaff, $when]) {
        DB::table('sms_credit_entries')->insert([
            'organization_id' => $organization->id,
            'reason' => $reason,
            'pool' => $pool,
            'credits' => $credits,
            'balance_after' => 0,
            'notification_delivery_id' => $delivery !== null ? $deliveries[$delivery] : null,
            'platform_staff_id' => $byStaff ? $staff->id : null,
            'idempotency_key' => 'fixture:'.$i,
            'created_at' => dashboardAt($when),
            'updated_at' => dashboardAt($when),
        ]);
    }
}

function emailAndConnectionFixture(): void
{
    foreach ([['a@x.ng', 'bounce', 'recent'], ['b@x.ng', 'bounce', 'mid_week'], ['c@x.ng', 'complaint', 'edge_in_24h'], ['d@x.ng', 'manual', 'recent'], ['e@x.ng', 'bounce', 'old'], ['f@x.ng', 'complaint', 'edge_out_7d']] as [$email, $reason, $when]) {
        DB::table('email_suppressions')->insert(['email' => $email, 'reason' => $reason, 'provider' => 'postmark', 'suppressed_at' => dashboardAt($when), 'created_at' => dashboardAt($when), 'updated_at' => dashboardAt($when)]);
    }

    foreach ([['postmark', 'recent'], ['mailgun', 'mid_week'], ['postmark', 'old'], ['paystack', 'recent']] as [$provider, $when]) {
        DB::table('webhook_log')->insert(['organization_id' => null, 'provider' => $provider, 'event' => 'Bounce HardBounce', 'payload' => '{}', 'signature_valid' => true, 'received_at' => dashboardAt($when), 'created_at' => dashboardAt($when), 'updated_at' => dashboardAt($when)]);
    }

    foreach (['active', 'active', 'failing', 'pending_test', 'disconnected'] as $status) {
        DB::table('organization_whatsapp_connections')->insert([
            'organization_id' => Organization::factory()->create()->id,
            'webhook_ulid' => strtolower((string) Str::ulid()),
            'webhook_verify_token' => Str::random(40),
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

/**
 * Every `data-figure` on a rendered widget, with its text and its link.
 *
 * @return array<string, array{value: string, href: string|null}>
 */
function pageFigures(string $html): array
{
    preg_match_all('/<(a|span) (?:href="([^"]*)" )?data-figure="([^"]+)"[^>]*>([^<]*)<\/\1>/', $html, $matches, PREG_SET_ORDER);
    $figures = [];

    foreach ($matches as [, , $href, $key, $value]) {
        $figures[$key] = ['value' => trim(html_entity_decode($value)), 'href' => $href !== '' ? html_entity_decode($href) : null];
    }

    return $figures;
}

function expectedRate(int $part, int $whole): string
{
    return number_format(round(100 * $part / $whole, 1, PHP_ROUND_HALF_UP), 1).' %';
}

function lagosTime(?string $time): ?string
{
    return $time === null ? null : CarbonImmutable::parse($time, 'UTC')->setTimezone('Africa/Lagos')->format('j M Y, H:i');
}

describe('acceptance: dashboard figures match the notification_deliveries log for a fixture', function () {
    beforeEach(function () {
        $a = Organization::factory()->create(['name' => 'Adeyemi Realty Ltd']);
        $b = Organization::factory()->create(['name' => 'Okafor Homes']);
        $this->deliveries = deliveryFixture($a, $b);
        creditFixture($a, $b, $this->deliveries);
        emailAndConnectionFixture();
        $this->actingAs(staffNamed('Tobi', ['technical-admin']), 'staff');

        $this->rows = DB::table('notification_deliveries')->get();
        $this->windows = ['24h' => $this->now->subHours(24), '7d' => $this->now->subDays(7)];
        $this->inWindow = fn (string $column, CarbonImmutable $since) => fn (object $row): bool => $row->{$column} !== null && CarbonImmutable::parse($row->{$column}, 'UTC')->greaterThanOrEqualTo($since);
    });

    it('counts every channel, outcome and failure rate as the rows do, and links each figure to exactly its rows', function () {
        $sets = [
            'sent' => ['sent', 'delivered', 'undelivered'],
            'delivered' => ['delivered'],
            'awaiting_report' => ['sent'],
            'undelivered' => ['undelivered'],
            'failed' => ['failed'],
            'no_credit' => ['no_credit'],
            'waiting' => ['queued', 'sending'],
        ];
        $expected = [];

        foreach ($this->windows as $window => $since) {
            foreach (['sms', 'whatsapp'] as $channel) {
                /** @var Collection<int, object> $mine */
                $mine = $this->rows->where('channel', $channel)->filter(($this->inWindow)('queued_at', $since));
                $count = fn (array $statuses): int => $mine->whereIn('status', $statuses)->count();
                $expected["{$window}.{$channel}.queued"] = (string) $mine->count();

                foreach ($sets as $outcome => $statuses) {
                    if ($outcome !== 'no_credit' || $channel === 'sms') {
                        $expected["{$window}.{$channel}.{$outcome}"] = (string) $count($statuses);
                    }
                }

                $attempted = $count(['sent', 'delivered', 'undelivered', 'failed']);
                $expected["{$window}.{$channel}.attempted"] = (string) $attempted;
                $expected["{$window}.{$channel}.failure_rate"] = $attempted > 0 ? expectedRate($count(['failed', 'undelivered']), $attempted) : '—';
            }

            $suppressions = DB::table('email_suppressions')->get()->filter(($this->inWindow)('suppressed_at', $since));
            $expected["{$window}.email.bounced"] = (string) $suppressions->where('reason', 'bounce')->count();
            $expected["{$window}.email.complaints"] = (string) $suppressions->where('reason', 'complaint')->count();
            $expected["{$window}.email.webhook_calls"] = (string) DB::table('webhook_log')->whereIn('provider', ['postmark', 'mailgun'])->get()->filter(($this->inWindow)('received_at', $since))->count();
        }

        $figures = pageFigures(Livewire::test(MessagingDeliveriesWidget::class)->html());

        expect(array_map(fn (array $figure): string => $figure['value'], $figures))->toEqual($expected)
            // The fixture really tests the edges: a row exactly 24 h / 7 d old is in, a second older is out.
            ->and($expected['24h.sms.queued'])->toBe('8')
            ->and($expected['7d.sms.queued'])->toBe('12')
            ->and($expected['24h.sms.failure_rate'])->toBe('40.0 %')
            ->and($expected['7d.sms.failure_rate'])->toBe('37.5 %')
            ->and($expected['24h.sms.no_credit'])->toBe('1')
            ->and($expected['7d.sms.no_credit'])->toBe('2');

        // Each linked figure opens the delivery log listing exactly the rows it counted.
        $linked = array_filter($figures, fn (array $figure): bool => $figure['href'] !== null);
        expect(count($linked))->toBe(count($expected) - 6); // the email figures have no log yet

        foreach ($linked as $key => $figure) {
            parse_str((string) parse_url((string) $figure['href'], PHP_URL_QUERY), $query);
            $value = str_ends_with($key, '.failure_rate')
                ? (int) $expected[str_replace('failure_rate', 'undelivered', $key)] + (int) $expected[str_replace('failure_rate', 'failed', $key)]
                : (int) $figure['value'];

            Livewire::withQueryParams($query)->test(ListDeliveryLog::class)->assertCountTableRecords($value);
        }
    });

    it('adds up SMS spend from the deliveries and the credit ledger as the rows do', function () {
        $credits = DB::table('sms_credit_entries')->get();
        $expected = [];

        foreach ($this->windows as $window => $since) {
            $sent = $this->rows->where('channel', 'sms')->whereIn('status', ['sent', 'delivered', 'undelivered'])->filter(($this->inWindow)('queued_at', $since));
            $entries = $credits->filter(($this->inWindow)('created_at', $since));
            $taken = -$entries->where('reason', 'usage')->sum('credits');
            $givenBack = $entries->where('reason', 'adjustment')->whereNotNull('notification_delivery_id')->where('credits', '>', 0)->sum('credits');
            $unreportedCost = $sent->whereNull('cost_kobo')->count();
            $unreportedPages = $sent->whereNull('pages')->count();

            $expected["{$window}.spend.messages"] = (string) $sent->count();
            $expected["{$window}.spend.pages"] = (string) $sent->sum('pages');
            $expected["{$window}.spend.cost"] = Money::kobo((int) $sent->sum('cost_kobo'))->format();
            if ($unreportedCost > 0) {
                $expected["{$window}.spend.cost_unreported"] = (string) $unreportedCost;
            }
            if ($unreportedPages > 0) {
                $expected["{$window}.spend.pages_unreported"] = (string) $unreportedPages;
            }
            $expected["{$window}.spend.credits_used"] = (string) ($taken - $givenBack);
            $expected["{$window}.spend.credits_given_back"] = (string) $givenBack;
            $expected["{$window}.spend.credits_bought"] = number_format($entries->where('reason', 'purchase')->sum('credits'));
            $expected["{$window}.spend.allowance"] = (string) $entries->where('reason', 'monthly_allowance')->sum('credits');
        }

        $figures = pageFigures(Livewire::test(SmsSpendWidget::class)->html());

        expect(array_map(fn (array $figure): string => $figure['value'], $figures))->toEqual($expected)
            ->and($expected)->toMatchArray([
                '24h.spend.messages' => '4', '24h.spend.cost' => '₦7.50', '24h.spend.cost_unreported' => '1',
                '24h.spend.credits_used' => '4', '24h.spend.credits_given_back' => '1',
                '7d.spend.messages' => '6', '7d.spend.pages' => '7', '7d.spend.credits_used' => '7', '7d.spend.credits_bought' => '500',
            ])
            ->and($figures['24h.spend.messages']['href'])->not->toBeNull();
    });

    it('shows each provider\'s health, last send and last failure as the rows do, numbers masked and no text', function () {
        $expected = [];
        $judge = fn (int $problems, int $attempted): string => match (true) {
            $attempted === 0 => 'No traffic',
            $problems * 100 >= 25 * $attempted => 'Failing',
            $problems * 100 >= 5 * $attempted => 'Degraded',
            default => 'OK',
        };

        foreach ([['sms', 'gotext'], ['sms', 'log'], ['whatsapp', 'meta']] as [$channel, $provider]) {
            $week = $this->rows->where('channel', $channel)->where('provider', $provider)->filter(($this->inWindow)('queued_at', $this->windows['7d']));
            $day = $week->filter(($this->inWindow)('queued_at', $this->windows['24h']));
            $attempted = $day->whereIn('status', ['sent', 'delivered', 'undelivered', 'failed'])->count();
            $problems = $day->whereIn('status', ['failed', 'undelivered'])->count();
            $failure = $week->whereIn('status', ['failed', 'undelivered'])->sortByDesc('failed_at')->first();
            $key = "provider.{$channel}.{$provider}";

            $expected["{$key}.health"] = $judge($problems, $attempted);
            if ($attempted > 0) {
                $expected["{$key}.failure_rate"] = expectedRate($problems, $attempted);
                $expected["{$key}.attempted"] = (string) $attempted;
            }
            $expected["{$key}.last_sent_at"] = lagosTime($week->max('sent_at')) ?? 'None in 7 days';
            $expected["{$key}.last_failure_at"] = $failure !== null ? lagosTime($failure->failed_at) : 'None in 7 days';
            if ($failure !== null) {
                $expected["{$key}.last_failure_error"] = $failure->error === 'GoText refused +2348031234567: number on DND' ? 'GoText refused +234 ••• 4567: number on DND' : $failure->error;
            }
            if ($channel === 'sms') {
                $expected["{$key}.last_status_check_at"] = lagosTime($week->max('status_checked_at')) ?? 'not in 7 days';
            }
        }

        $connections = DB::table('organization_whatsapp_connections')->get();
        $expected['whatsapp.connections.active'] = (string) $connections->where('status', 'active')->count();
        $expected['whatsapp.connections.failing'] = (string) $connections->where('status', 'failing')->count();
        $expected['whatsapp.connections.pending_test'] = (string) $connections->where('status', 'pending_test')->count();
        $expected['email.last_webhook_at'] = lagosTime(DB::table('webhook_log')->whereIn('provider', ['postmark', 'mailgun'])->max('received_at'));

        $html = Livewire::test(MessagingProvidersWidget::class)->html();

        expect(array_map(fn (array $figure): string => $figure['value'], pageFigures($html)))->toEqual($expected)
            ->and($expected)->toMatchArray([
                'provider.sms.gotext.health' => 'Failing', 'provider.sms.gotext.failure_rate' => '40.0 %',
                'provider.sms.log.health' => 'No traffic', 'provider.whatsapp.meta.health' => 'Failing',
                'whatsapp.connections.active' => '2', 'whatsapp.connections.failing' => '1',
                'email.last_webhook_at' => '10 Dec 2026, 12:00',
            ])
            ->and($html)->not->toContain('2348031234567')->not->toContain('SECRET-BODY');

        // The delivery log the figures open masks numbers in errors the same way.
        Livewire::test(ListDeliveryLog::class)->assertSee('GoText refused +234 ••• 4567: number on DND')->assertDontSee('2348031234567');
    });
});

it('renders the System operations page for the operations dashboard\'s staff and the delivery log\'s readers only', function () {
    $console = '/'.config('app.admin_path');

    foreach (['technical-admin', 'super-admin', 'operations-manager', 'support-agent'] as $role) {
        $this->flushSession();
        $this->actingAs(staffNamed(Str::studly($role), [$role]), 'staff');
        $this->get($console.'/operations')->assertOk()->assertSee('System operations');
        Livewire::test(MessagingDeliveriesWidget::class)->assertOk()->assertSee('Failure rate');
    }

    foreach (['content-manager', 'analyst', 'finance-admin', 'compliance-officer'] as $role) {
        $this->flushSession();
        $this->actingAs(staffNamed(Str::studly($role).'X', [$role]), 'staff');
        $this->get($console.'/operations')->assertForbidden();

        foreach ([MessagingDeliveriesWidget::class, MessagingProvidersWidget::class, SmsSpendWidget::class] as $widget) {
            Livewire::test($widget)->assertForbidden();
        }
    }

    // The console's home page does not carry the operations widgets.
    $this->flushSession();
    $this->actingAs(staffNamed('Ada', ['super-admin']), 'staff');
    $this->get($console)->assertOk()->assertDontSee('Provider status');
    expect(SystemOperations::canAccess())->toBeTrue();
});

it('shows the figures without links to staff who may not open the delivery log', function () {
    $role = StaffRole::query()->where('name', 'technical-admin')->firstOrFail();
    $role->revokePermissionTo('delivery_logs.view');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    deliveryFixture(Organization::factory()->create(), Organization::factory()->create());
    $this->actingAs(staffNamed('Tobi', ['technical-admin']), 'staff');

    $figures = pageFigures(Livewire::test(MessagingDeliveriesWidget::class)->html());

    expect($figures['24h.sms.failed']['value'])->toBe('1')
        ->and(array_filter($figures, fn (array $figure): bool => $figure['href'] !== null))->toBe([]);
    $this->get('/'.config('app.admin_path').'/delivery-log')->assertForbidden();
});

it('shows a quiet dashboard with nothing sent', function () {
    $this->actingAs(staffNamed('Tobi', ['technical-admin']), 'staff');
    $deliveries = pageFigures(Livewire::test(MessagingDeliveriesWidget::class)->html());

    expect($deliveries['24h.sms.queued']['value'])->toBe('0')
        ->and($deliveries['24h.sms.failure_rate']['value'])->toBe('—')
        ->and(pageFigures(Livewire::test(SmsSpendWidget::class)->html())['7d.spend.cost']['value'])->toBe(Money::zero()->format())
        ->and(pageFigures(Livewire::test(MessagingProvidersWidget::class)->html())['provider.sms.log.health']['value'])->toBe('No traffic');
});

it('judges provider health and rounds failure rates by the written thresholds, in integers', function () {
    expect(ProviderHealth::judge(0, 0))->toBe(ProviderHealth::Quiet)
        ->and(ProviderHealth::judge(0, 100))->toBe(ProviderHealth::Ok)
        ->and(ProviderHealth::judge(4, 100))->toBe(ProviderHealth::Ok)
        ->and(ProviderHealth::judge(5, 100))->toBe(ProviderHealth::Degraded)
        ->and(ProviderHealth::judge(24, 100))->toBe(ProviderHealth::Degraded)
        ->and(ProviderHealth::judge(25, 100))->toBe(ProviderHealth::Failing)
        ->and(ProviderHealth::judge(1, 1))->toBe(ProviderHealth::Failing)
        ->and(ChannelFigures::rate(1, 3))->toBe('33.3 %')
        ->and(ChannelFigures::rate(2, 3))->toBe('66.7 %')
        ->and(ChannelFigures::rate(1, 8))->toBe('12.5 %')
        ->and(ChannelFigures::rate(0, 7))->toBe('0.0 %')
        ->and(ChannelFigures::rate(7, 7))->toBe('100.0 %')
        ->and(ChannelFigures::rate(1, 0))->toBeNull()
        ->and(MessagingHealth::maskNumbers('Refused 08031234567 and +234 803 123 4567, HTTP 503'))->toBe('Refused 0803 ••• 4567 and +234 ••• 4567, HTTP 503')
        ->and(MessagingHealth::maskNumbers(null))->toBeNull();
});

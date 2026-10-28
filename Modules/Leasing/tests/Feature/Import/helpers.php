<?php

declare(strict_types=1);

use App\Enums\InvitationKind;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\WhatsApp\LogWhatsAppSender;
use App\WhatsApp\WhatsAppSender;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Modules\Import\Models\ImportBatch;
use Tests\Support\LivewireHttp;

/*
 * Helpers for the Leases import sheet's tests (P03-13a). Imports run
 * through the real engine (Modules\Import: upload, dry run, commit on the
 * sync queue, rollback) with the Properties module's import helpers; bills
 * come from the scheduled `bills-generate` job (Billing's helpers),
 * payments from PaymentService (Payments' helpers). Every name, phone and
 * email is fixed.
 */

require_once __DIR__.'/../../../../Payments/tests/Feature/helpers.php';
require_once __DIR__.'/../../../../Properties/tests/Feature/Import/helpers.php';

/** The Leases sheet's headers, as the template has them. */
const LEASES_HEADER = ['Your ID', 'Tenant', 'Building', 'Unit', 'Start date', 'End date', 'Billing cycle', 'Rent per period', 'Deposit held', 'Paid until', 'Due days after period start', 'Auto-renew', 'Contract number', 'Status', 'Notes'];

/** A Leases row from header => value, in the template's order; missing cells are empty. */
function leaseRow(array $values): array
{
    $unknown = array_diff(array_keys($values), LEASES_HEADER);
    expect($unknown)->toBe([]);

    return array_map(fn (string $header): string => (string) ($values[$header] ?? ''), LEASES_HEADER);
}

/**
 * The three ongoing-rent rows of the standard file: a yearly lease from
 * 2023 paid until 31/12/2026 (by the tenant's Your ID), a monthly one from
 * 15/03/2023 paid until 14/11/2026 (by phone), and a lease that ended in
 * January 2023 (history).
 */
function standardLeaseRows(): array
{
    return [
        leaseRow(['Your ID' => 'L-1', 'Tenant' => 'T-1', 'Building' => 'Palm Court', 'Unit' => 'Flat 1', 'Start date' => '01/01/2023', 'End date' => '31/12/2027', 'Billing cycle' => 'Yearly', 'Rent per period' => '₦1,500,000', 'Deposit held' => '150,000', 'Paid until' => '31/12/2026', 'Auto-renew' => 'No']),
        leaseRow(['Your ID' => 'L-2', 'Tenant' => '0803 555 0102', 'Building' => 'Palm Court', 'Unit' => 'Flat 2', 'Start date' => '15/03/2023', 'End date' => '14/03/2028', 'Billing cycle' => 'Monthly', 'Rent per period' => '125,000', 'Deposit held' => '250,000', 'Paid until' => '14/11/2026']),
        leaseRow(['Your ID' => 'L-3', 'Tenant' => 'T-3', 'Building' => 'Palm Court', 'Unit' => 'Flat 3', 'Start date' => '01/02/2021', 'End date' => '31/01/2023', 'Billing cycle' => 'Yearly', 'Rent per period' => '1,200,000', 'Deposit held' => '100,000', 'Paid until' => '31/01/2023', 'Contract number' => 'PC/2021/007']),
    ];
}

/**
 * A workbook with the building, its three flats, three tenants (Adaeze
 * and Bayo with an email, Chika without) and these Leases rows (the header
 * is added). `$sheets` replaces or removes (null) sheets.
 */
function ongoingRentsWorkbook(?array $leases = null, array $sheets = []): UploadedFile
{
    $all = array_merge([
        'Buildings' => [PORTFOLIO_BUILDINGS, ['B-1', 'Palm Court', '', 'Block of flats', '12 Palm Avenue, Lekki', 'Lagos', 'Eti-Osa', '', '', '', '']],
        'Units' => [PORTFOLIO_UNITS,
            ['U-1', 'Palm Court', 'Flat 1', 'Flat', '2', '2', '', '1st', '₦1,500,000', '150,000', '', ''],
            ['U-2', 'Palm Court', 'Flat 2', 'Flat', '2', '2', '', '1st', '₦1,500,000', '150,000', '', ''],
            ['U-3', 'Palm Court', 'Flat 3', 'Flat', '3', '2', '', '2nd', '₦1,800,000', '150,000', '', ''],
        ],
        'Tenants' => [PORTFOLIO_TENANTS,
            ['T-1', 'Adaeze', 'Okafor', '0803 555 0101', 'adaeze.okafor@example.com', '', 'Accountant', '', '', '', '', ''],
            ['T-2', 'Bayo', 'Adeleke', '0803 555 0102', 'bayo.adeleke@example.com', '', 'Engineer', '', '', '', '', ''],
            ['T-3', 'Chika', 'Nwosu', '0803 555 0103', '', '', 'Trader', '', '', '', '', ''],
        ],
        'Leases' => [LEASES_HEADER, ...($leases ?? standardLeaseRows())],
    ], $sheets);

    return portfolioWorkbook(array_filter($all, fn (?array $rows): bool => $rows !== null), 'ongoing-rents.xlsx');
}

/** The dry run's problems on the Leases sheet as [row, header, letter, severity, message]. */
function leaseIssues(ImportBatch $batch): array
{
    return portfolioIssues($batch, 'leases');
}

/** The WhatsApp messages the stand-in sender kept (P03-01b), as [event, to]. */
function leaseImportWhatsApp(): Collection
{
    $sender = app(WhatsAppSender::class);

    return collect($sender instanceof LogWhatsAppSender ? $sender->sent() : [])
        ->map(fn (array $sent): array => [$sent['message']->event, $sent['message']->to]);
}

/** The "Invite tenants now" panel's snapshot on the import's report page, as $user. */
function invitationsPanel(User $user, ImportBatch $batch, string $slug = 'acme-homes'): string
{
    leasesFreshRequest();
    $html = (string) test()->actingAs($user)->get("/o/{$slug}/imports/{$batch->id}")->assertOk()->getContent();
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);

    foreach ($matches[1] as $raw) {
        $snapshot = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5);

        if ((json_decode($snapshot, true)['memo']['name'] ?? null) === 'tenants::import-invitations') {
            leasesFreshRequest();
            test()->actingAs($user);

            return $snapshot;
        }
    }

    throw new LogicException('No "Invite tenants now" panel on the page.');
}

/** One action of the panel, as the browser sends it. */
function invitationsCall(User $user, ImportBatch $batch, string $method, string $slug = 'acme-homes'): TestResponse
{
    return LivewireHttp::update(test(), invitationsPanel($user, $batch, $slug), [], [[$method]]);
}

/** A value of the panel's state after an update. */
function invitationsState(TestResponse $response, string $key): mixed
{
    return json_decode(LivewireHttp::next($response), true)['data'][$key] ?? null;
}

/** The organization's tenant invitations (Core `invitations`, kind `tenant`), oldest first. */
function leaseImportInvitations(Organization $organization): Collection
{
    return inOrganization($organization, fn () => Invitation::query()->where('kind', InvitationKind::Tenant)->orderBy('id')->get());
}

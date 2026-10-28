<?php

declare(strict_types=1);

use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Handovers\Handovers\HandoverCredits;
use Modules\Handovers\Handovers\HandoverQuotes;
use Modules\Handovers\Handovers\HandoverService;
use Modules\Handovers\Handovers\IncomingHandovers;
use Modules\Handovers\Models\HandoverTenant;
use Modules\Handovers\Models\ManagementHandover;
use Modules\Handovers\Notifications\HandoverOffered;
use Modules\Properties\Models\Property;
use Tests\Support\LivewireHttp;

/*
 * Helpers for the Handovers module's tests (P03-14). Every step goes
 * through HandoverService, in the organization context each side works
 * in, as the pages do; pages through HTTP and Livewire update requests.
 * The world is in handover-world.php.
 */

require_once __DIR__.'/handover-world.php';
require_once __DIR__.'/../../../../tests/Support/impersonation.php';

/** $by (in $from) offers these buildings to $to. */
function offerHandover(User $by, Organization $from, Organization $to, array $buildings, int $noticeDays = 30): ManagementHandover
{
    return inOrganization($from, fn (): ManagementHandover => app(HandoverService::class)->offer($by, $to, array_map(fn (Property|int $building): int => $building instanceof Property ? $building->id : $building, $buildings), $noticeDays));
}

/** The handover as the receiving organization sees it (null when not addressed to it). */
function incomingHandover(Organization $to, ManagementHandover|int $handover): ?ManagementHandover
{
    return inOrganization($to, fn (): ?ManagementHandover => app(IncomingHandovers::class)->find($handover instanceof ManagementHandover ? $handover->id : $handover));
}

/** The receiving organization's bill after the handover, as the accept screen shows it now. */
function billAfter(ManagementHandover $handover): Money
{
    $handover = storedHandover($handover);
    $quotes = app(HandoverQuotes::class);

    return $quotes->change($handover->toOrganization, array_sum($quotes->unitsIn($handover->organization, $handover->propertyIds())))->after->amount;
}

/** $by (in $to) accepts, at the amount the accept screen shows now unless given. */
function acceptHandover(User $by, Organization $to, ManagementHandover $handover, ?Money $shown = null): ManagementHandover
{
    $shown ??= billAfter($handover);

    return inOrganization($to, fn (): ManagementHandover => app(HandoverService::class)->accept($by, $handover, $shown));
}

function declineHandover(User $by, Organization $to, ManagementHandover $handover): ManagementHandover
{
    return inOrganization($to, fn (): ManagementHandover => app(HandoverService::class)->decline($by, $handover));
}

function withdrawHandover(User $by, Organization $from, ManagementHandover $handover): ManagementHandover
{
    return inOrganization($from, fn (): ManagementHandover => app(HandoverService::class)->cancel($by, $handover));
}

function giveHandoverNotice(User $by, Organization $side, ManagementHandover $handover): ManagementHandover
{
    return inOrganization($side, fn (): ManagementHandover => app(HandoverService::class)->giveNotice($by, $handover));
}

function withdrawHandoverNotice(User $by, Organization $side, ManagementHandover $handover): ManagementHandover
{
    return inOrganization($side, fn (): ManagementHandover => app(HandoverService::class)->withdrawNotice($by, $handover));
}

/** The handover as stored now (read in its own organization), with what the pages show. */
function storedHandover(ManagementHandover $handover): ManagementHandover
{
    $from = Organization::query()->findOrFail($handover->organization_id);

    return inOrganization($from, fn (): ManagementHandover => ManagementHandover::query()
        ->with(['organization', 'toOrganization', 'landlordOrganization', 'initiatedBy', 'buildings', 'tenants'])
        ->findOrFail($handover->id));
}

/** The settlement line of a tenant (by their landlord-side record). */
function creditLine(ManagementHandover $handover, int $tenantProfileId): HandoverTenant
{
    return storedHandover($handover)->tenants->firstWhere('tenant_profile_id', $tenantProfileId) ?? throw new LogicException('No such tenant line.');
}

/** The landlord-side credit of each line now. */
function liveCredits(ManagementHandover $handover): array
{
    return app(HandoverCredits::class)->liveCredits(storedHandover($handover));
}

/**
 * How many rows of each table each organization holds, read without any
 * scope (history must not move: compared before and after).
 *
 * @param  list<string>  $tables
 * @return array<string, array<int, int>> table => organization id => rows
 */
function rowsByOrganization(array $tables): array
{
    $counts = [];

    foreach ($tables as $table) {
        $counts[$table] = DB::table($table)->selectRaw('organization_id, count(*) as aggregate')->groupBy('organization_id')->orderBy('organization_id')->pluck('aggregate', 'organization_id')
            ->map(fn (mixed $count): int => (int) $count)->all();
    }

    return $counts;
}

/** Which organization holds these rows of a table, read without any scope. */
function organizationsOf(string $table, array $ids): array
{
    return DB::table($table)->whereIn('id', $ids)->orderBy('id')->pluck('organization_id', 'id')->map(fn (mixed $id): int => (int) $id)->all();
}

/** Runs a handover job as the scheduler does (handovers-expire at :20, handovers-end at :25 every hour). */
function runHandoverJob(string $name): void
{
    billingScheduledEvent($name, $name === 'handovers-expire' ? '20 * * * *' : '25 * * * *')->run(app());
}

/** A fresh request, as production starts every one. */
function handoversFreshRequest(): void
{
    billsFreshRequest();
}

/** A handovers page as this user ($path after /handovers). */
function handoversPage(User $user, string $slug, string $path = ''): TestResponse
{
    handoversFreshRequest();

    return test()->actingAs($user)->get("/o/{$slug}/handovers{$path}");
}

/** One Livewire update of a handovers page the user opened. */
function handoversCall(User $user, string $slug, string $path, array $updates = [], array $calls = []): TestResponse
{
    $snapshot = LivewireHttp::snapshot(handoversPage($user, $slug, $path)->assertOk());
    handoversFreshRequest();
    test()->actingAs($user);

    return LivewireHttp::update(test(), $snapshot, $updates, $calls);
}

/** The token in the newest offer email to this user. */
function handoverToken(User $owner): string
{
    $sent = Notification::sent($owner, HandoverOffered::class);
    expect($sent)->not->toBeEmpty();
    $last = $sent->last();
    assert($last instanceof HandoverOffered);

    return Str::afterLast($last->url(), '/');
}

/**
 * A lighter world for the state machine and page tests: Eze Properties
 * (a property owner's organization; owners Ngozi Eze and Emeka Eze) with
 * Palm Court (3 units) and Lagoon View (2 units), and Adeyemi Realty (an
 * agency on Starter with 9 units of its own; owners Ada Owner and Chika
 * Owner). No leases: the move has only buildings and units to carry.
 */
function handoverLightWorld(): void
{
    $test = test();
    (function () {
        Notification::fake();
        Storage::fake('media');
        seedOrganizationRoles();
        $this->travelTo('2027-01-04 09:00:00');

        $this->landlord = Organization::factory()->create(['slug' => 'eze-properties', 'name' => 'Eze Properties', 'type' => OrganizationType::PropertyOwner, 'platform_covered' => true]);
        $this->ngozi = joinAs(User::factory()->create(['name' => 'Ngozi Eze', 'email' => 'ngozi.eze@example.com', 'phone' => '+2348051112222']), $this->landlord);
        $this->emeka = joinAs(User::factory()->create(['name' => 'Emeka Eze', 'email' => 'emeka.eze@example.com', 'phone' => '+2348051113333']), $this->landlord);

        $this->agency = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd', 'type' => OrganizationType::Agency, 'signup_plan_key' => 'starter']);
        $this->ada = joinAs(User::factory()->create(['name' => 'Ada Owner', 'email' => 'ada.owner@example.com', 'phone' => '+2348020000001']), $this->agency);
        $this->chika = joinAs(User::factory()->create(['name' => 'Chika Owner', 'email' => 'chika.owner@example.com', 'phone' => '+2348020000002']), $this->agency);

        $this->palm = buildingIn($this->landlord, ['name' => 'Palm Court', 'slug' => 'palm-court', 'address' => '22 Fola Osibo Road, Lekki Phase 1']);
        $this->lagoon = buildingIn($this->landlord, ['name' => 'Lagoon View', 'slug' => 'lagoon-view', 'address' => '4 Ozumba Mbadiwe Avenue, Victoria Island']);

        foreach (['Flat 1', 'Flat 2', 'Flat 3'] as $label) {
            unitIn($this->landlord, $this->palm, ['unit_label' => $label]);
        }

        foreach (['Suite 1', 'Suite 2'] as $label) {
            unitIn($this->landlord, $this->lagoon, ['unit_label' => $label]);
        }

        $office = buildingIn($this->agency, ['name' => 'Office Block', 'slug' => 'office-block', 'address' => '1 Marina Road, Lagos Island']);

        foreach (range(1, 9) as $n) {
            unitIn($this->agency, $office, ['unit_label' => "Office {$n}"]);
        }
    })->call($test);
}

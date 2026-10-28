<?php

declare(strict_types=1);

use App\Platform\Filament\Resources\Handovers\Pages\ListHandovers;
use App\Platform\Handovers\HandoverOverview;
use Filament\Facades\Filament;
use Livewire\Livewire;

require_once __DIR__.'/helpers.php';

/*
 * BILLING_PAYMENTS.md § 7.7 rule 3: the platform console shows pending and
 * ending handovers (support visibility), read-only, to staff holding
 * `customers.view`.
 */

beforeEach(function () {
    handoverLightWorld();
    Filament::setCurrentPanel(Filament::getPanel('console'));
    seedStaffRoles();
    $this->console = '/'.config('app.admin_path');
});

it('lists pending and ending handovers for support, and nothing else', function () {
    $pending = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm]);
    $ending = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->lagoon]);
    acceptHandover($this->ada, $this->agency, incomingHandover($this->agency, $ending));
    giveHandoverNotice($this->ngozi, $this->landlord, $ending);
    $third = buildingIn($this->landlord, ['name' => 'Cedar House', 'slug' => 'cedar-house', 'address' => '9 Bourdillon Road, Ikoyi']);
    $declined = offerHandover($this->ngozi, $this->landlord, $this->agency, [$third]);
    declineHandover($this->ada, $this->agency, incomingHandover($this->agency, $declined));

    $this->actingAs(staffNamed('Sade', ['support-agent']), 'staff');
    $this->get($this->console.'/handovers')->assertOk();

    Livewire::test(ListHandovers::class)
        ->assertCanSeeTableRecords(HandoverOverview::query()->whereKey([$pending->id, $ending->id])->get())
        ->assertCanNotSeeTableRecords(HandoverOverview::query()->whereKey([$declined->id])->get())
        ->assertSee('Eze Properties')
        ->assertSee('Adeyemi Realty Ltd')
        ->assertSee('Waiting for the agency')
        ->assertSee('Ending (notice given)');

    // Read-only: the console never changes a handover.
    expect(fn () => HandoverOverview::query()->findOrFail($pending->id)->forceFill(['status' => 'active'])->save())->toThrow(LogicException::class);
});

it('refuses staff without customers.view', function (string $role) {
    $this->actingAs(staffNamed('Sade', [$role]), 'staff')->get($this->console.'/handovers')->assertForbidden();
})->with(['analyst', 'technical-admin', 'content-manager']);

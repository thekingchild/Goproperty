<?php

declare(strict_types=1);

namespace Modules\Handovers\Livewire;

use App\Exports\Livewire\WithListExports;
use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Handovers\Enums\RentCollection;
use Modules\Handovers\Handovers\HandoverRefused;
use Modules\Handovers\Handovers\HandoverService;
use Modules\Handovers\Handovers\HandoverTargets;
use Modules\Handovers\Handovers\IncomingHandovers;
use Modules\Handovers\Handovers\OfferableBuildings;
use Modules\Handovers\Lists\HandoversMadeList;
use Modules\Handovers\Models\ManagementHandover;

/**
 * Handovers, /o/{organization}/handovers (BILLING_PAYMENTS.md § 7): for a
 * landlord's organization, the form to hand chosen buildings to an agency
 * already on GoProperty (its web address or an owner's email; only
 * active buildings in no open handover; who collects rent; the notice
 * period), and the handovers it offered (with "Withdraw" while waiting);
 * for every organization, the handovers offered to it (the inbox, and the
 * buildings it manages for landlords), each opening its page
 * (HandoverShow). For an agency's organization (P04-04, § 7.1), the form
 * to return buildings it holds without a handover to their landlord's own
 * organization (its web address or an owner's email), for good. Owners
 * only (`handovers.manage`).
 *
 * Runs inside the `organization` middleware, replayed for Livewire
 * updates; the component refuses any other organization. Every action
 * checks ManagementHandoverPolicy (the service checks again), is blocked
 * while GoProperty staff impersonate, and is refused in a suspended
 * organization. Offers are limited per organization (the form must not
 * become a way to probe for accounts).
 */
#[Layout('layouts::app')]
#[Title('Handovers')]
final class HandoverIndex extends Component
{
    use WithListExports;

    /** Offer attempts (found or not) per organization per hour. */
    public const int ATTEMPTS_PER_HOUR = 10;

    public const string NOTICE = 'handovers.notice';

    #[Locked]
    public int $organizationId;

    public string $target = '';

    /** @var array<int|string, int|string> the chosen building ids, as the browser sends them */
    public array $buildings = [];

    public string $noticeDays = '30';

    public string $rentCollection = 'agency';

    /** The one-way return's form (§ 7.1): the landlord's organization and the buildings. */
    public string $returnTarget = '';

    /** @var array<int|string, int|string> */
    public array $returnBuildings = [];

    #[Locked]
    public ?string $notice = null;

    public function boot(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    public function mount(OrganizationContext $context): void
    {
        $this->organizationId = $context->id();
        $this->authorize('viewAny', ManagementHandover::class);
        $this->noticeDays = (string) HandoverService::DEFAULT_NOTICE_DAYS;
        $notice = session(self::NOTICE);
        $this->notice = is_string($notice) ? $notice : null;
    }

    #[BlockedWhileImpersonating]
    public function offer(HandoverService $handovers, HandoverTargets $targets): void
    {
        $this->authorize('create', ManagementHandover::class);
        $this->validate([
            'target' => ['required', 'string', 'max:255'],
            'buildings' => ['required', 'array', 'min:1', 'max:200'],
            'buildings.*' => ['integer'],
            'noticeDays' => ['required', 'integer', 'between:0,'.HandoverService::MAX_NOTICE_DAYS],
            'rentCollection' => ['required', 'in:'.implode(',', RentCollection::values())],
        ], [
            'buildings.required' => __('Choose at least one building to hand over.'),
        ], ['target' => 'agency\'s web address or email', 'noticeDays' => 'notice period']);
        $this->notice = null;

        $key = 'handovers:offers:'.$this->organizationId;

        if (RateLimiter::tooManyAttempts($key, self::ATTEMPTS_PER_HOUR)) {
            $this->addError('target', __('Too many offers were tried. Try again in an hour.'));

            return;
        }

        RateLimiter::hit($key, 3600);
        $agency = $targets->find($this->target);

        if ($agency === null) {
            $this->addError('target', __(HandoverTargets::NOT_FOUND));

            return;
        }

        try {
            $handovers->offer(
                $this->user(),
                $agency,
                array_map('intval', array_values($this->buildings)),
                (int) $this->noticeDays,
                RentCollection::from($this->rentCollection),
            );
        } catch (HandoverRefused $refused) {
            $this->addError('buildings', $refused->getMessage());

            return;
        }

        $this->resetValidation();
        $this->reset('target', 'buildings');
        $this->noticeDays = (string) HandoverService::DEFAULT_NOTICE_DAYS;
        $this->notice = __('Your buildings were offered to :agency. Its owners have :days days to accept or decline; you will be told either way.', ['agency' => $agency->name, 'days' => HandoverService::VALID_DAYS]);
    }

    /** Return chosen buildings to their landlord's own organization, for good (BILLING_PAYMENTS.md § 7.1). */
    #[BlockedWhileImpersonating]
    public function offerReturn(HandoverService $handovers, HandoverTargets $targets): void
    {
        $this->authorize('offerReturn', ManagementHandover::class);
        $this->validate([
            'returnTarget' => ['required', 'string', 'max:255'],
            'returnBuildings' => ['required', 'array', 'min:1', 'max:200'],
            'returnBuildings.*' => ['integer'],
        ], [
            'returnBuildings.required' => __('Choose at least one building to return.'),
        ], ['returnTarget' => 'landlord\'s web address or email']);
        $this->notice = null;

        $key = 'handovers:offers:'.$this->organizationId;

        if (RateLimiter::tooManyAttempts($key, self::ATTEMPTS_PER_HOUR)) {
            $this->addError('returnTarget', __('Too many offers were tried. Try again in an hour.'));

            return;
        }

        RateLimiter::hit($key, 3600);
        $landlord = $targets->findLandlord($this->returnTarget);

        if ($landlord === null) {
            $this->addError('returnTarget', __(HandoverTargets::LANDLORD_NOT_FOUND));

            return;
        }

        try {
            $handovers->offerReturn($this->user(), $landlord, array_map('intval', array_values($this->returnBuildings)));
        } catch (HandoverRefused $refused) {
            $this->addError('returnBuildings', $refused->getMessage());

            return;
        }

        $this->resetValidation();
        $this->reset('returnTarget', 'returnBuildings');
        $this->notice = __('You offered to return the buildings to :landlord. Its owners have :days days to accept or decline; you will be told either way.', ['landlord' => $landlord->name, 'days' => HandoverService::VALID_DAYS]);
    }

    #[BlockedWhileImpersonating]
    public function withdraw(int $handoverId, HandoverService $handovers): void
    {
        $handover = ManagementHandover::query()->with('toOrganization')->findOrFail($handoverId);
        $this->authorize('cancel', $handover);
        $this->notice = null;

        try {
            $handovers->cancel($this->user(), $handover);
        } catch (HandoverRefused $refused) {
            $this->addError('outgoing', $refused->getMessage());

            return;
        }

        $this->resetValidation();
        $this->notice = __('Your offer to :agency was withdrawn.', ['agency' => $handover->toOrganization->name]);
    }

    public function render(OrganizationContext $context, IncomingHandovers $incoming, HandoversMadeList $made, OfferableBuildings $offerable): View
    {
        $canOffer = $this->user()->can('create', ManagementHandover::class);
        $canOfferReturn = $this->user()->can('offerReturn', ManagementHandover::class);
        $options = $canOffer || $canOfferReturn ? $offerable->options() : [];

        return view('handovers::livewire.handover-index', [
            'organization' => $context->get(),
            'canOffer' => $canOffer,
            'canOfferReturn' => $canOfferReturn,
            'buildingOptions' => $canOffer ? $options : [],
            'returnOptions' => $canOfferReturn ? $options : [],
            'outgoing' => IncomingHandovers::ordered($made->query($this->user(), [])->limit(HandoversMadeList::PAGE_LIMIT)->get()->toBase()),
            'incoming' => $incoming->recent(),
            'validDays' => HandoverService::VALID_DAYS,
            'maxNotice' => HandoverService::MAX_NOTICE_DAYS,
        ]);
    }

    /** Both lists export (P02-07, Lists\HandoversMadeList and …ReceivedList); no filters. */
    protected function listExports(): array
    {
        return ['handovers_made' => [], 'handovers_received' => []];
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}

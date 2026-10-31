<?php

declare(strict_types=1);

namespace App\Platform\Approvals;

use App\Models\ApprovalRequest;
use App\Models\PlatformStaff;
use App\Models\StaffStatus;
use App\Platform\Staff\StaffAudit;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The two-person rule (PLATFORM_CONSOLE.md § 1): request → approve, reject
 * or expire. A request is never decided by the staff member who made it,
 * and the decider needs the action's permission (ApprovalAction) plus
 * whatever the action's handler adds. Every step is audited.
 *
 *     $request = $approvals->request(ApprovalAction::Refund, $staff, $refund, ['amount_kobo' => …], 'Duplicate charge');
 *     $approvals->approve($request, $otherStaff);   // runs the handler
 */
final class ApprovalService
{
    /** @var array<string, class-string<ApprovalHandler>|ApprovalHandler> */
    private array $handlers = [];

    public function __construct(
        private readonly Container $container,
        private readonly StaffAudit $audit,
    ) {}

    /**
     * Registers what runs when a request for the action is approved
     * (resolved from the container when first needed).
     *
     * @param  class-string<ApprovalHandler>|ApprovalHandler  $handler
     */
    public function handle(ApprovalAction $action, string|ApprovalHandler $handler): void
    {
        $this->handlers[$action->value] = $handler;
    }

    private function handler(ApprovalAction $action): ?ApprovalHandler
    {
        $handler = $this->handlers[$action->value] ?? null;

        if (is_string($handler)) {
            $handler = $this->handlers[$action->value] = $this->container->make($handler);
        }

        return $handler;
    }

    /**
     * Opens a request. An identical pending request (same action, subject
     * and payload) is refused, so a double click cannot queue two.
     *
     * @param  array<string, mixed>  $payload
     */
    public function request(ApprovalAction $action, PlatformStaff $requester, ?Model $subject, string $summary, array $payload = [], ?string $reason = null): ApprovalRequest
    {
        if ($requester->status !== StaffStatus::Active) {
            throw new ApprovalRefused('Only active staff can make requests.');
        }

        return DB::transaction(function () use ($action, $requester, $subject, $summary, $payload, $reason): ApprovalRequest {
            $duplicate = ApprovalRequest::query()
                ->where('action', $action)
                ->where('status', ApprovalStatus::Pending)
                ->where('subject_type', $subject?->getMorphClass())
                ->where('subject_id', $subject?->getKey())
                ->lockForUpdate()
                ->get()
                ->contains(fn (ApprovalRequest $open): bool => ! $open->hasExpired() && $open->payload == $payload);

            if ($duplicate) {
                throw new ApprovalRefused('The same request is already waiting for approval.');
            }

            $request = new ApprovalRequest;
            $request->forceFill([
                'action' => $action,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'payload' => $payload,
                'summary' => mb_substr($summary, 0, 255),
                'reason' => $reason,
                'requested_by_staff_id' => $requester->id,
                'status' => ApprovalStatus::Pending,
                'expires_at' => now()->add($action->lifetime()),
            ])->save();

            $this->audit->record('approval.requested', $request, $requester, "approval requested: {$summary}", [
                'action' => $action->value,
                'payload' => $payload,
            ]);

            return $request;
        });
    }

    /**
     * Why this staff member may not decide the request, or null when they may.
     * The same rule for approving and rejecting.
     */
    public function refusalFor(PlatformStaff $decider, ApprovalRequest $request): ?string
    {
        if ($decider->getKey() === $request->requested_by_staff_id) {
            return 'You cannot decide your own request: another staff member must.';
        }

        if (! $request->isPending()) {
            return 'This request has already been decided.';
        }

        if ($request->hasExpired()) {
            return 'This request has expired.';
        }

        if (! $decider->holdsStaffPermission($request->action->approverPermission())) {
            return 'You do not have the permission needed to decide this request.';
        }

        return $this->handler($request->action)?->refusalFor($decider, $request);
    }

    public function canDecide(PlatformStaff $decider, ApprovalRequest $request): bool
    {
        return $this->refusalFor($decider, $request) === null;
    }

    public function approve(ApprovalRequest $request, PlatformStaff $approver, ?string $note = null): ApprovalRequest
    {
        return $this->decide($request, $approver, ApprovalStatus::Approved, $note);
    }

    public function reject(ApprovalRequest $request, PlatformStaff $decider, ?string $note = null): ApprovalRequest
    {
        return $this->decide($request, $decider, ApprovalStatus::Rejected, $note);
    }

    /**
     * Marks pending requests past their time as expired.
     *
     * @return int how many expired
     */
    public function expireStale(): int
    {
        $expired = 0;

        ApprovalRequest::query()
            ->where('status', ApprovalStatus::Pending)
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->each(function (ApprovalRequest $request) use (&$expired): void {
                DB::transaction(function () use ($request, &$expired): void {
                    $locked = ApprovalRequest::query()->whereKey($request->getKey())->lockForUpdate()->first();

                    if ($locked !== null && $locked->isPending() && $locked->hasExpired()) {
                        $this->markExpired($locked);
                        $expired++;
                    }
                });
            });

        return $expired;
    }

    private function decide(ApprovalRequest $request, PlatformStaff $decider, ApprovalStatus $decision, ?string $note): ApprovalRequest
    {
        $outcome = DB::transaction(function () use ($request, $decider, $decision, $note): ApprovalRequest|string {
            $locked = ApprovalRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isPending() && $locked->hasExpired()) {
                $this->markExpired($locked);

                // Committed: the request stays expired even though the decision is refused.
                return 'This request has expired.';
            }

            $refusal = $this->refusalFor($decider, $locked);

            if ($refusal !== null) {
                throw new ApprovalRefused($refusal);
            }

            $handler = $this->handler($locked->action);

            if ($decision === ApprovalStatus::Approved) {
                $handler?->apply($locked, $decider);
            } elseif ($handler instanceof HandlesNotApproved) {
                $handler->notApproved($locked);
            }

            $locked->forceFill([
                'status' => $decision,
                $decision === ApprovalStatus::Approved ? 'approved_by_staff_id' : 'rejected_by_staff_id' => $decider->id,
                'decision_note' => $note !== null ? mb_substr($note, 0, 500) : null,
                'decided_at' => now(),
            ])->save();

            $this->audit->record("approval.{$decision->value}", $locked, $decider, "approval {$decision->value}: {$locked->summary}", [
                'action' => $locked->action->value,
                'requested_by_staff_id' => $locked->requested_by_staff_id,
                'note' => $locked->decision_note,
            ]);

            return $locked;
        });

        if (is_string($outcome)) {
            throw new ApprovalRefused($outcome);
        }

        $request->setRawAttributes($outcome->getAttributes(), true);

        return $request;
    }

    private function markExpired(ApprovalRequest $request): void
    {
        $request->forceFill(['status' => ApprovalStatus::Expired, 'decided_at' => now()])->save();

        $handler = $this->handler($request->action);

        if ($handler instanceof HandlesNotApproved) {
            $handler->notApproved($request);
        }

        $this->audit->record('approval.expired', $request, null, "approval expired: {$request->summary}", [
            'action' => $request->action->value,
            'requested_by_staff_id' => $request->requested_by_staff_id,
        ]);
    }
}

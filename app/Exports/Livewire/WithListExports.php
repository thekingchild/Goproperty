<?php

declare(strict_types=1);

namespace App\Exports\Livewire;

use App\Exports\ListExportRefused;
use App\Exports\ListExportRequests;
use App\Exports\ListExports;
use App\Models\User;
use App\Tenancy\AllowedWhileSuspended;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The Export button of a workspace list (AGENT.md § 5: every list uses
 * it). The component says which lists it shows and their current filters
 * (listExports(), the same values its own query uses); the view puts
 * `<x-list-export list="owners" />` where the button goes. Pressing it
 * calls export(), which checks the list's policy, the rate limit and the
 * size (ListExportRequests::start) and sends the browser to the one-time
 * download, which streams the file; a refusal shows under the button.
 *
 * export() changes nothing, so it is marked #[AllowedWhileSuspended]: it
 * works in a suspended organization and in a read-only support session
 * (a list of people's contact details is still refused to staff by its
 * policy).
 */
trait WithListExports
{
    /**
     * The lists this page shows, each with the filters it is showing now:
     * ['owners' => ['search' => $this->search, 'archived' => $this->archived]].
     *
     * @return array<string, array<string, mixed>>
     */
    abstract protected function listExports(): array;

    #[AllowedWhileSuspended]
    public function export(string $list, string $format = 'csv'): void
    {
        $filters = $this->listExports()[$list] ?? null;
        abort_if($filters === null, 404);
        $this->resetErrorBag('export.'.$list);

        try {
            $url = app(ListExportRequests::class)->start($this->exportingUser(), $list, $format, $filters);
        } catch (ListExportRefused $refused) {
            $this->addError('export.'.$list, $refused->getMessage());

            return;
        }

        $this->redirect($url);
    }

    /** Whether to show the Export button of one of this page's lists. */
    public function canExport(string $list): bool
    {
        $filters = $this->listExports()[$list] ?? null;
        $export = $filters === null ? null : app(ListExports::class)->find($list);

        if ($export === null) {
            return false;
        }

        try {
            $export->authorize($this->exportingUser(), $export->filters($filters));
        } catch (AuthorizationException|ModelNotFoundException|HttpExceptionInterface) {
            return false;
        }

        return true;
    }

    private function exportingUser(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}

<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\PlatformPayments\Pages;

use App\Platform\Billing\PlatformPaymentRecord;
use App\Platform\Filament\Resources\PlatformPayments\PlatformPaymentResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * One payment into GoProperty's account, its refunds and Refund (P06-07b).
 *
 * @property-read PlatformPaymentRecord $record
 */
final class ViewPlatformPayment extends ViewRecord
{
    protected static string $resource = PlatformPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PlatformPaymentResource::refundAction()
                ->record(fn (): PlatformPaymentRecord => $this->record)
                ->after(fn () => $this->refreshRecord()),
        ];
    }

    private function refreshRecord(): void
    {
        $fresh = PlatformPaymentResource::getEloquentQuery()->find($this->record->getKey());

        if ($fresh instanceof PlatformPaymentRecord) {
            $this->record = $fresh;
        }
    }
}

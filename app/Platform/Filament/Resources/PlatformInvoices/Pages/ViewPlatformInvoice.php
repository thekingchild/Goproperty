<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\PlatformInvoices\Pages;

use App\Platform\Billing\PlatformInvoiceRecord;
use App\Platform\Filament\Resources\PlatformInvoices\PlatformInvoiceResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * One GoProperty invoice: its lines, payments and refunds, and Send invoice
 * email again (P06-07b).
 *
 * @property-read PlatformInvoiceRecord $record
 */
final class ViewPlatformInvoice extends ViewRecord
{
    protected static string $resource = PlatformInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [PlatformInvoiceResource::resendAction()->record(fn (): PlatformInvoiceRecord => $this->record)];
    }
}

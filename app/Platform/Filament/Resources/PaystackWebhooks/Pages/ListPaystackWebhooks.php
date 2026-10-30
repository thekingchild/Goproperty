<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\PaystackWebhooks\Pages;

use App\Platform\Filament\Resources\PaystackWebhooks\PaystackWebhookResource;
use Filament\Resources\Pages\ListRecords;

final class ListPaystackWebhooks extends ListRecords
{
    protected static string $resource = PaystackWebhookResource::class;

    protected ?string $subheading = 'Every call to GoProperty\'s own Paystack webhook (SMS credit bundles and subscription invoices). A call whose signature did not match is kept and never processed. Processing always asks Paystack about the payment first, and settles it at most once, so replaying a call never pays an invoice twice.';

    protected function getHeaderActions(): array
    {
        return [];
    }
}

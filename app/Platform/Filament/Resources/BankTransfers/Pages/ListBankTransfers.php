<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\BankTransfers\Pages;

use App\Platform\Filament\Resources\BankTransfers\BankTransferResource;
use Filament\Resources\Pages\ListRecords;

final class ListBankTransfers extends ListRecords
{
    protected static string $resource = BankTransferResource::class;

    protected ?string $subheading = 'Proofs owners sent for bank transfers into GoProperty\'s account, waiting ones first. A proof never pays an invoice by itself: confirm it only once the invoice\'s whole total is in GoProperty\'s account; otherwise reject it with a reason the customer will see.';

    protected function getHeaderActions(): array
    {
        return [];
    }
}

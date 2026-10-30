<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\EmailTemplates\Pages;

use App\Platform\Filament\Resources\EmailTemplates\EmailTemplateResource;
use Filament\Resources\Pages\ListRecords;

final class ListEmailTemplates extends ListRecords
{
    protected static string $resource = EmailTemplateResource::class;

    protected ?string $subheading = 'GoProperty\'s emails. Agencies never change the words: their name, logo and colour come in through the layout and the placeholders.';

    protected function getHeaderActions(): array
    {
        return [];
    }
}

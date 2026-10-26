<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\CustomerUsers;

use App\Platform\Customers\CustomerUser;
use App\Platform\Filament\Resources\CustomerUsers\Pages\ListCustomerUsers;
use App\Platform\Filament\Resources\CustomerUsers\Pages\ViewCustomerUser;
use App\Platform\Filament\Resources\CustomerUsers\RelationManagers\OrganizationsRelationManager;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Console → Customers → Users: the users lookup (PLATFORM_CONSOLE.md § 1,
 * "customer lookup"). Find a person by name, email or phone and see every
 * organization they belong to with their roles there. Read-only; checked by
 * CustomerUserPolicy (`customers.view`).
 */
final class CustomerUserResource extends Resource
{
    protected static ?string $model = CustomerUser::class;

    protected static ?string $slug = 'customer-users';

    protected static ?string $modelLabel = 'user';

    protected static ?string $pluralModelLabel = 'users';

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isGloballySearchable = false;

    protected static string|UnitEnum|null $navigationGroup = 'Customers';

    protected static ?string $navigationLabel = 'Users';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('organizations:id,name'))
            ->columns([
                TextColumn::make('name')
                    ->searchable(['name', 'email', 'phone'])
                    ->sortable()
                    ->description(fn (CustomerUser $record): ?string => $record->email),
                TextColumn::make('phone')->placeholder('—'),
                TextColumn::make('organizations.name')->label('Organizations')->badge()->limitList(3)->placeholder('None'),
                IconColumn::make('email_verified_at')->label('Verified')->boolean()->state(fn (CustomerUser $record): bool => $record->email_verified_at !== null),
                TextColumn::make('created_at')->label('Joined')->date()->sortable(),
            ])
            ->filters([
                TernaryFilter::make('email_verified_at')->label('Email verified')->nullable(),
            ])
            ->recordActions([ViewAction::make()])
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('User')
                ->columns(3)
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('email')->placeholder('—'),
                    TextEntry::make('phone')->placeholder('—'),
                    TextEntry::make('email_verified_at')->label('Email verified')->dateTime()->placeholder('Not verified'),
                    IconEntry::make('two_factor')
                        ->label('2FA')
                        ->state(fn (CustomerUser $record): bool => $record->two_factor_confirmed_at !== null)
                        ->boolean(),
                    TextEntry::make('created_at')->label('Joined')->dateTime(),
                ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [OrganizationsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomerUsers::route('/'),
            'view' => ViewCustomerUser::route('/{record}'),
        ];
    }
}

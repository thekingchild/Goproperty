<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\EmailTemplates;

use App\Mail\Templates\TemplateCategory;
use App\Models\EmailTemplate;
use App\Platform\Filament\Resources\EmailTemplates\Pages\EditEmailTemplate;
use App\Platform\Filament\Resources\EmailTemplates\Pages\ListEmailTemplates;
use App\Platform\Filament\Resources\EmailTemplates\RelationManagers\VersionsRelationManager;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Console → Content → Email templates (P05-04; PLATFORM_CONSOLE.md § 5):
 * GoProperty's emails by key and category, each edited in Markdown with
 * whitelisted placeholders, a live preview, a test email to oneself, and
 * its versions with rollback. `templates.edit` (EmailTemplatePolicy).
 * Templates come from code (App\Mail\Templates\TemplateCatalog): none is
 * created or deleted here.
 */
final class EmailTemplateResource extends Resource
{
    protected static ?string $model = EmailTemplate::class;

    protected static ?string $slug = 'email-templates';

    protected static ?string $modelLabel = 'email template';

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isGloballySearchable = false;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Email templates';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('currentVersion.createdBy');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->description(fn (EmailTemplate $record): string => $record->key),
                TextColumn::make('category')->badge()->formatStateUsing(fn (TemplateCategory $state): string => $state->label()),
                TextColumn::make('currentVersion.subject')->label('Subject')->limit(60)->wrap(),
                TextColumn::make('currentVersion.version')->label('Version'),
                TextColumn::make('currentVersion.created_at')->label('Changed')->since()
                    ->description(fn (EmailTemplate $record): string => $record->currentVersion?->createdBy->name ?? 'From the code'),
                IconColumn::make('active')->boolean(),
            ])
            ->filters([
                SelectFilter::make('category')->options(TemplateCategory::options()),
            ])
            ->recordActions([EditAction::make()])
            ->defaultSort('key');
    }

    public static function getRelations(): array
    {
        return [VersionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmailTemplates::route('/'),
            'edit' => EditEmailTemplate::route('/{record}/edit'),
        ];
    }
}

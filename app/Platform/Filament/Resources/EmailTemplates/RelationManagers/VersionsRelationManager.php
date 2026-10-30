<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\EmailTemplates\RelationManagers;

use App\Mail\Templates\EmailTemplates;
use App\Mail\Templates\TemplateRefused;
use App\Models\EmailTemplate;
use App\Models\EmailTemplateVersion;
use App\Platform\Filament\Concerns\ActsAsStaff;
use App\Platform\Filament\Resources\EmailTemplates\Pages\EditEmailTemplate;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A template's versions, newest first (PLATFORM_CONSOLE.md § 5: "Every
 * edit creates a new version; roll back to any version"). Rolling back
 * makes a new version copying the chosen one; the history stays as it was.
 */
final class VersionsRelationManager extends RelationManager
{
    use ActsAsStaff;

    protected static string $relationship = 'versions';

    protected static ?string $title = 'Versions';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof EmailTemplate && self::actor()->can('view', $ownerRecord);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('version')->sortable()
                    ->badge(fn (EmailTemplateVersion $record): bool => $record->id === $this->template()->current_version_id)
                    ->color('success')
                    ->description(fn (EmailTemplateVersion $record): ?string => match (true) {
                        $record->id === $this->template()->current_version_id => 'Being sent',
                        $record->restored_from_version !== null => 'Copy of version '.$record->restored_from_version,
                        default => null,
                    }),
                TextColumn::make('subject')->wrap(),
                TextColumn::make('createdBy.name')->label('By')->placeholder('From the code'),
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
            ])
            ->recordActions([
                Action::make('rollback')
                    ->label('Roll back to this')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription(fn (EmailTemplateVersion $record): string => "Version {$record->version}'s wording is sent from now on, saved as a new version. Nothing is deleted.")
                    ->visible(fn (EmailTemplateVersion $record): bool => $record->id !== $this->template()->current_version_id)
                    ->authorize(fn (): bool => self::actor()->can('rollback', $this->template()))
                    ->action(function (EmailTemplateVersion $record): void {
                        try {
                            $copy = app(EmailTemplates::class)->rollback($this->template(), $record->version, self::actor());
                        } catch (TemplateRefused $refused) {
                            Notification::make()->title($refused->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title("Rolled back to version {$record->version}")->body("Saved as version {$copy->version}.")->success()->send();
                        $this->redirect(EditEmailTemplate::getUrl(['record' => $this->template()]));
                    }),
            ])
            ->defaultSort('version', 'desc')
            ->paginated([10, 25, 50]);
    }

    private function template(): EmailTemplate
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof EmailTemplate ? $owner : throw new \LogicException('Versions belong to an email template.');
    }
}

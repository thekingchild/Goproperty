<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\TierApplications\RelationManagers;

use App\Enums\ComplianceDocumentStatus;
use App\Enums\ComplianceDocumentType;
use App\Models\PlatformStaff;
use App\Platform\Compliance\DocumentLinks;
use App\Platform\Compliance\ReviewDocument;
use App\Platform\Compliance\TierReview;
use App\Platform\Filament\Concerns\ActsAsStaff;
use App\Platform\Filament\Resources\TierApplications\TierApplicationResource;
use App\Platform\Staff\StaffPermission;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * The application's documents (not the removed ones). Type, dates and
 * status show to everyone who may review (`tiers.review`); the holder's
 * name, the document number and the file itself only to staff holding
 * `compliance.view_documents`, the file through a signed link that expires
 * in minutes and is audited when opened (DocumentLinks). Accept / Reject
 * per document go through TierReview.
 */
final class DocumentsRelationManager extends RelationManager
{
    use ActsAsStaff;

    protected static string $relationship = 'documents';

    protected static ?string $title = 'Documents';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $staff = Filament::auth()->user();

        return $staff instanceof PlatformStaff && $staff->holdsStaffPermission(StaffPermission::TiersReview);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    private static function seesDetails(): bool
    {
        $staff = Filament::auth()->user();

        return $staff instanceof PlatformStaff && $staff->holdsStaffPermission(StaffPermission::ComplianceViewDocuments);
    }

    public function table(Table $table): Table
    {
        $warningDays = TierApplicationResource::warningDays();

        return $table
            ->columns([
                TextColumn::make('type')->formatStateUsing(fn (ComplianceDocumentType $state): string => $state->label()),
                TextColumn::make('holder_name')
                    ->label('Name on it')
                    ->state(fn (ReviewDocument $record): ?string => self::seesDetails() ? $record->holder_name : ($record->holder_name !== null ? 'Hidden' : null))
                    ->placeholder('—'),
                TextColumn::make('document_number')
                    ->label('Number')
                    ->state(fn (ReviewDocument $record): ?string => self::seesDetails() ? $record->document_number : ($record->document_number !== null ? 'Hidden' : null))
                    ->placeholder('—'),
                TextColumn::make('issued_at')->label('Issued')->date()->placeholder('—'),
                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->date()
                    ->placeholder('—')
                    ->color(fn (ReviewDocument $record): ?string => $record->expires_at !== null && $record->expires_at->lessThanOrEqualTo(now()->addDays($warningDays)) ? 'danger' : null),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (ComplianceDocumentStatus $state): string => $state->label())
                    ->color(fn (ComplianceDocumentStatus $state): string => match ($state) {
                        ComplianceDocumentStatus::Accepted => 'success',
                        ComplianceDocumentStatus::Rejected, ComplianceDocumentStatus::Expired => 'danger',
                        ComplianceDocumentStatus::Pending => 'gray',
                    }),
                TextColumn::make('review_note')->label('Note')->placeholder('—')->wrap(),
                TextColumn::make('created_at')->label('Uploaded')->dateTime(),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-o-document-magnifying-glass')
                    ->authorize(fn (ReviewDocument $record): bool => Gate::forUser(self::actor())->allows('viewFile', $record))
                    ->url(fn (ReviewDocument $record): string => app(DocumentLinks::class)->for($record), shouldOpenInNewTab: true),
                Action::make('accept')
                    ->label('Accept')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->authorize(fn (ReviewDocument $record): bool => Gate::forUser(self::actor())->allows('accept', $record))
                    ->requiresConfirmation()
                    ->action(function (ReviewDocument $record): void {
                        self::attempt(function () use ($record): string {
                            app(TierReview::class)->acceptDocument(self::actor(), $record);

                            return $record->type->label().' accepted.';
                        });
                    }),
                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->authorize(fn (ReviewDocument $record): bool => Gate::forUser(self::actor())->allows('reject', $record))
                    ->schema([
                        Textarea::make('note')
                            ->label('What is wrong with it?')
                            ->helperText('The organization sees this next to the document.')
                            ->required()
                            ->maxLength(TierReview::NOTE_MAX),
                    ])
                    ->action(function (ReviewDocument $record, array $data): void {
                        self::attempt(function () use ($record, $data): string {
                            app(TierReview::class)->rejectDocument(self::actor(), $record, (string) ($data['note'] ?? ''));

                            return $record->type->label().' rejected.';
                        });
                    }),
            ])
            ->defaultSort('type')
            ->paginated(false);
    }
}

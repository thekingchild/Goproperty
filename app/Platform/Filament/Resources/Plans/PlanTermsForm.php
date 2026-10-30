<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\Plans;

use App\Marketing\Plan;
use App\Plans\Feature;
use App\Plans\FeatureValue;
use App\Platform\Plans\PlanChangeRefused;
use App\Platform\Plans\PlanTerms;
use App\Support\Money;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use InvalidArgumentException;

/**
 * The fields of a plan version's terms (P06-01a): prices in naira, units,
 * one switch or limit per feature of the registry. Shared by "Schedule new
 * terms" and "New plan".
 */
final class PlanTermsForm
{
    /** @return list<Component> */
    public static function components(): array
    {
        $switches = [];
        $limits = [];

        foreach (Feature::cases() as $feature) {
            if ($feature->isLimit()) {
                $limits[] = TextInput::make('feature_'.$feature->value)->label($feature->label())->integer()->minValue(0)->maxValue(1_000_000)
                    ->helperText('Leave empty for unlimited.');
            } else {
                $switches[] = Toggle::make('feature_'.$feature->value)->label($feature->label());
            }
        }

        return [
            Section::make('Prices')->columns(3)->schema([
                TextInput::make('base')->label('Base price a month')->prefix('₦')->required()->maxLength(16),
                TextInput::make('included_units')->label('Units included')->integer()->minValue(0)->maxValue(1_000_000)->required(),
                TextInput::make('unit_price')->label('Each extra unit a month')->prefix('₦')->maxLength(16)
                    ->helperText('Leave empty only for a custom plan (Enterprise).'),
            ]),
            Section::make('Features')->columns(2)->schema($switches),
            Section::make('Limits')->columns(2)->schema($limits),
        ];
    }

    /** @return array<string, mixed> */
    public static function fill(Plan $plan): array
    {
        $data = [
            'base' => $plan->base->toDecimalString(),
            'included_units' => $plan->includedUnits,
            'unit_price' => $plan->extraUnit?->toDecimalString(),
        ];

        foreach (Feature::cases() as $feature) {
            $value = $plan->feature($feature);
            $data['feature_'.$feature->value] = $feature->isLimit() ? $value->limit : $value->enabled;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws PlanChangeRefused
     */
    public static function terms(array $data): PlanTerms
    {
        $features = [];

        foreach (Feature::cases() as $feature) {
            $raw = $data['feature_'.$feature->value] ?? null;
            $features[$feature->value] = $feature->isLimit()
                ? FeatureValue::limit($raw === null || $raw === '' ? null : (int) $raw)
                : ($raw === true ? FeatureValue::on() : FeatureValue::off());
        }

        $unit = trim((string) ($data['unit_price'] ?? ''));

        return new PlanTerms(
            basePriceKobo: self::kobo((string) ($data['base'] ?? '')),
            includedUnits: (int) ($data['included_units'] ?? 0),
            unitPriceKobo: $unit === '' ? null : self::kobo($unit),
            features: $features,
        );
    }

    /** @throws PlanChangeRefused */
    private static function kobo(string $naira): int
    {
        try {
            return Money::naira(str_replace(',', '', trim($naira)))->kobo;
        } catch (InvalidArgumentException) {
            throw new PlanChangeRefused('Give prices in naira, for example 35000 or 35,000.00.');
        }
    }
}

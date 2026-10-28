<?php

declare(strict_types=1);

namespace Modules\Billing\Livewire;

use App\Media\UploadValidator;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Modules\Billing\Bills\BillDates;
use Modules\Billing\Lists\UtilityRows;
use Modules\Billing\Livewire\Concerns\InUtilityWorkspace;
use Modules\Billing\Models\UnitUtilitySetting;
use Modules\Billing\Models\UtilityReading;
use Modules\Billing\Utilities\MeterReadings;
use Modules\Properties\Contracts\BuildingAccess;
use Modules\Properties\Contracts\PropertyDirectory;

/**
 * Record a meter reading (/o/{organization}/utilities/readings/record,
 * optionally `?meter={setting}`), or correct or remove the latest one of a
 * meter before it is billed (/utilities/readings/{reading}/edit)
 * (P03-06a): the meter (a unit's switched-on metered utility, in the
 * member's buildings), the date, the reading, "meter replaced" with the
 * new meter's first value, a note and an optional photo of the meter
 * (evidence, `meter_photos`). The last reading is shown, and the writer
 * (Utilities\MeterReadings) refuses a reading that goes backwards or is
 * not after the last one.
 *
 * UtilityReadingPolicy::create / update / delete and
 * UnitUtilitySettingPolicy::recordReading (`utilities.record_readings`:
 * owners, administrators, agents; narrowed to an assigned agent's
 * buildings), checked again by the writer. Removing is a `delete` ability:
 * never while staff impersonate.
 */
#[Layout('layouts::app')]
#[Title('Meter reading')]
final class UtilityReadingForm extends Component
{
    use InUtilityWorkspace, WithFileUploads;

    #[Locked]
    public int $organizationId;

    #[Locked]
    public ?int $readingId = null;

    #[Url(except: '')]
    public string $building = '';

    /** The meter: a unit's utility setting id. */
    #[Url(except: '')]
    public string $meter = '';

    public string $readingDate = '';

    public string $value = '';

    public bool $meterReplaced = false;

    public string $newMeterStart = '0';

    public string $note = '';

    /** An optional photo of the meter (Livewire's temporary upload). */
    public mixed $photo = null;

    public string $removalReason = '';

    public function mount(OrganizationContext $context, ?UtilityReading $reading = null): void
    {
        $this->organizationId = $context->id();

        if ($reading === null || ! $reading->exists) {
            $this->authorize('create', UtilityReading::class);
            $this->readingDate = BillDates::today()->toDateString();
            $setting = ctype_digit($this->meter) ? UnitUtilitySetting::query()->with('utilityType')->find((int) $this->meter) : null;

            if ($setting !== null && $this->user()->can('recordReading', $setting)) {
                $this->building = (string) $setting->property_id;
            } else {
                $this->meter = '';
            }

            return;
        }

        $this->authorize('update', $reading);
        $this->readingId = $reading->id;
        $this->meter = (string) $reading->unit_utility_setting_id;
        $this->readingDate = $reading->reading_date->toDateString();
        $this->value = $reading->current_value->input();
        $this->meterReplaced = $reading->meter_replaced;
        $this->newMeterStart = $reading->meter_replaced ? $reading->previous_value->input() : '0';
        $this->note = (string) $reading->note;
    }

    /** Another building: choose its meter again. */
    public function updatedBuilding(): void
    {
        $this->meter = '';
    }

    public function save(MeterReadings $readings, UploadValidator $validator): void
    {
        $input = [
            'readingDate' => $this->readingDate,
            'value' => $this->value,
            'meterReplaced' => $this->meterReplaced,
            'newMeterStart' => $this->newMeterStart,
            'note' => $this->note,
        ];

        if ($this->readingId !== null) {
            $reading = UtilityReading::query()->findOrFail($this->readingId);
            $this->authorize('update', $reading);
            $readings->correct($this->user(), $reading, $input);
            session()->flash('utility-status', __('The reading is corrected.'));
            $this->redirectRoute('workspace.utilities.readings.index', ['organization' => $this->organizationSlug(), 'meter' => $reading->unit_utility_setting_id], navigate: true);

            return;
        }

        $this->authorize('create', UtilityReading::class);
        $this->validate(
            ['meter' => ['required'], 'photo' => ['nullable', 'file', $validator->rule(UtilityReading::PHOTO_COLLECTION)]],
            ['meter.required' => __('Choose the unit\'s meter.')],
        );
        $setting = UnitUtilitySetting::query()->with('utilityType')->findOrFail((int) $this->meter);
        $this->authorize('recordReading', $setting);

        $reading = $readings->record($this->user(), $setting, $input, $this->photo instanceof UploadedFile ? $this->photo : null);
        session()->flash('utility-status', __('The reading is recorded: :value, :consumption used since the last one.', ['value' => $reading->current_value->format(), 'consumption' => $reading->consumption->format()]));
        $this->redirectRoute('workspace.utilities.readings.index', ['organization' => $this->organizationSlug(), 'meter' => $setting->id], navigate: true);
    }

    public function remove(MeterReadings $readings): void
    {
        abort_if($this->readingId === null, 404);
        $reading = UtilityReading::query()->findOrFail($this->readingId);
        $this->authorize('delete', $reading);

        $readings->remove($this->user(), $reading, $this->removalReason);
        session()->flash('utility-status', __('The reading is removed. It is kept, with its photo, as removed.'));
        $this->redirectRoute('workspace.utilities.readings.index', ['organization' => $this->organizationSlug(), 'meter' => $reading->unit_utility_setting_id], navigate: true);
    }

    public function render(OrganizationContext $context, PropertyDirectory $buildings, BuildingAccess $access, UtilityRows $rows, MeterReadings $readings): View
    {
        $user = $this->user();
        $reading = $this->readingId !== null ? UtilityReading::query()->with('setting.utilityType')->findOrFail($this->readingId) : null;

        if ($reading !== null) {
            // Checked on every render, not only when the page opened.
            $this->authorize('update', $reading);
        }

        $buildingOptions = $buildings->activeOptions($user);
        $meters = [];

        if ($reading === null && ctype_digit($this->building) && array_key_exists((int) $this->building, $buildingOptions)) {
            $settings = UnitUtilitySetting::query()->with('utilityType')
                ->where('property_id', (int) $this->building)
                ->where('active', true)
                ->whereHas('utilityType', fn ($query) => $query->where('active', true)->where('charging_mode', 'metered'))
                ->orderBy('unit_id')->orderBy('utility_type_id')->get();

            foreach ($rows->settings($settings) as $row) {
                if (! $row->unitArchived) {
                    $meters[$row->setting->id] = $row->unitLabel.' · '.$row->setting->utilityType->name;
                }
            }
        }

        $setting = $reading->setting ?? (ctype_digit($this->meter) && array_key_exists((int) $this->meter, $meters) ? UnitUtilitySetting::query()->with('utilityType')->find((int) $this->meter) : null);
        $previous = $reading !== null ? $readings->latest($reading->setting, except: $reading) : ($setting !== null ? $readings->latest($setting) : null);

        return view('billing::livewire.utility-reading-form', [
            'organization' => $context->get(),
            'reading' => $reading,
            'row' => $reading !== null ? $rows->readings([$reading])[0] : null,
            'setting' => $setting,
            'previous' => $previous,
            'startsAt' => $setting?->startingReading(),
            'buildings' => $buildingOptions,
            'meters' => $meters,
            'canRemove' => $reading !== null && $user->can('delete', $reading),
        ]);
    }
}

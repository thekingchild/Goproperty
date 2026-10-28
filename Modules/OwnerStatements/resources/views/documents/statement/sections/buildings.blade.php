{{-- Rent and utilities collected per unit, and the expenses charged, building by building (BRANDING_DOCUMENTS.md § 4). --}}
@foreach ($statement->buildings as $building)
  <div class="statement-building">
    <div class="party-name">{{ $building->name }}@if ($building->archived) <span class="muted small">(no longer managed)</span>@endif</div>
    <table class="items">
      <tr>
        <th style="width:52%">Unit</th>
        <th class="r" style="width:24%">Rent collected</th>
        <th class="r" style="width:24%">Utilities collected</th>
      </tr>
      @forelse ($building->units as $index => $unit)
        <tr @class(['alt' => $index % 2 === 1])>
          <td>{{ $unit->unit !== '' ? $unit->unit : 'Building' }}</td>
          <td class="r nowrap">{{ $unit->rent->format() }}</td>
          <td class="r nowrap">{{ $unit->utilities->format() }}</td>
        </tr>
      @empty
        <tr><td class="muted">Nothing collected this month</td><td class="r nowrap">{{ $building->figures->rent->format() }}</td><td class="r nowrap">{{ $building->figures->utilities->format() }}</td></tr>
      @endforelse
      @foreach ($building->expenses as $expense)
        <tr>
          <td><div class="item-desc">Expense: {{ $expense->description }}</div><div class="muted small">{{ $expense->spentOn->format('d/m/Y') }} · {{ $expense->categoryLabel }}@if ($expense->unit !== '') · {{ $expense->unit }}@endif</div></td>
          <td class="r nowrap">− {{ $expense->amount->format() }}</td>
          <td class="r"></td>
        </tr>
      @endforeach
      <tr>
        <td class="sub">Net due for {{ $building->name }}</td>
        <td class="r nowrap sub">{{ $building->figures->netDue->format() }}</td>
        <td class="r muted small">Commission {{ $building->figures->commission->format() }}</td>
      </tr>
    </table>
  </div>
@endforeach

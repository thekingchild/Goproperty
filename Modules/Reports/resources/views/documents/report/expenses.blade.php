{{-- Expenses by category. --}}
<div class="report-section">
  <div class="party-name">Expenses by category</div>
  <table class="items">
    <tr><th style="width:36%">Category</th><th class="r" style="width:12%">Expenses</th><th class="r" style="width:18%">On buildings</th><th class="r" style="width:16%">Organization's own</th><th class="r" style="width:18%">Total</th></tr>
    @forelse ($report->expenses->categories as $index => $line)
      <tr @class(['alt' => $index % 2 === 1])>
        <td>{{ $line->label }}</td>
        <td class="r">{{ number_format($line->count) }}</td>
        <td class="r nowrap">{{ $line->buildings->format() }}</td>
        <td class="r nowrap">{{ $line->organization->format() }}</td>
        <td class="r nowrap">{{ $line->total()->format() }}</td>
      </tr>
    @empty
      <tr><td class="muted" colspan="5">No expenses paid in the period.</td></tr>
    @endforelse
    <tr class="total">
      <td>Total</td>
      <td class="r">{{ number_format($report->expenses->count()) }}</td>
      <td class="r"></td>
      <td class="r"></td>
      <td class="r nowrap">{{ $report->expenses->total()->format() }}</td>
    </tr>
  </table>
</div>

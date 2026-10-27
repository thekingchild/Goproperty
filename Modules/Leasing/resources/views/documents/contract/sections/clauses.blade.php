{{--
    The agency's clauses (BRANDING_DOCUMENTS.md § 4; Settings → Lease contract),
    numbered. Each is plain text with only the whitelisted placeholders filled
    in (Modules\Leasing\Documents\ContractClauses) and is escaped here: an
    agency's template is data, never Blade (AGENT.md § 3 rule 10).
--}}
<div class="label label-gap">Terms of the agreement</div>
<table class="clauses" role="presentation">
  @foreach ($contract->clauses() as $index => $clause)
    <tr>
      <td class="clause-num">{{ $index + 1 }}.</td>
      <td class="clause-text">{!! nl2br(e($clause), false) !!}</td>
    </tr>
  @endforeach
</table>

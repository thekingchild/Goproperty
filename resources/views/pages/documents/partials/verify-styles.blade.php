{{-- A few styles of the verify pages, beside auth.css (the sign-in pages' policy allows inline styles). --}}
<style>
  .verify-icon--valid { color: #15803d; background: #dcfce7; }
  .verify-icon--voided { color: #b91c1c; background: #fee2e2; }
  .verify-icon--superseded { color: #92400e; background: #fef3c7; }
  .verify-facts { margin: 0 0 20px; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; }
  .verify-facts div { display: grid; grid-template-columns: minmax(0, 9rem) minmax(0, 1fr); gap: 12px; padding: 10px 14px; border-top: 1px solid #e2e8f0; }
  .verify-facts div:first-child { border-top: 0; }
  .verify-facts dt { color: #64748b; font-size: .875rem; }
  .verify-facts dd { margin: 0; font-weight: 600; overflow-wrap: anywhere; }
  .verify-pill { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: .8rem; font-weight: 700; letter-spacing: .03em; text-transform: uppercase; }
  .verify-pill--valid { background: #dcfce7; color: #166534; }
  .verify-pill--voided { background: #fee2e2; color: #991b1b; }
  .verify-pill--superseded { background: #fef3c7; color: #92400e; }
  .verify-note { display: block; margin-top: 4px; font-weight: 400; color: #475569; font-size: .875rem; }
  .verify-code { font-family: ui-monospace, Menlo, Consolas, monospace; letter-spacing: .08em; }
</style>

# Paystack test-mode run (2026-11-11)

Both Paystack flows were tried on the local pilot (http://localhost:8090) with the product owner's Paystack **test** keys, at their request. No public address exists yet (hosting is not chosen), so Paystack's webhooks could not reach the pilot: each payment was settled by the app's own verify call when the payer came back from checkout. The keys live only in the pilot's database and in `~/.config/goproperty-local`, never in the repository.

## Webhook addresses, once GoProperty has a domain

| Flow | Address | Where it goes |
|---|---|---|
| GoProperty's own account (subscription invoices, SMS credits) | `https://<domain>/webhooks/paystack` | GoProperty's Paystack dashboard → Settings → API Keys & Webhooks |
| Each agency's own account (tenant rent) | `https://<domain>/webhooks/paystack/<token>` | Shown to the agency owner in Settings → Online payments, with a button to make a new one |

## What happened

1. **Subscription invoice:** test agency "Paystack Test Agency", invoice PR-2026-000001 for ₦7,166.67. Paystack checkout asked **NGN 7,377.33**; the test card succeeded; GoProperty **held** the payment ("₦7,377.33 was paid, but ₦7,166.67 was asked …") and left the invoice open.
2. **Tenant rent:** demo agency "Demo Homes Ltd", test keys connected (status active, mode test), bill INV-DEH-2026-000105 for ₦85,000 paid from its public pay page. Paystack asked **NGN 86,395.94**; the test card succeeded; the payment was recorded as **pending confirmation** with the hold note and the bill stayed unpaid. A second identical payment of the same bill followed two minutes later from the browser pane; it was held the same way.

## Findings

1. **The Paystack test account passes its fees to the payer.** Paystack adds its fee to the amount the payer is asked, so the paid amount never equals the bill or invoice, and every online payment is held for review. Either set the Paystack account so the merchant, not the customer, pays transaction charges (a setting in the Paystack dashboard), or change the app to accept a paid amount equal to the requested amount plus Paystack's fees (it would have to keep the fee out of the rent paid to the landlord). The second changes how money is counted and needs the product owner's decision. Agencies with fee pass-through on will hit the same hold.
2. **A refused payer email gives a misleading message.** Paystack refused the address `…@goproperty.test` with a 400; the owner saw "Paystack could not start the payment just now. Try again in a few minutes." and the failure note kept only "Paystack answered 400.", not Paystack's reason.
3. **The agency payment's paid time is stored an hour early.** `PaystackClient` parses Paystack's `paid_at` (UTC) and the value is written as Lagos time without converting (paid 15:44 WAT, stored 14:44). A payment made between midnight and 01:00 Lagos would carry the previous day's date.

## Left on the pilot

- Platform payments for org 2: two failed attempts (the refused email) and one held.
- Payments 105 and 106 on bill 105, both pending confirmation; the bill is unpaid.
- The demo agency stays connected to the test keys until disconnected in Settings → Online payments.

## Decided and built (2026-11-11)

The product owner took the recommendation:

1. **GoProperty's own account:** the product owner sets the Paystack account so GoProperty pays the fees; the app keeps holding any payment that differs from the invoice.
2. **Agencies' accounts:** the app accepts exactly the amount asked plus Paystack's fee, credits the bill with the amount asked and keeps the fee apart (`payer_fee_kobo`, a note on the payment). Any other difference is still held.
3. **Findings 2 and 3 fixed:** a refused email address is explained to the payer and Paystack's reason is kept; Paystack's paid time is converted to Lagos time.

The payments held during the run were settled before the fix and stay held; staff reject or keep them in the confirmation queue.

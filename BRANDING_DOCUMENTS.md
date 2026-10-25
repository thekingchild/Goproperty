# GoProperty — Agency Branding & Documents

Companion documents: [ARCHITECTURE.md § 6.5](ARCHITECTURE.md) · [UX_DESIGN.md](UX_DESIGN.md) · [DATABASE_SCHEMA.md](DATABASE_SCHEMA.md)

Agencies work under **their own brand**. Tenants see the agency's name, logo and colours on every invoice, receipt, reminder and portal page. GoProperty appears only as a small "Generated with GoProperty" footer line.

## 1. Branding Settings (Settings → Branding, org-owner / org-admin)

| Setting | Used on | Notes |
| --- | --- | --- |
| Company name (as registered) | All documents, emails, portal | Defaults to organization name; Tier 2/3 must match CAC name |
| Trading name (optional) | Headers, email sender name | "Adeyemi Realty" vs "Adeyemi Realty Ltd" |
| Logo | Documents, emails, tenant portal header, login of invited users | PNG/JPG/WebP, ≤ 1 MB, min 300 px wide; auto-trimmed and resized; no SVG uploads (security). Monogram generated from initials when none |
| Primary colour | Header bar, table headers, amount-due panel, buttons | Colour picker. Contrast checked against white text; if too light, a darker shade is used automatically for text-on-colour |
| Accent colour (optional) | Highlights, badges | |
| Letterhead details | Document header | Address, RC number, phone, email, website |
| Signature / stamp image (optional) | Invoice, receipt, contract | Uploaded image placed on the signature line |
| Document prefixes | Numbering | e.g. `INV-ADR`, `RCT-ADR`, `LS-ADR` → `INV-ADR-2027-000142` |
| Invoice notes / terms | Invoice "Notes" box | Plain text, max 600 characters |
| Receipt footer | Receipt | Plain text, max 300 characters |
| Document layout | All PDFs | **Modern** (default, shown in the samples), **Classic** (serif headings, boxed letterhead), **Compact** (smaller fonts for long bills) |
| Email sender name | Emails | "Adeyemi Realty via GoProperty", replies go to the agency's email |

A live preview shows a sample invoice and receipt while settings are edited. Every change is audited. Documents already issued keep the branding they were issued with (a snapshot is stored with each document).

### Where branding appears

- **PDF and web documents:** invoices, receipts, utility bills, lease contracts, owner statements, quit/renewal notices.
- **Tenant portal:** when a tenant views a bill from an agency, the page header shows that agency's logo and colours. A tenant renting from two agencies sees each agency's brand on its own bills.
- **Emails, SMS sender ID and WhatsApp:** agency name in sender name and template text. A custom SMS sender ID needs Termii approval per agency. Until approved, the default GoProperty sender is used with the agency name in the message.
- **Pay page** (`/pay/{payment-reference}`): agency branded, no login required, shows bank details and the online-payment button if available.

### Plan differences

All plans get logo, colours, letterhead and prefixes. The "Generated with GoProperty" footer line can be removed on the Scale plan.

## 2. Invoice

Design reference: [`docs/design/templates/invoice.html`](docs/design/templates/invoice.html) (rendered: [`invoice.png`](docs/design/templates/invoice.png)).

Sections, top to bottom:

1. **Brand bar** in the primary colour.
2. **Letterhead:** logo, company name, address, RC number, phone, email, website. Right side: **INVOICE**, invoice number, issue date, due date, status badge (`UNPAID`, `PARTLY PAID`, `PAID`, `OVERDUE`, `VOID`).
3. **Billed to** (tenant name, phone, email) and **Property** (building, unit, address, lease number, cycle), side by side.
4. **Line items:** description + short note, period, amount. Rent, service charge, levies, utilities, agency/legal/caution fees on first-year bills.
5. **Totals:** subtotal, credit applied, paid to date, and a large **Amount due** panel with the due date.
6. **How to pay:** the agency's bank, account name, account number and the bill's **payment reference** (`GP-7KQ2M`) to put in the narration. If the agency has connected Paystack and the amount is within its online limit, a **Pay online** button and short link appear beside it.
7. **Notes** (agency terms) and signature line.
8. **QR code + verification code**: scanning opens `goproperty.ng/verify/{code}`, which confirms the invoice is genuine and shows its current status.
9. **Footer:** company name, document number, page x of y, "Generated with GoProperty".

## 3. Receipt

Design reference: [`docs/design/templates/receipt.html`](docs/design/templates/receipt.html) (rendered: [`receipt.png`](docs/design/templates/receipt.png)).

1. Brand bar and letterhead as on the invoice. Title **OFFICIAL RECEIPT**, receipt number, date issued, tenant ID.
2. **Amount received** in large figures, **amount in words** ("Two million, nine hundred thousand naira only"), and a **PAID** stamp.
3. **Received from:** tenant, phone, property, lease.
4. **Payment details:** method, date paid, account paid into (masked), bank narration / Paystack reference, how it was confirmed (statement match, Paystack, proof reviewed, cash).
5. **Applied to:** each invoice and amount applied; outstanding balance after payment; credit balance.
6. **Confirmed by:** name of the agency user who confirmed it (or "Automatically — Paystack" / "Statement match"), date/time (WAT); signature/stamp line.
7. **Verify box:** QR code and short code. The verification page shows the agency name, amount, date and whether the receipt is valid or **voided**. This stops fake or edited receipts.
8. Statement that it was issued electronically and sent to the tenant by SMS/WhatsApp/email.

Voided receipts are re-rendered with a diagonal **VOID** watermark and the void reason; the verification page shows "voided".

## 4. Other Documents (same layouts)

| Document | Key content |
| --- | --- |
| Utility bill | Utility type, meter readings (previous, current, consumption, rate) for metered utilities |
| Lease / tenancy agreement | Parties, property, term, cycle, rent and charges schedule, deposit, clauses (agency-editable template), signature blocks |
| Owner statement | Period, rent collected per unit, agency commission, expenses, net due to owner, payout record |
| Tenant statement of account | All bills, payments and credits for a period with running balance |
| Renewal / quit notice | Notice period, dates, legal wording templates per state (agency-editable) |

## 5. Rendering

- One Blade template per document and layout. Templates use **table layouts and simple CSS only** (no flexbox, grid or CSS variables), so they render identically with both PDF drivers:
  - `dompdf`: pure PHP, works on shared hosting.
  - Headless Chrome (Browsershot or a Gotenberg service): VPS hosting; faster for large batches.
- The driver is chosen in config (`PDF_DRIVER`). See [ARCHITECTURE.md § 12](ARCHITECTURE.md) for hosting profiles.
- Fonts are bundled locally (Inter + DejaVu Sans fallback, which includes the ₦ sign).
- Every document is also viewable as a responsive **web page** (faster on mobile data); the PDF is a download.
- Generated PDFs are stored once and re-used. Changing branding does not alter issued documents.
- Numbering comes from `document_counters` inside a transaction (never `max(id)+1`).
- Amount-in-words helper is part of the money module and tested for kobo, millions and billions.

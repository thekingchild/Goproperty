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

### How it is built (P01-05)

- **Pages:** Settings → Profile (`/o/{slug}/settings/profile`: company name, web address, address, state, LGA, CAC number) and Settings → Branding (`/o/{slug}/settings/branding`). Seen with `settings.view`, saved with `organization.update`: `org-owner` and `org-admin`. Read-only while suspended. The company name is the organization's name, edited under Profile; from Tier 2 it and the CAC number are locked there because compliance checked them.
- **Colour contrast (decided here; the table above says a too-light primary gets a darker text shade, the task asks for low-contrast colours to be rejected):** both apply. A primary below **3:1 against white** (WCAG's minimum for large text and graphics: the brand bar, headings and table headers would not read on paper) is **rejected** with a message giving the ratio. Between 3:1 and 4.5:1 it is accepted and text on or in the colour uses the nearest darker shade of the same hue that reaches 4.5:1 (`App\Branding\BrandColours::textShade()`). An accent closer than **3:1 to the primary** is rejected (it must stand out next to it). Enforced on the server (`App\Branding\BrandingSettings`), whatever the page sent.
- **Other rules:** prefixes are capital letters and digits in up to three hyphen-joined parts (`INV-ADR`), at most 20 characters, different per document type; defaults are `INV-`, `RCT-`, `LS-` plus a code from the name (Adeyemi Realty Ltd → `ADR`). Plain text only (no `<` or `>`), notes ≤ 600 and footer ≤ 300 characters. Layout `modern` / `classic` / `compact`. Hiding the "Generated with …" line is allowed only on the plans in `config/branding.php` (`scale`, `enterprise`), read from `organizations.signup_plan_key` until subscriptions exist (P06-01).
- **Images:** logo, signature and stamp (two optional images rather than one). PNG, JPEG or WebP, ≤ 1 MB, type sniffed from the bytes, never SVG; logo at least 300 px wide, signature and stamp 150 px. Re-encoded as PNG, trimmed of a plain border and scaled down (logo ≤ 800 × 400 px), stored in the media library as the organization's `branding` media (P02-05; private disk, `organizations.logo_media_id` and the `*_media_id` keys of `organizations.branding`) and served through the workspace route; PDFs embed them. Replaced images are kept for documents already issued ([ARCHITECTURE.md § 9](ARCHITECTURE.md)).
- **Preview:** rendered on the server from the form's current, unsaved values with the same Blade templates as real documents (since P03-05b `resources/views/documents/{invoice,receipt}/{layout}.blade.php`, § 5 "How it is built (P03-05b)"; `App\Documents\DocumentBranding`, sample `InvoiceDocument` and `ReceiptDocument` from `App\Documents\SampleDocuments`, `App\Documents\BrandingPreview`), shown in a script-less `srcdoc` frame scaled to the column. Sample tenant, property and bank details only, never the organization's real ones. Nothing is saved until Save. Since P03-05a the preview goes through the document kit (§ 5 "How it is built"): `DocumentRenderer::web()`, the brand bar, verify box with a real QR code and amount in words; the frame is sandboxed without same origin, so the bundled fonts may not load there and the browser's fallback shows instead. P03-05b replaced the two templates with the designed layouts; real bills (P03-05c) and receipts (Payments) use the same ones.
- **Layouts:** one template per document and layout, built from shared sections; `styles` sets fonts, sizes and spacing per layout (classic: serif headings, square corners, a boxed letterhead in place of the rule under it, white ruled panels; compact: smaller type and tighter rows). The page is 794 px wide; P03-05a moved the footer into the PDF's bottom margin (§ 5 "How it is built"). Since P03-05b every layout fits one A4 page for up to 8 lines (§ 5 "How it is built (P03-05b)").
- **Audit and checklist:** saving updates `organizations.branding` through the organization's activity log (before and after); the first save ticks the setup checklist's branding item.

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
2. **Letterhead:** logo, company name, address, RC number, phone, email, website. Right side: **INVOICE**, invoice number, issue date, due date, status badge (`UNPAID`, `PARTLY PAID`, `PAID`, `OVERDUE`, `VOID`; `DRAFT` for a bill shown before it is issued).
3. **Billed to** (tenant name, phone, email) and **Property** (building, unit, address, lease number, cycle), side by side.
4. **Line items:** description + short note, period, amount. Rent, service charge, levies, utilities, agency/legal/caution fees on first-year bills.
5. **Totals:** subtotal, credit applied, paid to date, and a large **Amount due** panel with the due date.
6. **How to pay:** the agency's bank, account name, account number and the bill's **payment reference** (`GP-7KQ2M9X`) to put in the narration. If the agency has connected Paystack and the amount is within its online limit, a **Pay online** button and short link appear beside it.
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

Voided receipts are re-rendered with a diagonal **VOID** watermark and the void reason; the verification page shows "voided". Void invoices get the same watermark (§ 5 "How it is built (P03-05b)").

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
  - Headless Chrome through a Gotenberg service (HTTP, so the PHP server needs neither Node nor `proc_open`): VPS hosting; faster for large batches.
- The driver is chosen in config (`PDF_DRIVER`, defaulted by `HOSTING_PROFILE`) and used through `App\Pdf\PdfRenderer`. Each template should set its page with `@page { size; margin }`: both drivers let the template's `@page` rule win. See [ARCHITECTURE.md § 12](ARCHITECTURE.md) for hosting profiles.
- Fonts are bundled locally (Inter + DejaVu Sans fallback, which includes the ₦ sign).
- Every document is also viewable as a responsive **web page** (faster on mobile data); the PDF is a download.
- Generated PDFs are stored once and re-used. Changing branding does not alter issued documents.
- Numbering comes from `document_counters` inside a transaction (never `max(id)+1`).
- Amount-in-words helper is part of the money module and tested for kobo, millions and billions.

### How it is built (P03-05a: the document kit, `app/Documents`)

- **Rendering:** a document is a `App\Documents\Document` (Blade view under `resources/views/documents`, its data, its `DocumentBranding`, a file name; `ViewDocument` builds one from parts). `App\Documents\DocumentRenderer` renders `web()` (responsive page, fonts by URL), `pdf()` (bytes from the configured driver through `App\Pdf\PdfManager`) and `stored($owner, $key, $document)`: an issued document's PDF is rendered **once**, under a row lock on the owner, and kept in the owner's media collection **`issued_documents`** (PDF only, evidence: never deleted) with `document_key`, the driver and the page count; later calls return the kept file and never render again. A voided receipt's re-issue uses another key (e.g. `receipt-void`).
- **Targets** (`App\Documents\RenderTarget`): the HTML is the same for `web`, `dompdf` and `chrome` except what the drivers cannot share: where fonts load from (URL; file path in dompdf's chroot; data URIs for Gotenberg) and the footer. PDFs reserve a 40 px bottom margin (`@page { margin: 0 0 40px 0 }`) for the footer band with "company · document number · Page x of y" and "Generated with …": dompdf prints a fixed table there (included right after `<body>`, since dompdf repeats a fixed element only from the page it appears on), with its page counter and the total from a first pass (`PdfPages::count`; a second render only when the document runs past one page); Chrome prints the same text from `@page` margin boxes (`@bottom-left`, `@bottom-right`), which count pages natively. Web pages show the footer once at the bottom, with no page numbers. Templates include `partials/footer` twice (`at` = `start` and `end`).
- **Partials** (table-only): `styles` (per layout from `App\Documents\DocumentLayout`: base size, heading font, corner radius), `brand-bar`, `letterhead`, `footer`, `verify-box` (QR code + code + verify address). `tests/Feature/Documents/DocumentViewsLintTest.php` bans flexbox, grid and CSS variables (and font weights other than 400/600/700/800) in every document view, Core's and modules', and in the rendered pages of every layout and target. Padded or bordered tables use `border-collapse: separate` (Chrome ignores a collapsed table's padding; dompdf does not).
- **Fonts** (`public/fonts/documents`, built by `scripts/fonts/build-document-fonts.php` from npm's `@fontsource/inter` and dompdf's DejaVu, licences beside them): Inter 400/600/700/800 and 400 italic as two families, "Inter" (Latin) and "Inter Ext" (Latin Extended, which has ₦), DejaVu Sans Mono Bold for codes and DejaVu Serif for classic headings (`App\Documents\DocumentFonts`). Both drivers fall back glyph by glyph along the family list. dompdf sets lines at line-height × the font's height × `pdf.dompdf.font_height_ratio`, which `config/pdf.php` sets (1000/1210) so Inter's lines are exactly as tall as in Chrome; PDF font stacks name only bundled faces, because dompdf makes a line as tall as the tallest font in the list.
- **Branding snapshot** (`App\Documents\BrandingSnapshot`, taken by `BrandingSnapshots::take($organization)`): every branding setting (`Branding::toArray()` + company name), the product name for the "Generated with" line, and the logo, signature and stamp as PNG data URIs (not media ids), with the time taken. The issuing module stores `toArray()` in the document's json column (`bills.branding_snapshot`, …) and rebuilds it with `fromArray()`, which accepts only PNG data URIs as images. Later changes to settings, images, the name or the plan never change an issued document.
- **Verification codes** (`App\Documents\Verification`): `VerificationCodes::issue($kind)` draws 10 characters of Crockford base 32 (no I, L, O, U) from the CSPRNG (50 bits) and records them in `document_verifications` (unique across all kinds, retrying on a collision) inside the current organization, in the issuing transaction; printed as `7KQ2M-X9P4D`, typed codes are normalised (case, spaces, dashes, O/I/L). The QR code holds `route('documents.verify', code)`. Modules register a `VerificationResolver` per kind into the `DocumentVerifiers` singleton from their providers; `DocumentVerifiers::find($code)` reads the code's organization and kind (one unscoped read, the P01-04 invitation pattern), then runs the kind's resolver inside that organization and returns a `VerifiedDocument` (title, number, issuer, date, amount, valid or voided) or null. The `/verify/{code}` page is P03-05b.
- **Numbers** (P03-04a): `App\Documents\DocumentNumberer::next(DocumentType, $issuedAt, $branding)` inside the issuing transaction gives `{prefix}-{YYYY}-{seq}`: the type's prefix from the branding (pass the snapshot's, so number and print agree; defaults `INV-`, `RCT-`, `LS-` plus the organization's code), the year of issue in Lagos and a six-digit sequence per organization, type and year (`INV-ADR-2027-000142`; a seventh digit after 999999). Rent and utility bills are both invoices and share a sequence. Numbers are gap-free under concurrency (the counter row is locked until the transaction ends; a rollback gives the number back) and the call is refused outside a transaction. Changing a prefix mid-year keeps the sequence going. `DocumentBranding::number()` formats sample numbers the same way.
- **QR codes** (`App\Documents\QrCode`): bacon/bacon-qr-code encodes (error correction M), GD draws a two-colour PNG data URI (bacon's image back end needs Imagick).
- **Amount in words:** `App\Support\AmountInWords` / `Money::inWords()`: "Two million, nine hundred thousand naira only", "One thousand, two hundred and fifty naira and fifty kobo only"; British "and", up to the largest amount `Money` holds.
- **Visual diff:** `tests/Support/PdfVisualDiff` rasterises PDFs with poppler's `pdftoppm` at 50 dpi in grey and scores the share of pixels with no match within 2 px and 64 grey levels; the threshold is 0.4 % (dompdf against Chrome scored 0.02–0.07 % on the kit's sample page, a page with one row fewer 0.9 %). Gotenberg runs in CI and in `compose.yaml` ([docs/runbooks/ci.md](docs/runbooks/ci.md)).

### How it is built (P03-05b: invoice and receipt templates, `/verify`)

- **Documents** (Core, `app/Documents`; Core knows no bills or receipts): `Invoice\InvoiceDocument` (number, issue and due dates, `InvoiceStatus`, `Parts\DocumentTenant`, `Parts\DocumentProperty`, `InvoiceLine`s with Money amounts, notes and a `Parts\DocumentPeriod` or a word such as "Once", the bill's period for lines without one, credit applied and paid to date as Money, `BankTransfer`, the payment reference, an optional `OnlinePayment` link, the verification code, the void reason) and `Receipt\ReceiptDocument` (number, issue time, amount, tenant, property, `ReceiptPayment` — method, date paid, the account paid into kept as its last four digits only, narration or Paystack reference, how it was confirmed —, `ReceiptAllocation`s, balances after payment, who confirmed it and when, the channels a copy went out on, void time and reason). Both implement `Document`: the view is `documents.invoice.{layout}` / `documents.receipt.{layout}` for the snapshot's layout, the file name the document number (`…-void.pdf` for a voided receipt). The issuing module builds one from its record, the stored `BrandingSnapshot` and the current status. Subtotal and amount due are worked out in Money (never below zero; nothing on a void invoice). Dates print in Lagos time (`Parts\DocumentDates`).
- **Templates:** `resources/views/documents/{invoice,receipt}/{modern,classic,compact}.blade.php` extend `partials/page` (head, styles, footer, brand bar, watermark) and arrange shared sections (`invoice/sections/*`, `receipt/sections/*`) with the kit's table-only partials plus `status-badge`, `signature` and `void-watermark`. To fit **one A4 page for up to 8 lines** in every layout (with a note on every line, credit and part payment, bank and online payment, logo, signature and stamp, and the longest notes and footer the settings allow), the invoice puts *how to pay* beside the totals (with the signature line under the amount due) and the notes beside the verify box, where the design had them stacked; the online-payment button sits inside the bank-transfer panel; the receipt puts the signature/stamp between *confirmed by* and the verify box. Longer bills run onto further pages with "Page x of y". Paid, void and draft invoices print a one-line payment panel instead of the bank details.
- **Status badges:** `InvoiceStatus` (values = `bills.status`, plus `draft`) with fixed colours, not the agency's, each at least 4.5:1 (`DRAFT` grey, `UNPAID` amber as in the design, `PARTLY PAID` blue, `PAID` green, `OVERDUE` red, `VOID` dark).
- **VOID:** a void invoice or voided receipt prints a large translucent diagonal **VOID** across the page (a rotated block, drawn alike by both drivers); the receipt's PAID stamp becomes VOID and "when and why it was voided" replaces the "valid without a signature" statement and the receipt footer; a void invoice's reason replaces the agency's notes.
- **Web pages:** the same HTML with a phone stylesheet (web target only, never in PDFs): below 640 px the columns stack, the period/description columns move under the line's description, the letterhead stacks, long words wrap; no sideways scrolling at 360 px (checked in Chrome through Gotenberg's screenshot route, `tests/Support/NarrowScreen`).
- **Drivers alike:** pills set their own line height and padded fixed-width cells count their content only (`box-sizing: content-box`), so lines and columns measure the same in dompdf and Chrome; dompdf's footer text is padded to the middle of the footer band, where Chrome's margin boxes put it. Visual diff of the fullest invoice and receipt, issued and voided, all layouts: 0.01–0.27 % locally (threshold 0.4 %).
- **`/verify/{code}` and `/verify`** (`App\Http\Controllers\Documents\VerifyDocumentController`, public, on the sign-in pages' layout and security headers, rate-limited to 30 a minute per IP for both, `no-store`, `noindex`): the QR code opens `/verify/{code}`; a code typed with dashes, spaces, lower case or O/I/L is redirected (301) to its one address. `DocumentVerifiers::find()` resolves it inside the issuing organization; the page shows the issuer, the document's title and number, issue date, amount, status — **valid**, **voided** (with the module's note) or **superseded** (with the number that replaced it) — and the module's extra lines about the document, only what `VerifiedDocument` carries: never tenants, contact or bank details. Unknown, malformed, unresolvable and unregistered-kind codes are all the same plain 404. `/verify` asks for the code and sends a well-formed one to its page.

# GoProperty — Data Import (Excel / CSV)

Companion documents: [ONBOARDING_COMPLIANCE.md § 2](ONBOARDING_COMPLIANCE.md) · [DATABASE_SCHEMA.md](DATABASE_SCHEMA.md) · [ARCHITECTURE.md § 6.3–6.4](ARCHITECTURE.md)

Most agencies run on Excel, notebooks and WhatsApp. They can only switch to GoProperty if they can bring **everything** across, including rents already running and money already owed, without retyping.

## 1. What Can Be Imported

| # | Sheet | Key columns | Notes |
| --- | --- | --- | --- |
| 1 | Owners | Name / company, phone, email, address, bank (optional) | Landlords whose buildings the agency manages |
| 2 | Properties | Property name, owner, address, state, LGA, type, amenities | Owner matched by name/phone from sheet 1 or existing data |
| 3 | Units | Property, unit label, type, bedrooms, rent, service charge, status | Status can be left blank — derived from leases |
| 4 | Tenants | First/last name, phone (required), email, occupation, next of kin | Phone is the matching key |
| 5 | Leases (ongoing rents) | Unit, tenant, start date, end date, billing cycle, rent amount, service charge, **paid until**, deposit held, auto-renew | Creates active leases without back-billing (§ 3) |
| 6 | Opening balances | Tenant, unit, amount owed (or credit), as-of date, description | Creates one "Opening balance" bill or a credit per tenant |
| 7 | Utility settings | Unit, utility type, amount, cycle, metered?, last meter reading | Utility types created if missing |
| 8 | Payment history (optional) | Tenant, date, amount, method, reference | For records and statements only; never re-issues receipts unless asked |
| 9 | Staff (optional) | Name, email, phone, role | Sends invitations only after the import is committed |

Agencies can import everything at once (one workbook, one sheet per type) or step by step.

## 2. How It Works

```mermaid
flowchart LR
    A[Download template<br>or upload own file] --> B[Map columns]
    B --> C[Dry run: validate,<br>no data saved]
    C --> D{Errors?}
    D -->|Yes| E[Download error report<br>fix & re-upload]
    E --> C
    D -->|No, or warnings accepted| F[Review summary]
    F --> G[Commit import]
    G --> H[Import report +<br>optional tenant invitations]
```

1. **Template:** a downloadable `.xlsx` with one sheet per type, an "Instructions" sheet, example rows, and dropdowns for fixed values (cycles, unit types, states/LGAs).
2. **Own file:** agencies can upload their own spreadsheet instead. GoProperty suggests a column mapping from the headers, and the agency confirms or changes it. Mappings are saved for next time.
3. **Dry run (always first):** the whole file is validated and simulated. **Nothing is saved.** The agency sees:
   - counts of what would be created or updated per sheet;
   - **errors** (blocking: missing phone, unknown property, invalid date, negative rent, duplicate unit label);
   - **warnings** (non-blocking: tenant phone already exists in another lease, rent differs from unit default, lease already expired, unit count will raise the subscription bill);
   - a preview of the first rows of each sheet as they will look in GoProperty;
   - the effect on the subscription (units counted) and on the Transaction Tier forecast (expected yearly collections).
   An error report can be downloaded as Excel with an "Error" column next to each bad row.
4. **Commit:** only after a successful dry run. Runs as a queued job with a progress bar. Each sheet is committed in its own database transaction, in dependency order (owners → properties → units → tenants → leases → balances → utilities → history).
5. **Report:** what was created, what was skipped and why, plus links to review imported records.
6. **Invitations:** tenants and staff are **not** contacted automatically. After reviewing, the agency chooses "Invite tenants now" (SMS/WhatsApp/email) or later.

### Re-running and updates

- Each row can carry the agency's own ID (`external_ref`). Re-importing the same file updates existing records instead of duplicating them.
- Without an `external_ref`, matching uses natural keys: tenant by phone, property by name, unit by property + label.

### Undo

An import batch can be **rolled back within 7 days** if none of its records have been used since (no payments recorded, no bills sent). Rollback soft-deletes the batch's records and is audited. After that, records are edited individually.

## 3. Ongoing Rents and Balances (the important part)

Importing a lease that started years ago must not bill the tenant for past years or send a flood of reminders.

- **Paid until** is the key column: the date up to which the tenant has already paid. GoProperty sets the lease's next billing date from it, so the first bill it creates is for the period after "paid until". Bills follow the normal lead time (e.g. an annual rent paid until 31/12/2026 is billed ahead of 01/01/2027).
- **No back-billing:** past periods are never generated as bills.
- **Arrears** go in the Opening Balances sheet. Each becomes one bill of type `opening_balance` dated on the as-of date, so it appears in the tenant's statement, can be paid and receipted, and counts in arrears reports.
- **Tenant credit** (overpaid before switching) is imported as a credit entry and applied automatically to the next bill.
- **Deposits held** (caution fees) are recorded on the lease as a liability for refund at move-out. They don't create a bill.
- **Reminders** are calculated only from the commit date forwards. Nothing is sent for dates in the past.
- **Units and occupancy** are set from active leases.
- **Expired leases** in the file are imported as `expired` (history), unless "Treat as month-to-month" is chosen.
- **Lease numbers:** existing contract numbers can be kept. Otherwise GoProperty numbers them.

## 4. Limits & Performance

- Up to 10,000 rows per sheet in one file. Larger files are split.
- Files are read in chunks with a streaming reader (OpenSpout / `spatie/simple-excel`) so memory stays low. This matters on shared hosting.
- Formats: `.xlsx`, `.xls`, `.csv` (UTF-8). Dates accepted as DD/MM/YYYY or Excel date cells. Amounts accepted with or without ₦ and commas.
- Uploaded files are stored privately for 30 days for support purposes, then deleted.

## 5. Permissions & Audit

- Only `org-owner` and `org-admin` can run imports.
- Every batch is recorded (`import_batches`) with who ran it, file, dry-run results, commit time and counts. Every created record links to its batch.
- During impersonation, staff can view import results but cannot commit or roll back imports.

## 6. Also Supported

- **Export** of every list to Excel/CSV, so agencies are never locked in.
- A GoProperty "assisted import" service (staff run the import for the customer) can be offered on higher plans. Staff prepare the file, mapping and dry run; the org-owner reviews the dry-run summary and clicks **Commit** themselves.

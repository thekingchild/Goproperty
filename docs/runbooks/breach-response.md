# Runbook — personal data breach response (NDPA 2023 s. 40)

What to do when personal data GoProperty holds may have been lost, stolen, seen by someone who should not see it, changed or destroyed: a leaked database dump or backup, a stolen laptop with an export, a bug that showed one organization's tenants to another, a compromised staff account, an email sent to the wrong people, ransomware on a server.

Built in P09-02. The register is **Console → Compliance → Breach register** (`privacy.breaches`: compliance-officer, technical-admin, super-admin). The data protection officer (DPO) is named in **Console → Settings → Data protection** and on the public privacy page.

## The clock

| When | What | Who |
| --- | --- | --- |
| At once | Record the breach in the register (it starts the **72-hour** clock from "became aware") and tell the DPO | Whoever finds it |
| First hours | Contain it; keep evidence | Technical lead (technical-admin) |
| Within 24 hours | Assess the risk; decide who must be told | DPO with the technical lead |
| Without undue delay | Tell the **customers** (agencies, landlords) whose tenants' or owners' data is involved: they are the controllers for it | DPO |
| **Within 72 hours** of becoming aware | Tell the **Nigeria Data Protection Commission** (NDPC), unless the breach is unlikely to result in a risk to people's rights and freedoms (then record why in the register) | DPO |
| Immediately, if the risk is high | Tell the **people affected** directly, in plain language | DPO (with the customer, for its tenants) |
| Within 2 weeks of containment | Close it: what was learned and changed | DPO |

The 72 hours run from when GoProperty **became aware** of the breach, not from when it happened or when the investigation ends. If not everything is known yet, notify with what is known and send the rest later ("phased notification").

**Two roles.** For the data an agency or landlord keeps about its tenants and property owners, the customer is the data **controller** and GoProperty its **processor**: GoProperty must tell the customer without undue delay, and helps it notify the NDPC and its tenants. For GoProperty's own customers' account data (owners' and staff's names, emails, phones, sign-in records) and GoProperty's own staff, GoProperty is the controller and notifies the NDPC itself. Most breaches involve both: do both.

## Who does what

- **Whoever finds it** (any staff member): do not try to investigate alone, do not delete anything. Record it in the register (title, what happened, when you became aware), then call the DPO and the technical lead. If you cannot reach the console, write down the time you became aware and tell the DPO by phone.
- **Technical lead** (a technical-admin, or a super-admin): contain it (below), keep evidence, find the scope (which organizations, how many people, which kinds of data), and update the register's details.
- **DPO** (Settings → Data protection): owns the decision and every notice; records each one in the register (**Commission told**, **Customers told**, **People told**, or **No notice needed** with the reason).
- **A super-admin**: ends sessions and changes credentials that need a super-admin (staff accounts, two-person approvals), and approves any data purge the response needs.

## 1. Contain

Pick what applies; note each step and its time in the breach's "Measures taken".

- **A staff account is compromised:** deactivate it in Staff (ends its sessions at once); review its actions (Staff → the member → Actions; Audit → Audit log, filter by staff member); end any support session it holds (Customers → Impersonation log).
- **A customer account is compromised:** have the owner reset the password; staff cannot change customers' passwords (PLATFORM_CONSOLE.md § 6). Check Audit → Access log for the account.
- **Secrets leaked** (APP_KEY, database password, S3 or Google keys, Paystack keys, GoText, Slack webhook): rotate them at the provider, then on the server (`.env`), then `php artisan config:cache`. Rotating `APP_KEY` needs the old key kept in `APP_PREVIOUS_KEYS` (Laravel reads both), or encrypted columns and the audit chain can no longer be read.
- **A bug shows data across organizations:** put the site in maintenance mode (`php artisan down`) if it is still happening; fix, deploy, then `php artisan up`. The cross-tenant tests (`tests/Feature/Tenancy/CrossTenantAccessTest.php`) must pass before going live again.
- **A backup or export file leaked:** backups are AES-256 encrypted zips; check whether the archive password (installation record) leaked with it. Data exports (Compliance → Data requests) are not encrypted: find who downloaded it (Audit → Audit log, event `data_export.downloaded`).
- **A server is compromised:** take it offline; restore to a clean server from the last good backup (docs/runbooks/restore.md); rotate every secret.

Keep evidence: do not delete logs, audit entries or files. The audit log is append-only and hash-chained; `php artisan` never edits it.

## 2. Assess

Answer in the register's fields:

- **What data:** kinds only (e.g. "tenants' names, phone numbers, ID types; no ID numbers") — never paste the data itself into the register.
- **Whose:** which organizations (by number, e.g. #12, #40) and about how many people.
- **Risk:** *unlikely to harm anyone* (e.g. encrypted data with the key safe), *a risk to people's rights*, or *a high risk* (ID numbers, bank details, data that enables fraud or harm; many people; vulnerable people).
- **Likely consequences** and **measures taken or proposed**.

## 3. Notify

Record each notice in the register as it is sent. Keep copies of what was sent.

### Template: to the Nigeria Data Protection Commission

Send through the channel the Commission publishes for breach notifications (check the NDPC website at the time; the address changes), from the DPO's address.

> **Subject:** Personal data breach notification — GoProperty — {register reference}
>
> 1. **Controller / processor:** {GoProperty's legal name}, {address}, {RC number}. Data protection officer: {name}, {email}, {phone}. For the tenants' data involved, GoProperty is the processor for the customers listed in 3.
> 2. **What happened:** {short description}. Became aware: {date and time, WAT}. Occurred: {date and time, or "not yet known"}.
> 3. **Data and people involved:** categories of personal data: {…}; categories of data subjects: {tenants / property owners / customers' staff / GoProperty staff}; approximate number of people: {n}; approximate number of records: {n}; organizations affected: {number}.
> 4. **Likely consequences:** {…}.
> 5. **Measures taken or proposed:** to address the breach: {…}; to mitigate its effects: {…}.
> 6. **People informed:** {yes, on {date}, by {email/SMS}} / {not yet: because …} / {not needed: because …}.
> 7. **More to follow:** {what is still being investigated, and when the next update will come}.

### Template: to a customer (the controller)

From the DPO, by email to every org-owner of the organization (Console → Customers → the organization shows them), and a call to the main owner.

> **Subject:** Important: a data incident affecting {organization name}'s data in GoProperty
>
> Dear {owner's name},
>
> On {date} we found that {plain description}. It involved {kinds of data} about {about n} of your {tenants / property owners}. It did not involve {what it did not, e.g. bank account numbers}.
>
> What we have done: {containment}. What we are doing next: {…}.
>
> As the organization that keeps your tenants' data, you may need to tell the Nigeria Data Protection Commission and your tenants. We have {told / will tell} the Commission about GoProperty's part on {date} (reference {…}) and will give you everything you need for yours: the list of people affected and a draft notice for them.
>
> Our data protection officer, {name} ({email}, {phone}), is your contact for this.

### Template: to the people affected

Plain words, no jargon; by email, or SMS when there is no email (keep SMS under 160 characters and point to a page or a phone number).

> **Subject:** About your personal data at {agency name} / GoProperty
>
> Dear {first name},
>
> On {date}, {what happened, in one sentence}. Your {name, phone number, …} may have been {seen by someone who should not have / copied}. {Your ID number and bank details were not involved.}
>
> What this could mean for you: {e.g. you may get calls or messages pretending to be from your agency}. What you can do: {e.g. do not share codes or passwords; your agency will never ask for them; check any request for money with your agency by phone}.
>
> What we have done: {…}. If you have questions, contact {DPO name, email, phone} or {agency contact}. You may also complain to the Nigeria Data Protection Commission.

SMS version: "{Agency}: some of your details (name, phone) may have been exposed on {date}. Never share codes or passwords. Questions: {phone}. Details: {short link}"

## 4. Close

When contained and every notice is sent: **Close** in the register with what was learned and what changed (a test added, a permission tightened, a provider changed). Review the register at each quarterly backup drill.

## Data protection requests (export and erasure)

The same console area holds **Compliance → Data requests** (`privacy.requests`):

- **Verify identity first.** A person asking for their data or its erasure must be who they say: a request from the email or phone on their record, or confirmed by the agency that manages their building. A tenant's request normally goes to their agency (the controller); GoProperty acts on a request the agency forwards, or directly when the agency is gone.
- **Reference, not names.** The "Reference and why" field keeps the ticket or letter and how identity was checked; it stays with the request, so use the ticket number rather than the person's name.
- **Exports** are ready within minutes, downloaded through a 15-minute link, kept 7 days. Send them to the person by a secure route (not as an email attachment to an unverified address).
- **Erasures** need a second staff member with `data.purge` (Staff → Approvals), then wait 90 days after the tenancy or subscription ended; the scheduler carries them out. Answer the person within 30 days of the request saying what will happen and when.

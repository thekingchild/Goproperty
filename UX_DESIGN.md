# GoProperty — UX & Design Baseline

Companion documents: [ARCHITECTURE.md](ARCHITECTURE.md) · [ONBOARDING_COMPLIANCE.md](ONBOARDING_COMPLIANCE.md) · [PLATFORM_CONSOLE.md](PLATFORM_CONSOLE.md)

## 1. Design Principles

1. **Mobile-first for agents and tenants.** Agents work from phones in the field; tenants use low-end Android on slow networks. Every customer and tenant screen is designed at 360 px width first.
2. **Show what needs action.** The agent home is a "Today" inbox (payments to confirm, statement matches, overdue bills, open tickets, leases expiring, compliance prompts), not a wall of charts.
3. **Money is always clear.** ₦ with thousands separators (₦1,250,000), no kobo shown unless non-zero, DD/MM/YYYY dates, Africa/Lagos time.
4. **Trust signals everywhere money appears.** Receipt QR code + verify link, "confirmed by" names, bank name check results, tier badge.
5. **Light on data.** Server-rendered pages, compressed images (WebP), proof images compressed in the browser before upload, no autoplay media, HTML receipts with PDF as download.
6. **Accessible:** WCAG 2.2 AA contrast, 44 px touch targets, visible focus, labels on every input, no information by colour alone.

## 2. Surfaces

| Surface | Style | Notes |
| --- | --- | --- |
| Marketing (`goproperty.ng`) | Bold, modern, product screenshots | Hero (§ 4), pricing, features, FAQs, signup |
| Customer app (`/o/{slug}`) | Clean dashboard, sidebar on desktop, bottom nav on mobile; agency logo in the sidebar | Installable PWA with push notifications. As built (P04-03): the Tickets page is an inbox — status tabs with counts (Open, Submitted, Under review, In progress, Reopened, Resolved, Closed, All; the row scrolls sideways on phones) and assignee tabs (Anyone, Assigned to me, Unassigned), each counting under the other filters; a lease's page lists its tickets with "Raise a ticket" |
| Tenant portal (`/portal`) | Very simple, card-based, big buttons; each agency's bills shown with **that agency's logo and colours** | "Pay this bill", "Upload proof", "Report a problem". As built (P03-11a): one card per agency in its colours (amount to pay, "Pay this bill", my homes, latest bills), my bills per agency under the logo of its newest bill, each bill in the branding it was issued with; Home / My bills tabs of 44 px; [ARCHITECTURE.md § 5](ARCHITECTURE.md) "Tenant portal". As built (P03-11b): Home / My bills / Payments / Documents tabs (two a row on phones); "Paid already? Send a proof" on the home card and the bill's page, a one-page proof form (amount, date, sender, bank, file) that keeps working while the agency is suspended; my payments (receipts, and each proof with where it stands); a receipt's page with "confirmed by", the verify link and the PDF; my documents (agreement, invoices, receipts and VOID copies) as downloads. Checked in real Chrome at 360 px on every portal page: no sideways scrolling, every tap target at least 44 × 44 px (links inside a sentence exempt); the app's mobile header buttons and the sign-in layout's small links and password eye were enlarged to 44 px for it. As built (P04-01): a fifth tab, **Repairs** (tabs come from `App\Navigation\PortalTabs`, each module adding its own): "Report a problem" (which home, kind of problem, how urgent with plain-words hints, a title and what is wrong; "Send to the agency" or "Save as draft"), per agency in its colours the drafts, the problems being dealt with and the closed ones (since P04-03 an inbox: two 44 px tabs "Open" and "Finished" with their counts across agencies; Open lists "Fixed? Please check" (resolved, waiting for the tenant) first, then "Being dealt with", then the drafts; Finished the closed ones; `?show=finished` keeps the tab), and a ticket's page with its status badge, the history with each note ("You" for the tenant's own moves) and, while allowed, "Confirm it's fixed" and "Reopen" with what is still wrong and until when; the same 360 px pass. Ticket status badges: draft and closed grey, submitted sky, under review amber, in progress indigo, resolved green, reopened red; priority badges low grey, medium sky, high amber, urgent red. As built (P04-02): a ticket's page has **Messages** after "Is it fixed?" / "Reopen": the thread with the agency (and the building's owner), each message with who wrote it ("You", a name), a party badge (Tenant sky, Agency grey, Owner violet), the time and its photos (thumbnails) or files (a 44 px chip with name, type and size; HEIC photos are files), then "Your message", "Photos or PDFs (optional, up to 5)" and a full-width "Send"; none for a draft, "its conversation has ended" once closed, hidden while the agency is on hold; the agency's internal notes never appear. Checked at 360 px with a thread of long words, photos and files |
| Owner portal (`/owner`) | Read-only statements and charts, agency-branded | Monthly statement PDF (P08). As built (P03-15, first cut): one card per agency that manages the owner's buildings, in its current colours and logo, with each building's units and occupancy; a building's page (units with status and active lease — the tenant's name only — and the latest bills), a lease's page and a bill's page; nothing editable, no pay or invoice links; "No properties yet" for someone who owns nothing. The owner invitation page (`/owner/invitations/{token}`) and `/owner/welcome` reuse the tenant invitation's split layout (§ 3.3). Checked in real Chrome at 360 px like the tenant portal: no sideways scrolling, 44 px targets Owner statements (P08-05): "Statements" on the home opens `/owner/statements`: per agency in its colours, the commission, upcoming renewals (90 days, renewal state, the tenant's name; phone and email only when the agency shares them) and the last 12 months with the net due and Settled / Unsettled (or In progress); a month's page shows the summary and payout record, then per building rent and utilities per unit, each amount counted, bills issued and charged expenses, with the PDF. Cards, 44 px targets, checked at 360 px. Repairs (P04-02): "Repairs" on the home opens `/owner/tickets`: per agency in its colours, the problems reported in the owner's buildings (number, status and priority badges, title, unit, kind, sent / resolved / closed dates; one card each, the whole card the link); a ticket's page shows the ticket, the tenant's name (phone and email only when shared), "Handled by" (or "Nobody at the agency yet"), the messages (never internal notes) and the history; while nobody is assigned the owner can reply ("Nobody at the agency is assigned to this ticket yet, so you can reply to the tenant here"), otherwise the page says someone at the agency handles it. Checked at 360 px. |
| Invoices, receipts, statements | Agency-branded documents ([BRANDING_DOCUMENTS.md](BRANDING_DOCUMENTS.md)) | Reference designs in `docs/design/templates/` |
| Platform console (`/{ADMIN_PATH}`) | Filament, dense tables | Distinct accent colour so staff always know they're in admin |

## 3. Login Pages

### 3.1 Customer login (`goproperty.ng/login`)

**Layout: split screen on desktop, single column on mobile.** Visual spec: [`docs/design/templates/login.html`](docs/design/templates/login.html) (see § 5).

| Area | Content |
| --- | --- |
| Left panel (desktop ≥ 1024 px, 55% width, sticky full height) | Brand gradient background with the apartment skyline art at low opacity, **or the uploaded login image** with the theme overlay ([PLATFORM_CONSOLE.md § 9.2](PLATFORM_CONSOLE.md)), GoProperty logo top-left; three floating glass activity cards ("Payment confirmed", "Receipt sent", "5 statement lines matched" — decorative, hidden on short screens, theme toggle); bottom-left: short headline ("Manage properties, rent and tenants in one place"), 3 benefit bullets with icons (Collect & match rent automatically · Instant receipts for tenants · Reports for every landlord), small trust line ("Bank-grade security · NDPA compliant · Made for Nigeria"). On mobile it becomes a slim top bar with the logo and a "Start free trial" link |
| Right panel | Top-right "New to GoProperty? [Start free trial]" (desktop). Centred form column, no card (max 400 px), heading "Welcome back", subtext "Sign in to your GoProperty account" |
| Form | 1. **"Sign in with a passkey"** button (only when the browser supports passkeys), then a divider "or use your password". 2. **Email or phone number** (one field with a person icon; a phone number is only a login name, normalised to +234 — no SMS is sent) with `autocomplete="username webauthn"` so saved passkeys are offered in the field. 3. Password with lock icon, show/hide eye, Caps Lock warning, "Forgot password?" on the label row. 4. "Keep me signed in on this device" (30 days). 5. Turnstile slot (hidden until needed). 6. Full-width deep-navy "Sign in" button with loading state |
| Below form | "New to GoProperty? Start free trial" (mobile only; desktop has it top-right). Footer: © year, Privacy, Terms, Help |
| States | Default · wrong details (generic error) · bot check (Turnstile shown, button disabled until it passes) · locked (15 min, link to reset password). All four can be previewed from the design file's state bar |

**No SMS codes anywhere.** SMS is paid per message, so signup, sign-in, 2FA, invitations and password reset never send one. Phone-only tenants sign in with their phone number + a password they set from their invitation link ([ONBOARDING_COMPLIANCE.md § 2](ONBOARDING_COMPLIANCE.md)).

**Security behaviour (all built in, mostly invisible):**

- Rate limit per email/phone and per IP; lockout after 5 failures for 15 min; Cloudflare Turnstile challenge after 3 failures, or on every attempt when "always" mode is on ([PLATFORM_CONSOLE.md § 9.6](PLATFORM_CONSOLE.md)). Turnstile is verified server-side and fails closed.
- Same error for wrong email or wrong password ("Those details don't match our records"), so attackers can't discover accounts.
- **Passkeys** (WebAuthn, Fortify passkeys): passwordless sign-in from the button or the field's autofill; users add/rename/remove passkeys under Profile → Security. After any password sign-in without a passkey, a one-time prompt offers "Add a passkey for faster sign-in".
- **2FA** (authenticator app, TOTP) with recovery codes, required for org-owners of Tier 2/3 organizations and optional for all others ([`login-2fa.html`](docs/design/templates/login-2fa.html)). A passkey sign-in already counts as two factors and skips the 2FA screen.
- New passwords checked against breached-password lists; minimum 10 characters.
- Session cookie `Secure`, `HttpOnly`, `SameSite=Lax`; session regenerated on login; "Log out other sessions" in profile.
- New-device login email.
- HTTPS only with HSTS; CSP headers; no third-party scripts on login pages except Turnstile (custom code from the console is never printed on auth pages).

### 3.2 Staff login (`goproperty.ng/{ADMIN_PATH}/login`)

Same visual quality, but clearly different: dark neutral background, GoProperty logo + "Staff console" label, single centred card, **no** signup link, **no** marketing content. Email + password, then mandatory 2FA code screen. Optional IP allowlist; 30-minute idle timeout with warning modal. The path is configurable and never `/admin`. Common guesses such as `/admin`, and any request from a non-allowlisted IP when the allowlist is on, get a plain 404 with no GoProperty branding, so the console is hard to find by scanning.

### 3.3 Other auth screens (same split layout)

Forgot password (one field, "Email or phone number"; the same reply whatever is typed: "If an account matches, we've sent a reset link by email or WhatsApp. No email? Ask your agency for a new sign-in link.") · Reset password · Verify email · **2FA challenge** ([`login-2fa.html`](docs/design/templates/login-2fa.html): 6 code boxes with auto-advance, paste support and auto-submit; "Don't ask again on this device for 30 days"; other ways to verify — passkey, recovery code, "Lost your phone and codes? Get help") · Accept invitation (shows the agency's logo, who invited you and to which organization; set a password, optionally add a passkey; phone-only tenants see an optional "Add an email for easy password resets"; built for team members in P01-04 at `/invitations/{token}` with name, optional mobile number and password, "Sign in to accept" for existing accounts and one "no longer works" page for used, revoked or expired links — the agency logo arrives with branding, P01-05, and a passkey can be added afterwards under Settings → Security; tenants' invitations are built in P03-01b at `/portal/invitations/{token}`: agency and inviter, the address or masked number the link is for, name (prefilled) and password, or "Sign in to accept" when an account already uses that address or number; then "Your account is ready" with an optional "Add a passkey" (Settings → Security, no second password prompt) and "Go to my rentals". The optional "Add an email" for phone-only tenants moves to the portal profile, P03-11a. The agency's one-time sign-in link page, `/portal/sign-in/{token}`, has the same layout: "Sign in, {first name}", an optional new password and one button) · Organization picker · **Signup** ([ONBOARDING_COMPLIANCE.md § 2](ONBOARDING_COMPLIANCE.md)): the account step ("Start your free trial": name, work email, mobile number, password with show/hide; terms line) and "Check your email" use this layout with the seven signup steps in the brand panel; the wizard's later steps (organization, Transaction Tier, plan, collections, properties) keep the same shell with a slightly wider form column, choice cards for type, tier and plan, "Back" beside the main button, and "Step 4 of 7" above the form on phones. The workspace dashboard then shows the setup checklist (progress bar, one row per item) until it is done.

## 4. Marketing Landing Page (`goproperty.ng`)

Visual spec: [`docs/design/templates/landing.html`](docs/design/templates/landing.html) (+ `assets/landing.css`, `assets/landing.js`). Style reference for the sections below the hero: the product owner's MembersFlow landing page — editorial headings that mix regular and bold weights, product screens **rebuilt in HTML** instead of screenshots or stock photos, and small interactions that let visitors try the product. Every number on the page comes from these docs (prices from the plans table).

- **Nav bar (fixed):** logo left; links Product · Features · Payments · Pricing · FAQ; right side "Log in" (text) + "Start free trial" (white pill). Floats over the hero and turns into a dark glass bar once the page scrolls. Hamburger + dialog menu on mobile.
- **Hero (approved — keep as is):** dark brand gradient with the skyline art, or the uploaded hero image with overlay ([PLATFORM_CONSOLE.md § 9.2](PLATFORM_CONSOLE.md)). 100 svh on desktop, ~88 svh on mobile. Left: pill badge, very large two-line headline ("Rent in. / Receipts out.", second line amber), trust row. Right (bottom-aligned): lede, big white "Start free trial" pill, "Prefer a walkthrough? Book a demo".

Sections, in order:

| # | Section | What it does |
| --- | --- | --- |
| 1 | Fact strip | ₦0 commission on rent · ₦10,000/month to start · Dry run before any import |
| 2 | **The workspace** | Browser-window mock of the agency "Today" list (KPIs + tasks) with a phone showing the tenant's verified receipt. Tabs switch between **Agency desk / Landlord view / Tenant app** |
| 3 | Statement band (dark) | "Rent records don't belong in ~~WhatsApp chats~~ and ~~Excel sheets~~" + 4 short outcomes |
| 4 | **Feature bento** | Mixed-size cards, each with a live mini-visual: bank statement matching (table), Building Group (chat), lease cycles, ticket pipeline, utilities, Excel import (dry-run bar), owner statements (chart) |
| 5 | **Payments** | Tabs **Bank transfer / Proof upload / Your Paystack**; each shows a 4-step animated flow and three checks. Notes: no commission, "confirmed by" on every payment, Transaction Tiers |
| 6 | **Reminders** | Slider "days to rent due" (−14 → +3) that shows the exact message the tenant gets at each stage and on which channels |
| 7 | **Your brand** | Colour swatches recolour a sample agency receipt live; "Scan the QR code" flips it to the public verification page |
| 8 | **Pricing** | Unit slider recommends the cheapest plan and shows the monthly price and per-unit cost; Monthly/Yearly toggle; 4 plan cards (Growth featured, matching plan outlined) |
| 9 | Security (dark) | Passkeys & 2FA · bot protection · audit log · encrypted backups · NDPA · verifiable documents |
| 10 | Getting started | 4 steps on a timeline: Start trial → Import → Brand → Send first bills |
| 11 | FAQ | Accordion (money handling, import, pricing, trial end, landlord/tenant logins, Transaction Tiers) |
| 12 | Final CTA (dark, skyline) | "Your next rent cycle could be the easy one." + trial and demo buttons |
| 13 | Footer | Logo, footer text, social links (theme settings), Product / Company / Help columns |

- Interactions are keyboard accessible (tabs use arrow keys), respect `prefers-reduced-motion`, and the page still reads correctly with JavaScript off (default tab content is in the HTML).
- Sections fade up once as they enter the viewport. No autoplay video, no carousels.
- SEO and custom-code slots in `<head>`/`<body>` follow [PLATFORM_CONSOLE.md § 9.4–9.5](PLATFORM_CONSOLE.md).
- Testimonials and a customer-logo strip are added only when real ones exist.

## 5. Visual Reference (SocietyFlow)

The product owner wants the hero and login quality to match **SocietyFlow**. The reference was shared in Oct 2026 (a local build); screenshots are in [`docs/design/reference/`](docs/design/reference/README.md). The resulting GoProperty designs are [`landing.html`](docs/design/templates/landing.html), [`login.html`](docs/design/templates/login.html) and [`login-2fa.html`](docs/design/templates/login-2fa.html) in `docs/design/templates/` — open them in a browser; preview images sit next to them. **These files are the visual spec for the Blade views**; §§ 3–4 remain the functional spec.

### What we borrow

| Area | From SocietyFlow | In GoProperty |
| --- | --- | --- |
| Hero shell | Full-bleed dark hero, 100 svh on desktop (80–88 svh on mobile), nav sits on top of it | Same. Brand gradient (deep navy derived from primary) + soft light beam top-right |
| Hero background | Photo with a dark overlay at ~60% | Default: our own SVG skyline of apartment blocks with amber lit windows (`templates/assets/skyline.svg`, ~16 KB, no photo download on slow networks). Theme "image" style swaps in an uploaded photo with the same overlay |
| Hero type | Very large two-line headline at the bottom-left (weight 800, tight −0.04em tracking), second line a different tone | Same structure; second line in the accent colour (amber) |
| Hero actions | Right column, bottom-aligned: one-sentence lede, one big full-width white pill button (60 px), one text line with a link under it | Same: lede → "Start free trial" pill → "Prefer a walkthrough? Book a demo" |
| Nav | Logo left, plain white links, white pill "Log in" right; hamburger opens a dialog menu on mobile | Same; desktop shows "Log in" as text + "Start free trial" as the pill |
| Login shell | Split screen 55/45 from 1024 px; left panel is sticky and full height; on mobile the panel shrinks to a slim top bar with the logo | Same |
| Login panel | Photo + overlay, logo top-left, one caption bottom-left | Gradient + skyline (or uploaded photo), logo top-left; bottom-left has the theme headline, 3 benefit bullets and the trust line from § 3.1 |
| Login form | Plain column (no card) max 400 px, centred; 26–28 px heading + one muted line; labels above inputs; "Forgot your password?" on the password label row; eye toggle in the field; full-width deep-navy button; "New to…? link" line under it | Same, including SocietyFlow's "Email or phone number" field and passkey button + divider; plus input icons, Caps Lock warning and the Turnstile slot |

### What we leave out

- SocietyFlow's "Try the demo as…" persona grid and demo banner (demo-only features).
- SocietyFlow's photos, logo and wording.

### Decisions this changed

- No laptop/phone product mock-up inside the hero — the big type is the hero. Product screenshots move to the Features section.
- "Book a demo" is a text link under the primary button, not a second outline button.
- Oct 2026 (second round): sections below the hero redesigned in the MembersFlow style (§ 4); SMS codes removed everywhere (login, signup, invitations, resets); passkeys, Turnstile and the 2FA screen added (§ 3); hero and login images made replaceable, with SEO and custom-code settings modelled on SocietyFlow ([PLATFORM_CONSOLE.md § 9](PLATFORM_CONSOLE.md)).

Rule: use the reference as **inspiration only**. Never copy SocietyFlow's logo, illustrations, wording or exact layout. GoProperty must have its own brand.

## 6. Design System (Phase 0)

- Theme tokens come from the **configurable platform theme** ([PLATFORM_CONSOLE.md § 9](PLATFORM_CONSOLE.md)): primary, accent, neutral scale, success/warning/danger, light and dark variants, font, corner style — all CSS variables, so the theme can change without rebuilding assets.
- Font: chosen in the theme from bundled sans-serifs with good numerals (default Inter), served locally.
- Components: buttons, inputs (with ₦ and +234 prefixes), status badges (bill/lease/ticket/tier), cards, tables that become cards on mobile, empty states with a clear next step, toasts, modals, bottom sheets on mobile.
- Brand: product name, logos and colours are theme settings. The shipped default (blue `#1D4ED8`, amber accent, Inter) is a placeholder that can be replaced in the console at any time.

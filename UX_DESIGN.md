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
| Customer app (`/o/{slug}`) | Clean dashboard, sidebar on desktop, bottom nav on mobile; agency logo in the sidebar | Installable PWA with push notifications |
| Tenant portal (`/portal`) | Very simple, card-based, big buttons; each agency's bills shown with **that agency's logo and colours** | "Pay this bill", "Upload proof", "Report a problem" |
| Owner portal (`/owner`) | Read-only statements and charts, agency-branded | Monthly statement PDF |
| Invoices, receipts, statements | Agency-branded documents ([BRANDING_DOCUMENTS.md](BRANDING_DOCUMENTS.md)) | Reference designs in `docs/design/templates/` |
| Platform console (`/{ADMIN_PATH}`) | Filament, dense tables | Distinct accent colour so staff always know they're in admin |

## 3. Login Pages

### 3.1 Customer login (`goproperty.ng/login`)

**Layout: split screen on desktop, single column on mobile.**

| Area | Content |
| --- | --- |
| Left panel (desktop ≥ 1024 px, 50–55% width) | Brand gradient background (deep primary → slightly lighter, subtle pattern of building outlines at low opacity), GoProperty logo top-left, short headline ("Manage properties, rent and tenants — all in one place"), 3 benefit bullets with icons (Collect & match rent automatically · Instant receipts for tenants · Reports for every landlord), a floating product screenshot card (dashboard or receipt) with soft shadow, small trust line at bottom ("Bank-grade security · NDPA compliant · Made for Nigeria") |
| Right panel | Centred card (max 420 px), logo on mobile only, heading "Welcome back", subtext "Sign in to your GoProperty account" |
| Form | Tabs or toggle: **Email** / **Phone**. Email + password with show/hide eye icon, or phone number (+234 prefix, numeric keyboard) + "Send code" (OTP). "Remember this device" checkbox. "Forgot password?" link right-aligned. Full-width primary button "Sign in" with loading state |
| Below form | "New to GoProperty? Start free trial" link → `goproperty.ng/signup`. Footer: © year, Privacy, Terms, Help |

**Security behaviour (all built in, mostly invisible):**

- Rate limit per email/phone and per IP; lockout after 5 failures for 15 min; Cloudflare Turnstile challenge after 3 failures.
- Same error for wrong email or wrong password ("Those details don't match our records"), so attackers can't discover accounts.
- 2FA (authenticator app) required for org-owners of Tier 2/3 organizations and optional for all others; recovery codes; passkeys later.
- New passwords checked against breached-password lists; minimum 10 characters.
- Session cookie `Secure`, `HttpOnly`, `SameSite=Lax`; session regenerated on login; "Log out other sessions" in profile.
- New-device login email.
- HTTPS only with HSTS; CSP headers; no third-party scripts on login pages except Turnstile.

### 3.2 Staff login (`goproperty.ng/{ADMIN_PATH}/login`)

Same visual quality, but clearly different: dark neutral background, GoProperty logo + "Staff console" label, single centred card, **no** signup link, **no** marketing content. Email + password, then mandatory 2FA code screen. Optional IP allowlist; 30-minute idle timeout with warning modal. The path is configurable and never `/admin`. Common guesses such as `/admin`, and any request from a non-allowlisted IP when the allowlist is on, get a plain 404 with no GoProperty branding, so the console is hard to find by scanning.

### 3.3 Other auth screens (same split layout)

Forgot password · Reset password · Verify email · Phone OTP entry (6 boxes, auto-advance, paste support, resend timer 60 s) · 2FA challenge · Accept invitation (shows who invited you and to which organization) · Organization picker.

## 4. Marketing Hero (`goproperty.ng`)

- **Nav bar:** logo left; links Features · Pricing · For Agencies · For Landlords · Help; right side "Log in" (text) + "Start free trial" (primary button). Collapses to hamburger on mobile.
- **Hero, two columns on desktop:**
  - Left: small pill badge ("Built for Nigerian property managers"), headline of up to 8 words (e.g. "Rent collection and property management, simplified"), one-sentence subheadline, two CTAs ("Start free trial" primary, "Book a demo" secondary/outline), a trust row (e.g. "14-day free trial · No card required · Cancel anytime").
  - Right: product mock-up. Laptop dashboard with a phone overlapping it showing the tenant receipt screen. Soft glow/gradient blob behind.
- **Directly below:** logo strip of early customers (when available) or 3 headline stats; then a 3-step "How it works" (Add properties → Send bills → Get paid & receipt automatically).
- Background: light, with a subtle brand gradient wash at top. Large, generous spacing (hero ≥ 80 vh on desktop).

## 5. Visual Reference (SocietyFlow)

The product owner wants the hero and login quality to match **SocietyFlow** (`demo.societyflow.ng` and `demo.societyflow.ng/login`). When this document was written (Oct 2026) those pages could not be reached from the build environment (domain did not resolve), so they are **not yet captured**.

**Action for the product owner:** save full-page screenshots (desktop and mobile) of the SocietyFlow hero and login page into `docs/design/reference/` (e.g. `societyflow-hero-desktop.png`, `societyflow-login-mobile.png`) so the reference survives if the site goes offline. The designer then notes what to borrow (layout, spacing, hierarchy, mood) in this section.

Rule: use it as **inspiration only**. Never copy SocietyFlow's logo, illustrations, wording or exact layout. GoProperty must have its own brand.

## 6. Design System (Phase 0)

- Tailwind CSS theme tokens: primary, accent, neutral scale, success/warning/danger, plus light and dark variants.
- Font: one modern sans-serif with good numerals (e.g. Inter or Plus Jakarta Sans), served locally.
- Components: buttons, inputs (with ₦ and +234 prefixes), status badges (bill/lease/ticket/tier), cards, tables that become cards on mobile, empty states with a clear next step, toasts, modals, bottom sheets on mobile.
- Brand: final name check, logo and colour palette are open decisions ([IMPLEMENTATION_PLAN.md § 8](IMPLEMENTATION_PLAN.md)).

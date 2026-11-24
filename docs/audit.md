# Security And Compliance Audit: 24 November 2026

Branch `audit/full-review`, built on `build/bespoke-platform` (PR #1). The build was checked as if seen for the
first time, under UK GDPR, DPA 2018, PECR and UK consumer law.

**How it was checked:**
- Reading the code.
- The full test suites.
- A real Chrome run of every page (mobile, tablet and desktop).
- axe-core WCAG 2.2 AA scans of 24 pages, signed out and in.
- `composer audit` and `npm audit`.
- New regression tests for every fix.

Nothing here was checked against a live server, because there isn't one yet.

## 1. Map Of The Build

| Part | What there is |
|---|---|
| Pages (Astro, `frontend/src/pages`) | 41 routes. **Public:** home, competitions, competition, winning numbers, entry lists, winners, instant winners, verify, how it works, FAQ, contact, free entry, legal (6), login, join, forgot/reset password, verify email, basket, vault case (off), game demos, 404, robots, sitemap. **Signed in:** account, tickets, wallet, settings, safer play, loyalty, membership, refer, checkout, order, payment failed/cancelled. **Endpoints:** logout (POST), `/r/{code}` |
| API (Laravel, `routes/api.php`) | 58 endpoints. **Public:** competitions, entry lists, winners, pages, site, contact, experiments, recommendations, referral invite, basket price, health. **Auth:** register, login, logout, forgot, reset, verify, resend. **Signed in (`/api/me/*`):** profile, address, preferences, limits, break, self-exclude, deposits, withdrawals, ID check, tickets, wallet, loyalty, membership, referrals, vouchers. **Orders:** checkout, show, play, return, repeat. **Webhooks:** Cashflows, SMS opt-out |
| Admin (Filament `/admin`) | 2FA for every admin. Five roles: admin, manager, finance, marketing and support (`App\Support\Roles`). Plus 4 routes outside Filament (ID documents, evidence pack, report exports and print, live draw reveal) |
| Database | 59 tables: 46 of ours plus Verity's 13. Personal data sits in `users`, `orders`, `order_lines`, `wallet_ledger`, `withdrawals`, `id_checks`, `postal_entries`, `prize_claims`, `contact_messages` (with IP), `spend_limits`, `loyalty_entries`, `memberships`, `referrals`, `vouchers`, `meta_events`, `sessions`, `experiment_assignments`, `disputes` and `verity_entries` (owner ref only) |
| Storage | `local` (private, `storage/app/private`): ID documents and CSV imports, deleted after import. `public` (`/storage`): competition pictures and winner photos |
| Scheduled jobs | Verity (publish, close, draw, audit), `orders:sweep`, `players:housekeeping`, `platform:memberships`, `platform:reports`, `platform:conversion`, `platform:end-prize-rules` and `platform:meta-events` |
| Environment | `APP_*`, `DB_*`, `SESSION_*` (secure, lax), `SANCTUM_STATEFUL_DOMAINS`, `RESEND_API_KEY`, `CASHFLOWS_*`, `VERITY_*`, `META_CAPI_TOKEN`, `META_AD_ACCOUNT_ID` / `META_MARKETING_TOKEN`, `SENTRY_LARAVEL_DSN`, `MARKETING_*`, `SMS_*`, `CONTACT_INBOX` and `ADMIN_ALERT_EMAILS`. **Front end:** `API_INTERNAL_URL`, `PUBLIC_SITE_URL`, `PUBLIC_SEARCH_INDEXING` and `PUBLIC_GOOGLE_SITE_VERIFICATION`. No secret reaches the browser, and none is committed (only `.env.example`, with blank values) |

### Cookies and browser storage the code sets

| Name | Kind | Set when | Purpose |
|---|---|---|---|
| `cp_session`, `XSRF-TOKEN`, `remember_web_…` | Cookie | Always | Sign-in, protection against forged requests |
| `cp_consent` | Cookie | After a choice | Copy of the choice, for the server (Conversions API) |
| `cp_src` | Cookie | After Accept | First ad source, for marketing reports |
| `cp_vid` | Cookie | After Accept | A/B test visitor id |
| `cp_ref` | Cookie | On a `/r/{code}` share link (referrals on) | Fills in the referral code at sign-up |
| `_fbp`, `_fbc` | Cookie (Meta) | After Accept | Meta Pixel |
| `_ga`, `_ga_*`, `_gcl_*` | Cookie (Google) | After Accept | GA4, Google Ads (if IDs set) |
| `cp-cookie-consent` | localStorage | On a choice | The choice, date, version |
| `cp-basket-v1` | localStorage | Adding to basket | Basket |
| `cp-device` | localStorage | At sign-up | Random id, hashed server-side, for referral fraud checks |
| `cp-translate` | localStorage | On "Translate This Site" | Remembers the visitor asked for translation |
| `cp-g-purchase:*`, Meta purchase keys | localStorage | After Accept, on purchase | Count a sale once |

### Third parties that receive personal data

- **Cashflows:** payments.
- **Resend:** email.
- **Sentry:** errors, with `send_default_pii` off.
- **Meta:** Pixel and Conversions API, after Accept, with personal details SHA-256 hashed.
- **Google:** GA4, GTM and Ads, after Accept.
- **Weglot:** page text, only when asked.
- **Mailchimp, Klaviyo or a marketing webhook:** marketing consenters only.
- **SMS provider:** via webhook.
- **Hosting:** Laravel Forge or the hosting provider, and Cloudflare if proxied.
- **No personal data:** drand (public randomness), and Verity's timestamp authority, which receives hashes only.

## 2. Scorecard

| Area | Before | After | Note |
|---|---|---|---|
| 1. Security | Amber | Green | Open redirect and ID-document access fixed. Payments, IDOR, CSRF, uploads, rate limits and secrets checked and sound. Live headers unverified |
| 2. GDPR / PECR | Red | Amber | Reset token/email no longer sent to Meta or Google, Weglot gated, data export added. Policies are out of date, consent isn't granular and there is no retention clean-up (all need decisions) |
| 3. Legal / consumer | Red | Red | Terms contradict the skill question and the per-player limit. Guessed VAT and jurisdiction statements. Company details TBC |
| 4. Functionality | Green | Green | All suites pass. Empty-basket "0" badge bug fixed |
| 5. Accessibility | Amber | Green | Label-in-name failures on every page fixed. axe is clean except the Vault Case button (feature off) |
| 6. Performance | Green | Green | Pages 90–320 kB, JS at most 170 kB, self-hosted fonts, lazy images |
| 7. SEO / technical | Green | Green | Unique titles and descriptions, canonical, OG, sitemap, robots, 404, favicon. Search engines shut out until launch |
| 8. Content | Red | Amber | Unbacked "from 1p" claim removed. Brand, logos, darts artwork and legal drafts are still placeholders (known) |
| 9. Code quality | Amber | Amber | 0 type errors or warnings. Pint style differs across base files. Dev-only Vitest advisories |
| 10. Competition compliance | Amber | Amber | Postal route, Verity draws, row-locked overselling checks and masked winners are sound. Terms vs skill question needs a legal decision |

## 3. Fixed Issues

After the fixes, every suite passes:

| Suite | Result |
|---|---|
| Backend | 286 passed |
| `astro check` | 0 errors |
| Unit and e2e | 185 passed |
| Full-stack | 19 passed |
| Visual | 148 passed (see the journey note in the change log) |

| ID | Sev | Area | Issue (evidence) | Change | Verified |
|---|---|---|---|---|---|
| F-01 | High | Security | Open redirect after sign-in. `safeNext` blocked only `\r\n`, so `/login?next=/%09/evil.com` sent the player to `window.location.assign("/\t/evil.com")`, which browsers resolve to `https://evil.com/` (checked with `new URL`) | `src/lib/safe.ts` refuses all control characters | Failing unit test first (`lib.test.ts`), then passing |
| F-02 | High | Security / privacy | Any admin role (marketing, finance) could open players' passports and proof of address at `/admin/id-checks/{check}/{which}`. The route checked admin and 2FA, not role | Route also needs `Roles::can($user, 'verification')` (manager, support, admin) | `AuditTest::test_only_admins_who_check_id…`: failed (200 for marketing), now 403/200 by role |
| F-03 | High | GDPR | The password reset link (`/reset-password?token=…&email=…`) starts Meta's Pixel and Google tags for anyone who accepted cookies. They send the page address, so the player's email and a live reset token would go to Meta and Google | `addressIsPrivate()` in `consent.ts`. Pixel and Google tags never start when the address holds `token`, `email` or `signature` | Unit test failed without the fix, passes with it |
| F-04 | High | PECR / GDPR | Weglot loaded on every page as soon as a key was set, before any cookie choice. That broke the project's "nothing third-party before a choice" rule, and on account and checkout pages it sends the page's text (name, balance, tickets, address) to Weglot | Weglot loads only after "Translate This Site" (footer, remembered in `cp-translate`) and never on `/account`, `/checkout`, `/order` or `/payment` | Full-stack test (no Weglot script, `data-weglot-ok="0"` on signed-in pages) and a real-browser run (no request before the click, one after, none on `/account/wallet`, no page errors) |
| F-05 | Medium | GDPR | No way to answer a subject access request except by hand across 14 tables | Players → "Export personal data" (JSON, reason required, logged). Secrets left out; ID documents listed, not attached | `SowGapsTest::test_an_admin_exports…` |
| F-06 | Medium | Accessibility / function | The basket badge's `hidden` attribute was overridden by CSS, so every page showed "0" on an empty basket. The link was named "Basket" while showing a number (WCAG 2.5.3) | `.ib .badge[hidden]{display:none}`. The label becomes "Basket, N items" | axe re-run: no failures on 24 pages (except F-note below) |
| F-07 | Medium | Accessibility | Home hero link's `aria-label` replaced its visible text. Loyalty "Get It" buttons were named "Get {reward}" (2.5.3) | Hero: title as visually hidden text. Buttons: visible words first in the name | axe re-run |
| F-08 | Medium | Content / ASA | "UK prize competitions from 1p" (default meta description) and "from 1p a ticket" (competitions page): nothing sets a 1p price | Removed the claim | SEO e2e test (lengths) passes |
| F-09 | Medium | Legal / content | Footer hard-coded "registered in England and Wales", a guess about a company not yet given. It also read "© 2026 Brand Name is a trading name of…" | `COMPANY.registeredIn` (TBC; the launch build refuses while TBC). Sentence fixed | Build, screenshots |
| F-10 | Low | GDPR | Contact form collected name and email with no link to the privacy notice | Line plus link under the button | Build |
| F-11 | Low | Code | CLAUDE.md and the CSP comment said the Meta Pixel was the only third party | Updated | n/a |
| – | Check | Security | Does a password change or reset sign out other sessions? | No change needed: Sanctum's `AuthenticateSession` does it | New regression test `AuditTest::test_a_new_password_signs_out_every_other_session` |

## 4. Needs Liam's Action

| ID | Priority | What and why | Steps |
|---|---|---|---|
| N-01 | **Critical** | **Terms contradict how the site works.** Terms 4.5: "Prizes are awarded by chance. No skill or judgement is involved". Yet every paid entry now has to answer a skill question (`require_skill_question` on). Terms 4.4: "no limit on the number of Tickets one person may hold". Yet competitions can now have a limit per player. The skill question is multiple choice and can be retried at once, so it wouldn't stand as a real skill test on its own. The site's lawful basis should be the free postal route with an equal chance (which works) | Decide with Hamid's solicitor: free-entry model only (then make the skill question optional or drop it), or a genuine skill test (harder questions, limited retries). Then redraft Terms 4.4, 4.5 and the FAQ. I'll change the settings to match |
| N-02 | **High** | **Privacy and cookie policies don't match the build.** The privacy policy doesn't name Google, Weglot, Mailchimp/Klaviyo or the SMS provider, and calls Resend only "our email provider". The cookie policy lists only `cp_session`, `XSRF-TOKEN`, `remember_web_…`, the basket, the consent choice and `_fbp`. It's missing `_fbc`, Google's `_ga*`/`_gcl*`, `cp_consent`, `cp_src`, `cp_vid`, `cp_ref`, `cp-device` and `cp-translate` (see the tables above) | Give the tables in section 1 to whoever drafts the legal pages (SOW 3.5). Edit in Admin → Pages or `backend/resources/legal/*.html` |
| N-03 | High | **Guessed facts in the legal drafts.** Terms 10.1 says "We are not VAT registered". Terms 18.5 says English law and courts, while players in Scotland and NI are accepted | Confirm VAT status with Hamid's accountant, and the governing-law and jurisdiction wording with the solicitor |
| N-04 | Medium | **Cookie consent isn't granular.** One "Accept" switches on Meta, Google Analytics, Google Ads and A/B tests together. The ICO expects separate choices for analytics and marketing | Say yes and I'll add "Analytics" and "Marketing" toggles (Meta, Ads and `cp_src` under Marketing; GA4 and A/B tests under Analytics; GTM needs both). About half a day |
| N-05 | Medium | **No retention clean-up.** The privacy policy promises periods (e.g. 6 years), but nothing deletes old contact messages (with IP), ID documents after checking, Meta event queues or sign-up hashes | Decide the periods per data type, then I build a nightly pruning job |
| N-06 | Medium | **Support staff can move money.** The support role can add and remove wallet credit (Players area) | Decide whether only admin/finance should; then I tie those actions to the finance area |
| N-07 | Medium | **`cp-device` is stored at every sign-up without consent** (fraud check for referrals) | Ask the solicitor whether the fraud-prevention exemption covers it; otherwise I limit it to when referrals are switched on and list it in the cookie policy |
| N-08 | Medium | **CI can't install Verity.** `COMPOSER_AUTH` isn't set on the CPBE repo, so the backend CI job fails at `composer install` (checked again: still not rerun) | GitHub → CPBE → Settings → Secrets and variables → Actions → `COMPOSER_AUTH` = `{"github-oauth":{"github.com":"<token with read access to Sprouthubuk/verity-engine>"}}`, then re-run the jobs |
| N-09 | Medium | **Email sending domain** | When the domain is chosen: add Resend's SPF and DKIM records, and DMARC (`v=DMARC1; p=quarantine; rua=mailto:…`), then check with mail-tester.com |
| N-10 | Low | **"Raffles" in page titles** ("Live UK Competitions & Raffles"). In law a raffle is a lottery | Decide whether to drop the word. A one-line change in `src/pages/competitions/index.astro` |
| N-11 | Low | Company details, brand and legal drafts are TBC (known; the launch build refuses) | As before |

## 5. Unverified (Can't Check From Here)

- **Live response headers** on `/admin` and `/api`. HSTS and CSP come from Astro for pages only; Laravel relies on Forge's default nginx headers.
  - Check: `curl -sI https://<domain>/admin/login` should show `X-Frame-Options`, `X-Content-Type-Options` and HSTS.
  - Score it at securityheaders.com.
- **TLS and redirects.**
  - Check: run ssllabs.com on the domain, and confirm `http://` and `www.` both redirect to `https://<domain>`.
- **Production settings:** `APP_DEBUG=false`, `APP_ENV=production`, `SESSION_SECURE_COOKIE=true`.
  - Check: in Forge → Environment, then visit `/api/nope` and expect `{"message":"Not found."}`.
- **Cashflows live:** card success, decline, cancel and webhooks, and recurring membership charges.
  - Check: run the journeys in the test suite against Cashflows' integration environment with real keys.
- **Meta and Google receiving events.**
  - Check: Meta Events Manager → Test events, and GA4 DebugView, after accepting cookies.
- **Weglot translating real pages.**
  - Check: after the key is set, press "Translate This Site" and check pages and the switcher.
- **Cookies in Safari and Firefox**, and that withdrawing consent clears `_fbp` and `_fbc` there.
  - Check: DevTools → Application → Cookies, before and after Accept and Reject.
- **Backups and restores of the database and `storage/app/private`.**
  - Check: in Forge (or the host), confirm a daily backup and test one restore.

## 6. Recommendations (Impact vs Effort)

1. **Granular consent (N-04).** High impact, half a day.
2. **Retention pruning job (N-05).** High impact, half a day once periods are decided.
3. **Laravel security headers middleware for `/admin` and `/api`** (CSP, HSTS, frame-ancestors), so they don't depend on the server config. Medium impact, an hour.
4. **Block known breached passwords.** Use Laravel's `Password::uncompromised()`, as NCSC guidance suggests (sends a hash prefix to Have I Been Pwned). Medium impact, 15 minutes.
5. **Keep the axe accessibility scan in `test:visual`** (it was run as a one-off here). Medium impact, an hour.
6. **Upgrade Vitest to 4.1.12 or later.** Clears critical `npm audit` advisories in dev tools only (Tinypool, @vitest/mocker); nothing in production uses them. Low impact, an hour or two.
7. **Run Pint over the backend once**, in its own PR; base files don't follow it. Low impact, 10 minutes.
8. **Vault Case button name (feature off):** include its visible price in the accessible name before switching it on. Low impact, 10 minutes.
9. **SMS opt-out webhook** loads every user with a phone to match one number. Store a normalised phone column before the list grows. Low now, an hour.
10. **Sign-up says when an email already has an account** (account enumeration). Common and throttled (50/hour per IP); keep unless Hamid wants a "check your email" flow.

## 7. Change Log

| File | Change |
|---|---|
| `frontend/src/lib/safe.ts` | Refuse control characters (F-01) |
| `backend/routes/web.php` | ID documents need the verification role (F-02) |
| `frontend/src/lib/consent.ts`, `frontend/src/lib/google.ts` | `addressIsPrivate()`; trackers never start on those pages (F-03) |
| `frontend/src/layouts/Layout.astro`, `frontend/src/components/Footer.astro`, `frontend/src/styles.css` | Weglot on request only, not on signed-in pages; "Translate This Site" button (F-04) |
| `backend/app/Support/PersonalData.php` (new), `backend/app/Filament/Resources/Users/UserResource.php` | Export personal data action (F-05) |
| `frontend/src/components/Header.astro`, `frontend/src/layouts/Layout.astro`, `frontend/src/styles.css` | Basket badge hidden when empty; label with count (F-06) |
| `frontend/src/pages/index.astro`, `frontend/src/islands/LoyaltyPanel.tsx` | Accessible names (F-07) |
| `frontend/src/layouts/Layout.astro`, `frontend/src/pages/competitions/index.astro` | "From 1p" claim removed (F-08) |
| `frontend/src/lib/company.ts`, `frontend/src/components/Footer.astro` | `registeredIn` TBC; copyright sentence (F-09) |
| `frontend/src/islands/ContactForm.tsx` | Privacy link (F-10) |
| `CLAUDE.md`, `frontend/src/middleware.ts` | Third-party rules and CSP comment (F-11) |
| `frontend/tests/visual/journey.test.ts` | Waits for the order page instead of a fixed 1.5 s (it failed once on a slow run; the order had gone through) |
| Tests | `backend/tests/Feature/AuditTest.php` (new), `SowGapsTest.php`, `frontend/tests/unit/lib.test.ts`, `consent.test.ts`, `tests/fullstack/admin-content.test.ts` |
| `docs/audit.md` | This report |

No settings, keys, data or database structure were changed. To roll back, revert the commit on `audit/full-review`.

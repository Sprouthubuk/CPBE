# Scope map: SOW 16 November 2026 → code

The Statement of Work (RaffleLabs Bespoke Package, Sprout Hub Limited for Hamid Reza Mousavizadeh) lists the
deliverables in sections 2 and 3. This file maps each one to where it lives and its status. Anything not here is a
change request (SOW section 8).

Status: **Built** = done and covered by tests · **Needs Liam/Client** = waiting on an input, account or decision ·
**Not code** = a design, document or service deliverable outside the repo.

## 2. Platform

| SOW | Item | Where | Status |
|---|---|---|---|
| 2.1 | Bespoke design, all page designs, 2 revision rounds, no RaffleLabs/Sprout Hub branding | Every page already exists (`frontend/src/pages`), in a temporary neutral look (`src/styles.css`). "Brand Name" and "LOGO TBC" are placeholders. | Needs Liam/Client (brand and design) |
| 2.2 | Create, edit, schedule, close competitions; title, description, images, prize value, cash alternative, price, max tickets, start/end, category | Admin → Competitions (`CompetitionResource`) | Built |
| 2.2 | Maximum tickets per player | "Limit per player" field (empty = no limit), stored as `max_per_person` | Built |
| 2.2 | Skill question on every paid entry | `competitions.skill_*`, `Orders::skillAnswers`, setting `require_skill_question` (on), checkout radios | Built |
| 2.2 | Countdowns, sold count, % bar | Competition cards and pages ("Max N", "N of M left", `soldPercent`) | Built |
| 2.2 | Ticket bundles per competition | `App\Services\Bundles`, Admin → Bundle Tiers | Built |
| 2.2 | Category filters; sort by ending soon, newest, price | `/competitions` | Built |
| 2.3 | Multi-competition basket, checkout, confirmation pages and emails | `Orders`, `/basket`, `/checkout`, `OrderConfirmed` email | Built |
| 2.3 | Discount codes: fixed or %, use limits, per-player limits, expiry, start, minimum spend | `PromoCode`, `Pricing::promo`, Admin → Promo Codes | Built |
| 2.3 | Pay fully or partly with site credit | `Wallet::spendForOrder` | Built |
| 2.3 | Automatic ticket numbers, no overselling | Verity entries inside the payment-confirm transaction | Built |
| 2.4 | Live draw console with animated reveal | Admin → Live Draw (`LiveDraw` page: "Draw now" asks Verity; "Open reveal" is a full-screen page for the stream) | Built |
| 2.4 | Automatic draws | `verity:process-due` draws every closed competition as soon as its drand round is out | Built |
| 2.4 | Draw record (entries, winning ticket, date/time) | `/verify/{ref}`, `/entry-lists` | Built |
| 2.5 | Instant wins, winning tickets set in advance or uploaded by CSV | Verity fixes winning tickets at publish from its committed seed. "Import prizes (CSV)" on an unpublished competition loads the prize table (name, value, type, quantity). The file never names ticket numbers, because that would break the public proof. | Built (see note 1) |
| 2.5 | Cash, site credit, physical prizes | Prize `type` | Built |
| 2.5 | Six reveal games (Bingo Card, Coin Drop, Scratch Card, Slots, Wheel Spin, Gems), chosen per competition | `src/games/`, `shared/game-ids.json` (12 games in total) | Built (classic look until the design) |
| 2.5 | Prizes awarded and remaining, public and in admin | `/competitions/{slug}/winning-numbers`, competition page, Business Reports → Instant wins | Built |
| 2.6 | Wallet in account and header; credit from wins, loyalty, referrals, admin; history | `Wallet`, `/account/wallet` | Built |
| 2.6 | Admin adds or removes credit with a reason | Players → "Add wallet credit" / "Remove wallet credit" (`Wallet::adminDebit`, never below zero, logged) | Built |
| 2.7 | Free postal route on every competition, same chance | `PostalBox`, `/free-entry`, top bar, cards, basket, checkout, footer | Built |
| 2.7 | Admin intake page plus CSV upload | Admin → Postal Entries: log one envelope, or "Import envelopes (CSV)" (columns `email`, `received_at`, UK time). Each row goes through the same checks. | Built (see note 2) |
| 2.7 | Postal entries count towards the maximum | Verity postal entries share the ticket pool | Built |
| 2.8 | Daily, weekly, monthly limits, cool-off, self-exclusion; enforced at checkout | `PlayCheck`, `/account/safer-play` | Built |
| 2.8 | Date of birth and 18+ at sign-up; under-18 winners void | Registration; ID checks | Built |
| 2.9 | Winners upload photo ID; admin approves/rejects with a note; fulfilment tracked | `/account/verify-id`, Admin → ID Checks, Prize Claims | Built |
| 2.9 | Public winners page with photo, first name and initial, prize, draw date | `/winners`; photos and draw videos from Admin → Winner Photos (photo shown only with the winner's consent) | Built |
| 2.10 | Sign up, log in, password reset; tickets, orders, wallet, loyalty, referrals, membership, safer play, details | `/account/*` | Built |
| 2.11 | Loyalty programmes: points per £1, bonus events, tiers, expiry, rewards, basket estimate, admin adjust and reporting | `App\Services\Loyalty`, Admin → Loyalty, `/account/loyalty` | Built |
| 2.12 | Membership tiers, perks (free entries, monthly credit, early access, extra points), recurring billing, join/upgrade/downgrade/cancel, failed renewals pause perks, admin | `App\Services\Memberships`, Cashflows recurring jobs, `platform:memberships`, Admin → Memberships, `/account/membership` | Built (live recurring billing to be tested once Cashflows is live) |
| 2.13 | Referral codes and links, schemes (who, qualifying action, rewards, limits, dates), members, reversal on refund/chargeback, multi-level | `App\Services\Referrals`, Admin → Referral Schemes, `/account/refer` | Built (off by default: `feature_referrals`) |
| 2.14 | Executive and operational dashboard, drill-down, role-based access | Admin → Business (`BusinessReports`), admin roles (`App\Support\Roles`) | Built |
| 2.14 | Profit planner, detailed reports, forecasts, alerts, Excel/CSV/PDF exports, scheduled emails | `ProfitPlanner`, `Reports`, `Alerts`, `Settlements`, Reconciliation, Cost Entries, Alert Rules, Report Schedules, `platform:reports` | Built (layouts to agree with the Client) |
| 2.15 | Disputes and evidence pack | Admin → Disputes; "Evidence pack" on an order (`/admin/evidence/{order}`) | Built |
| 2.16 | Admin and staff roles; manage everything listed | Filament at `/admin`, 2FA, roles admin/manager/finance/marketing/support | Built |
| 2.16 | Homepage announcement bar and featured competitions | Settings → Announcement Bar (links only to pages on this site); "Featured" on a competition | Built |
| 2.17 | Language switcher | Weglot, loaded with the key from Settings | Needs Liam/Client (Weglot account and key) |
| 2.18 | Upsells, one-click repeat, abandoned checkout recovery, recommendations, A/B tests, tool revenue reporting | `App\Services\Conversion`, Admin → Experiments, dashboard tools table | Built |

## 3. Brand, launch, integrations, training

| SOW | Item | Status |
|---|---|---|
| 3.1 | Logo, palette, type, guidelines, social images | Not code (design work) |
| 3.2 | Launch strategy, 30-day draw calendar, first 30 days of competitions loaded | Not code; competitions loaded in the admin once prizes are approved (the profit planner helps) |
| 3.3 | Cashflows | Built (`CashflowsGateway`, recurring jobs). Needs the live account and keys in Forge |
| 3.3 | Transactional email | Built (notifications, mail theme). Needs the mail service and domain verification |
| 3.3 | Email marketing | Built: `MarketingSync` drivers for Mailchimp, Klaviyo or a webhook. Needs the Client's choice and key; not yet tested against a live account |
| 3.3 | SMS marketing | Built: SMS webhook driver with opt-out (`/api/webhooks/sms-opt-out`). Needs the Client's provider |
| 3.3 | Meta Pixel, Conversions API, ad spend | Built (`MetaEvents`, `platform:conversion --ad-spend`). Needs Pixel ID, CAPI token, ad account. RMG application is not code |
| 3.3 | Google Analytics, GTM, Search Console, Ads conversions | Built (`src/lib/google.ts`, after cookie consent). Google Ads spend has no simple API: enter it in Cost Entries |
| 3.4 | Training, admin guide, source, database export, deployment docs | Admin guide: `docs/admin-guide.md`. Training is a call. Deployment: README |
| 3.5 | Draft legal policies | Needs redrafting for the Client: `backend/resources/legal/*.html` are carried-over texts with the name replaced |
| 3.6 | Introductions | Not code |

## Notes

1. **Instant-win CSV.** The SOW says winning tickets are "set in advance or uploaded by CSV". The platform sets them
   in advance: Verity picks them at publish from a committed seed and publishes the winning numbers, which is what
   makes the draw provable. A CSV naming ticket numbers would mean we chose the winners, so the CSV imports the
   prize table instead. Agree this reading with the Client.
2. **Postal CSV format.** The import takes `email,received_at`. Once the Client picks a postal entry provider,
   map its export to these columns (or add a mapping): `Postal::importCsv`.
3. **Early access** (memberships) applies to competitions published after a tier with early access exists, because
   Verity's opening time is fixed at publish.

# CLAUDE.md — CPBE (bespoke competition platform, RaffleLabs Bespoke Package)

Built by Sprout Hub Limited for Hamid Reza Mousavizadeh's competition business. The scope is the
Statement of Work dated 16 November 2026 (sections 2 and 3); `docs/scope.md` maps each SOW item to the code.
Anything not in the SOW is a change request: don't build it without Liam's say-so.

Read before every task. If a request conflicts with this, stop and ask.

## How we work
- Never commit or push to `main`. One branch per task; Liam reviews and merges.
- Plan first for anything non-trivial. For a bug, write a failing test first.
- Before every commit: `cd backend && php artisan test`, then
  `cd frontend && npx astro check && npm run build && npm test && npm run test:fullstack && npm run test:visual`
  (the last saves screenshots of every page at four sizes to /tmp/cp-shots — look at the ones you changed — and
  runs `tests/visual/journey.test.ts`: a real phone browser joins, confirms the email, pays by card, plays, buys from
  the wallet, tops up and recovers from a declined and a cancelled card, failing on any page or console error, and `tests/visual/mobile-audit.test.ts`: crawls every page at 390px and 320px,
  signed out and in, and fails on errors, sideways scroll, taps under 40px, inputs under 16px or unlabelled, broken images or a
  missing h1, then checks the same pages on 768px and 1024px tablets and a 1440px desktop; findings and page weights go to
  /tmp/cp-audit).
- `docs/api.md` + `frontend/src/lib/types.ts` are the contract. Change both together, and the mock
  API (`frontend/mock-api/server.mjs`) and the Laravel tests with them.

## Money and fairness (carried over from v1 — non-negotiable)
- Money is whole pence. Prices, totals and outcomes come only from the server.
- Wallet: deposit / bonus / winnings, an append-only ledger; spend deposit → bonus → winnings;
  bonus pays at most 50% of a basket. There is no sign-up / deposit-match bonus (removed for launch); the bonus
  bucket holds credit an admin adds and bonus brought over from the old site, until it expires.
- Wallet part is taken at checkout before any ticket is issued; given back if the card fails.
- Card payments are confirmed only by asking Cashflows about the job reference stored on the order,
  matching job, order number and amount. The webhook finds orders only by that stored reference.
- Tickets and instant-win results come only from Verity, inside the payment-confirm transaction.
  Winning ticket numbers and entry lists are public from publish (`/winning-numbers`, `/entry-lists`):
  safe only with random ticket numbers, so publishing always uses `ticket_allocation` `random`.
  Reveal games are presentation only (`frontend/src/games/`); `tests/games` proves a board can only
  show the result it was given.
- One play check on every path: sales switch (off by default), staff can't play, 18+, email
  verified, self-exclusion, break, hold, spend limits (daily, weekly, monthly). No credit-card cap. No limit per person by default;
  SOW 2.2 adds an optional "Limit per player" on each competition (empty = none).
  Self-exclusion and breaks always apply at once — no check or delay in front of them.
- At fulfilment only WINNING entries are played through Verity (`Fulfilment::playAll`: entries with an
  `instant_ticket_id`), so an order's cost doesn't grow with its ticket count; never go back to
  `Verity::playGroup` per ticket at checkout (`tests/Feature/BigOrdersTest` holds a query budget).
- End prizes: `end_prize_count` places, one per person. A repeat win isn't paid and is redrawn through
  Verity (`EndPrizeRules`, also every minute via `platform:end-prize-rules`). `end_prize_type` site_credit
  pays winnings once (reference `verity-winner:{id}`); physical opens a claim for a person.
- A paid order that couldn't get all its tickets is flagged for a person, never auto-refunded.
- Bundles ("Buy X Get Y Free", live draws only): priced and sized only by `App\Services\Bundles` /
  `Pricing` from the bundle id; free tickets go to Verity in the same entry as the paid ones (no flag).
  A bundle bigger than what's left or than `max_bundle_share_pct` is never offered or sold. The featured
  tier is badged "Our Pick" (owner, 19 Nov 2026): never "Best Value" (it isn't the cheapest per ticket) or
  "Most Popular" (not backed by sales data). Promo codes apply to singles only. Unpaid orders
  hold tickets for `Order::HOLD_MINUTES` (15).
- Ticket counts (carried from the base build): competition summaries carry
  `maxTickets` (the entry cap) and `ticketsLeft`, and cards and competition pages show "Max N" and "N of M left"
  beside `soldPercent` (rounded down; 100 only when sold out), "Almost gone!" from 90% and "Sold Out".
  Per-person limits, odds and the promo cap are never public
  (`BundlesTest::test_competitions_show_the_entry_cap_and_whats_left_but_nothing_private`). Entry lists and the
  fair-play record stay public as they are (don't gate them or touch Verity), and Terms 4.3 stays.
- Vault Case (live draws only) and Refer a Friend are each behind a setting (`feature_vault_case`,
  `feature_referrals`, Admin → Settings), off by default; never switch them on in a migration or seeder.
  A box is packed and priced only by `VaultCasePacker` at the competition page prices, never over budget;
  its bonus tickets are added by `Pricing` only while the basket line still matches the box. Referral
  rewards come only from `App\Services\Referrals` on the friend's first real-money order; card and
  device fingerprints are stored as hashes only, never a card number. Vouchers become £0 orders through
  `Orders::issueFree`, so their tickets come from Verity like any other.
- Promo ticket cap: voucher tickets + Vault Case bonus tickets per draw never pass `promo_ticket_cap_pct`
  (default 5%), counted and checked only in `App\Services\PromoCap`, with the competition row locked
  (checkout, voucher redeem). Postal entries are never capped or counted. Players see a neutral notice, never the numbers.
- Never invent winners, reviews or trust figures. Free postal route always visible.
- All dates shown in UK time (`frontend/src/lib/time.ts`).

## Brand and content (nothing decided yet)
- This build started as a copy of other sites' engines. Nothing of their brands, companies or data may come
  back: `frontend/tests/unit/no-old-brand.test.ts` reads every tracked file.
- The brand isn't designed yet. The name is the placeholder "Brand Name" (`APP_NAME`, `frontend/src/lib/seo.ts`,
  `frontend/src/lib/company.ts`) and the logos say "LOGO TBC". The current look and palette are a temporary stand-in
  until the bespoke design (SOW 2.1, 3.1) replaces `src/styles.css`. A launch build refuses to run while the brand or
  any company detail is still a placeholder (`astro.config.mjs`).
- Free postal entry is prominent everywhere a player buys: the top bar, every card ("Or enter free by post"),
  the "No purchase necessary" box (`components/PostalBox.astro`) beside every buy panel, the basket and checkout,
  and the footer badges. Wording: "Enter free by post with exactly the same chance of winning."
  Postal entries are never capped below paid entries (Meta's advertising review depends on it).
- Company details live only in `frontend/src/lib/company.ts` and the legal pages, all "TBC" until Liam
  gives them: never guess them.
- Never copy secrets or `.env` values from another site; Liam adds every key in Forge himself.
- Game skins: the classic look only, for now. The Vault Case stays off.
- Never put up member counts, review links, brand logos or sample winners until Liam confirms them.

## Backend (backend/)
- Money moves only through `app/Services`: `Wallet` (ledger), `Orders` (checkout, deposits, gateway sync,
  closing), `Fulfilment` (Verity entries and instant wins), `Postal`. Controllers and the admin
  call these; they never write `wallet_ledger`, `orders.status` or Verity tables themselves.
- `wallet_ledger` and `admin_actions` are append-only (database triggers, `App\Support\LedgerGuards`).
  Every credit has a unique `reference` so repeating an event pays once.
- Every refusal a player can see is a `RefusedException` with a stable `reason` code.
- Never write to `verity_*` tables or edit `vendor/sprouthub/verity-engine` (its fingerprints are checked).
- Competition terms are fixed once published to Verity (`Competition::LOCKED_WHEN_PUBLISHED`).
- Customer accounts are never deleted (model refuses); admin actions carry a reason and are logged.
- Admin is Filament at `/admin` (`app/Filament`); admins and staff can't play.
- Tests: `tests/Feature` (Verity runs for real on its local beacon; Cashflows is `FakeGateway`).
- `DemoSeeder` is for laptops and tests only and refuses production.
- Emails are Laravel notifications in the platform mail theme (`resources/views/vendor/mail`, `vendor/notifications/email.blade.php`):
  near-black page, green-bordered card, green button, Barlow fonts, PNG logo, the 18+ / postal / Safer Play footer. Ticket numbers and money go in
  blocks (`['tickets', …]`, `['rows', …]`), never raw HTML in a line. Subjects in Title Case. `tests/Feature/EmailsTest`
  checks every email; `EMAIL_PREVIEWS=/tmp/cp-emails php artisan test --filter=EmailsTest` writes them out to look at.

## Front end
- Astro drops a space between `{expr}` and a following tag inside `<>…</>`: write `{expr}{" "}<span>`.
- Standalone small links get `class="tap"` (40px tap height).
- Title Case for every title. Mobile first (44px taps, 16px inputs, no sideways scroll at 320px).
- Nothing third-party loads before a cookie choice. The only one is the Meta Pixel (`src/lib/consent.ts`, ID set in
  Admin → Settings), loaded after "Accept" in the cookie banner; its addresses are the only extra ones in the CSP.
- Meta tracking: the banner's choice is copied to a `cp_consent` cookie; only with it does the server keep and send
  events to the Conversions API (`App\Tracking\MetaEvents`, `platform:meta-events` every minute, token in `META_CAPI_TOKEN`
  only). Browser and server share event ids (order number; `reg-{user id}`). Values are real money only, never bonus.
  Personal details leave only SHA-256 hashed (`MetaTrackingTest`); never log Meta's error message or the token.
- Smooth games on slow phones: never animate or move anything under a `filter` (drop-shadow, blur), and take a board's
  filter off while it moves (vault, claw, coin drop do). Keep fast-moving parts in their own layer (`will-change: transform`,
  or a separate SVG over the still picture) and `memo` static artwork in boards that re-render every frame.
- Reveal games are 2D (SVG and CSS, no WebGL): each board sits on an illustrated scene (`src/games/arcade/shell/scenery`,
  swappable for a painted picture through `PAINTED`). The classic skin's premium look is `shell/premium.css` plus a
  `.rg-skin-classic` block at the end of each game's CSS. `npm run test:games` plays every game (run the smoothness checks
  alone: `GAMES_WORKERS=1 … -g smoothness`). A new game needs its id in `shared/game-ids.json`, `src/games/gameIds.ts`,
  the admin and presenter labels, and a migration calling `GameIds::replaceDatabaseChecks()`.
- Legal wording loads from `backend/resources/legal/*.html` on deploy (`platform:legal-pages`) and is then edited in the admin.
- Search: titles, descriptions and structured data come from `src/lib/seo.ts` (titles ≤ 60, descriptions ≤ 155, "Win …" for
  competitions, never a ticket count). Every public page needs its own `title` and `description` on `<Layout>`; private pages
  are `noindex` and left out of `sitemap.xml.ts` / disallowed in `robots.txt.ts`. `tests/e2e/seo.test.ts` checks every sitemap
  page. Google is shut out until the build has `PUBLIC_SEARCH_INDEXING=on` (launch day); `PUBLIC_GOOGLE_SITE_VERIFICATION` adds
  Search Console's tag.
- Redirects after sign-in go through `safeNext` (`src/lib/safe.ts`).
- Astro trusts Host / X-Forwarded-* only for `allowedDomains` in `astro.config.mjs`; the build must
  have `PUBLIC_SITE_URL` set to the live address or form posts are refused.

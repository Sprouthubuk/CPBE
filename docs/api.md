# Brand Name API contract

The Astro front end (`frontend/`) talks to the Laravel backend (`backend/`) only through the
endpoints below. The TypeScript shapes are the source of truth: `frontend/src/lib/types.ts`.
`frontend/mock-api/server.mjs` implements this contract in memory and the end-to-end tests run
against it; the Laravel feature tests must cover the same behaviour.

## Ground rules

- **Same domain.** nginx sends `/api`, `/sanctum`, `/admin`, `/livewire`, `/verity`, `/storage` to
  Laravel and everything else to Astro (`deploy/nginx.conf`). No CORS.
- **Lists** come wrapped: `{ data: [...] }` (plus `total` / `hasMore` where they page).
- **Auth** is Sanctum SPA cookie auth. Browser code calls `GET /sanctum/csrf-cookie` once, then
  sends `X-XSRF-TOKEN` (the decoded `XSRF-TOKEN` cookie) on every write. A missing or wrong token
  is `419`. Astro's server-side calls forward the visitor's `Cookie`, with `Origin`/`Referer` set
  to `PUBLIC_SITE_URL` so Sanctum treats them as first-party, and copy any `Set-Cookie` back.
- **Money is whole pence** (integers) everywhere, in and out. Never floats, never "12.50".
- **Dates** are ISO 8601 in UTC. The front end shows UK time.
- **Errors** are `ApiError`: `{ message, errors?: { field: [msg] }, code? }`. `422` validation,
  `401` not signed in, `403` signed in but not allowed (with `code`, e.g. `self_excluded`),
  `404`, `409` conflict (stale/duplicate), `419` CSRF, `429` rate limited.
  `message` is safe to show a player; never include internals.
- **Ids** are opaque strings (ULIDs). Order numbers are `CP-…` (tickets) and `CD-…` (deposits).
- Every money-moving `POST` is idempotent: checkout takes a `requestId`; the same id returns the
  same order.

## Public

| Method | Path | Returns |
| --- | --- | --- |
| GET | `/api/site` | `SiteInfo` (cached 30s by Astro). `metaPixelId` is the Pixel ID set in the admin, or null; the site loads the Pixel only after the visitor accepts marketing cookies. `vaultCase` and `referrals` are null while those features are off (both start off; see below). `socials` holds the Instagram, Facebook, WhatsApp VIP and TikTok links set in Admin → Settings (null when not set) `loyalty` is true while any loyalty programme is switched on; `memberships` while any membership tier is open to join; `google` (`{ gtmId, ga4Id, adsId, adsPurchaseLabel }`, loaded only after cookie consent) and `translation` (`{ weglotKey }`) when set up in Settings. `announcement` (`{ text, link }`, link only a path on this site) is the top bar message from Admin → Settings, or null for the default. |
| GET | `/api/health` | `{ status: "ok"\|"failing", checks: { database, scheduler } }`, `200` or `503` (for an uptime monitor; scheduler = ran in the last 5 minutes) |
| GET | `/api/competitions?type=instant\|draw` | `{ data: CompetitionSummary[] }` (live and sold out, featured first, then closing soonest). `opensAt` is when a scheduled competition opens; `soldPercent` is rounded down (100 only when sold out); `featuredBundle` is a live draw's Our Pick bundle or null. `maxTickets` is the entry cap and `ticketsLeft` what's left of it (Brand Name shows both, owner 22 Nov 2026); per-person limits and the promo cap are never returned |
| GET | `/api/competitions/{slug}` | `CompetitionDetail`; `heldByMe` for the signed-in player; `bundles` = the bundles on sale now (only ones that still fit) |
| GET | `/api/winners/instant?limit=&page=&game=` | `{ data: InstantWinnerRow[], total }` |
| GET | `/api/winners/draws` | `{ data: DrawWinnerRow[] }`; `photoUrl` / `videoUrl` from Admin → Winner Photos (photo only with the winner’s consent, video only https) |
| GET | `/api/entry-lists` | `{ data: CompetitionSummary[] }` (no entry counts): every published competition once it opens, on sale first (closing soonest), then finished ones |
| GET | `/api/entry-lists/{slug}?q=&page=` | `{ competition, data: EntryRow[], hasMore }` (no total); open from the first sale; names masked ("L*** P."); `prizeName` on an instant-win ticket that won |
| GET | `/api/competitions/{slug}/winning-numbers?show=all\|won\|left&q=&prize=&page=` | `WinningNumbersPage` (`prize` = one prize's name). A paid win isn't named (here, in `/api/winners/instant` or as an entry list's `prizeName`) until its player has played the reveal, or for an hour: every instant prize's winning ticket number, public from publish, biggest prize first, 100 a page; `404` for draws |
| GET | `/api/pages/{slug}` | `{ title, updatedAt, version, html, headings }` (terms, privacy, rules, cookies, free-entry) |
| POST | `/api/contact` | `{ name, email, topic, message }` → `204`; rate limited |

`CompetitionDetail.prizes` lists the prize *tiers* and how many of each are won. Which ticket number
wins each prize is public from publish (owner's decision, 18 Nov 2026) through `/winning-numbers`.
That is safe only because Verity gives each buyer random unsold numbers at payment (it refuses
in-order numbering with instant wins); never publish with `ticket_allocation` other than `random`.
The seed that made the list stays sealed until the competition ends.
`CompetitionDetail.endPrize` is `{ name, valuePence, count }`: `count` winners each win the prize
(Verity draws that many different tickets; one end prize per person, a repeat win is redrawn).
`presets` are the ticket quick-pick buttons (smallest first), `popularPreset` the one marked
"Most popular" (or null), and `minTickets` the fewest tickets one order can hold.

Amendment 8 (plain information at a glance): `CompetitionDetail.howToWatch` and `winnerAnnounced` (the
competition's own text, or the defaults from Admin → Settings → On Every Competition) and `hasSkillQuestion`
(amendment 6: false unless the competition switches its question on). Amendment 4: `CompetitionSummary.membersOnly`
is `[tierName]` when only members of those tiers may buy (null for everyone; postal entry stays open to all), and
`CompetitionDetail.memberPricePence` is the signed-in member's price after their tier's ticket discount (null
otherwise). Refusal for a non-member: "This competition is for members only. You can still enter free by post."

## Automatic pricing and offers (amendment 5)

Rules on a competition (`competition_price_rules`, Admin → Competition → Automatic Pricing & Offers) change, while
their dates and trigger match, the ticket price, a "buy X get Y free" bonus on single tickets, which bundles are on
offer and (live draws) the share of tickets on sale. Triggers: `always`, `hours_left_more`, `hours_left_at_most`,
`sold_pct_below`, `sold_pct_at_least`, `sold_24h_at_least`. For each setting the first matching rule (by `sort`) wins.
Applied only by `App\Services\PriceRules`, so summaries, the basket and checkout agree:
`CompetitionSummary.pricePence` is the price now, `basePricePence` the published price, `offer` =
`{ label, until, bonus: { buy, get } | null }` or null. A `PricedLine` carries `offerLabel` and `priceRuleId`;
bonus tickets are `freeQuantity` on the single-ticket line and are entered like paid ones. `maxTickets` never
changes; a release rule only holds tickets back (refusal "All the tickets on sale for now are taken…"), never
free postal entries.

## Bundles ("Buy X Get Y Free", live draws)

A `BundleTier` is a preset (paid + free tickets); each draw chooses which it offers and which is Best
Value (`competition_bundle_tier`). A bundle is offered only while bundles are on, the tier is active,
it fits in the tickets left (less those being paid for) and it is no bigger than the competition's
`max_bundle_share_pct` of its tickets. `Bundle` = `{ id, label, paid, free, tickets, pricePence, savePence, ourPick }`
with `pricePence = paid × price` and `savePence = free × price`. Free tickets are ordinary entries: the
order line's `quantity` is paid + free and every one goes to Verity in the same entry.

## Basket and checkout

`POST /api/basket/price` — `{ lines: BasketLine[], promoCode?: string|null }` → `PricedBasket`.
A `BasketLine` is `{ competitionId, quantity }` (single tickets) or `{ competitionId, bundleId }`
(one bundle; a competition holds one bundle plus any singles). Works signed out (no wallet figures
then). Prices and ticket counts come only from the database; anything else the browser sends is
ignored. Each `PricedLine` has `quantity` (tickets: paid + free), `paidQuantity`, `freeQuantity`,
`bundleId`/`bundleLabel`, and `upgrade` (the next bundle up: `{ bundleId, label, tickets, freeQuantity, extraPence }`
or null). A line gets a `problem` when it cannot be bought as asked (closed, sold out, "Not that many
tickets are left. Please choose fewer.", "This bundle is no longer available. Please choose a smaller
one.", or under `minTickets`). It never says how many are left. `problems` holds basket-wide reasons
(sales closed, play block). Promo codes (SOW 2.3) are a percentage or a fixed amount off, with optional start and
expiry dates, a total use limit, a per-player limit and a minimum basket spend ("Spend £X or more to use that
promo code.", "You’ve already used that promo code."). They apply to single tickets unless the code includes
bundles, take at most half of what they apply to and never make an order free. `promo` is
`{ code, discountPence, label }` ("20% off", "£5.00 off"). SOW 2.2: a line for a competition with a skill question carries `skillQuestion { question, options[] }` (never the
answer); checkout lines send `skillAnswer` (the option's index) and `via` (`upsell`, `repeat`, `recovery`, `recommendation`: the tool that
added the line, for SOW 2.18 reporting), and are refused with `skill_answer_missing` or
`skill_answer_wrong` before anything is taken. `loyaltyPoints` lists `{ programme, points }` the basket would
earn in each switched-on loyalty programme if it's paid with real money (bonus credit earns nothing). Unpaid orders hold their tickets for 15 minutes (`Order::HOLD_MINUTES`); checkout
locks each competition row so two orders can't both take the last tickets.

`POST /api/checkout` (signed in) —
`{ lines, payWith: "wallet"|"card", useWallet: boolean, promoCode, requestId }` → `CheckoutResult`.

1. Run the play check (sales switch, staff, 18+, email verified, self-exclusion, break, hold,
   spend limits including card payments in progress). Refuse with
   `403` + `code`.
   Before that, a player who has never been offered spending limits (DCMS Code 1.4) gets `409`
   `code: "limits_prompt"`: the site shows the limits prompt, and `POST /api/me/limits` or
   `POST /api/me/limits/skip` clears it. The same applies to `/api/me/deposits` and memberships.
2. Re-price on the server. A card part under `minCardPence` is `422`.
3. Take the wallet part at once (append-only ledger; spend deposit → bonus → winnings; bonus ≤ 50%
   of the basket). Not enough is `422`.
4. Wallet covers it → mark paid, issue entries through Verity in the same transaction, return
   `next: "play"` (or `"attention"` if entries could not all be issued — never auto-refunded).
5. Otherwise create a Cashflows payment job for the card part, store its reference on the order,
   return `next: "redirect"` and `redirectUrl`.

**Credit cards (DCMS Code 1.3, amendment 11).** `PricedBasket.cardNote` is set when the card part must be paid by
debit card: any instant-win line, wallet top-ups, memberships, or credit card spending this calendar month already at
the cap (`credit_card_monthly_cap_pence`, never above £250). Then the payment job is created with
`paymentMethodsToUse: ['DebitCard']` and `orders.credit_allowed` is false. If Cashflows still reports a credit card on
such an order, it is marked paid and flagged for a person, and no tickets are issued until someone decides.

`GET /api/orders/{number}` → `OrderView` (own orders only; `404` otherwise). `trackValuePence` is
what the Meta pixel reports for the order: real money only (card, deposit and winnings), never bonus credit.

**Meta tracking (Conversions API).** No endpoint: `POST /api/checkout`, `POST /api/me/deposits` and
`POST /api/auth/register` note the event when the request carries the `cp_consent=accepted` cookie (written
by the cookie banner) and a Pixel ID is set; `_fbp`, `_fbc`, IP and browser name are kept with it. The
scheduler (`platform:meta-events`, every minute) sends it once the order is paid. Event ids match the browser
pixel's so Meta counts each once: Purchase and the custom Deposit use the order number,
CompleteRegistration uses `reg-{user id}`. Customer details leave only SHA-256 hashed.

`POST /api/orders/{number}/return` — `{ leftAs?: "failed"|"cancelled" }` → `OrderView`. Called by
the payment return pages and polled by the order page. Asks Cashflows about the **stored** job
reference and confirms only when the job, order number and amount all match. When Cashflows says
it is not paid and `leftAs` is given, close the order and give back the wallet part. A late payment
on a closed order is still confirmed.

`POST /api/orders/{number}/play` → `PlayResult`. Instant prizes are won when the ticket is sold:
fulfilment plays every instant-win entry with Verity (`playGroup`) and credits cash and site-credit
prizes to winnings in the same transaction. This endpoint only reports the stored outcome for this
order's tickets, and marks them watched. Calling again returns the same result and pays nothing.
Each ticket carries `proofUrl`: the competition's public fairness record (`/verify/{ref}`). The premium
games (`frontend/packages/games`) read this response through `fromRevealOutcome` and only present it.

`POST /api/webhooks/cashflows` — `{ paymentJobReference }`. Nothing in the message is trusted: the
order is found **only** by the stored job reference, then confirmed through the same path as `/return`
(which asks Cashflows itself).

Return addresses given to Cashflows: success `/order/{n}` (deposits: `/account/wallet?deposit={n}`,
which calls `/return` server-side), failed `/payment/failed?order={n}`, cancelled
`/payment/cancelled?order={n}`.

## Vault Case (vault case, live draws only)

Off unless an admin switches it on (`site.vaultCase` is then `{ available, chipsPence, minPence, maxPence, bonusPct }`;
`available` is false when fewer than two draws can go in a box). While off every route below is `404`.

| Method | Path | Body → Returns |
| --- | --- | --- |
| POST | `/api/vault-case` | `{ budgetPence, mode: "surprise"\|"big"\|"most", replaces?: boxId }` → `201 VaultCase`. Budget in whole pounds within min/max (`422` otherwise). `replaces` releases an earlier box. Works signed out. Rate limited (`VAULT_CASE_RATE_PER_MINUTE`, 20) |
| POST | `/api/vault-case/{id}/added` | — → `VaultCase` (hold restarts); `410 { code: "vault_case_gone" }` once released or paid |

A `VaultCase` is `{ id, mode, budgetPence, totalPence, unusedPence, tickets, bonusTickets, expiresAt, lines, basketLines }`.
`totalPence` ≤ budget, and `unusedPence` is always less than one ticket of the cheapest draw in the box.
Each line has the player's own counts only (`paidQuantity`, `freeQuantity`, `bonusQuantity`, `tickets`,
`singles`, `bundleId`, `bundleLabel`, `linePence`, `unitPence`) and the draw's `soldPercent`: never a
ticket count of a draw. Prices are the competition page prices. A box holds its tickets for
`vault_case_hold_minutes` (10). `basketLines` are `BasketLine`s with `boxId`: put them in the basket as
they are. While a line still holds what the box packed (same bundle, at least its singles) and the box
isn't released or paid, the server adds the box's bonus: the `PricedLine` then has `bonusQuantity` > 0
(inside `quantity`), `source: "vault_case"` and `vaultCaseId`. Order lines record the same.

**Promo ticket cap.** Each draw has `promo_ticket_cap_pct` (admin, default 5%): referral voucher tickets
and Vault Case bonus tickets together never pass that share of its tickets (paid orders and unpaid ones
still in their hold are counted; postal entries and bundle free tickets never are). Once reached, a box
line and a `PricedLine` carry `bonusQuantity: 0` and `notice: "Bonus tickets for this draw have all been
given out."` (paid tickets still sell), and the voucher draw picker leaves the draw out. No response
carries the cap or the count. Every `PricedLine` and box line has `notice` (null when there's nothing to say).

## Refer a Friend

Off unless an admin switches it on (`site.referrals` is then `{ rewardType: "tickets"\|"credit", rewardTickets,
rewardCreditPence, minPurchasePence }`). While off every route below is `404`.

| Method | Path | Body → Returns |
| --- | --- | --- |
| GET | `/api/referrals/{code}` | `{ code, firstName }` of the referrer, for the join page; `404` for an unknown or closed account's code |
| GET | `/api/me/referrals` | `MyReferrals`: `{ code, link, stats: { invited, qualified, rewards, leftThisMonth, cap }, rewardType, rewardTickets, rewardCreditPence, minPurchasePence, vouchers: Voucher[], friends: { name, status, joinedAt }[] }`; `scheme` (SOW 2.13: `{ name, qualifying: first_order\|any_order\|membership, minSpendPence, referrerReward, friendReward, endsAt }` or null), `schemeClosed`, and the player's `rewards` (`{ level: 0 friend · 1 referrer · 2+ further up, reward, status: given\|reversed, at }`) |
| GET | `/api/me/vouchers/{id}/draws` | `{ data: CompetitionSummary[] }`: live draws the voucher can go into (% sold only); a draw whose promo ticket cap can't take the voucher is left out |
| POST | `/api/me/vouchers/{id}/redeem` | `{ competitionId }` → `201 { order: { number, status } }`: a £0 order, tickets from Verity. `422` (`voucher_unavailable`, `voucher_draw`) or a play-check refusal |
| GET | `/api/me/loyalty` | `MyLoyalty` (SOW 2.11): each switched-on programme's `balance`, `lifetime` (sets the tier), `tier`, `nextTier { name, pointsToGo }`, `tiers`, `rewards` (`available`, `affordable`), `expiringSoon` (next 30 days), the last 30 `history` lines; the last 20 `redemptions`; and loyalty `vouchers` (bonus entries carry `forCompetition`) |
| POST | `/api/me/loyalty/redeem` | `{ rewardId }` → `201 { redemption: { id, status, voucherId } }`. Site credit goes to the bonus wallet; free and bonus entries become a voucher (30 days, used through `/api/me/vouchers/{id}/…`); custom rewards wait as `pending` until the Client fulfils them. `422` `reward_unavailable`, `reward_out_of_stock`, `reward_tier`, `not_enough_points`, or a play-check refusal |
| GET | `/api/me/membership` | `MyMembership` (SOW 2.12): `membership` (null, or `{ status: active\|past_due, tier, payWith, perksOn, periodEnd, cancelAtPeriodEnd, nextTier, nextAttemptAt }`) and the open `tiers` (`{ id, name, description, pricePence, perks[], ineligible }`) |
| POST | `/api/me/membership` | `{ tierId, payWith: card\|wallet }` → `201 MyMembership + { order, redirectUrl }`. Wallet pays from cash and winnings only (never bonus credit) and starts at once; card opens the payment page (a Cashflows recurring parent; renewals are charged to it). `422` `membership_exists`, `membership_ineligible`, `not_enough_wallet`, or a play-check refusal |
| POST | `/api/me/membership/change` | `{ tierId }`: dearer → upgrade now, charging the difference for the rest of the month (wallet, the stored card, or `redirectUrl`); cheaper → `nextTier` from the next renewal |
| POST | `/api/me/membership/cancel` · `/keep` | Stop at the end of the paid month, or undo that. Self-exclusion, a break, a hold or a closed account end it at once |
| POST | `/api/me/membership/pay` | After a failed renewal (`past_due`, perks paused): pay now on a card (a new recurring parent). Renewals are retried every 3 days, 3 times, then the membership lapses |
| GET | `/api/recommendations?exclude=&limit=` | SOW 2.18: `{ data: CompetitionSummary[] }`, live competitions not in `exclude` (comma-separated ids); signed in, ranked by what the player plays (kind, categories), then closing soonest |
| GET | `/api/orders/{number}/repeat?via=` | One-click repeat purchase: `{ lines: BasketLine[] (via repeat, or recovery for the reminder email), skipped: string[] }`; only the player's own ticket orders, only what's still on sale |
| GET | `/api/experiments` · POST `/api/experiments/{key}/seen` | A/B tests: `{ data: { [placement]: { variant, text } } }` for a signed-in player or a visitor who accepted cookies (`cp_vid`); `seen` counts a view. A paid order converts the tests its player saw |
| POST | `/api/webhooks/sms-opt-out` | SOW 3.3: the SMS platform's STOP: `{ phone?, email? }` signed `X-Signature` = HMAC-SHA256 of the body with SMS_OPTOUT_SECRET; turns off marketing texts |
| POST | `/api/me/postal-references` | Amendment 7, signed in: `{ competitionId }` → `{ reference, competitionId, title, closesAt }`. One reference per player per open competition ("PE7K 3M9Q 2R"), made on first ask, to write on a postal entry |
| POST | `/api/webhooks/postal` | Amendment 7, the postal entry provider: `Authorization: Bearer POSTAL_PROVIDER_TOKEN`, `{ entries: [{ id, reference, receivedAt }] }` (max 500) → `{ results: [{ id, status: entered\|refused\|unmatched, reason, duplicate }] }`. Each is checked like a hand-logged envelope; a repeated `id` enters once. 404 while no token is set |

A referral qualifies on the friend's first paid ticket order if its real money (card + deposit +
winnings; never bonus, vouchers or postal entries) is at least `minPurchasePence`. Then fraud checks
(same card hash, device, sign-up IP, address or email pattern: blocked), the review flag (many friends
from one IP or device), and the referrer's monthly cap (over it, only the friend is rewarded). A
refund within `referral_clawback_days` takes back unused rewards. `/r/{code}` on the site sets a
30-day first-party cookie and goes to `/join?ref={code}`.

## Account (signed in)

| Method | Path | Body → Returns |
| --- | --- | --- |
| POST | `/api/auth/register` | `{ firstName, lastName, email, password, dateOfBirth, marketingOptIn, referralCode?, deviceId?, next?, monthlyLimitPence? }` → `Me` (signed in, email unverified); under 18 is `422`. `next` is a path on the site (e.g. the checkout for the tickets they picked): the confirmation link then redirects to `/verify-email?status=verified&next=…`, even in another browser; anything else is ignored. `referralCode` links the friend to a referrer (now or never; an unknown code is ignored). `deviceId` is a random id the browser keeps; only its hash is stored, for the referral fraud checks |
| POST | `/api/auth/login` | `{ email, password, remember }` → `Me`; throttled |
| POST | `/api/auth/logout` | → `204` |
| POST | `/api/auth/forgot-password` | `{ email }` → `204` always |
| POST | `/api/auth/reset-password` | `{ token, email, password }` → `204` |
| POST | `/api/auth/email/resend` | → `204` |
| GET | `/api/auth/email/verify/{id}/{hash}?expires&signature` | the signed link in the email; confirms and redirects to `/verify-email?status=verified` |
| GET | `/api/me` | `Me` |
| GET | `/api/me/wallet/transactions` | `{ data: WalletTransaction[] }` newest first |
| GET | `/api/me/tickets?tab=active\|to-play\|won\|past&limit=` | `{ data: TicketGroup[] }`; outcomes hidden until watched |
| GET | `/api/me/tickets/{order}/{competitionId}?page=` | `TicketPage`: every ticket of one order line, 500 a page (a `TicketGroup` lists only the first 40 plus winners, with `ticketCount`) |
| GET/POST | `/api/me/limits` | `Limits` / any of `{ dailyDepositPence, weeklyDepositPence, monthlyDepositPence }` → `Limits` (SOW 2.8: each over a rolling 1, 7 or 30 days; lowering is instant, raising or removing waits 24h and shows in `pending` for just the periods changing; refusals `daily_limit`, `spend_limit` (weekly), `monthly_limit`) |
| POST | `/api/me/break` | `{ days }` → `Limits` (instant, no checks in front of it) |
| POST | `/api/me/limits/skip` | → `Limits`. Amendment 11 / Code 1.4: the player chose no limits for now; `Me.limitsPrompt` becomes false. A monthly limit given at sign-up (`monthlyLimitPence`, £0 allowed) also clears it |
| POST | `/api/me/self-exclude` | `{ months }` → `Limits` (instant) |
| PATCH | `/api/me/profile` | `{ phone }` → `Me` (name, date of birth fill-once) |
| PATCH | `/api/me/address` | `{ line1, line2, city, postcode }` → `Me` (fill-once) |
| PATCH | `/api/me/preferences` | `{ email, sms, winNotifications }` → `Me` |
| POST | `/api/me/password` | `{ currentPassword, password }` → `204` |
| POST | `/api/me/deposits` | `{ amountPence }` → `{ redirectUrl, orderNumber }` (min £5, max setting, play check) |
| POST | `/api/me/withdrawals` | `{ amountPence, accountName, sortCode, accountNumber }` → `201` (winnings only, min £20, needs `idCheck` verified; bank details stored encrypted, never returned; paid by hand; player emailed) |
| POST | `/api/me/id-check` | multipart `photoId`, `proofOfAddress` (JPG/PNG/HEIC/PDF, 10MB each) → `201 { idCheck: "pending" }`; refused while one is pending or once verified |

`Me.playBlock` is the same reason the play check would give, so the site can explain it before
the player tries.
`Me.limitsPrompt` is true until the player has set or skipped spending limits (see checkout step 1).

## Platform API and webhooks (amendment 2)

Read-only access to the Platform's data for any tool the Client chooses (accounting, CRM, reporting). Tokens are made
in Admin → Site → API Tokens (admin role), shown once, stored only as a SHA-256 hash, and can be revoked. Send
`Authorization: Bearer cpk_…`; no or a revoked token is `401 { code: "api_token" }`. 120 requests a minute.

| Method | Path | Returns |
| --- | --- | --- |
| GET | `/api/v1/competitions?since=&page=` | `{ data, page, lastPage, total }`: id, slug, title, type, pricePence, maxTickets, opensAt, closesAt, publishedAt, endedAt, endPrize, endPrizeValuePence, prizeCostPence, verityRef, updatedAt |
| GET | `/api/v1/orders?since=&status=&page=` | orders: number, playerId, type, status, totalPence, cardPence, walletPence, bonusPence, discountPence, promoCode, cardType, channel, paidAt, createdAt, updatedAt, lines (competitionId, quantity, freeQuantity, unitPence, linePence) |
| GET | `/api/v1/players?since=&page=` | players only (never staff): id, firstName, lastName, email, phone, postcode, emailVerified, marketingEmail, marketingSms, closed, joinedAt, updatedAt. No passwords, birth dates, ID checks, bank details or protection records |
| GET | `/api/v1/postal-entries?since=&page=` | id, competitionId, playerId, status, reason, source, reference, receivedAt, createdAt |
| GET | `/api/v1/daily-totals?from=YYYY-MM-DD&to=` | `{ data }`: one row per UK day (at most 366, default the last 30), the same figures as Business Reports' Daily Totals export, in pence |

Lists are 100 a page, oldest change first; `since` (ISO 8601) returns only rows changed after it (`422 bad_since`).

**Webhooks.** Admin → Site → Webhooks: an https address, the events it wants and a signing secret. Events:
`order.paid`, `order.refunded` (data = the `/api/v1/orders` row), `player.registered` (a `/players` row),
`postal.entered` (a `/postal-entries` row), `competition.published`, `competition.drawn` (with `verifyUrl`), and
`test` from the admin. Each is a POST of `{ id, event, createdAt, data }` with `X-Event` and
`X-Signature: sha256=<HMAC-SHA256 of the raw body, keyed with the secret>`; check it before trusting the body.
A 2xx answer is delivered; anything else retries after 1, 5, 15, 60, 180, 360 and 720 minutes (eight tries) and then
gives up (shown as "Gave up" in the admin). `id` is the same on every retry, so receivers can ignore repeats.
`platform:webhooks` sends every minute.

## Reveal shapes

```ts
RevealOutcome = { purchaseId, tickets: { ticketNumber, competitionId, competitionTitle,
  prize: { name, valuePence, type: "cash"|"site_credit"|"physical" } | null }[],
  totalWonPence, creditedPence, balanceAfterPence? }
RevealContext = { [competitionId]: { game, isInstantWin, prizeLabels, inLiveDraw,
  endPrizeName?, skin? } }
```

Every cash or site-credit prize is credited to the winnings balance before the response is sent;
`physical` prizes are fulfilled by hand and are not in `creditedPence`.

## Verification

`/verify/{ref}` (Astro) loads Verity's browser verifier (`/vendor/verity/verify-page/verity-verify.js`,
published from the package on deploy) against `/verity/competitions/{ref}/verification`
(`VERITY_ROUTES=true`). Every draw winner and competition links to it.

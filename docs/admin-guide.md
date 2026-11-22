# Admin Guide

The admin is at `/admin`. Every admin signs in with a password and an authenticator app code. What you see
depends on your role:

| Role | Can use |
|---|---|
| Admin | Everything, including Settings and the team (admin roles) |
| Manager | Competitions, draws, postal entries, winners, players, orders, ID checks, messages, finance, marketing, content, reports |
| Finance | Orders, withdrawals, finance (disputes, reconciliation, costs), reports |
| Marketing | Promotions and programmes, content, reports |
| Customer support | Players, orders, ID checks, postal entries, contact messages |

Admins and staff can't enter competitions. Every change that matters (credit, holds, roles, refunds, draws,
imports) asks for a reason where it should and is written to the admin log.

## Competitions

**Competitions → New.** Fill in the title, description, picture, price, maximum tickets, opening and closing
times, category, prize value and cash alternative.

- **Limit per player:** leave it empty for no limit.
- **Skill question:** every paid entry needs one. Write the question and the answers, then tick the right answer.
- **Bundles** (draws only): choose the tiers to offer. One of them can be badged "Our Pick".

**Instant wins.** Choose the reveal game, then add the prizes (name, value, type, quantity). For a long list, save
the competition first and use **Import prizes (CSV)**. The file needs the columns:

```
name,value,type,quantity
£500 Cash,500,cash,1
£5 Site Credit,5,site_credit,50
Air Fryer,89.99,physical,2
```

**Publish.** Publishing hands the terms to Verity. From then on, the price, tickets, closing time and prizes are
fixed. Verity also picks which tickets win the instant prizes, so the winning numbers can be shown publicly and
checked.

## Draws

Closed draws appear in **Competitions → Live Draw**.

- **Draw now** asks Verity for the result as soon as the drand round it committed to is out. This is usually within
  a minute of closing. If you don't press it, Verity draws on its own.
- **Open reveal** opens a full-screen page to share on the live stream. Press "Reveal Winner" and the ticket number
  spins and lands on the real winner. If there are several places, press it once for each.

Every draw has a public record at `/verify/{ref}`, and its entry list is at `/entry-lists`.

**Competitions → Winner Photos.** Add a draw winner's photo and a link to the draw video. A photo only appears on
`/winners` once you tick that the winner agreed to it.

## Postal entries

To log envelopes, open **Competitions → Postal Entries**.

- **One at a time:** choose the competition, enter the account email on the entry and the time the envelope was
  received.
- **In bulk:** use **Import envelopes (CSV)** with the columns `email,received_at`. Times are UK time, for example
  `21/11/2026 14:30`.

Every envelope is recorded as entered or refused, with the reason.

## Players and wallets

On **Players**, a player's menu has these actions:

- Add wallet credit and Remove wallet credit. Both need a reason, and you confirm them by typing the amount again,
  for example `REMOVE 5.00`. You can't remove more than the balance holds.
- Adjust loyalty points.
- Holds.
- Admin roles (admins only).

**ID Checks** and **Prize Claims** handle winner verification and sending out prizes.

## Promotions and programmes

- **Promo Codes:** a fixed amount or a percentage. You can set a minimum spend, a start and expiry date, total uses
  and uses per player.
- **Loyalty Programmes:** points per £1, bonus events, tiers, expiry and rewards. Switch each programme on or off.
- **Membership Tiers:** price, perks (free entries, monthly credit, early access, extra points) and who can join.
  **Members** lists every membership.
- **Referral Schemes:** who can refer, the qualifying action, rewards for both sides, levels, limits and dates.
  Referrals stay off until switched on in Settings.
- **Experiments:** A/B tests and their results.

## Disputes

On **Orders → Log a dispute**, record the reference, amount, reason and respond-by date. **Evidence pack** opens a
printable page with everything about the order. Save it as a PDF from the browser.

## Reports

- **Business:** company, competitions, customers, marketing, operations, instant wins, promotions, programmes,
  finance and cohorts. Each has Excel, CSV and print/PDF exports.
- **Profit Planner:** model a competition before launch.
- **Reconciliation:** match orders against Cashflows settlements.
- **Cost Entries:** costs the system can't see, including Google Ads spend.
- **Alert Rules** and **Report Schedules:** email alerts and regular reports.

## Settings

Admins only.

- The sales switch (off until launch).
- The announcement bar. The message can link only to a page on this site.
- Social links.
- Meta, Google and Weglot IDs.
- Payment fees, revenue target and promotional budget.
- Feature switches.

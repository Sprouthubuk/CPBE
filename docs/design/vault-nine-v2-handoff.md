# Vault Nine v2 — Design Handoff for the Astro + Laravel build

**Design canvas:** https://claude.ai/artifact/2Sk93SNhJrnPRCAwSpqaRj (artifact slug `0bb1516b-cbe7-47ba-8884-df6f824a5c4e`)
**Source files:** `vault-nine-design.zip` contains `design/*.dc.html` (one file per page or component) and `vault-nine-logo.png`.
**Engine:** Same engine as the build this site started from (Verity draw engine, wallet, basket). Use the same page structure and data flow, and only reskin it. Repo: Sprouthubuk/Vaultninev2.

The `.dc.html` files are design mockups, not production code. Each one is HTML with inline styles, a `<helmet><style>` block holding the animation CSS, and a small `renderVals()` class that shows the interaction logic. Port the markup and styles into Astro components, and take the data from Laravel.

---

## 1. Pages and components

| Design file | Build as | Notes |
|---|---|---|
| `Main.dc.html` | `src/pages/index.astro` | Home |
| `Competitions.dc.html` | `src/pages/competitions/index.astro` | All comps, with filters |
| `LiveDraw.dc.html` | `src/pages/competitions/[slug].astro` (live draw type) | Product page |
| `InstantWin.dc.html` | `src/pages/competitions/[slug].astro` (instant type) | Product page |
| `Header.dc.html` | `src/components/Header.astro` | Prop `active`: home / competitions / instant / winners / entries |
| `Footer.dc.html` | `src/components/Footer.astro` | |
| `CompCard.dc.html` | `src/components/CompCard.astro` | Live draw card |
| `InstantCard.dc.html` | `src/components/InstantCard.astro` | Instant win card |

Islands (client JS needed): countdowns, Vault Case, competition filters, bundle and quantity pickers. Everything else can be static Astro.

## 2. Design tokens

```css
:root{
  --bg:#07090A;            /* page */
  --surface:#0E1311;       /* cards / panels */
  --surface-2:#111715;     /* chips, placeholders */
  --inset:#0B100E;         /* ticker band */
  --line:#1E2723;          /* card borders */
  --line-2:#2B3A32;        /* button/input borders */
  --text:#F4F7F5;
  --text-2:#C9D3CE;        /* body copy */
  --muted:#9AA8A1;         /* labels (passes 4.5:1 on surface) */
  --green:#2FD36B;         /* CTA, accents, prices */
  --green-hover:#7BEBA3;   /* link hover */
  --brand-green:#1B9A47;   /* logo green, top bar, stripes */
  --green-deep:#0F2A1A;    /* selected-state fill */
  --green-tile:#0B2416;    /* instant-win tile bg */
  --red:#D7322B;           /* urgency only: ending soon, featured dot */
  --on-green:#04140A;      /* text on green */
  --ease:cubic-bezier(.16,1,.3,1);
}
```
Radii: cards 10px, panels 12 to 14px, chips and inputs 6 to 8px, pills 999px. Container: max-width 1240px, side padding 24px. Card grid: `repeat(auto-fill,minmax(260px,1fr))`, gap 20px.

**Type** (Google Fonts):
- Display: **Barlow Condensed** 800/900 *italic*, uppercase. Used for all headings, prices and big numbers. It echoes the logo's slant.
- Body: **Barlow** 400 to 700.
- Mono: **JetBrains Mono** 500/700, for the wallet balance, ticket counts and seed fingerprint.
- Section H2: 56px, line-height .9. Hero H1: 92px. Product H1: 64 to 72px. One word in each heading is green, e.g. "Live **Draws**".

**Signature details**
- Primary CTA: green fill, condensed italic uppercase, parallelogram `clip-path: polygon(14px 0,100% 0,calc(100% - 14px) 100%,0 100%)`.
- Countdowns are styled as darts scoreboards: black cells and green tabular digits.
- Footer top border: a 6px green and red stripe, like the rings of a dartboard.
- Bundle names use darts terms: **Double** (buy 5 get 2), **Treble** (buy 10 get 5, "Our pick"), **Ton** (buy 20 get 12), **Ton-80** (buy 30 get 20). These offers are placeholders until pricing is agreed.
- Instant win prize names use checkout slang: The Big Fish £170, Ton Checkout £100, Double Top £50, Shanghai £20, Bed & Breakfast £5, The Madhouse £1. These are samples.

## 3. Page structures

**Home:** top bar (18+, free postal entry, wallet wins, VIP WhatsApp) → header → featured live draw hero (countdown, entries-left bar, CTA, free entry line) → latest-winners marquee → Instant Wins grid → Live Draws grid → **Vault Case** → How It Works (3 steps: Step up to the oche / Grab your tickets / Game shot) → Real Winners (3) → brands strip (Target, Winmau, Harrows, Mission, Red Dragon, as text) → Join the Community (WhatsApp VIP highlighted, Facebook 4,200+, Instagram, Email Club) → SEO block "Darts Prize Competitions in the UK" → footer.

**All Competitions:** eyebrow "N live now" → H1 → search input and sort select → filter chips with counts (All / Instant Wins / Live Draws / Darts / Cases & Kit / Under £1) → mixed grid (InstantCard or CompCard by type) → VIP WhatsApp banner.

**Live Draw:** breadcrumb → 2 columns. The left column is a sticky image with 4 thumbnails. The right column holds: tags (draw time, close time, max entries) → countdown panel with sold bar → bundles panel (2×2 bundles, single-ticket stepper, live total, Enter now / Add to basket) → **prominent free-postal-entry box** → "Try a Vault Case" link. Below that: How draw night works (4 steps), More Information (description, entry list, fair play record, seed fingerprint), More Draws (3 cards).

**Instant Win:** same layout as Live Draw. The hero is a typographic tile ("170 CHECKOUT CASH"). The buy panel has a quantity stepper, presets 5/10/25/50, a minimum of 4, and the CTA "Buy & throw". After that come: the game explainer ("One ticket. One dart.", with an animated dartboard) → prize table (2 columns: value, name, left, found x of y, winning tickets link) → end prize banner → More Information → You Might Also Like.

**Compliance (Meta RMG review):** keep free postal entry visible in the top bar, next to every buy button (a green-bordered box), in the footer badges and on each product page. Wording: "Enter free by post with exactly the same chance of winning."

## 4. Vault Case (it replaces the old lucky-dip box)

This is a darts case. The customer picks a budget (£5 / £10 / £20 / £50) and a way to pack it: Surprise me / Big prizes / Most tickets. "Unzip my £X case" opens the lid in 3D, and three darts slide into foam slots. Each dart is a ticket bundle for a different live draw (ticket count and comp name), with 10% bonus tickets. Once it's open, the CTAs are "Add case to basket" and "Repack". Changing the budget or mix closes the case.

The mock's allocation logic is simple: budget ÷ 3 per comp, floor(spend / ticket price), bonus = ceil(total × 10%). **Replace it with a Laravel endpoint** that picks real open comps by mix and respects the entry caps.

Markup layers: `.v9-case` (perspective 1400px) → `.v9-tray` (foam with darts) under `.v9-lid` (logo, dashed zip line, slanted green stripes, green zip pull, price chip).

## 5. Motion spec

- Easing `cubic-bezier(.16,1,.3,1)`. Durations: 250ms for UI, 450ms for cards, 900ms for the hero, 1.1s for the case lid.
- **Page transitions:** Astro View Transitions (`<ClientRouter />`). Give the card image `transition:name={`comp-${id}`}` and the product hero the same name, so the tile morphs into the product page.
- **Scroll reveal:** CSS only, using `animation-timeline: view()` inside `@supports`, so unsupported browsers get no animation. No JS.
- **Load:** the hero column and product buy panel stagger in 70ms apart. The hero dartboard rotates slowly (90s per turn).
- **Cards:** on hover they lift 6px and get a green border and glow. The live-draw dartboard spins 54°, the instant-win stripes slide, and the big number glows. Progress bars fill from 0 on load. "Ending soon" badges blink.
- **Buttons:** a shine sweeps across on hover, they lift 2px, and press to scale .98.
- **Countdown:** the seconds digit glows on each tick. Live dots pulse.
- **Winners ticker:** a 40s marquee with the list duplicated, paused on hover, with faded edges.
- **Instant win game graphic:** a dart flies in on a 3.4s loop with a wobble and an impact ripple.
- **Reduced motion:** with `prefers-reduced-motion: reduce`, turn every animation and transition off.

The CSS (copy into a global stylesheet):

```css
@keyframes v9up{from{opacity:0;transform:translateY(28px)}to{opacity:1;transform:none}}
@keyframes v9spin{to{transform:rotate(360deg)}}
@keyframes v9float{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}
@keyframes v9marquee{to{transform:translateX(-50%)}}
@keyframes v9pulse{0%{box-shadow:0 0 0 0 rgba(47,211,107,.6)}100%{box-shadow:0 0 0 10px rgba(47,211,107,0)}}
@keyframes v9fill{from{transform:scaleX(0)}}
@keyframes v9shine{from{transform:translateX(-150%) skewX(-20deg)}to{transform:translateX(350%) skewX(-20deg)}}
@keyframes v9tick{0%{color:#B6FFD0;text-shadow:0 0 18px rgba(47,211,107,.9)}100%{color:#2FD36B;text-shadow:none}}
.v9-in{animation:v9up .9s var(--ease) both}
.v9-stagger>*{animation:v9up .8s var(--ease) both}
.v9-stagger>*:nth-child(2){animation-delay:.07s}.v9-stagger>*:nth-child(3){animation-delay:.14s}.v9-stagger>*:nth-child(4){animation-delay:.21s}.v9-stagger>*:nth-child(5){animation-delay:.28s}.v9-stagger>*:nth-child(6){animation-delay:.35s}.v9-stagger>*:nth-child(n+7){animation-delay:.42s}
.v9-spin{animation:v9spin 90s linear infinite}
.v9-float{animation:v9float 6s ease-in-out infinite}
.v9-live{animation:v9pulse 1.6s ease-out infinite}
.v9-bar{transform-origin:left center;animation:v9fill 1.6s var(--ease) .4s both}
.v9-tick{animation:v9tick 1s ease-out infinite}
.v9-tilt{transition:transform .6s var(--ease),box-shadow .6s}
.v9-tilt:hover{transform:perspective(1000px) rotateY(-5deg) rotateX(3deg) scale(1.015);box-shadow:0 30px 80px -30px rgba(47,211,107,.45)}
.v9-btn{position:relative;overflow:hidden;transition:transform .25s var(--ease),filter .25s}
.v9-btn:hover{transform:translateY(-2px);filter:brightness(1.07)}
.v9-btn:active{transform:scale(.98)}
.v9-btn::after{content:"";position:absolute;inset:0 auto 0 0;width:35%;background:linear-gradient(90deg,transparent,rgba(255,255,255,.6),transparent);transform:translateX(-150%) skewX(-20deg);pointer-events:none}
.v9-btn:hover::after{animation:v9shine .9s ease}
.v9-pill{transition:background-color .25s,border-color .25s,color .25s,transform .25s var(--ease)}
.v9-pill:hover{transform:translateY(-2px);border-color:var(--green)}
.v9-card{transition:transform .45s var(--ease),border-color .45s,box-shadow .45s}
.v9-card:hover{transform:translateY(-6px);border-color:var(--green);box-shadow:0 24px 50px -24px rgba(47,211,107,.55)}
.v9-marquee{display:flex;gap:12px;width:max-content;animation:v9marquee 40s linear infinite}
.v9-marquee:hover{animation-play-state:paused}
/* Vault Case */
.v9-case:not(.is-open){animation:v9float 5s ease-in-out infinite}
.v9-case .v9-lid{transform-origin:50% 0;transition:transform 1.1s cubic-bezier(.7,0,.2,1);backface-visibility:hidden}
.v9-case:not(.is-open):hover .v9-lid{transform:rotateX(-12deg)}
.v9-case.is-open .v9-lid{transform:rotateX(-112deg)}
.v9-case.is-open .v9-tray{box-shadow:inset 0 10px 30px rgba(0,0,0,.8),0 0 80px -10px rgba(47,211,107,.45)}
.v9-dart{opacity:0;transform:translateX(-56px) rotate(-3deg);transition:opacity .5s,transform .9s var(--ease)}
.v9-case.is-open .v9-dart{opacity:1;transform:none} /* stagger: transition-delay .75s + i*.15s */
@supports (animation-timeline:view()){
  .v9-reveal{animation:v9up linear both;animation-timeline:view();animation-range:entry 0% cover 22%}
  .v9-kids>*{animation:v9up linear both;animation-timeline:view();animation-range:entry 5% cover 25%}
}
@media (prefers-reduced-motion:reduce){*,*::before,*::after{animation:none!important;transition:none!important}}
```

## 6. Still to supply or confirm
- **Imagery:** product photos, winner photos, instant-win tile art, the Vault Case art (optional) and the OG image. Liam is generating the artwork with ChatGPT. Until then, keep the placeholder tiles (the darts board drawn in code) as the fallback.
- **Instant wins:** the 180 Club, Checkout Cash and Bullseye Bonanza prices and prize tables are samples.
- **Bundle offers** and per-comp "buy X get Y" lines are samples. Pricing is being reworked around VAT and prize cost.
- **Draw nights** are assumed to be Wednesday and Sunday at 8pm, streamed on Facebook.
- **Footer** needs the company number and registered address.
- **Product page** needs the product description, Verity fair play record ID and seed fingerprint (the engine supplies these).

---

## 7. Owner decisions made while building (22 Nov 2026)
- **Ticket counts are shown**: "Max N", "N of M left" and "N sold" on cards and competition pages (replaces the old % only rule).
- **No "was" prices.** The crossed-out price in the mock-ups is left out.
- **Draw nights:** live every Wednesday and Sunday at **9:30pm** on Facebook (the mock-ups say 8pm).
- **Left out until confirmed:** the Facebook member count, the brands strip, the Trustpilot link and sample winners. Winners, the ticker and "Real Winners" only ever show real published winners.
- **Vault Case** replaces the Lucky Dip in name and look; the server's packer (real open draws, entry caps, never over budget) is unchanged, and it stays off until switched on in Admin → Settings.

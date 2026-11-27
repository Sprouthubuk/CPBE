// The API contract between the Astro front end and the Laravel backend.
// Every shape here is documented in docs/api.md. Money is always whole pence.
// Dates are ISO 8601 strings in UTC; the front end shows them in UK time.
import type { RevealOutcome, RevealTicket } from "../games/types";
import type { RevealContext } from "../games/arcade/games/types";

export type Pence = number;

export type CompetitionType = "instant" | "draw";
export type CompetitionStatus = "scheduled" | "live" | "sold_out" | "closed" | "drawn" | "ended";

export interface CompetitionSummary {
  id: string;
  slug: string;
  title: string;
  type: CompetitionType;
  status: CompetitionStatus;
  /** Reveal game id (shared/game-ids.json), instant wins only. */
  game: string | null;
  /** Player-facing label of the game, e.g. "Claw Machine". */
  gameLabel: string | null;
  skin: string;
  image: string;
  /** A 400px WebP copy for thumbnails (the original when there are no copies). */
  imageSmall: string;
  /** "url 400w, url 800w, url 1200w" for srcset, or null when there are no copies. */
  imageSrcset: string | null;
  /** The ticket price now: the published price, or the one an automatic pricing rule gives (amendment 5). */
  pricePence: Pence;
  /** The price published with the competition. Shown struck through when an offer makes it cheaper. */
  basePricePence: Pence;
  /** The automatic offer applying now (amendment 5), or null. */
  offer: Offer | null;
  /** Amendment 4: paid entry for members of these tiers only (free postal entry stays open to all), or null. */
  membersOnly?: string[] | null;
  /** Instant wins: the biggest prize value. Draws: the prize value. */
  topPrizePence: Pence | null;
  /** Shown under the price, e.g. "£50 cash alternative" or "2,516 wins". */
  subtitle: string | null;
  /** Rounded down; 100 only when it has actually sold out. */
  soldPercent: number;
  /** The entry cap (max tickets) and how many are left. Brand Name shows both (owner, 22 Nov 2026). */
  maxTickets: number;
  ticketsLeft: number;
  /** Instant wins: how many instant prizes there are in total. Null for draws. */
  prizeCount: number | null;
  /** Instant wins with an end prize: what is drawn at the close, and for how many winners. Null otherwise. */
  endPrize: { name: string; valuePence: Pence; count: number } | null;
  /** When entries open (status is "scheduled" until then), or null when it opened on publish. */
  opensAt: string | null;
  closesAt: string;
  drawAt: string | null;
  featured: boolean;
  tags: string[];
  /** Live draws with bundles: the featured ("Our Pick") bundle on sale now, or null. */
  featuredBundle: Bundle | null;
}

/** Amendment 5: an automatic price or bonus-ticket offer, e.g. "Early Bird" or "Buy 5 Get 1 Free". */
export interface Offer {
  label: string;
  /** When a time-limited offer ends, or null. */
  until: string | null;
  /** Free tickets on single tickets: buy `buy`, get `get` free. */
  bonus: { buy: number; get: number } | null;
}

export interface PrizeTier {
  name: string;
  valuePence: Pence;
  type: "cash" | "site_credit" | "physical";
  quantity: number;
  won: number;
}

export interface CompetitionDetail extends CompetitionSummary {
  description: string;
  howToPlay: string | null;
  /** Amendment 8: how to watch the draw (draws), and how the winner is announced. */
  howToWatch?: string | null;
  /** Amendment 4: the signed-in member's discounted ticket price, or null. */
  memberPricePence?: Pence | null;
  /** Amendment 6: whether paid entries answer a skill question at checkout. */
  hasSkillQuestion?: boolean;
  winnerAnnounced?: string;
  /** Tickets the signed-in player already holds (0 when signed out). */
  heldByMe: number;
  cashAlternativePence: Pence | null;
  /** Instant wins: one row per prize tier, biggest first. */
  prizes: PrizeTier[];
  /** Instant wins with an end prize, and draws: what is drawn, and for how many winners (one each). */
  endPrize: { name: string; valuePence: Pence; count: number } | null;
  /** Ticket quick-pick buttons, smallest first. */
  presets: number[];
  /** The button marked "Most popular", or null. */
  popularPreset: number | null;
  /** Fewest tickets in one order for this competition (1 unless set). */
  minTickets: number;
  /** Live draws: the "Buy X Get Y Free" bundles on sale now, smallest first. Empty when there are none. */
  bundles: Bundle[];
  /** Verity reference and public seed commitment. */
  verityRef: string | null;
  commitment: string | null;
  verifyUrl: string | null;
}

export interface SiteInfo {
  salesOpen: boolean;
  minDepositPence: Pence;
  minCardPence: Pence;
  minWithdrawalPence: Pence;
  maxTopUpPence: Pence;
  featuredSlug: string | null;
  socials: { instagram: string | null; facebook: string | null; whatsapp: string | null; tiktok: string | null };
  supportEmail: string | null;
  /** Meta Pixel ID from the admin; null when none is set (then nothing from Meta ever loads). */
  metaPixelId: string | null;
  /** Vault Case (vault case): null while the feature is off. `available` is false when fewer than two draws can go in. */
  vaultCase: { available: boolean; chipsPence: Pence[]; minPence: Pence; maxPence: Pence; bonusPct: number } | null;
  /** Refer a Friend: null while the feature is off. */
  referrals: { rewardType: "tickets" | "credit"; rewardTickets: number; rewardCreditPence: Pence; minPurchasePence: Pence } | null;
  /** True while any loyalty programme is switched on (SOW 2.11). */
  loyalty: boolean;
  /** True while any membership tier is open to join (SOW 2.12). */
  memberships: boolean;
  /** SOW 3.3: Google tags set in the admin (loaded only after cookie consent), or null. */
  google: { gtmId: string | null; ga4Id: string | null; adsId: string | null; adsPurchaseLabel: string | null } | null;
  /** SOW 2.16: the announcement bar text (and an optional link on this site) set in the admin, or null. */
  announcement: { text: string; link: string | null } | null;
  /** SOW 2.17: the language switcher (Weglot), or null while it isn't set up. */
  translation: { weglotKey: string } | null;
}

export interface WalletBalances {
  depositPence: Pence;
  bonusPence: Pence;
  winningsPence: Pence;
  totalPence: Pence;
  withdrawablePence: Pence;
}

export interface Me {
  id: string;
  firstName: string;
  lastName: string;
  email: string;
  emailVerified: boolean;
  phone: string | null;
  dobVerified: boolean;
  /** ID check before the first withdrawal: verified, pending (being checked), rejected (send again, see idCheckNote) or needed. */
  idCheck: "verified" | "pending" | "rejected" | "needed";
  idCheckNote: string | null;
  address: { line1: string | null; line2: string | null; city: string | null; postcode: string | null };
  marketing: { email: boolean; sms: boolean; winNotifications: boolean };
  memberSince: string;
  wallet: WalletBalances;
  gamesWaiting: number;
  stats: { activeTickets: number; activeCompetitions: number; instantWins: number; totalWonPence: Pence; spentThisWeekPence: Pence };
  /** Why this account cannot play right now, or null. */
  playBlock: null | "staff" | "self_excluded" | "cool_off" | "on_hold" | "closed" | "under_18" | "no_date_of_birth" | "email_unverified";
}

export interface WalletTransaction {
  id: string;
  at: string;
  description: string;
  amountPence: Pence;
  direction: "in" | "out";
  bucket: "deposit" | "bonus" | "winnings";
}

export interface TicketGroup {
  competition: Pick<CompetitionSummary, "id" | "slug" | "title" | "type" | "image" | "imageSmall" | "imageSrcset" | "drawAt" | "closesAt" | "status">;
  orderNumber: string | null;
  /** A preview: the first 40 tickets plus every winning one. `ticketCount` is how many there are. */
  tickets: TicketRow[];
  ticketCount: number;
  toPlay: number;
  wonPence: Pence;
  status: "in_draw" | "to_play" | "won" | "no_win" | "drawn";
}

export interface TicketRow {
  number: number;
  won: boolean;
  played: boolean;
  prizeName: string | null;
}

/** GET /api/me/tickets/{order}/{competition}?page=: one order line's tickets, 500 a page. */
export interface TicketPage {
  competition: Pick<CompetitionSummary, "id" | "slug" | "title" | "type">;
  orderNumber: string;
  data: TicketRow[];
  total: number;
  page: number;
  perPage: number;
  hasMore: boolean;
}

export interface BasketLine {
  competitionId: string;
  /** Single tickets. Leave out (or 0) for a bundle line. */
  quantity: number;
  /** A "Buy X Get Y Free" bundle (live draws). The server works out its price and tickets. */
  bundleId?: number | null;
  /** Set when the line came from a Vault Case: the server adds the box's bonus tickets while the line still matches it. */
  boxId?: string | null;
  /** SOW 2.2: the chosen answer (its index) to the competition's skill question, sent at checkout. */
  skillAnswer?: number | null;
  /** SOW 2.18: the conversion tool that put it in the basket, for reporting. */
  via?: "upsell" | "repeat" | "recovery" | "recommendation";
}

export type VaultCaseMode = "surprise" | "big" | "most";

/** A box the server packed and priced. Only the player's own tickets and each draw's % sold: never a ticket count of a draw. */
export interface VaultCase {
  id: string;
  mode: VaultCaseMode;
  budgetPence: Pence;
  totalPence: Pence;
  /** Budget not spent: always less than one ticket of the cheapest draw in the box. */
  unusedPence: Pence;
  tickets: number;
  bonusTickets: number;
  /** The box's tickets are held until then. */
  expiresAt: string;
  lines: {
    competitionId: string; slug: string; title: string; image: string; imageSmall: string; imageSrcset: string | null;
    soldPercent: number; unitPence: Pence; bundleId: number | null; bundleLabel: string | null; singles: number;
    paidQuantity: number; freeQuantity: number; bonusQuantity: number; tickets: number; linePence: Pence;
    /** Neutral note when the draw's promo ticket cap stops its bonus (never a number). */
    notice: string | null;
  }[];
  /** What to put in the basket. */
  basketLines: { competitionId: string; quantity: number; bundleId?: number; boxId: string }[];
}

export type ReferralStatus = "pending" | "missed" | "blocked" | "review" | "rewarded" | "capped" | "rejected" | "clawed_back";

export interface Voucher {
  id: number;
  tickets: number;
  status: "available" | "redeemed" | "expired" | "clawed_back";
  expiresAt: string;
  competition: { title: string; slug: string } | null;
  /** Bonus entries: the only draw this voucher can go into. */
  forCompetition?: { title: string; slug: string } | null;
}

/** GET /api/me/loyalty (SOW 2.11). */
export interface LoyaltyProgrammeView {
  id: number;
  name: string;
  description: string | null;
  balance: number;
  lifetime: number;
  pointsPerPound: number;
  expiryDays: number | null;
  expiringSoon: number;
  tier: { name: string; benefits: string | null; multiplierPct: number } | null;
  nextTier: { name: string; pointsToGo: number } | null;
  tiers: { name: string; thresholdPoints: number; multiplierPct: number; benefits: string | null }[];
  rewards: { id: number; name: string; description: string | null; type: "site_credit" | "free_entries" | "bonus_entries" | "custom"; pointsCost: number;
    creditPence: Pence | null; tickets: number | null; minTier: string | null; available: boolean; affordable: boolean }[];
  history: { points: number; kind: "earn" | "bonus" | "redeem" | "expire" | "reverse" | "adjust" | "refund"; reason: string; at: string; expiresAt: string | null }[];
}

/** A membership tier (SOW 2.12). `ineligible` says why this player can't join it, or null. */
export interface MembershipTierView {
  id: number;
  name: string;
  description: string | null;
  pricePence: Pence;
  perks: string[];
  ineligible: string | null;
}

/** GET /api/me/membership; the membership actions return this plus `order` and `redirectUrl`. */
export interface MyMembership {
  membership: {
    id: number;
    status: "active" | "past_due";
    tier: MembershipTierView;
    payWith: "card" | "wallet";
    perksOn: boolean;
    periodEnd: string | null;
    cancelAtPeriodEnd: boolean;
    nextTier: string | null;
    nextAttemptAt: string | null;
    startedAt: string | null;
  } | null;
  tiers: MembershipTierView[];
  order?: { number: string; status: OrderStatus };
  redirectUrl?: string | null;
}

export interface MyLoyalty {
  programmes: LoyaltyProgrammeView[];
  redemptions: { id: number; reward: string | null; points: number; status: "done" | "pending" | "fulfilled" | "cancelled"; voucherId: number | null; at: string }[];
  vouchers: Voucher[];
}

export interface MyReferrals {
  code: string;
  link: string;
  stats: { invited: number; qualified: number; rewards: number; leftThisMonth: number; cap: number };
  rewardType: "tickets" | "credit";
  rewardTickets: number;
  rewardCreditPence: Pence;
  minPurchasePence: Pence;
  vouchers: Voucher[];
  friends: { name: string; status: ReferralStatus; joinedAt: string }[];
  /** SOW 2.13: the scheme new referrals join (its rewards replace the standard ones), or null. */
  scheme: { name: string; qualifying: "first_order" | "any_order" | "membership"; minSpendPence: Pence; referrerReward: string; friendReward: string; endsAt: string | null } | null;
  /** True when schemes are set up but none is running for this player right now. */
  schemeClosed: boolean;
  /** Rewards this player has earned: level 0 as a friend, 1 as the referrer, 2+ from further down the chain. */
  rewards: { level: number; reward: string; status: "given" | "reversed"; at: string }[];
}

/** A bundle a live draw is selling now. Only what fits is sent; nothing about the ticket pool. */
export interface Bundle {
  id: number;
  label: string;
  paid: number;
  free: number;
  tickets: number;
  pricePence: Pence;
  /** free × ticket price. */
  savePence: Pence;
  /** The admin's featured bundle: badged "Our Pick" and preselected. */
  ourPick: boolean;
}

export interface PricedLine {
  competitionId: string;
  slug: string;
  title: string;
  image: string;
  imageSmall: string;
  imageSrcset: string | null;
  type: CompetitionType;
  /** Tickets on the line: paid + free. */
  quantity: number;
  bundleId: number | null;
  bundleLabel: string | null;
  paidQuantity: number;
  freeQuantity: number;
  unitPence: Pence;
  linePence: Pence;
  minQuantity: number;
  /** Extra tickets from a Vault Case, already inside `quantity`. */
  bonusQuantity: number;
  source: "vault_case" | null;
  vaultCaseId: string | null;
  problem: string | null;
  /** SOW 2.2: the question every paid entry must answer (never the answer), or null. */
  skillQuestion?: { question: string; options: string[] } | null;
  /** Amendment 5: the automatic offer that set this line's price or free tickets, or null. */
  offerLabel?: string | null;
  priceRuleId?: number | null;
  /** Amendment 4: the line is at the member's discounted price. */
  memberPrice?: boolean;
  /** Neutral note that doesn't stop checkout, e.g. a Vault Case bonus the draw's promo cap no longer allows. */
  notice: string | null;
  /** The next bundle up, for "Upgrade to … for +£X". */
  upgrade: { bundleId: number; label: string; tickets: number; freeQuantity: number; extraPence: Pence } | null;
}

export interface PricedBasket {
  lines: PricedLine[];
  totalPence: Pence;
  ticketCount: number;
  /** A valid promo code (SOW 2.3): its discount and a label such as "20% off" or "£5.00 off". */
  promo: { code: string; discountPence: Pence; label: string } | null;
  /** Points the basket would earn in each switched-on loyalty programme, paid with real money (SOW 2.11). */
  loyaltyPoints: { programme: string; points: number }[];
  /** What the wallet would pay if the player chooses wallet. */
  walletCanPayPence: Pence;
  walletCovers: boolean;
  /** Voluntary Code 1.3: why only a debit card can be used for this basket, or null. */
  cardNote?: string | null;
  problems: string[];
}

export type OrderStatus = "created" | "pending_payment" | "paid" | "failed" | "cancelled" | "expired" | "refunded";

export interface OrderView {
  number: string;
  status: OrderStatus;
  needsAttention: boolean;
  totalPence: Pence;
  cardPence: Pence;
  walletPence: Pence;
  /** What Meta is told the order was worth: real money only (card, deposit, winnings), never bonus credit. */
  trackValuePence: Pence;
  paidAt: string | null;
  lines: { competitionId: string; slug: string; title: string; image: string; imageSmall: string; imageSrcset: string | null; type: CompetitionType; quantity: number; paidQuantity: number; freeQuantity: number; bundleLabel: string | null; tickets: number[]; drawAt: string | null }[];
  /** Instant-win tickets in this order not yet revealed. */
  toPlay: number;
}

export interface CheckoutResult {
  order: { number: string; status: OrderStatus };
  next: "play" | "redirect" | "attention";
  redirectUrl: string | null;
}

export interface PlayResult {
  /** Each ticket also links to its competition's fairness record (`/verify/{ref}`), or null. */
  outcome: RevealOutcome & { tickets: readonly (RevealTicket & { proofUrl: string | null })[] };
  context: RevealContext;
}

export interface InstantWinnerRow {
  id: string;
  name: string;
  prizeName: string;
  valuePence: Pence;
  game: string | null;
  gameLabel: string | null;
  competitionTitle: string;
  at: string;
}

export interface DrawWinnerRow {
  id: string;
  name: string;
  prizeName: string;
  competitionTitle: string;
  ticketNumber: number | null;
  at: string;
  videoUrl: string | null;
  /** The winner's photo, shown only with their consent (SOW 2.9). */
  photoUrl: string | null;
  verifyUrl: string | null;
}


export interface EntryRow {
  ticket: number;
  name: string;
  source: "paid" | "postal" | "free";
  /** Instant wins: the prize this ticket won, or null. */
  prizeName: string | null;
}

/** One instant prize and its winning ticket number, public from publish. Won once that ticket is sold. */
export interface WinningNumberRow {
  ticket: number;
  prizeName: string;
  valuePence: Pence;
  type: "cash" | "site_credit" | "physical";
  won: boolean;
  /** "Amy B." (or "Postal entrant" / "Anonymous") once won; null until then, and for a paid win its player hasn't played yet (up to an hour). */
  winnerName: string | null;
  wonAt: string | null;
}

/** GET /api/competitions/{slug}/winning-numbers?show=all|won|left&q=&prize=&page= (100 a page, biggest prize first). */
export interface WinningNumbersPage {
  competition: CompetitionSummary;
  data: WinningNumberRow[];
  /** Every winning number in the competition, and how many are won. */
  total: number;
  won: number;
  /** Rows matching show and q. */
  matching: number;
  hasMore: boolean;
}

export interface Limits {
  /** SOW 2.8: daily, weekly and monthly deposit limits (null: none). */
  dailyDepositPence: Pence | null;
  weeklyDepositPence: Pence | null;
  monthlyDepositPence: Pence | null;
  /** Raised or removed limits waiting 24 hours: only the periods that are changing are present. */
  pending: { dailyDepositPence?: Pence | null; weeklyDepositPence?: Pence | null; monthlyDepositPence?: Pence | null; appliesAt: string } | null;
  coolOffUntil: string | null;
  selfExcludedUntil: string | null;
  reminders: { sessionMinutes: number | null; weeklySummary: boolean };
}

export interface ApiError {
  message: string;
  errors?: Record<string, string[]>;
  code?: string;
}

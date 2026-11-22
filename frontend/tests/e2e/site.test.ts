import { afterAll, beforeAll, describe, expect, it } from "vitest";
import { Browser, startSite, type Site } from "./harness";
import { text } from "./harness";
import { COMPANY } from "../../src/lib/company";

let site: Site;
beforeAll(async () => { site = await startSite(); }, 60000);
afterAll(async () => { await site?.stop(); });


const PUBLIC_PAGES: [string, RegExp][] = [
  ["/", /Instant Wins/],
  ["/competitions", /All Competitions/],
  ["/competitions?type=draw", /Live Draws 3/],
  ["/competitions?q=treble", /Treble Twenty/],
  ["/competitions/treble-twenty-cash-claw", /Instant Wins/],
  ["/competitions/pro-dartboard-bundle", /Drawn Live/],
  ["/instant-winners", /Instant Winners/],
  ["/winners", /Our Winners/],
  ["/entry-lists", /Entry Lists/],
  ["/entry-lists/pro-dartboard-bundle", /\d+% sold/],
  ["/entry-lists/treble-twenty-cash-claw", /Won Single One/],
  ["/competitions/treble-twenty-cash-claw/winning-numbers", /Winning Numbers[\s\S]*prizes won[\s\S]*#\d+/],
  ["/competitions/treble-twenty-cash-claw/winning-numbers?show=won", /Emmie A\./],
  ["/how-it-works", /How It Works/],
  ["/faq", /Got A Question/],
  ["/contact", /Talk To Us/],
  ["/free-entry", /Free Postal Entry/],
  ["/legal/terms", /Terms &(amp;)? Conditions/],
  ["/menu", /All Competitions/],
  ["/join", /Join Brand Name/],
  ["/login", /Welcome Back/],
  ["/forgot-password", /Reset Password/],
  ["/basket", /Your Basket/],
  ["/demo/claw_machine", /Try Claw Machine/],
];

const NO_FOOTER = new Set(["/menu", "/join", "/login", "/forgot-password"]);

describe("every public page renders", () => {
  for (const [path, expected] of PUBLIC_PAGES) {
    it(`${path}`, async () => {
      const b = new Browser(site.origin);
      const { res, html } = await b.page(path);
      expect(res.status, html.slice(0, 400)).toBe(200);
      const t = text(html);
      expect(t).toMatch(expected);
      expect(t).not.toMatch(/\bundefined\b|\bNaN\b|\[object Object\]/);
      expect(html).toContain('<html lang="en-GB">');
      if (!NO_FOOTER.has(path)) expect(html).toContain(COMPANY.legalName); // company details in the footer
    });
  }
});

describe("site-wide rules", () => {
  it("unknown pages and competitions are a 404 page", async () => {
    const b = new Browser(site.origin);
    expect((await b.page("/nope")).res.status).toBe(404);
    expect((await b.page("/competitions/not-a-real-comp")).res.status).toBe(404);
    expect((await b.page("/demo/not_a_game")).res.status).toBe(404);
  });
  it("sends security headers and loads nothing from other companies", async () => {
    const b = new Browser(site.origin);
    const { res, html } = await b.page("/");
    expect(res.headers.get("x-content-type-options")).toBe("nosniff");
    expect(res.headers.get("x-frame-options")).toBe("DENY");
    expect(res.headers.get("referrer-policy")).toBe("strict-origin-when-cross-origin");
    expect(html).not.toMatch(/fonts\.googleapis|googletagmanager|facebook\.net/);
    expect(html).toMatch(/noindex/); // search indexing stays off until launch
  });
  it("a returning player who taps Buy while signed out can log in from the top of the join page", async () => {
    const next = "/checkout?buy=c-cash:10";
    const { html } = await new Browser(site.origin).page(`/join?next=${encodeURIComponent(next)}`);
    const notice = html.match(/data-testid="join-from-buy"[\s\S]*?<\/div>/)?.[0] ?? "";
    expect(notice).toContain("Your tickets are saved");
    expect(notice).toContain(`/login?next=${encodeURIComponent(next).replace(/&/g, "&amp;")}`);
  });
  it("How It Works never promises a ticket count", async () => {
    const t = text((await new Browser(site.origin).page("/how-it-works")).html);
    expect(t).not.toMatch(/how many tickets there are|tickets left|max(imum)? entries/i);
    expect(t).toContain("how much has sold");
  });
  it("members-only pages send visitors to log in and come back", async () => {
    const b = new Browser(site.origin);
    for (const p of ["/account", "/account/wallet", "/account/tickets", "/account/safer-play", "/account/settings", "/checkout", "/order/CP-1234", "/payment/failed?order=CP-1234", "/payment/cancelled?order=CP-1234"]) {
      const res = await b.get(p);
      expect(res.status, p).toBe(302);
      expect(res.headers.get("location")).toBe(`/login?next=${encodeURIComponent(p)}`);
    }
  });
  it("the home page shows the featured comp, live countdown, every instant win and the live draws", async () => {
    const { html } = await new Browser(site.origin).page("/");
    expect(html).toContain("data-countdown=");
    expect(html).toContain("Treble Twenty Cash Claw");
    // Instant wins, then live draws, each with one green word and a "View all" link (design/Main.dc.html).
    expect(html).toMatch(/Instant <span class="np">Wins<\/span>/);
    expect(html).toMatch(/Live <span class="np">Draws<\/span>/);
    expect(text(html)).toMatch(/Streamed live on social media/);
    // The ticker under the hero is the real winners feed (names, prize, competition, when).
    expect(html).toMatch(/Latest <span class="np">Winners<\/span>/);
    expect(html).toMatch(/<strong>Emmie A\.<\/strong> won <em>£0\.50 site credit<\/em> · Double Top Pop · /);
    // Nothing the owner hasn't confirmed: no member counts, brand strip or review links.
    expect(text(html)).not.toMatch(/4,200|Trustpilot|Brands we feature/i);
  });
  it("competition cards show price, prizes, entry cap and entries left, and the free postal route", async () => {
    const { html } = await new Browser(site.origin).page("/competitions");
    const t = text(html);
    expect(t).toMatch(/25p/);
    expect(t).toMatch(/2,516 PRIZES/); // an instant win's prize count
    expect(t).toMatch(/Win up to £/);
    expect(t).toMatch(/2% sold/);
    expect(t).toMatch(/MAX 999/); // a live draw shows its entry cap and what's left (owner, 22 Nov 2026)
    expect(t).toMatch(/\d+ of 999 left/);
    expect(t).toMatch(/Buy 10 Get 15 Free/); // a live draw card names its Our Pick bundle
    expect(t).toMatch(/Or enter free by post/); // on every card
    expect(html.match(/class="vc-post"/g)?.length).toBe(html.match(/<article class="vc"/g)?.length);
    expect(t).not.toMatch(/per person/i);
    expect(text((await new Browser(site.origin).page("/competitions/treble-twenty-cash-claw")).html)).not.toMatch(/per person/i);
  });
  it("the competition page: prize cards, one information box, no demo link", async () => {
    const { html } = await new Browser(site.origin).page("/competitions/treble-twenty-cash-claw");
    const t = text(html);
    expect(html).not.toContain("Try The Game Free");
    expect(html).not.toContain('class="acd"');
    expect(html).toContain('data-testid="more-information"');
    expect(t).toMatch(/Instant prizes left to win/);
    expect(t).toMatch(/\d Left/);
    expect(t).toMatch(/One ticket\. One throw\./);
    expect(html).toContain('data-postal'); // the No purchase necessary box beside the buy panel
    expect(t).toMatch(/of 1,500 found/);
    expect(html).toContain("/competitions/treble-twenty-cash-claw/winning-numbers?prize=Single+One");
    expect(html).toContain('href="/free-entry"'); // the free postal route stays visible
    const one = text((await new Browser(site.origin).page("/competitions/treble-twenty-cash-claw/winning-numbers?prize=Single+One")).html);
    expect(one).toContain("Show every prize");
    expect(one).not.toContain("Nine-Darter");
  });
  it("game demos can be framed by this site only, and ?embed=1 is just the game", async () => {
    const b = new Browser(site.origin);
    const demo = await b.page("/demo/slots?embed=1");
    expect(demo.res.headers.get("x-frame-options")).toBe("SAMEORIGIN");
    expect(demo.html).not.toContain('class="hd"'); // no header
    expect(demo.html).not.toContain(COMPANY.legalName); // no footer
    expect((await b.page("/demo/slots")).html).toContain(COMPANY.legalName);
    expect((await b.page("/")).res.headers.get("x-frame-options")).toBe("DENY");
  });
  it("a live draw's page shows its bundles, Our Pick first choice, the entry cap and entries left", async () => {
    const { html } = await new Browser(site.origin).page("/competitions/pro-dartboard-bundle");
    const t = text(html);
    expect(t).toMatch(/Buy 10 Get 15 FREE!/);
    expect(t).toMatch(/Our Pick/);
    expect(t).toMatch(/25 tickets for £5\.90/);
    expect(t).toMatch(/Save £8\.85/);
    expect(t).toMatch(/Bundle and free tickets are entered into the draw exactly like paid tickets/);
    expect(t).toMatch(/Max 999 entries/);
    expect(t).toMatch(/\d+ sold/);
    expect(t).toMatch(/\d+ of 999 left/);
    expect(t).toMatch(/Drawn live/);
    expect(html).not.toMatch(/maxPerPerson/);
  });
  it("the competition page renders the ticket picker on the server", async () => {
    const { html } = await new Browser(site.origin).page("/competitions/treble-twenty-cash-claw");
    expect(html).toContain('data-testid="ticket-picker"');
    expect(html).toContain("Or choose your own amount");
    expect(html).toContain("£2.50"); // 10 tickets × 25p preselected
    expect(html).toMatch(/Seed fingerprint/);
  });
});

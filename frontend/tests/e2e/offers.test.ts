import { afterAll, beforeAll, describe, expect, it } from "vitest";
import { Browser, startSite, text, type Site } from "./harness";

// Amendment 5: an automatic offer shows on the card and the competition page, with the usual price beside it.
let site: Site;
beforeAll(async () => { site = await startSite(); }, 60000);
afterAll(async () => { await site?.stop(); });

describe("Automatic offers", () => {
  it("the card shows the offer and the usual price struck through", async () => {
    const { html } = await new Browser(site.origin).page("/competitions");
    expect(html).toMatch(/data-testid="card-offer">Early Bird · Buy 5 Get 1 Free</);
    expect(html).toMatch(/<s class="vc-was">99p<\/s>/);
  });

  it("the competition page explains the offer before buying", async () => {
    const { html } = await new Browser(site.origin).page("/competitions/tungsten-darts-set");
    expect(text(html)).toContain("Early Bird · 79p a ticket, usually 99p · Buy 5, get 1 free");
  });

  it("competitions without an offer show nothing extra", async () => {
    const { html } = await new Browser(site.origin).page("/competitions/250-tax-free-cash");
    expect(html).not.toMatch(/data-testid="offer"/);
  });
});

describe("Postal references (amendment 7)", () => {
  it("a signed-in player sees the button on a competition page; a visitor doesn't", async () => {
    const visitor = await new Browser(site.origin).page("/competitions/250-tax-free-cash");
    expect(visitor.html).not.toContain("Get My Postal Reference");
    const b = new Browser(site.origin);
    expect((await b.api("/api/auth/login", { email: "player@example.com", password: "password123" })).status).toBe(200);
    const { html } = await b.page("/competitions/250-tax-free-cash");
    expect(html).toContain("Get My Postal Reference");
    const c = (await b.api("/api/competitions")).body.data.find((x: any) => x.slug === "250-tax-free-cash");
    expect((await b.api("/api/me/postal-references", { competitionId: c.id })).body.reference).toBe("PE7K 3M9Q 2R");
  });
});

describe("At a glance (amendment 8)", () => {
  it("each competition says plainly how to enter, the price, when the draw is, how to watch and how the winner is announced", async () => {
    const t = text((await new Browser(site.origin).page("/competitions/250-tax-free-cash")).html);
    for (const s of ["How to enter", "enter free by post", "99p a ticket", "When the draw is", "How to watch", "Streamed live", "How the winner is announced", "in accordance with the laws of chance"]) expect(t).toContain(s);
    const instant = text((await new Browser(site.origin).page("/competitions/checkout-slots")).html);
    expect(instant).toContain("When you find out");
    expect(instant).not.toContain("How to watch");
  });
});

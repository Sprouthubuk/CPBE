import { afterAll, beforeAll, describe, expect, it } from "vitest";
import { Browser, startSite, text, type Site } from "./harness";

let site: Site;
beforeAll(async () => { site = await startSite(); }, 60000);
afterAll(async () => { await site?.stop(); });

describe("Checkout and conversion tools (SOW 2.18)", () => {
  it("the account page shows picks for the player and the order page offers Buy Again", async () => {
    const b = new Browser(site.origin);
    expect((await b.api("/api/auth/login", { email: "player@example.com", password: "password123" })).status).toBe(200);
    const acct = await b.page("/account");
    expect(acct.html).toContain('data-testid="picked-for-you"');
    expect(acct.html).toMatch(/\?via=recommendation/);
    const comps = (await b.api("/api/competitions")).body.data;
    const c = comps.find((x: { slug: string }) => x.slug === "pro-dartboard-bundle") ?? comps.find((x: { type: string; slug: string }) => x.type === "draw" && x.slug !== "tungsten-darts-set");
    const order = await b.api("/api/checkout", { lines: [{ competitionId: c.id, quantity: 2, via: "upsell" }], payWith: "wallet", useWallet: true, requestId: `r${Date.now()}` });
    expect(order.status).toBe(201);
    const again = await b.api(`/api/orders/${order.body.order.number}/repeat`);
    expect(again.body.lines[0]).toMatchObject({ competitionId: c.id, via: "repeat" });
    const recs = await b.api(`/api/recommendations?exclude=${c.id}&limit=2`);
    expect(recs.body.data.every((x: { id: string }) => x.id !== c.id)).toBe(true);
  });

  it("the home page marks its main button for A/B tests, and loads no tag before consent", async () => {
    const { html } = await new Browser(site.origin).page("/");
    expect(html).toContain('data-exp="home_hero_cta"');
    expect(html).not.toMatch(/googletagmanager|weglot\.com/);
    expect(text(html)).not.toContain("Translations are automatic");
  });
});

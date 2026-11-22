import { afterAll, beforeAll, describe, expect, it } from "vitest";
import { Browser, startSite, text, type Site } from "./harness";

let site: Site;
beforeAll(async () => { site = await startSite(); }, 60000);
afterAll(async () => { await site?.stop(); });

const uniq = () => `l${Date.now()}${Math.floor(Math.random() * 1e6)}@example.com`;

describe("Loyalty points (SOW 2.11)", () => {
  it("a player sees their points, tier, rewards and history, and can spend points", async () => {
    const b = new Browser(site.origin);
    expect((await b.api("/api/auth/register", { firstName: "Lee", lastName: "Points", email: uniq(), password: "correct-horse", dateOfBirth: "1990-05-05" })).status).toBe(201);
    const page = await b.page("/account/loyalty");
    expect(page.res.status).toBe(200);
    const t = text(page.html);
    expect(t).toContain("My Rewards");
    expect(t).toContain("Rewards Club");
    expect(t).toContain("more points to reach Silver");
    expect(t).toContain("£1 Site Credit");
    expect(page.html).toContain('data-testid="points-balance"');
    // The account menu links to it.
    expect((await b.page("/account")).html).toContain('href="/account/loyalty"');
    const r = await b.api("/api/me/loyalty/redeem", { rewardId: 1 });
    expect(r.status).toBe(201);
    expect((await b.api("/api/me/loyalty/redeem", { rewardId: 2 })).status).toBe(422);
  });

  it("the basket shows the points an order would earn", async () => {
    const b = new Browser(site.origin);
    const comps = (await b.api("/api/competitions")).body.data;
    const priced = await b.api("/api/basket/price", { lines: [{ competitionId: comps[0].id, quantity: 20 }] });
    expect(priced.body.loyaltyPoints.length).toBeGreaterThan(0);
    expect(priced.body.loyaltyPoints[0].programme).toBe("Rewards Club");
  });
});

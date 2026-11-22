import { afterAll, beforeAll, describe, expect, it } from "vitest";
import { Browser, startSite, text, type Site } from "./harness";

let site: Site;
beforeAll(async () => { site = await startSite(); }, 60000);
afterAll(async () => { await site?.stop(); });

const uniq = () => `m${Date.now()}${Math.floor(Math.random() * 1e6)}@example.com`;

describe("Memberships (SOW 2.12)", () => {
  it("a player sees the tiers and perks, joins, cancels and keeps it", async () => {
    const b = new Browser(site.origin);
    expect((await b.api("/api/auth/register", { firstName: "Mo", lastName: "Member", email: uniq(), password: "correct-horse", dateOfBirth: "1990-05-05" })).status).toBe(201);
    const page = await b.page("/account/membership");
    expect(page.res.status).toBe(200);
    const t = text(page.html);
    expect(t).toContain("Choose A Membership");
    expect(t).toContain("Silver");
    expect(t).toContain("10 free entries every month");
    expect(page.html).toContain('data-testid="join-card"');
    expect((await b.page("/account")).html).toContain('href="/account/membership"');

    const joined = await b.api("/api/me/membership", { tierId: 1, payWith: "card" });
    expect(joined.status).toBe(201);
    expect(joined.body.membership.tier.name).toBe("Silver");
    const after = text((await b.page("/account/membership")).html);
    expect(after).toContain("Renews on");
    expect(after).toContain("Upgrade To Gold");
    expect((await b.api("/api/me/membership/cancel", {})).body.membership.cancelAtPeriodEnd).toBe(true);
    expect(text((await b.page("/account/membership")).html)).toContain("Keep My Membership");
  });
});

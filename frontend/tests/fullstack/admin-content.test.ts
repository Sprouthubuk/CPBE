import { afterAll, beforeAll, describe, expect, it } from "vitest";
import { Browser, startSite, text, type Site } from "../e2e/harness";

// Admin-set content reaching the real site (SOW 2.16 announcement bar, 2.9 winner photos).
let site: Site;
beforeAll(async () => {
  site = await startSite("laravel", { tinker: "App\\Models\\Setting::put('announcement_text', 'Double Draw Sunday'); App\\Models\\Setting::put('announcement_link', '/competitions');" });
}, 120000);
afterAll(async () => { await site?.stop(); });

describe("Admin content on the real site", () => {
  it("the top bar shows the admin's announcement beside the free postal route", async () => {
    const { html } = await new Browser(site.origin).page("/");
    expect(html).toMatch(/<a href="\/competitions" data-testid="announcement">Double Draw Sunday<\/a>/);
    expect(text(html)).toContain("Free postal entry on every comp");
  });

  it("the winners page works with no winners yet and invents none", async () => {
    const { res, html } = await new Browser(site.origin).page("/winners");
    expect(res.status).toBe(200);
    expect(text(html)).toContain("coming soon");
  });
});

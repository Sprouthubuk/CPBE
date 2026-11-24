import { afterAll, beforeAll, describe, expect, it } from "vitest";
import { Browser, startSite, text, type Site } from "../e2e/harness";

// Admin-set content reaching the real site (SOW 2.16 announcement bar, 2.9 winner photos).
let site: Site;
beforeAll(async () => {
  site = await startSite("laravel", { tinker: "App\\Models\\Setting::put('announcement_text', 'Double Draw Sunday'); App\\Models\\Setting::put('announcement_link', '/competitions'); App\\Models\\Setting::put('weglot_api_key', 'wg_abcdefghij1234');" });
}, 120000);
afterAll(async () => { await site?.stop(); });

describe("Admin content on the real site", () => {
  it("the top bar shows the admin's announcement beside the free postal route", async () => {
    const { html } = await new Browser(site.origin).page("/");
    expect(html).toMatch(/<a href="\/competitions" data-testid="announcement">Double Draw Sunday<\/a>/);
    expect(text(html)).toContain("Free postal entry on every comp");
  });

  it("Weglot (a third party that gets the page's text) loads only when the visitor asks, and never on signed-in pages", async () => {
    const { html } = await new Browser(site.origin).page("/");
    expect(html).not.toMatch(/<script[^>]+src="https:\/\/cdn\.weglot\.com/);
    expect(html).toMatch(/data-weglot-ok="1"/);
    expect(html).toMatch(/data-translate hidden>Translate This Site</);
    const login = await new Browser(site.origin).page("/login?next=/account");
    expect(login.html).toMatch(/data-weglot-ok="1"/);
    const b = new Browser(site.origin);
    expect((await b.api("/api/auth/login", { email: "player@example.com", password: "password123" })).status).toBe(200);
    for (const p of ["/account", "/account/wallet", "/checkout"]) {
      const r = await b.page(p);
      expect(r.res.status, p).toBe(200);
      expect(r.html, p).toMatch(/data-weglot-ok="0"/);
    }
  });

  it("the winners page works with no winners yet and invents none", async () => {
    const { res, html } = await new Browser(site.origin).page("/winners");
    expect(res.status).toBe(200);
    expect(text(html)).toContain("coming soon");
  });
});

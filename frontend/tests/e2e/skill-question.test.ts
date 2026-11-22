import { afterAll, beforeAll, describe, expect, it } from "vitest";
import { Browser, startSite, type Site } from "./harness";

let site: Site;
beforeAll(async () => { site = await startSite(); }, 60000);
afterAll(async () => { await site?.stop(); });

describe("Skill question (SOW 2.2)", () => {
  it("the basket carries the question and never the answer; checkout needs the right answer", async () => {
    const b = new Browser(site.origin);
    expect((await b.api("/api/auth/login", { email: "player@example.com", password: "password123" })).status).toBe(200);
    const comps = (await b.api("/api/competitions")).body.data;
    const c = comps.find((x: any) => x.slug === "tungsten-darts-set");
    const priced = await b.api("/api/basket/price", { lines: [{ competitionId: c.id, quantity: 2 }] });
    expect(priced.body.lines[0].skillQuestion.options).toEqual(["Two", "Three", "Four"]);
    expect(JSON.stringify(priced.body)).not.toContain("answer");
    const base = { payWith: "card", useWallet: false, requestId: `r${Date.now()}` };
    expect((await b.api("/api/checkout", { ...base, lines: [{ competitionId: c.id, quantity: 2 }] })).status).toBe(422);
    const wrong = await b.api("/api/checkout", { ...base, requestId: `${base.requestId}b`, lines: [{ competitionId: c.id, quantity: 2, skillAnswer: 0 }] });
    expect(wrong.status).toBe(422);
    expect(wrong.body.message).toContain("isn't right");
    expect((await b.api("/api/checkout", { ...base, requestId: `${base.requestId}c`, lines: [{ competitionId: c.id, quantity: 2, skillAnswer: 1 }] })).status).toBe(201);
  });
});

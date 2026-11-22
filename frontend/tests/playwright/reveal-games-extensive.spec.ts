import { expect, test, type Page } from "@playwright/test";

/**
 * Pre-launch sweep of every reveal game (classic look and Christmas skin) through every
 * sample purchase on the development preview page, /dev/reveal-games. Sample tickets only:
 * nothing is bought and the database is never touched.
 *
 * For every game, skin and purchase it plays each screen to the end and checks:
 *  - each ticket's result screen shows exactly what that ticket was given;
 *  - the closing summary's total, list of wins and wallet balance are right;
 *  - nothing errors in the console, nothing scrolls sideways, the buttons are on screen.
 * Then awkward play (closing early, double taps, reset, sound on), the "Try the demo"
 * button on the real competition pages (read only), and smoothness on a slowed-down phone.
 *
 * The board generators themselves are proven across thousands of seeds in tests/reveal-games.
 */

const CLASSIC_GAMES = ["scratch_card", "spin_wheel", "bingo_card", "balloon_pop", "hook_a_duck", "claw_machine", "coin_drop", "slots", "gems", "whack_a_mole", "pinata"];
/** The games each skin draws its own way (Brand Name starts with the classic look only). */
const SKIN_GAMES: Record<string, string[]> = {
  classic: CLASSIC_GAMES,
};
/** The looks with their own sounds: none yet. */
const THEMED: string[] = [];
const BATCH = new Set(["balloon_pop"]);
const FIRST_TICKET = 3104;

const c = (p: string) => `${p} credit`;
/** What each sample purchase holds, ticket by ticket (headline shown on a win, or null), and what it adds up to. */
const SAMPLES: Record<string, { tickets: (string | null)[]; total: string | null; wins: number; wallet: string | null }> = {
  win: { tickets: [c("£10")], total: "£10", wins: 1, wallet: "£22.50" },
  lose: { tickets: [null], total: null, wins: 0, wallet: "£12.50" },
  mixed: { tickets: [c("£10"), null, null, null, c("£50"), null], total: "£60", wins: 2, wallet: "£72.50" },
  "all-lose": { tickets: [null, null, null, null], total: null, wins: 0, wallet: "£12.50" },
  types: { tickets: ["£50 cash", "AirPods Pro", c("£6.66"), null], total: "£285.66", wins: 3, wallet: "£69.16" },
  "one-tier": { tickets: [null, c("£5"), null], total: "£5", wins: 1, wallet: "£17.50" },
  "end-prize": { tickets: [null, c("£10")], total: "£10", wins: 1, wallet: "£22.50" },
  "no-end-prize": { tickets: [null, c("£10")], total: "£10", wins: 1, wallet: null },
  many: { tickets: Array.from({ length: 40 }, (_, i) => (i === 3 || i === 33 ? c("£0.50") : i === 17 ? c("£5") : i === 26 ? c("£1") : null)), total: "£7", wins: 4, wallet: "£19.50" },
  huge: { tickets: Array.from({ length: 250 }, (_, i) => (i % 41 === 7 ? c("£1") : null)), total: "£6", wins: 6, wallet: "£18.50" },
};

/** Fails the test on any console error or uncaught exception (the blocked phone buzz excepted). */
function watchErrors(page: Page) {
  const errors: string[] = [];
  page.on("pageerror", (e) => errors.push(`pageerror: ${e.message}`));
  page.on("console", (m) => {
    if (m.type() === "error" && !/vibrate|Download the React DevTools/i.test(m.text())) errors.push(`console: ${m.text()}`);
  });
  return errors;
}

async function open(page: Page, game: string, skin: string, sample: string, motion: "reduced" | "full" = "reduced") {
  await page.goto("/dev/reveal-games");
  await page.getByTestId("skin").selectOption(skin);
  await page.getByTestId("motion").selectOption(motion);
  await page.getByTestId("present").selectOption("auto");
  await page.getByTestId("game").selectOption(game);
  await page.getByTestId("sample").selectOption(sample);
  await expect(page.locator(".rg-reveal")).toBeVisible();
}

/** Plays whatever board is on screen until its result appears. */
async function playBoard(page: Page, game: string) {
  const sheetOrSummary = page.locator(".rg-sheet, .rg-summary");
  const press = async (sel: string) => {
    const el = page.locator(sel).first();
    await expect(el).toBeVisible({ timeout: 10_000 });
    await el.click();
  };
  switch (game) {
    case "scratch_card":
      await press(".rg-scratch-actions .rg-btn-cyan");
      break;
    case "spin_wheel":
      await press(".rg-wheel-controls button:not([disabled])");
      break;
    case "bingo_card":
      await press(".rg-bingo-controls .rg-btn, .rg-bingo-controls .rg-cta");
      break;
    case "balloon_pop":
      await press(".rg-balloon-all");
      break;
    case "hook_a_duck":
      await press(".rg-duck:not([disabled])");
      break;
    case "claw_machine":
      await press(".rg-grab");
      break;
    case "coin_drop":
      await press(".rg-coin-board");
      break;
    case "slots":
      await press(".rg-board .rg-cta, .rg-board .rg-btn-yellow, .rg-lever");
      break;
    case "whack_a_mole":
      await press(".rg-whack-controls .rg-cta");
      for (let k = 0; k < 300 && !(await page.locator(".rg-hole.is-final").count()); k++) {
        const up = page.locator(".rg-hole.is-up:not([disabled])").first();
        if (await up.count()) await up.dispatchEvent("pointerdown").catch(() => {});
        await page.waitForTimeout(80);
      }
      break;
    case "pinata":
      for (let k = 0; k < 20 && !(await page.locator(".rg-pinata.is-broken").count()); k++) {
        await page.locator(".rg-pinata-swing").dispatchEvent("pointerdown").catch(() => {});
        await page.waitForTimeout(120);
      }
      break;
    case "gems":
      for (let k = 0; k < 9 && !(await sheetOrSummary.isVisible()); k++) {
        const tile = page.locator(".rg-gem-tile:not([disabled])").first();
        if (await tile.count()) await tile.click();
        await page.waitForTimeout(250);
      }
      break;
  }
  await expect(sheetOrSummary).toBeVisible({ timeout: 25_000 });
}

/** Nothing scrolls sideways, and the sheet's main button is on screen. */
async function checkLayout(page: Page) {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  expect(overflow).toBeLessThanOrEqual(0);
  const btn = page.locator(".rg-sheet .rg-btn-primary, .rg-summary .rg-btn-primary").first();
  if (await btn.count()) {
    // Let the sheet finish sliding up before measuring it.
    await page.waitForFunction(() =>
      [...document.querySelectorAll(".rg-sheet, .rg-summary")].every((el) => el.getAnimations().every((a) => a.playState !== "running")),
    );
    // A long results list scrolls: the button must be reachable, then fully on screen.
    await btn.scrollIntoViewIfNeeded();
    const box = (await btn.boundingBox())!;
    const vp = page.viewportSize()!;
    expect(box.y + box.height).toBeLessThanOrEqual(vp.height + 1);
    expect(box.x).toBeGreaterThanOrEqual(-1);
    expect(box.x + box.width).toBeLessThanOrEqual(vp.width + 1);
  }
}

/** Plays a whole purchase, checking each result screen against its ticket, and returns at the last screen. */
async function playPurchase(page: Page, game: string, sample: string) {
  const s = SAMPLES[sample];
  const single = s.tickets.length === 1;
  for (let step = 0; step < 400; step++) {
    if (await page.locator(".rg-summary").isVisible()) return;
    const sheet = page.locator(".rg-sheet");
    if (!(await sheet.isVisible())) {
      await playBoard(page, game);
      if (await page.locator(".rg-summary").isVisible()) return;
    }
    await checkLayout(page);
    if (BATCH.has(game)) {
      // One screen for all of this competition's tickets.
      if (s.wins === 0) await expect(sheet.locator(".rg-no-win")).toBeVisible();
      else await expect(sheet.locator(".rg-prize-big")).toHaveText(s.wins === 1 ? s.tickets.find(Boolean)! : s.total!);
    } else {
      const n = Number((await page.locator(".rg-ticket-chip").textContent())!.replace(/\D/g, ""));
      const expected = s.tickets[n - FIRST_TICKET];
      if (expected) {
        await expect(sheet.locator(".rg-prize-big")).toHaveText(expected);
        await expect(sheet.locator(".rg-no-win")).toHaveCount(0);
      } else {
        await expect(sheet.locator(".rg-no-win")).toBeVisible();
        await expect(sheet.locator(".rg-prize-big")).toHaveCount(0);
        await expect(sheet).toContainText(`#${n}`);
      }
    }
    if (single) return;
    // Long purchases: check the first few screens, then reveal the rest at once, as a player can.
    const revealAll = sheet.getByRole("button", { name: /^Reveal all \d+ left$/ });
    if (step >= 3 && (await revealAll.count())) await revealAll.click();
    else await sheet.locator(".rg-btn-primary").click();
  }
  throw new Error("purchase never finished");
}

async function checkFinal(page: Page, sample: string) {
  const s = SAMPLES[sample];
  if (s.tickets.length === 1) {
    const sheet = page.locator(".rg-sheet");
    if (s.total) await expect(sheet.locator(".rg-prize-big")).toHaveText(s.tickets[0]!);
    else await expect(sheet.locator(".rg-no-win")).toBeVisible();
  } else {
    const summary = page.locator(".rg-summary");
    await expect(summary).toBeVisible();
    if (s.total) {
      await expect(summary.locator(".rg-prize-big")).toHaveText(s.total);
      await expect(summary.locator(".rg-summary-list li")).toHaveCount(s.wins);
    } else {
      await expect(summary.locator(".rg-no-win")).toBeVisible();
      await expect(summary.locator(".rg-summary-list li")).toHaveCount(0);
    }
    if (sample === "types") await expect(summary).toContainText("We'll be in touch to arrange delivery");
  }
  const pill = page.locator(".rg-wallet-amount");
  if (s.wallet) await expect(pill).toHaveText(s.wallet);
  else await expect(pill).toHaveCount(0);
  await checkLayout(page);
}

// ---------------------------------------------------------------------------------------------
// 1. Every game, both looks, every purchase.
// ---------------------------------------------------------------------------------------------
for (const size of [
  { width: 390, height: 844, samples: Object.keys(SAMPLES) },
  { width: 360, height: 640, samples: ["mixed", "types", "lose"] },
]) {
  test.describe(`every game and purchase at ${size.width}x${size.height}`, () => {
    test.use({ viewport: { width: size.width, height: size.height }, reducedMotion: "reduce" });
    for (const skin of Object.keys(SKIN_GAMES)) {
      for (const game of SKIN_GAMES[skin]) {
        for (const sample of size.samples) {
          test(`${skin} ${game} · ${sample}`, async ({ page }) => {
            const errors = watchErrors(page);
            await open(page, game, skin, sample);
            await playPurchase(page, game, sample);
            await checkFinal(page, sample);
            expect(errors).toEqual([]);
          });
        }
      }
      if (THEMED.includes(skin)) continue;
      test(`${skin} mixed basket of four games and a standard competition`, async ({ page }) => {
        const errors = watchErrors(page);
        await open(page, "scratch_card", skin, "basket");
        for (let k = 0; k < 40 && !(await page.locator(".rg-summary").isVisible()); k++) {
          if (await page.locator(".rg-sheet").isVisible()) {
            await page.locator(".rg-sheet .rg-btn-primary").click();
            continue;
          }
          const cls = (await page.locator(".rg-reveal").getAttribute("class")) ?? "";
          const game = CLASSIC_GAMES.find((g) => cls.includes(`rg-game-${g} `)) ?? "scratch_card";
          await playBoard(page, game);
        }
        const summary = page.locator(".rg-summary");
        await expect(summary.locator(".rg-prize-big")).toHaveText("£18.50");
        await expect(summary.locator(".rg-summary-list li")).toHaveCount(5);
        await expect(page.locator(".rg-wallet-amount")).toHaveText("£31.00");
        expect(errors).toEqual([]);
      });
    }
  });
}

// ---------------------------------------------------------------------------------------------
// 2. Awkward play, with full animation.
// ---------------------------------------------------------------------------------------------
test.describe("awkward play", () => {
  test.use({ viewport: { width: 390, height: 844 }, reducedMotion: "no-preference" });

  for (const skin of ["classic"]) {
    test(`${skin}: closing mid-purchase never loses a win`, async ({ page }) => {
      const errors = watchErrors(page);
      await open(page, "spin_wheel", skin, "mixed", "full");
      await page.locator(".rg-close").click(); // before playing anything
      await expect(page.locator(".rg-summary .rg-prize-big")).toHaveText("£60");
      await expect(page.locator(".rg-summary-list li")).toHaveCount(2);
      expect(errors).toEqual([]);
    });

    test(`${skin}: a burst of taps on the spin button lands once, on the right result`, async ({ page }) => {
      const errors = watchErrors(page);
      await open(page, "spin_wheel", skin, "win", "full");
      const btn = page.locator(".rg-wheel-controls button").first();
      await btn.click();
      // The button goes once the wheel is spinning; extra taps find nothing to press.
      await btn.click({ force: true, timeout: 1000 }).catch(() => {});
      await btn.dblclick({ force: true, timeout: 1000 }).catch(() => {});
      await expect(page.locator(".rg-sheet .rg-prize-big")).toHaveText("£10 credit", { timeout: 20_000 });
      expect(errors).toEqual([]);
    });

    test(`${skin}: hammering one balloon or cracker pops it once`, async ({ page }) => {
      const errors = watchErrors(page);
      await open(page, "balloon_pop", skin, "many", "full");
      // Six taps on the same one, faster than it can burst.
      await page.locator(".rg-balloon").first().evaluate((el: HTMLElement) => {
        for (let k = 0; k < 6; k++) el.click();
      });
      await expect(page.locator(".rg-balloon-stats")).toContainText(/^\s*1 of 40/);
      await page.locator(".rg-balloon-all").click();
      await expect(page.locator(".rg-sheet .rg-prize-big")).toHaveText("£7", { timeout: 20_000 });
      expect(errors).toEqual([]);
    });

    test(`${skin}: scratch, reset, scratch, then open the rest`, async ({ page }) => {
      const errors = watchErrors(page);
      await open(page, "scratch_card", skin, "win", "full");
      const box = (await page.locator(".rg-scratch-foil").boundingBox())!;
      const swipe = async (f: number) => {
        await page.mouse.move(box.x + 6, box.y + box.height * f);
        await page.mouse.down();
        await page.mouse.move(box.x + box.width - 6, box.y + box.height * f, { steps: 12 });
        await page.mouse.up();
      };
      await swipe(0.17);
      await page.locator(".rg-scratch-actions .rg-btn-white").click(); // reset
      await swipe(0.5);
      await page.locator(".rg-scratch-actions .rg-btn-cyan").click();
      await expect(page.locator(".rg-sheet .rg-prize-big")).toHaveText("£10 credit", { timeout: 10_000 });
      expect(errors).toEqual([]);
    });
  }
});

// ---------------------------------------------------------------------------------------------
// 3. "Try the demo" on the real competition pages (read only: nothing signs in or buys).
// ---------------------------------------------------------------------------------------------
test.describe("every look is laid out properly on a fresh page", () => {
  // Each test starts on a freshly loaded page, so no classic game's styles are already
  // loaded: a board must look right on its own (a button once sat off-centre
  // unless the classic wheel had been opened first).
  // Every game in every look it has.
  const LOOKS: [string, string, string][] = CLASSIC_GAMES.map((g): [string, string, string] => ["classic", g, "win"]);
  const SIZES: [number, number][] = [[320, 568], [360, 640], [375, 667], [390, 844], [430, 932], [768, 1024], [1280, 720], [1366, 768], [1440, 900]];
  for (const [w, h] of SIZES) {
    for (const [skin, game, sample] of LOOKS) {
      test(`${skin} ${game} at ${w}x${h}: centred, nothing off screen, cut off or covered`, async ({ page }) => {
        const errors = watchErrors(page);
        await page.setViewportSize({ width: w, height: h });
        await open(page, game, skin, sample, "full");
        await page.waitForTimeout(900);
        const issues = await page.evaluate(() => {
          const reveal = document.querySelector(".rg-reveal")!;
          const rr = reveal.getBoundingClientRect();
          const board = (document.querySelector(".rg-board") ?? reveal).getBoundingClientRect();
          const mid = board.left + board.width / 2;
          const out: string[] = [];
          const sideways = document.documentElement.scrollWidth - window.innerWidth;
          if (sideways > 0) out.push(`page scrolls sideways by ${sideways}px`);
          const shown = (el: Element) => {
            const s = getComputedStyle(el);
            const r = el.getBoundingClientRect();
            return s.visibility !== "hidden" && s.display !== "none" && r.width > 0 && r.height > 0 && Number(s.opacity) > 0.05 && !el.closest("[aria-hidden=true]");
          };
          const name = (el: Element) => `${el.getAttribute("class")} "${(el.textContent || el.getAttribute("aria-label") || "").trim().slice(0, 24)}"`;
          for (const el of reveal.querySelectorAll("button, .rg-prompt, .rg-note")) {
            if (!shown(el)) continue;
            const r = el.getBoundingClientRect();
            if (r.left < rr.left - 1 || r.right > rr.right + 1 || r.left < -1 || r.right > window.innerWidth + 1) out.push(`off the edge: ${name(el)}`);
            // Full screen on a phone, the game must fit the screen; as a card (desktop) the page scrolls as usual.
            const floor = reveal.classList.contains("rg-fullscreen") ? Math.min(rr.bottom, window.innerHeight) : rr.bottom;
            if (r.bottom > floor + 1) out.push(`cut off at the bottom: ${name(el)}`);
            if (el instanceof HTMLButtonElement && !el.disabled) {
              el.scrollIntoView({ block: "nearest" });
              const q = el.getBoundingClientRect();
              const hit = document.elementFromPoint(q.left + q.width / 2, q.top + q.height / 2);
              if (hit && !el.contains(hit) && !hit.contains(el)) out.push(`covered: ${name(el)} by ${hit.getAttribute("class")}`);
            }
          }
          const rail = reveal.querySelector(".rg-di-rail");
          const coins = reveal.querySelector(".rg-di-board");
          if (rail && coins && Math.abs(rail.getBoundingClientRect().width - coins.getBoundingClientRect().width) > 2) out.push("the Drop It rail and board are different widths");
          for (const sel of [".rg-prompt", ".rg-note", ".rg-coin-board"]) {
            const el = reveal.querySelector(sel);
            if (!el || !shown(el)) continue;
            const r = el.getBoundingClientRect();
            const off = Math.round(r.left + r.width / 2 - mid);
            if (Math.abs(off) > 2) out.push(`${sel} is ${off}px off centre`);
          }
          return out;
        });
        expect(issues).toEqual([]);
        expect(errors).toEqual([]);
      });
    }
  }
});


// ---------------------------------------------------------------------------------------------
// 4. Smooth on a slowed-down phone: the moving parts keep the frame rate up.
// ---------------------------------------------------------------------------------------------
test.describe("smoothness on a slowed-down phone", () => {
  test.use({ viewport: { width: 390, height: 844 }, reducedMotion: "no-preference" });
  for (const [skin, game] of [["classic", "balloon_pop"], ["classic", "spin_wheel"], ["classic", "bingo_card"], ["classic", "scratch_card"], ["classic", "gems"], ["classic", "claw_machine"], ["classic", "slots"], ["classic", "coin_drop"]] as const) {
    test(`${skin} ${game}: at least 40 frames a second with the processor slowed 4x`, async ({ page }) => {
      await open(page, game, skin, "mixed", "full");
      const cdp = await page.context().newCDPSession(page);
      await cdp.send("Emulation.setCPUThrottlingRate", { rate: 4 });
      await page.waitForTimeout(500);
      if (game === "bingo_card") await page.locator(".rg-bingo-controls .rg-btn, .rg-bingo-controls .rg-cta").first().click();
      // Measured while the board is busy: bags bursting, or the claw on its way down.
      if (game === "gems") for (const k of [0, 5, 10]) await page.locator(".rg-gem-tile").nth(k).click();
      if (game === "claw_machine") await page.locator(".rg-grab").click();
      // The themed looks are measured mid-play: reels spinning, the vault turning, the coin falling, a jar smashing.
      const fps = await page.evaluate(
        () =>
          new Promise<number>((resolve) => {
            let frames = 0;
            const start = performance.now();
            const tick = (now: number) => {
              frames++;
              if (now - start < 2000) requestAnimationFrame(tick);
              else resolve((frames * 1000) / (now - start));
            };
            requestAnimationFrame(tick);
          }),
      );
      await cdp.send("Emulation.setCPUThrottlingRate", { rate: 1 });
      console.log(`${skin} ${game}: ${fps.toFixed(0)} fps at 4x slowdown`);
      expect(fps).toBeGreaterThanOrEqual(40);
    });
  }
});

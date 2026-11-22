import { expect, test, type Page } from "@playwright/test";

/**
 * Plays every reveal game, classic and in every skin, on the development preview page
 * (/dev/reveal-games: sample tickets only, nothing touches the database), the way a
 * player would: real taps and real scratching with the pointer, at phone sizes.
 *
 * Each game must reach its result sheet, show the result it was given (a win shows
 * the prize, a miss shows no prize), and never get stuck. Scratch cards are
 * scratched by dragging across the card only, never by pressing "Open all": every
 * square must open. 390x667 is a short phone, where the board is drawn smaller.
 */

const SIZES = [
  { name: "390x844", width: 390, height: 844 },
  { name: "390x667 (short phone, board drawn smaller)", width: 390, height: 667 },
];
const GAMES = ["scratch_card", "spin_wheel", "bingo_card", "balloon_pop", "hook_a_duck", "claw_machine", "coin_drop", "slots", "gems", "whack_a_mole", "pinata"] as const;
const SKINS = ["classic"] as const;
/** The games each skin draws its own way (null: all of them). Any other game falls back to the classic look. */
const SKIN_GAMES: Record<(typeof SKINS)[number], readonly string[] | null> = {
  classic: null,
};

async function open(page: Page, game: string, skin: string, sample: "win" | "lose" | "mixed") {
  await page.goto("/dev/reveal-games");
  await page.getByTestId("skin").selectOption(skin);
  await page.getByTestId("present").selectOption("auto");
  await page.getByTestId("game").selectOption(game);
  await page.getByTestId("sample").selectOption(sample);
  await expect(page.locator(".rg-reveal.is-play")).toBeVisible();
}

/** Drags back and forth over the whole scratch area, like a finger, row by row. */
async function scratchAll(page: Page) {
  const canvas = page.locator(".rg-scratch-foil");
  await expect(canvas).toBeVisible();
  // Wait until the card has stopped moving (a new card grows into place): a finger lands where it can see the card.
  let box = (await canvas.boundingBox())!;
  for (let k = 0; k < 40; k++) {
    await page.waitForTimeout(60);
    const next = (await canvas.boundingBox())!;
    if (Math.abs(next.width - box.width) < 0.5 && Math.abs(next.y - box.y) < 0.5) break;
    box = next;
  }
  const rows = Math.ceil(box.height / 26) + 1;
  await page.mouse.move(box.x + 4, box.y + 4);
  await page.mouse.down();
  for (let r = 0; r < rows; r++) {
    const y = Math.min(box.y + box.height - 3, box.y + 4 + r * 26);
    const [from, to] = r % 2 ? [box.x + box.width - 4, box.x + 4] : [box.x + 4, box.x + box.width - 4];
    await page.mouse.move(from, y, { steps: 2 });
    await page.mouse.move(to, y, { steps: 14 });
  }
  await page.mouse.up();
}

/** Presses START, then whacks each mole as it pops up until the result shows. */
async function whackAll(page: Page) {
  await expect(page.locator(".rg-whack")).toBeVisible({ timeout: 10_000 });
  if (await page.locator(".rg-whack.is-ready").count()) await page.locator(".rg-whack-controls .rg-cta").click();
  for (let k = 0; k < 300 && !(await page.locator(".rg-hole.is-final").count()); k++) {
    const up = page.locator(".rg-hole.is-up:not([disabled])").first();
    if (await up.count()) await up.dispatchEvent("pointerdown").catch(() => {});
    await page.waitForTimeout(80);
  }
}

/** Taps the piñata until it breaks. */
async function hitPinata(page: Page) {
  await expect(page.locator(".rg-pinata")).toBeVisible({ timeout: 10_000 });
  for (let k = 0; k < 20 && !(await page.locator(".rg-pinata.is-broken").count()); k++) {
    await page.locator(".rg-pinata-swing").dispatchEvent("pointerdown").catch(() => {});
    await page.waitForTimeout(120);
  }
}

/** Plays the board on screen until the result sheet shows. */
async function play(page: Page, game: string) {
  const click = (sel: string) => page.locator(sel).first().click();
  switch (game) {
    case "scratch_card":
      await scratchAll(page);
      break;
    case "spin_wheel":
      await page.locator(".rg-wheel-controls button").first().click();
      break;
    case "bingo_card":
      await page.locator(".rg-bingo-controls .rg-btn, .rg-bingo-controls .rg-cta").first().click();
      break;
    case "balloon_pop":
      await click(".rg-balloon-all");
      break;
    case "claw_machine":
      await click(".rg-grab");
      break;
    case "coin_drop":
      await click(".rg-coin-board");
      break;
    case "whack_a_mole":
      await whackAll(page);
      break;
    case "pinata":
      await hitPinata(page);
      break;
    default: {
      // The other classic games: press whatever the board offers until it lands.
      for (let k = 0; k < 12 && !(await page.locator(".rg-sheet").isVisible()); k++) {
        const target = page.locator(".rg-board button:not([disabled]), .rg-board [role=button]").first();
        if (await target.count()) await target.click({ force: true }).catch(() => {});
        await page.waitForTimeout(700);
      }
    }
  }
  await expect(page.locator(".rg-sheet")).toBeVisible({ timeout: 20_000 });
}

for (const size of SIZES) {
  test.describe(`reveal games at ${size.name}`, () => {
    test.use({ viewport: { width: size.width, height: size.height }, reducedMotion: "no-preference", hasTouch: false });

    for (const skin of SKINS) {
      for (const game of GAMES) {
        if (SKIN_GAMES[skin] && !SKIN_GAMES[skin]!.includes(game)) continue;
        test(`${skin} ${game}: a win shows the prize, a miss shows none`, async ({ page }) => {
          for (const sample of ["win", "lose"] as const) {
            await open(page, game, skin, sample);
            await play(page, game);
            const sheet = page.locator(".rg-sheet");
            if (sample === "win") {
              await expect(sheet).toContainText("£10");
              await expect(sheet.locator(".rg-no-win")).toHaveCount(0);
            } else {
              await expect(sheet.locator(".rg-no-win")).toBeVisible();
              await expect(sheet).not.toContainText("£10 credit");
            }
          }
        });
      }

      // The scratch card checks are for the looks that draw a scratch card.
      if (SKIN_GAMES[skin] && !SKIN_GAMES[skin]!.includes("scratch_card")) continue;

      test(`${skin} scratch card: one quick swipe through each row opens every square`, async ({ page }) => {
        await open(page, "scratch_card", skin, "lose");
        const box = (await page.locator(".rg-scratch-foil").boundingBox())!;
        // One finger-width line through the middle of each row of three, lifting between rows.
        for (const f of [1 / 6, 3 / 6, 5 / 6]) {
          const y = box.y + box.height * f;
          await page.mouse.move(box.x + 6, y);
          await page.mouse.down();
          await page.mouse.move(box.x + box.width - 6, y, { steps: 16 });
          await page.mouse.up();
        }
        await expect(page.locator(".rg-prompt-title")).toHaveText(/Match 3!|No match this time/, { timeout: 5_000 });
      });

      test(`${skin} scratch card: a scratch stays scratched when the card is redrawn`, async ({ page }) => {
        await open(page, "scratch_card", skin, "lose");
        const canvas = page.locator(".rg-scratch-foil");
        const box = (await canvas.boundingBox())!;
        // A small dab in the first square: not enough to open it.
        const at = { x: box.x + box.width * 0.12, y: box.y + box.height * 0.12 };
        await page.mouse.move(at.x, at.y);
        await page.mouse.down();
        await page.mouse.move(at.x + 4, at.y, { steps: 2 });
        await page.mouse.up();
        const alphaAt = () =>
          canvas.evaluate((c: HTMLCanvasElement, p) => {
            const r = c.getBoundingClientRect();
            const x = Math.round(((p.x - r.left) / r.width) * c.width);
            const y = Math.round(((p.y - r.top) / r.height) * c.height);
            return c.getContext("2d")!.getImageData(x, y, 1, 1).data[3];
          }, at);
        // Cover alpha is 158 (the vault's thin frost) to 255 (foil); a scratched spot is clear.
        expect(await alphaAt()).toBeLessThan(40);
        // Redraw the cover, as a resize or the display font loading does.
        await page.setViewportSize({ width: size.width - 10, height: size.height });
        await page.waitForTimeout(300);
        await page.setViewportSize({ width: size.width, height: size.height });
        await page.waitForTimeout(300);
        const after = (await canvas.boundingBox())!;
        expect(Math.abs(after.x - box.x)).toBeLessThan(1);
        expect(await alphaAt()).toBeLessThan(40);
      });

      test(`${skin} scratch card: card after card in one purchase, each one scratches to the end`, async ({ page }) => {
        await open(page, "scratch_card", skin, "mixed"); // six tickets
        for (let card = 1; card <= 4; card++) {
          await expect(page.locator(".rg-counter")).toContainText(`${card} of 6`);
          await scratchAll(page);
          await expect(page.locator(".rg-prompt-title")).toHaveText(/Match 3!|No match this time/, { timeout: 5_000 });
          await expect(page.locator(".rg-sheet")).toBeVisible({ timeout: 5_000 });
          await page.locator(".rg-sheet .rg-btn-primary").click();
          // Start straight away, while the board is still growing back: that is when it used to stick.
          await page.waitForTimeout(120);
        }
      });

      test(`${skin} scratch card: scratching alone opens every square`, async ({ page }) => {
        await open(page, "scratch_card", skin, "lose");
        await scratchAll(page);
        // The prompt says it is done, and "Open all" was never needed.
        await expect(page.locator(".rg-prompt-title")).toHaveText(/Match 3!|No match this time/, { timeout: 5_000 });
      });
    }
  });
}

test.describe("reduced motion", () => {
  test.use({ viewport: { width: 390, height: 844 }, reducedMotion: "reduce" });
  const STILL: [string, string][] = ["scratch_card", "spin_wheel", "bingo_card", "balloon_pop", "gems", "claw_machine", "slots", "coin_drop"].map((g): [string, string] => ["classic", g]);
  for (const [skin, game] of STILL) {
    test(`${skin} ${game} still reaches its result`, async ({ page }) => {
      await open(page, game, skin, "win");
      await page.getByTestId("motion").selectOption("reduced");
      await page.getByTestId("game").selectOption(game);
      await play(page, game);
      await expect(page.locator(".rg-sheet")).toContainText("£10");
      // Nothing falls: the snow is still (and the vault has none).
      await expect(page.locator(".rg-snow:not(.is-still)")).toHaveCount(0);
    });
  }
});

test.describe("whack-a-mole", () => {
  test.use({ viewport: { width: 390, height: 844 }, reducedMotion: "no-preference" });

  test("the result shows only after the third whack, and earlier moles carry nothing", async ({ page }) => {
    await open(page, "whack_a_mole", "classic", "win");
    await page.locator(".rg-whack-controls .rg-cta").click();
    let whacked = 0;
    for (let k = 0; k < 300 && whacked < 2; k++) {
      const up = page.locator(".rg-hole.is-up:not([disabled])").first();
      if (await up.count()) {
        await expect(page.locator(".rg-mole-sign")).toHaveCount(0);
        await up.dispatchEvent("pointerdown");
        whacked++;
      }
      await page.waitForTimeout(80);
    }
    await expect(page.locator(".rg-whack-count i.is-done")).toHaveCount(2);
    await expect(page.locator(".rg-mole-sign")).toHaveCount(0);
    await expect(page.locator(".rg-sheet")).toHaveCount(0);
    await whackAll(page);
    await expect(page.locator(".rg-mole-sign")).toHaveText("£10");
    await expect(page.locator(".rg-sheet")).toBeVisible({ timeout: 10_000 });
  });

  test("a mole that is not whacked goes back down and another comes up", async ({ page }) => {
    await open(page, "whack_a_mole", "classic", "lose");
    await page.locator(".rg-whack-controls .rg-cta").click();
    const first = page.locator(".rg-hole.is-up");
    await expect(first).toHaveCount(1, { timeout: 3000 });
    const firstIndex = await page.locator(".rg-hole").evaluateAll((els) => els.findIndex((e) => e.classList.contains("is-up")));
    await expect.poll(async () => page.locator(".rg-hole").evaluateAll((els) => els.findIndex((e) => e.classList.contains("is-up"))), { timeout: 4000 }).not.toBe(firstIndex);
    await expect(page.locator(".rg-whack-count i.is-done")).toHaveCount(0);
  });
});

test.describe("piñata", () => {
  test.use({ viewport: { width: 390, height: 844 }, reducedMotion: "no-preference" });

  test("the prize shows only when the last hit breaks it", async ({ page }) => {
    await open(page, "pinata", "classic", "win");
    const swing = page.locator(".rg-pinata-swing");
    for (let i = 1; i < 5; i++) {
      await swing.dispatchEvent("pointerdown");
      await expect(page.locator(".rg-pinata-count i.is-done")).toHaveCount(i);
      await expect(page.locator(".rg-pinata-prize")).toHaveCount(0);
    }
    await swing.dispatchEvent("pointerdown");
    await expect(page.locator(".rg-pinata-prize")).toHaveText("£10");
    await expect(page.locator(".rg-sheet")).toBeVisible({ timeout: 10_000 });
  });

  test("a losing piñata says so plainly", async ({ page }) => {
    await open(page, "pinata", "classic", "lose");
    await hitPinata(page);
    await expect(page.locator(".rg-pinata-prize")).toHaveText("No prize");
    await expect(page.locator(".rg-pinata-prize")).not.toHaveClass(/is-win/);
  });
});

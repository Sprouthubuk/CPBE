// Game skins (src/games/arcade/skins). A skin re-draws a game; it must never change what the
// game shows. Brand Name starts with the classic skin only (the themed skins of the build this
// site started from were removed); these checks keep the skin lists in step and stop any skin
// added later from deciding anything.
//
//   cd frontend && npx vitest run tests/games skins
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";
import fs from "node:fs";
import path from "node:path";
import { GAME_SKIN_OPTIONS, SKINNED_GAMES, normaliseGameSkin, skinnedGameBadge, skinnedGameName } from "../../src/games/skinIds";
import { REVEAL_GAME_OPTIONS } from "../../src/games/gameIds";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../..");
const SKINS = path.join(ROOT, "src/games/arcade/skins");
/** Every source file of every skin. */
const allSkinFiles = () =>
  fs.readdirSync(SKINS, { withFileTypes: true }).flatMap((d) => {
    const dir = path.join(SKINS, d.name);
    return d.isDirectory() ? fs.readdirSync(dir).filter((f) => /\.tsx?$/.test(f)).map((f) => path.join(dir, f)) : /\.tsx?$/.test(d.name) ? [dir] : [];
  });
const read = (f: string) => fs.readFileSync(f, "utf8");
/** Source with comments removed. */
const code = (f: string) => read(f).replace(/\/\*[\s\S]*?\*\//g, "").replace(/(^|[^:])\/\/.*$/gm, "$1");

describe("skin ids", () => {
  it("unknown or missing skins read as classic", () => {
    for (const bad of [null, undefined, "", "Classic", "christmas", "pirates", "easter", 7]) expect(normaliseGameSkin(bad)).toBe("classic");
    expect(normaliseGameSkin("classic")).toBe("classic");
  });

  it("every skin in the picker is in the registry, and the reverse", () => {
    const registry = read(path.join(SKINS, "registry.ts"));
    const listed = [...registry.matchAll(/^\s+([a-z_]+): [A-Z_]+_SKIN,/gm)].map((m) => m[1]).sort();
    expect(listed).toEqual(GAME_SKIN_OPTIONS.map((o) => o.id).sort());
  });

  it("every skinned game is a real game", () => {
    const games = new Set<string>(REVEAL_GAME_OPTIONS.map((o) => o.id));
    for (const list of Object.values(SKINNED_GAMES)) for (const g of list) expect(games.has(g)).toBe(true);
  });

  it("the classic skin keeps every game's own name and badge", () => {
    for (const { id } of REVEAL_GAME_OPTIONS) {
      expect(skinnedGameName(id, "classic")).toBeNull();
      expect(skinnedGameBadge(id, "classic")).toBeNull();
    }
  });

  it("no skin file builds a board of its own: none defines a generate… function", () => {
    for (const f of allSkinFiles()) expect(code(f)).not.toMatch(/function generate[A-Z]/);
  });
});

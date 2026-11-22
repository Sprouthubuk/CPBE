/**
 * Game skin ids and their admin labels. Plain data, no component imports, so the
 * admin form and the competition pages can use it without loading any game.
 *
 * A skin changes how a reveal game looks and reads (pictures, names, animation,
 * wording, sounds), never how a result is decided: every skin plays the same
 * board generators in ./arcade/games/generators.ts. "classic" is the original
 * look and the default for every competition.
 *
 * These are the only values competitions.skin may hold (shared/game-ids.json; a test
 * compares the lists). Vault Nine starts with "classic" only. To add a skin (a darts
 * look, a seasonal one): add it here and to shared/game-ids.json, add a migration calling
 * GameIds::replaceDatabaseChecks(), then its folder under ./arcade/skins/ and one line in
 * ./arcade/skins/registry.ts.
 */
export const GAME_SKIN_OPTIONS = [
  { id: "classic", label: "Classic" },
] as const;

export type GameSkinId = (typeof GAME_SKIN_OPTIONS)[number]["id"];

export const DEFAULT_GAME_SKIN: GameSkinId = "classic";

const IDS: ReadonlySet<string> = new Set(GAME_SKIN_OPTIONS.map((o) => o.id));

export const isGameSkinId = (id: unknown): id is GameSkinId => typeof id === "string" && IDS.has(id);

/** Unknown, missing or misspelt values read as the classic skin, so a bad value never blocks a result. */
export const normaliseGameSkin = (id: unknown): GameSkinId => (isGameSkinId(id) ? id : DEFAULT_GAME_SKIN);

/**
 * The reveal games each skin has its own version of. A competition whose game is not
 * listed plays the classic look whatever skin is chosen. "classic" (the original reveal,
 * which the site calls "Scratch Card") plays the scratch card.
 */
export const SKINNED_GAMES: Readonly<Record<GameSkinId, readonly string[]>> = {
  classic: [],
};

/** Player-facing game names under a skin. Missing ones keep the classic name. */
const SKIN_GAME_NAMES: Readonly<Record<GameSkinId, Readonly<Record<string, string>>>> = {
  classic: {},
};

/** The short word on a competition card's game badge under a skin. Missing ones use the game's name. */
const SKIN_GAME_BADGES: Readonly<Record<GameSkinId, Readonly<Record<string, string>>>> = {
  classic: {},
};

/** The skinned name for a game, or null when the skin has no version of it. */
export const skinnedGameName = (game: string | null | undefined, skin: unknown): string | null =>
  (game && SKIN_GAME_NAMES[normaliseGameSkin(skin)][game.toLowerCase()]) || null;

/** The skinned card badge for a game, or null when the skin has no version of it. */
export const skinnedGameBadge = (game: string | null | undefined, skin: unknown): string | null =>
  (game && SKIN_GAME_BADGES[normaliseGameSkin(skin)][game.toLowerCase()]) || null;

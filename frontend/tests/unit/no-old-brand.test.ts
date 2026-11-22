// Brand Name started as a copy of another competition site's build. Nothing of that site's brand,
// company or data may be left in this repository: its name, domain, company details, order-number
// prefix, cookie names, colours or fonts. This test reads every tracked text file.
//
//   cd frontend && npx vitest run tests/unit/no-old-brand
import { execFileSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../../..");
const SELF = path.relative(ROOT, fileURLToPath(import.meta.url));
const BINARY = /\.(png|jpe?g|webp|gif|ico|woff2?|ttf|otf|mp3|wav|ogg|zip|gz|lockb)$/i;

// Built from parts so this file doesn't match itself.
const j = (...p: string[]) => p.join("");
const OLD: [string, RegExp][] = [
  ["old name", new RegExp(j("gim", "me"), "i")],
  ["old company number", new RegExp(j("1749", "6537"))],
  ["old address", new RegExp(j("Oak", "hanger") + "|" + j("BS11", " 0RJ"), "i")],
  ["old order prefix", new RegExp("\\b(" + j("G", "G") + "|" + j("G", "D") + ")-[A-Z0-9]")],
  ["old cookie or class prefix", new RegExp("\\b" + j("g", "g") + "[-_][a-z]")],
  ["old pink", new RegExp(j("#FF2", "DAA") + "|" + j("255, ?45, ?", "170"), "i")],
  ["old cyan", new RegExp(j("#22E", "6FF") + "|" + j("34, ?230, ?", "255"), "i")],
  ["old yellow", new RegExp(j("#FFF2", "3A") + "|" + j("255, ?242, ?", "58"), "i")],
  ["old slogan", new RegExp(j("ginor", "mous"), "i")],
  ["old fonts", new RegExp(j("Lili", "ta") + "|" + j("Nun", "ito"), "i")],
  // The second site this engine was copied from (CPBE is its own brand).
  ["base site name", new RegExp(j("vault", " ?", "nine"), "i")],
  ["base site domain", new RegExp(j("vaultnine", "competitions"), "i")],
  ["base site prefix", new RegExp("\\b" + j("v", "9") + "[-_]|\\b" + j("V", "9") + "-[A-Z0-9]")],
];

const files = execFileSync("git", ["ls-files"], { cwd: ROOT, encoding: "utf8" })
  .split("\n")
  .filter((f) => f && f !== SELF && !BINARY.test(f) && fs.existsSync(path.join(ROOT, f)));

describe("nothing from the old brand", () => {
  it("reads the repository", () => {
    expect(files.length).toBeGreaterThan(300);
  });

  it("no tracked file names or contents carry the old brand, company, prefixes, colours or fonts", () => {
    const found: string[] = [];
    for (const f of files) {
      for (const [what, re] of OLD) if (re.test(f)) found.push(`${f}: ${what} in the file name`);
      const text = fs.readFileSync(path.join(ROOT, f), "utf8");
      text.split("\n").forEach((line, i) => {
        for (const [what, re] of OLD) if (re.test(line)) found.push(`${f}:${i + 1}: ${what}`);
      });
    }
    expect(found).toEqual([]);
  });
});

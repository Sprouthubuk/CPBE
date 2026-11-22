// Headings carry one green part (the design's "Olly Croft OBE / Limited Edition").
// Split at a dash if there is one, otherwise the last word is the green part.
export function splitTitle(title: string): [string, string] {
  const t = title.trim();
  const dash = t.match(/^(.+?)\s+[—–-]\s+(.+)$/);
  if (dash) return [dash[1], dash[2]];
  const words = t.split(/\s+/);
  if (words.length < 2) return [t, ""];
  return [words.slice(0, -1).join(" "), words[words.length - 1]];
}

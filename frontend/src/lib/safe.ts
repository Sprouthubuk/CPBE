/** Only ever send someone back to a path on this site (never //evil.com or https://…). */
export function safeNext(next: string | null | undefined, fallback = "/account"): string {
  if (!next || typeof next !== "string") return fallback;
  if (!next.startsWith("/") || next.startsWith("//") || next.startsWith("/\\")) return fallback;
  // Browsers drop tabs and newlines from a URL, so "/<tab>/evil.com" would become //evil.com.
  if (/[\x00-\x1f\x7f]/.test(next) || /^\/(api|sanctum|admin)(\/|$)/.test(next)) return fallback;
  return next.slice(0, 500);
}

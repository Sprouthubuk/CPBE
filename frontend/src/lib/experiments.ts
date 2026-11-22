/**
 * A/B tests (SOW 2.18). Elements marked data-exp="{placement}" show the wording of the variant this visitor
 * is in. Only signed-in players and visitors who accepted cookies take part (cp_vid identifies a visitor);
 * everyone else sees the page as it is.
 */
import { readConsent } from "./consent";

function visitorId(): string | null {
  if (readConsent() !== "accepted") return null;
  const found = document.cookie.split("; ").find((x) => x.startsWith("cp_vid="))?.slice(7);
  if (found && /^[a-f0-9]{16,40}$/.test(found)) return found;
  const id = Array.from(crypto.getRandomValues(new Uint8Array(12)), (b) => b.toString(16).padStart(2, "0")).join("");
  const secure = location.protocol === "https:" ? "; Secure" : "";
  document.cookie = `cp_vid=${id}; Max-Age=${60 * 60 * 24 * 90}; Path=/; SameSite=Lax${secure}`;
  return id;
}

export async function runExperiments(signedIn: boolean) {
  const marked = Array.from(document.querySelectorAll<HTMLElement>("[data-exp]"));
  if (!marked.length || (!signedIn && !visitorId())) return;
  try {
    const res = await fetch("/api/experiments", { credentials: "same-origin", headers: { Accept: "application/json" } });
    if (!res.ok) return;
    const { data } = (await res.json()) as { data: Record<string, { variant: string; text: string | null }> };
    const xsrf = decodeURIComponent(document.cookie.split("; ").find((x) => x.startsWith("XSRF-TOKEN="))?.slice(11) ?? "");
    for (const key of new Set(marked.map((m) => m.dataset.exp!))) {
      const v = data[key];
      if (!v) continue;
      if (v.text) marked.filter((m) => m.dataset.exp === key).forEach((m) => { m.textContent = v.text; });
      void fetch(`/api/experiments/${encodeURIComponent(key)}/seen`, { method: "POST", credentials: "same-origin", headers: { Accept: "application/json", "X-XSRF-TOKEN": xsrf } });
    }
  } catch {
    // a test that can't load leaves the page as it is
  }
}

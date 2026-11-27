import { useState } from "react";
import { api } from "../lib/api";

/** Amendment 7: the reference a signed-in player writes on a postal entry, so it is matched to them automatically. */
export default function PostalReference({ competitionId }: { competitionId: string }) {
  const [ref, setRef] = useState<string | null>(null);
  const [err, setErr] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const get = async () => {
    setBusy(true);
    setErr(null);
    try {
      const r = await api<{ reference: string }>("/api/me/postal-references", { json: { competitionId } });
      setRef(r.reference);
    } catch {
      setErr("We couldn't get your reference just now. Please try again.");
    } finally {
      setBusy(false);
    }
  };
  if (ref) {
    return (
      <p className="postal-ref" data-testid="postal-reference">
        Your postal reference for this competition: <b translate="no" data-wg-notranslate="">{ref}</b>. Write it clearly on your entry and we match it to you automatically.
      </p>
    );
  }
  return (
    <p className="postal-ref">
      <button type="button" className="tap link-btn" onClick={get} disabled={busy}>{busy ? "Getting your reference…" : "Get My Postal Reference"}</button>
      {err && <span className="err" role="alert"> {err}</span>}
    </p>
  );
}

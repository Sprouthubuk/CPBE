import { useState } from "react";
import { api, errorText } from "../lib/api";

/**
 * Voluntary Code 1.4 (amendment 11): before a second paid entry the player is offered a monthly spending limit.
 * They can set one (£0 allowed) or carry on without; either way they can change it any time in Safer Play.
 */
export default function LimitsPrompt({ onDone }: { onDone: () => void }) {
  const [pounds, setPounds] = useState("");
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  const save = async (skip: boolean) => {
    setErr(null);
    let pence: number | null = null;
    if (!skip) {
      const n = Number(pounds.replace(/[£,\s]/g, ""));
      if (pounds.trim() === "" || !Number.isFinite(n) || n < 0) { setErr("Enter an amount, e.g. 50 (or 0)."); return; }
      pence = Math.round(n * 100);
      if (pence > 0 && pence < 500) { setErr("Set £0, or £5 or more."); return; }
    }
    setBusy(true);
    try {
      if (skip) await api("/api/me/limits/skip", { json: {} });
      else await api("/api/me/limits", { json: { monthlyDepositPence: pence } });
      onDone();
    } catch (e) {
      setErr(errorText(e));
    } finally {
      setBusy(false);
    }
  };
  return (
    <div className="card card-p stack g12" role="region" aria-labelledby="limits-prompt-title" data-testid="limits-prompt">
      <h2 className="h3" id="limits-prompt-title">Set A Monthly Spending Limit</h2>
      <p className="hint" style={{ margin: 0 }}>Before your next entry, choose the most you want to spend each month. You can set £0, and change it any time in Safer Play.</p>
      <label className="lbl" htmlFor="limit-pounds">Monthly limit (£)</label>
      <input className="inp" id="limit-pounds" inputMode="decimal" placeholder="e.g. 50" value={pounds} onChange={(e) => setPounds(e.target.value)} />
      {err && <div className="err" role="alert">{err}</div>}
      <button type="button" className="btn btn-y btn-block" disabled={busy} onClick={() => save(false)}>Save My Limit</button>
      <button type="button" className="btn btn-o btn-block" disabled={busy} onClick={() => save(true)} data-testid="limits-skip">Carry On Without A Limit</button>
    </div>
  );
}

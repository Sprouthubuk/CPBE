import { useEffect, useRef, useState } from "react";
import { api, errorText } from "../lib/api";
import { addBoxLines, boxIdsIn, readBasket } from "../lib/basket";
import { formatMoney, formatPence } from "../lib/money";
import { trackAddToCart, trackCustom } from "../lib/consent";
import type { VaultCase, VaultCaseMode } from "../lib/types";

const MODES: { id: VaultCaseMode; label: string; hint: string }[] = [
  { id: "surprise", label: "Surprise me", hint: "A bit of everything" },
  { id: "big", label: "Big prizes", hint: "Top-end darts" },
  { id: "most", label: "Most tickets", hint: "Cheapest comps" },
];

const SHAKE_MS = 1400;
const reducedMotion = () => typeof window !== "undefined" && window.matchMedia?.("(prefers-reduced-motion: reduce)").matches;

/*
 * The Vault Case (design/Main.dc.html): a zipped darts case. Closed, it floats; opened, the lid swings up
 * in 3D and one dart slides into the foam for each draw the server packed. Decorative: the same lines are
 * listed below it as text.
 */
const FLIGHTS = ["#2FD36B", "#F4F7F5", "#1B9A47", "#D7322B"];
function CaseArt({ open, lines, label }: { open: boolean; lines: { title: string; tickets: number }[]; label: string }) {
  const darts = lines.length ? lines : [{ title: "", tickets: 0 }, { title: "", tickets: 0 }, { title: "", tickets: 0 }];
  return (
    <div className={`v9-case${open ? " is-open" : ""}`} aria-hidden="true">
      <div className="v9-tray">
        {darts.map((d, i) => (
          <div key={i} className="v9-dart" style={{ transitionDelay: open ? `${0.75 + i * 0.15}s` : "0s" }}>
            <div className="v9-slot">
              <svg viewBox="0 0 220 30" width="196" height="27" style={{ flex: "none", maxWidth: "46%" }}>
                <polygon points="0,15 42,13.2 42,16.8" fill="#D9E0DC" />
                <rect x="42" y="8" width="70" height="14" rx="4" fill="#8C9692" />
                <rect x="42" y="8" width="70" height="5" rx="2" fill="#B5BFBA" />
                <path d="M52 8v14M60 8v14M68 8v14M76 8v14M84 8v14M92 8v14M100 8v14" stroke="#4A5450" strokeWidth="2" />
                <rect x="112" y="12" width="40" height="6" rx="2" fill="#1A211E" />
                <polygon points="146,15 176,1 216,1 202,15 216,29 176,29" fill={FLIGHTS[i % FLIGHTS.length]} />
              </svg>
              {d.tickets > 0 && <div style={{ minWidth: 0 }}><b>×{d.tickets.toLocaleString("en-GB")} tickets</b><small>{d.title}</small></div>}
            </div>
          </div>
        ))}
      </div>
      <div className="v9-lid">
        <div className="v9-zip" />
        <svg viewBox="0 0 120 80" preserveAspectRatio="none" className="v9-lid-stripes"><polygon points="40,80 62,0 74,0 52,80" fill="#1B9A47" opacity=".45" /><polygon points="62,80 84,0 96,0 74,80" fill="#1B9A47" opacity=".75" /><polygon points="84,80 106,0 118,0 96,80" fill="#2FD36B" /></svg>
        <img src="/logo-sm.webp" alt="" className="v9-lid-logo" width={300} height={175} />
        <span className="v9-lid-name">THE VAULT CASE</span>
        <span className="v9-lid-chip">{label}</span>
        <span className="v9-lid-pull" />
      </div>
    </div>
  );
}

export default function VaultCaseBuilder({ chipsPence, minPence, maxPence, bonusPct, available = true }: { chipsPence: number[]; minPence: number; maxPence: number; bonusPct: number; available?: boolean }) {
  const [budget, setBudget] = useState(chipsPence[1] ?? chipsPence[0] ?? minPence);
  const [mode, setMode] = useState<VaultCaseMode>("surprise");
  const [box, setBox] = useState<VaultCase | null>(null);
  const [shaking, setShaking] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const skip = useRef<(() => void) | null>(null);
  const panel = useRef<HTMLDivElement>(null);

  useEffect(() => () => skip.current?.(), []);

  async function shake() {
    setBusy(true); setError(null);
    const replaces = box && !boxIdsIn(readBasket()).includes(box.id) ? box.id : null;
    setShaking(true);
    // The box shakes while the server packs it. Tapping the box skips the wait; reduced motion has none.
    const wait = reducedMotion() ? Promise.resolve() : new Promise<void>((done) => {
      const t = window.setTimeout(done, SHAKE_MS);
      skip.current = () => { window.clearTimeout(t); done(); };
    });
    try {
      const [next] = await Promise.all([api<VaultCase>("/api/vault-case", { json: { budgetPence: budget, mode, replaces } }), wait]);
      setBox(next);
      trackCustom("VaultCaseShake", { mode, budget: budget / 100 });
      // On a phone the box's contents are below the button: bring them into view.
      if (window.matchMedia?.("(max-width: 999px)").matches) {
        window.setTimeout(() => panel.current?.scrollIntoView({ behavior: reducedMotion() ? "auto" : "smooth", block: "start" }), 60);
      }
    } catch (e) {
      setError(errorText(e));
    } finally {
      skip.current = null;
      setShaking(false);
      setBusy(false);
    }
  }

  async function addToBasket() {
    if (!box) return;
    setBusy(true); setError(null);
    try {
      const fresh = await api<VaultCase>(`/api/vault-case/${box.id}/added`, { method: "POST" });
      addBoxLines(fresh.basketLines);
      trackCustom("VaultCaseAdded", { mode: fresh.mode, value: fresh.totalPence / 100 });
      for (const l of fresh.lines) trackAddToCart(l.slug, l.tickets, l.linePence);
      window.location.assign("/basket");
    } catch (e) {
      setError(errorText(e));
      setBox(null);
      setBusy(false);
    }
  }

  const pounds = Math.round(budget / 100);
  const pick = (fn: () => void) => () => { fn(); if (box) setBox(null); };

  const mode1 = MODES.find((m) => m.id === mode)!;
  return (
    <div className="v9-cbox" data-testid="vault-case">
      <div className="stack g20">
        <div className="stack g8">
          <div className="lbl" id="gbx-budget">Your budget</div>
          <div className="r4" role="group" aria-labelledby="gbx-budget">
            {chipsPence.map((p) => <button key={p} type="button" className={`pick v9-pill${budget === p ? " on" : ""}`} aria-pressed={budget === p} onClick={pick(() => setBudget(p))}>{formatPence(p)}</button>)}
          </div>
          <div className="row g12" style={{ alignItems: "center" }}>
            <input type="range" className="gbx-range" min={minPence / 100} max={maxPence / 100} step={1} value={pounds} onChange={(e) => { setBudget(Number(e.target.value) * 100); if (box) setBox(null); }}
              aria-label="Budget in pounds" aria-valuetext={`£${pounds}`} style={{ ["--pct" as string]: `${((pounds - minPence / 100) / Math.max(1, maxPence / 100 - minPence / 100)) * 100}%` }} />
            <span className="d" style={{ fontSize: 24, minWidth: 56, textAlign: "right" }} data-testid="gb-budget">£{pounds}</span>
          </div>
        </div>
        <div className="stack g8">
          <div className="lbl" id="gbx-mode">How should we pack it?</div>
          <div className="r3" role="radiogroup" aria-labelledby="gbx-mode">
            {MODES.map((m) => (
              <button key={m.id} type="button" role="radio" aria-checked={mode === m.id} className={`opt-b v9-pill${mode === m.id ? " on" : ""}`} onClick={pick(() => setMode(m.id))}>
                <b>{m.label}</b><small>{m.hint}</small>
              </button>
            ))}
          </div>
        </div>
        {!box ? (
          <button type="button" className="btn btn-y btn-lg btn-block" onClick={shake} disabled={busy || !available} data-testid="gb-shake">
            {!available ? "Opening soon" : shaking ? "Packing…" : `Unzip my £${pounds} case`}
          </button>
        ) : (
          <div className="row wrapx g12">
            <button type="button" className="btn btn-y btn-lg" style={{ flex: "1 1 240px" }} onClick={addToBasket} disabled={busy} data-testid="gb-add">Add case to basket · {formatMoney(box.totalPence)}</button>
            <button type="button" className="btn btn-o btn-lg" style={{ fontSize: 15 }} onClick={shake} disabled={busy}>Repack</button>
          </div>
        )}
        <p className="hint" style={{ margin: 0 }}>Live draws only · 18+ UK · <a href="/free-entry">Free postal entry</a> for every draw</p>
      </div>

      <div className="v9-cstage" ref={panel}>
        <button type="button" className="v9-case-btn" onClick={() => (shaking ? skip.current?.() : !busy && available && !box && shake())} aria-label={box ? "Your case is open" : shaking ? "Skip" : "Unzip the case"} disabled={!available || !!box || (busy && !shaking)} data-testid={available ? undefined : "gb-unavailable"}>
          <CaseArt open={!!box} lines={box ? box.lines.map((l) => ({ title: l.title, tickets: l.tickets })) : []} label={`£${pounds} · ${mode1.label}`} />
        </button>
        <div aria-live="polite" style={{ textAlign: "center" }}>
          <div className="d" style={{ fontSize: 24 }}>{box ? `${box.tickets.toLocaleString("en-GB")} tickets${box.bonusTickets > 0 ? ` + ${box.bonusTickets} bonus` : ""}` : !available ? "Packing soon" : shaking ? "Unzipping…" : "Your case is packed"}</div>
          <div className="hint" style={{ marginTop: 4 }}>{box ? "One dart per live draw. Add the lot to your basket." : !available ? "The case opens once there are at least two live draws to pack." : "Pick a budget, then unzip to see your darts."}</div>
        </div>
        {box && (
          <div className="stack g8" style={{ width: "100%" }} data-testid="gb-reveal">
            {box.lines.map((l, i) => (
              <div key={l.competitionId} className="gbx-line" style={{ animationDelay: `${i * 80}ms` }} data-testid="gb-line">
                <img src={l.imageSmall} alt="" width={48} height={48} loading="lazy" />
                <div className="gbx-line-txt">
                  <a href={`/competitions/${l.slug}`}>{l.title}</a>
                  <span className="hint">{l.tickets.toLocaleString("en-GB")} tickets{l.bundleLabel ? ` · ${l.bundleLabel}` : ""}{l.bonusQuantity > 0 ? ` · +${l.bonusQuantity} bonus` : ""} · {l.soldPercent}% sold</span>
                  {l.notice && <span className="hint" style={{ color: "var(--green)" }}>{l.notice}</span>}
                </div>
                <b>{formatMoney(l.linePence)}</b>
              </div>
            ))}
            {box.unusedPence > 0 && <p className="hint" style={{ margin: 0 }} data-testid="gb-unused">{formatMoney(box.unusedPence)} of your {formatPence(box.budgetPence)} wasn't enough for another ticket, so you only pay {formatMoney(box.totalPence)}.</p>}
            <span className="hint">Tickets held for a few minutes. Same prices as each competition page.</span>
          </div>
        )}
        {error && <div className="err" role="alert">{error}</div>}
      </div>
    </div>
  );
}

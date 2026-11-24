import { useState } from "react";
import { api, errorText } from "../lib/api";
import { formatMoney } from "../lib/money";
import { ukDate } from "../lib/time";
import type { LoyaltyProgrammeView, MyLoyalty } from "../lib/types";
import { VoucherCard } from "./ReferPanel";
import { Svg } from "./Svg";

const KIND: Record<LoyaltyProgrammeView["history"][number]["kind"], string> = {
  earn: "Earned", bonus: "Bonus", redeem: "Spent", expire: "Expired", reverse: "Reversed", adjust: "Adjusted", refund: "Returned",
};

const n = (x: number) => x.toLocaleString("en-GB");

function rewardWhat(r: LoyaltyProgrammeView["rewards"][number]): string {
  if (r.type === "site_credit" && r.creditPence) return `${formatMoney(r.creditPence)} site credit`;
  if (r.type === "free_entries" && r.tickets) return `${r.tickets} free entries into a live draw you choose`;
  if (r.type === "bonus_entries" && r.tickets) return `${r.tickets} bonus entries`;
  return r.description ?? "We’ll be in touch to arrange it";
}

function Programme({ p, onChange }: { p: LoyaltyProgrammeView; onChange: () => void }) {
  const [busy, setBusy] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState<string | null>(null);
  const top = p.tiers.length ? p.tiers[p.tiers.length - 1].thresholdPoints : 0;
  const progress = p.nextTier ? Math.min(100, Math.floor(((p.lifetime) / (p.lifetime + p.nextTier.pointsToGo)) * 100)) : 100;

  async function redeem(id: number, name: string) {
    setBusy(id); setError(null); setDone(null);
    try {
      const r = await api<{ redemption: { status: string } }>("/api/me/loyalty/redeem", { json: { rewardId: id } });
      setDone(r.redemption.status === "pending" ? `${name}: we’ve got your request and will be in touch.` : `${name} is yours.`);
      onChange();
    } catch (e) {
      setError(errorText(e));
    } finally {
      setBusy(null);
    }
  }

  return (
    <div className="stack g16" data-testid="loyalty-programme">
      <div className="card card-hot card-p stack g12">
        <div className="row between wrapx g8">
          <h2 className="h3" style={{ margin: 0 }}>{p.name}</h2>
          {p.tier && <span className="tag tag-y">{p.tier.name}</span>}
        </div>
        {p.description && <p className="p" style={{ margin: 0 }}>{p.description}</p>}
        <div className="kpi">
          <div className="card card-p"><div className="eyebrow">Points</div><div className="d np" style={{ fontSize: 30 }} data-testid="points-balance">{n(p.balance)}</div></div>
          <div className="card card-p"><div className="eyebrow">Earn Rate</div><div className="d nc" style={{ fontSize: 30 }}>{n(Math.floor((p.pointsPerPound * (p.tier?.multiplierPct ?? 100)) / 100))}</div><div className="hint">points per £1</div></div>
        </div>
        {p.nextTier ? (
          <div className="stack g8">
            <div className="hint">{n(p.nextTier.pointsToGo)} more points to reach <b>{p.nextTier.name}</b></div>
            <div className="bar" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={progress} aria-label={`Progress to ${p.nextTier.name}`}><div style={{ width: `${progress}%` }} /></div>
          </div>
        ) : top > 0 ? <div className="hint">You’ve reached the top tier.</div> : null}
        {p.tier?.benefits && <div className="hint">Your {p.tier.name} benefits: {p.tier.benefits}</div>}
        {p.expiringSoon > 0 && <div className="notice notice-y"><Svg name="clock" size={18} /><span>{n(p.expiringSoon)} points expire in the next 30 days.</span></div>}
      </div>

      {p.rewards.length > 0 && (
        <div className="stack g12">
          <h3 className="h3">Rewards</h3>
          {done && <div className="notice notice-c" role="status"><Svg name="check" size={18} /><span>{done}</span></div>}
          {error && <div className="err" role="alert">{error}</div>}
          <div className="card" style={{ overflow: "hidden" }}>
            {p.rewards.map((r) => (
              <div key={r.id} className="li" style={{ cursor: "default", alignItems: "center" }}>
                <span className="ico"><Svg name="gift" size={18} /></span>
                <span style={{ flex: 1, minWidth: 0 }}>
                  <b>{r.name}</b>
                  <span className="hint" style={{ display: "block" }}>{n(r.pointsCost)} points · {rewardWhat(r)}{r.minTier ? ` · ${r.minTier} and above` : ""}</span>
                </span>
                <button type="button" className="btn btn-o btn-sm" disabled={!r.available || !r.affordable || busy !== null} onClick={() => redeem(r.id, r.name)} aria-label={`${busy === r.id ? "Getting" : !r.available ? "Unavailable" : r.affordable ? "Get It" : "Need More"}: ${r.name}`}>
                  {busy === r.id ? "…" : !r.available ? "Unavailable" : r.affordable ? "Get It" : "Need More"}
                </button>
              </div>
            ))}
          </div>
        </div>
      )}

      {p.tiers.length > 1 && (
        <div className="stack g8">
          <h3 className="h3">Tiers</h3>
          <div className="card" style={{ overflow: "hidden" }}>
            {p.tiers.map((t) => (
              <div key={t.name} className="li" style={{ cursor: "default" }}>
                <span style={{ flex: 1 }}><b>{t.name}</b><span className="hint" style={{ display: "block" }}>From {n(t.thresholdPoints)} points · {t.multiplierPct / 100}x points{t.benefits ? ` · ${t.benefits}` : ""}</span></span>
                {p.tier?.name === t.name && <span className="tag tag-c">You</span>}
              </div>
            ))}
          </div>
        </div>
      )}

      <div className="stack g8">
        <h3 className="h3">History</h3>
        {p.history.length ? (
          <div className="card" style={{ overflow: "hidden" }}>
            {p.history.map((h, i) => (
              <div key={i} className="li" style={{ cursor: "default" }}>
                <span style={{ flex: 1, minWidth: 0 }}>{h.reason}<span className="hint" style={{ display: "block" }}>{KIND[h.kind]} · {ukDate(h.at)}{h.expiresAt ? ` · expires ${ukDate(h.expiresAt)}` : ""}</span></span>
                <b className={h.points > 0 ? "nc" : ""}>{h.points > 0 ? "+" : ""}{n(h.points)}</b>
              </div>
            ))}
          </div>
        ) : <div className="card empty">Your points will show here after your first order.</div>}
      </div>
      <p className="hint" style={{ margin: 0 }}>Points are earned on what you pay by card or from your wallet’s cash balance, not bonus credit.{p.expiryDays ? ` Points expire ${p.expiryDays} days after you earn them.` : ""} Refunded orders lose their points.</p>
    </div>
  );
}

export default function LoyaltyPanel({ initial }: { initial: MyLoyalty }) {
  const [data, setData] = useState(initial);
  const refresh = () => api<MyLoyalty>("/api/me/loyalty").then(setData).catch(() => undefined);
  const available = data.vouchers.filter((v) => v.status === "available");

  return (
    <div className="stack g24">
      {available.length > 0 && (
        <div className="stack g12">
          <h2 className="h3">Your Free Entries</h2>
          {available.map((v) => <VoucherCard key={v.id} v={v} onUsed={refresh} />)}
        </div>
      )}
      {data.programmes.length ? data.programmes.map((p) => <Programme key={p.id} p={p} onChange={refresh} />)
        : <div className="card empty">There are no rewards programmes running right now.</div>}
      {data.redemptions.length > 0 && (
        <div className="stack g8">
          <h2 className="h3">Rewards You’ve Claimed</h2>
          <div className="card" style={{ overflow: "hidden" }}>
            {data.redemptions.map((r) => (
              <div key={r.id} className="li" style={{ cursor: "default" }}>
                <span style={{ flex: 1 }}>{r.reward}<span className="hint" style={{ display: "block" }}>{ukDate(r.at)} · {n(r.points)} points</span></span>
                <span className={`tag ${r.status === "pending" ? "tag-y" : "tag-c"}`}>{r.status === "pending" ? "On Its Way" : r.status === "cancelled" ? "Cancelled" : "Done"}</span>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}

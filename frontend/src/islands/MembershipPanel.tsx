import { useState } from "react";
import { api, errorText } from "../lib/api";
import { formatMoney } from "../lib/money";
import { ukDate } from "../lib/time";
import type { MembershipTierView, MyMembership } from "../lib/types";
import { Svg } from "./Svg";

function Perks({ t }: { t: MembershipTierView }) {
  return (
    <ul className="stack g8" style={{ margin: 0, paddingLeft: 0, listStyle: "none" }}>
      {t.perks.map((p) => <li key={p} className="row g8" style={{ alignItems: "flex-start" }}><Svg name="check" size={16} /><span>{p}</span></li>)}
    </ul>
  );
}

export default function MembershipPanel({ initial, notice }: { initial: MyMembership; notice: "paid" | "pending" | "failed" | null }) {
  const [data, setData] = useState(initial);
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState<string | null>(notice === "paid" ? "Payment received — your membership is active." : null);
  const m = data.membership;

  async function act(key: string, path: string, json: Record<string, unknown> = {}, success?: string) {
    setBusy(key); setError(null); setDone(null);
    try {
      const r = await api<MyMembership>(path, { json });
      if (r.redirectUrl) { window.location.href = r.redirectUrl; return; }
      setData(r);
      if (success) setDone(success);
    } catch (e) {
      setError(errorText(e));
    } finally {
      setBusy(null);
    }
  }

  return (
    <div className="stack g16">
      {done && <div className="notice notice-c" role="status"><Svg name="check" size={18} /><span>{done}</span></div>}
      {notice === "pending" && <div className="notice notice-y" role="status"><Svg name="clock" size={18} /><span>We’re waiting to hear from the payment provider. This page will show your membership once it’s confirmed.</span></div>}
      {notice === "failed" && <div className="notice notice-p" role="alert"><Svg name="info" size={18} /><span>That payment didn’t go through, so nothing has been taken.</span></div>}
      {error && <div className="err" role="alert">{error}</div>}

      {m && (
        <div className="card card-hot card-p stack g12" data-testid="my-membership">
          <div className="row between wrapx g8">
            <h2 className="h3" style={{ margin: 0 }}>{m.tier.name}</h2>
            <span className={`tag ${m.status === "active" ? "tag-c" : "tag-y"}`}>{m.status === "active" ? (m.cancelAtPeriodEnd ? "Ending" : "Active") : "Payment Failed"}</span>
          </div>
          <div className="d ny" style={{ fontSize: 28 }}>{formatMoney(m.tier.pricePence)}<span className="hint" style={{ fontSize: 14 }}> a month</span></div>
          <Perks t={m.tier} />
          {m.status === "active" && m.periodEnd && (
            <p className="hint" style={{ margin: 0 }}>
              {m.cancelAtPeriodEnd ? `Ends on ${ukDate(m.periodEnd)}. Your perks carry on until then.` : `Renews on ${ukDate(m.periodEnd)} ${m.payWith === "wallet" ? "from your wallet (or your card if the wallet is short)" : "on your card"}.`}
              {m.nextTier ? ` Moving to ${m.nextTier} from then.` : ""}
            </p>
          )}
          {m.status === "past_due" && (
            <>
              <p className="p" style={{ margin: 0 }}>Your last renewal didn’t go through, so your perks are paused.{m.nextAttemptAt ? ` We’ll try again on ${ukDate(m.nextAttemptAt)}.` : ""}</p>
              <button type="button" className="btn btn-y cp-cta" disabled={busy !== null} onClick={() => act("pay", "/api/me/membership/pay")}>{busy === "pay" ? "Opening…" : "Pay Now By Card"}</button>
            </>
          )}
          {m.status === "active" && (m.cancelAtPeriodEnd
            ? <button type="button" className="btn btn-o" disabled={busy !== null} onClick={() => act("keep", "/api/me/membership/keep", {}, "Your membership will carry on.")}>Keep My Membership</button>
            : <button type="button" className="btn btn-o" disabled={busy !== null} onClick={() => act("cancel", "/api/me/membership/cancel", {}, "Cancelled. Your perks carry on to the end of the month.")}>Cancel Membership</button>)}
        </div>
      )}

      <div className="stack g12">
        <h2 className="h3">{m ? "Other Tiers" : "Choose A Membership"}</h2>
        {data.tiers.filter((t) => t.id !== m?.tier.id).map((t) => {
          const up = m ? t.pricePence > m.tier.pricePence : false;
          return (
            <div key={t.id} className="card card-p stack g12" data-testid="membership-tier">
              <div className="row between wrapx g8"><h3 className="h3" style={{ margin: 0 }}>{t.name}</h3><span className="d ny" style={{ fontSize: 24 }}>{formatMoney(t.pricePence)}<span className="hint" style={{ fontSize: 13 }}> a month</span></span></div>
              {t.description && <p className="p" style={{ margin: 0 }}>{t.description}</p>}
              <Perks t={t} />
              {t.ineligible ? <p className="hint" style={{ margin: 0 }}>{t.ineligible}</p> : m ? (
                m.status === "active" && <button type="button" className="btn btn-o" disabled={busy !== null} onClick={() => act(`c${t.id}`, "/api/me/membership/change", { tierId: t.id }, up ? `You’re now on ${t.name}.` : `You’ll move to ${t.name} when your membership renews.`)}>
                  {busy === `c${t.id}` ? "…" : up ? `Upgrade To ${t.name}` : `Move To ${t.name} Next Month`}
                </button>
              ) : (
                <div className="r2">
                  <button type="button" className="btn btn-y cp-cta" disabled={busy !== null} onClick={() => act(`j${t.id}`, "/api/me/membership", { tierId: t.id, payWith: "card" }, `Welcome to ${t.name}!`)} data-testid="join-card">{busy === `j${t.id}` ? "…" : "Join By Card"}</button>
                  <button type="button" className="btn btn-o" disabled={busy !== null} onClick={() => act(`w${t.id}`, "/api/me/membership", { tierId: t.id, payWith: "wallet" }, `Welcome to ${t.name}!`)}>Pay From Wallet</button>
                </div>
              )}
            </div>
          );
        })}
        {m && data.tiers.length <= 1 && <div className="card empty">There are no other tiers right now.</div>}
        {!m && data.tiers.length === 0 && <div className="card empty">Memberships aren’t open to join right now.</div>}
      </div>
      <p className="hint" style={{ margin: 0 }}>Memberships renew every month until you cancel, which you can do here at any time. Upgrades start straight away and cost the difference for the rest of the month; moving to a cheaper tier happens when you renew. Bonus credit can’t pay for a membership. Self-exclusion or taking a break ends your membership straight away. 18+ UK only.</p>
    </div>
  );
}

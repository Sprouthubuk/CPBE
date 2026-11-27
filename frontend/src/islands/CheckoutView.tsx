import { useEffect, useMemo, useState } from "react";
import LimitsPrompt from "./LimitsPrompt";
import { ApiFailure, api, errorText } from "../lib/api";
import { clearBasket, readBasket, setAside } from "../lib/basket";
import { trackInitiateCheckout } from "../lib/consent";
import { lineMakeup, topUpFor } from "../lib/bundles";
import { formatMoney } from "../lib/money";
import type { BasketLine, CheckoutResult, PricedBasket } from "../lib/types";
import { Svg } from "./Svg";

function parseBuy(buy: string | null): BasketLine[] | null {
  if (!buy) return null;
  // "id:10" is ten single tickets; "id:b3" is bundle 3. The server prices both.
  const m = buy.match(/^([A-Za-z0-9-]{1,64}):(b?)(\d{1,6})$/);
  if (!m) return null;
  return m[2] ? [{ competitionId: m[1], quantity: 0, bundleId: Number(m[3]) }] : [{ competitionId: m[1], quantity: Number(m[3]) }];
}

function requestId(): string {
  return typeof crypto !== "undefined" && "randomUUID" in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

export default function CheckoutView({ buy, walletPence, minCardPence, minDepositPence = 500 }: { buy: string | null; walletPence: number; minCardPence: number; minDepositPence?: number }) {
  const direct = useMemo(() => parseBuy(buy), [buy]);
  const [lines, setLines] = useState<BasketLine[] | null>(null);
  const [priced, setPriced] = useState<PricedBasket | null>(null);
  const [answers, setAnswers] = useState<Record<string, number>>({});
  const [pay, setPay] = useState<"wallet" | "card">("wallet");
  const [useWallet, setUseWallet] = useState(true);
  const [promo, setPromo] = useState("");
  const [promoApplied, setPromoApplied] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [rid] = useState(requestId);
  const [needLimits, setNeedLimits] = useState(false);

  useEffect(() => { setLines(direct ?? readBasket()); }, [direct]);
  useEffect(() => {
    if (!lines?.length) return;
    api<PricedBasket>("/api/basket/price", { json: { lines, promoCode: promoApplied } })
      .then((p) => {
        setPriced(p); setPay(p.walletCovers ? "wallet" : "card"); setError(null);
        trackInitiateCheckout(p.totalPence, p.lines.map((l) => ({ slug: l.slug, quantity: l.quantity })));
      })
      .catch((e) => setError(errorText(e)));
  }, [lines, promoApplied]);

  if (lines && !lines.length) return <div data-empty-checkout className="card card-p stack g12" style={{ alignItems: "center", textAlign: "center" }}><div className="h3">Nothing To Pay For</div><p className="p">Your basket is empty.</p><a className="btn btn-y" href="/competitions">Browse Competitions</a></div>;
  if (!priced) return <div className="card empty">{error ?? <><span className="spin" /> Getting your order ready…</>}</div>;

  const total = priced.totalPence;
  // "Use my wallet first" only splits a payment the wallet can't cover alone. Choosing the card when the
  // wallet could pay it all means paying by card (never a "Pay £0.00 By Card" button).
  const splitWallet = pay === "card" && useWallet && !priced.walletCovers && walletPence > 0;
  const walletPart = pay === "wallet" ? total : splitWallet ? Math.min(walletPence, total) : 0;
  const cardPart = total - walletPart;
  const cardTooSmall = pay === "card" && cardPart > 0 && cardPart < minCardPence;
  // SOW 2.2: one answer per competition with a skill question, chosen before paying.
  const questions = priced.lines.filter((l, i, all) => l.skillQuestion && all.findIndex((x) => x.competitionId === l.competitionId) === i);
  const unanswered = questions.some((l) => answers[l.competitionId] === undefined);
  const blocked = priced.problems.length > 0 || priced.lines.some((l) => l.problem) || unanswered;

  async function submit() {
    if (!lines) return;
    setBusy(true);
    setError(null);
    try {
      const withAnswers = lines.map((l) => (answers[l.competitionId] !== undefined ? { ...l, skillAnswer: answers[l.competitionId] } : l));
      const res = await api<CheckoutResult>("/api/checkout", { json: { lines: withAnswers, payWith: pay, useWallet: pay === "wallet" || splitWallet, promoCode: promoApplied, requestId: rid } });
      if (res.next === "redirect" && res.redirectUrl) {
        setAside(res.order.number, direct ?? undefined);
        window.location.assign(res.redirectUrl);
        return;
      }
      if (!direct) clearBasket();
      window.location.assign(`/order/${encodeURIComponent(res.order.number)}`);
    } catch (e) {
      if (e instanceof ApiFailure && e.body?.code === "limits_prompt") setNeedLimits(true);
      else setError(errorText(e));
      setBusy(false);
    }
  }

  return (
    <div className="stack g16">
      {needLimits && <LimitsPrompt onDone={() => { setNeedLimits(false); void submit(); }} />}
      <div className="card cp-rise cp-d2" style={{ overflow: "hidden" }}>
        {priced.lines.map((l, i) => (
          <div key={`${l.competitionId}:${l.bundleId ?? "s"}`} className="row g12" style={{ padding: "14px 16px", borderTop: i ? "1px solid rgba(255,255,255,.06)" : 0 }}>
            <img src={l.imageSmall} alt="" style={{ width: 48, height: 48, borderRadius: 12, objectFit: "cover" }} />
            <div style={{ flex: 1, minWidth: 0 }}><div style={{ fontWeight: 900 }}>{l.title}</div>{l.bundleLabel && <div className="tag tag-y" style={{ marginTop: 4 }}>{l.bundleLabel}</div>}{l.offerLabel && <div className="tag tag-y" style={{ marginTop: 4 }}>{l.offerLabel}</div>}<div className="hint">{lineMakeup(l)} · {l.type === "draw" ? "live draw" : "instant win"}</div>{l.problem && <div className="err">{l.problem}</div>}</div>
            <b>{formatMoney(l.linePence)}</b>
          </div>
        ))}
        <div className="row between" style={{ padding: "12px 16px", borderTop: "1px solid rgba(255,255,255,.06)" }}>{direct ? <span /> : <a href="/basket" style={{ fontWeight: 800, fontSize: 14 }}>Edit basket</a>}<span className="d ny" style={{ fontSize: 26 }}>{formatMoney(total)}</span></div>
      </div>

      {questions.map((l) => (
        <fieldset key={`q-${l.competitionId}`} className="card card-p stack g8" style={{ margin: 0 }} data-testid="skill-question">
          <legend className="eyebrow" style={{ padding: 0 }}>Question · {l.title}</legend>
          <div style={{ fontWeight: 900 }}>{l.skillQuestion!.question}</div>
          {l.skillQuestion!.options.map((o, k) => (
            <label key={k} className="opt">
              <input type="radio" name={`skill-${l.competitionId}`} checked={answers[l.competitionId] === k} onChange={() => setAnswers((a) => ({ ...a, [l.competitionId]: k }))} />
              <span>{o}</span>
            </label>
          ))}
        </fieldset>
      ))}
      {unanswered && <div className="hint">Answer the {questions.length === 1 ? "question" : "questions"} above to enter.</div>}

      <fieldset className="stack g8 cp-rise cp-d3" style={{ border: 0, margin: 0, padding: 0 }}>
        <legend className="eyebrow" style={{ marginBottom: 8 }}>How would you like to pay?</legend>
        <label className="opt" style={!priced.walletCovers ? { opacity: 0.55 } : undefined}>
          <input type="radio" name="pay" checked={pay === "wallet"} disabled={!priced.walletCovers} onChange={() => setPay("wallet")} />
          <span style={{ flex: 1 }}><b className="d" style={{ fontSize: 18 }}>Wallet</b><span className="hint" style={{ display: "block" }}>{formatMoney(walletPence)} available · {priced.walletCovers ? "instant, no card needed" : "not enough for this order"}</span></span>
          {priced.walletCovers && <span className="tag tag-y">Fastest</span>}
        </label>
        {!priced.walletCovers && total > 0 && (() => {
          const topUp = topUpFor(total - priced.walletCanPayPence, minDepositPence);
          return <a className="btn btn-o btn-block" href={`/account/wallet?topup=${topUp}`} data-testid="top-up-and-pay">Top Up {formatMoney(topUp)} And Pay From Your Wallet</a>;
        })()}
        <label className="opt">
          <input type="radio" name="pay" checked={pay === "card"} onChange={() => setPay("card")} />
          <span style={{ flex: 1 }}><b className="d" style={{ fontSize: 18 }}>{priced.cardNote ? "Debit Card" : "Debit Or Credit Card"}</b><span className="hint" style={{ display: "block" }}>On Cashflows’ secure payment page · from {formatMoney(minCardPence)}</span></span>
          <Svg name="card" />
        </label>
        {pay === "card" && priced.cardNote && <div className="hint" role="note" data-testid="card-note">{priced.cardNote}</div>}
        {pay === "card" && walletPence > 0 && !priced.walletCovers && (
          <label className="row g12" style={{ padding: "6px 2px", cursor: "pointer" }}>
            <input type="checkbox" className="sw" checked={useWallet} onChange={(e) => setUseWallet(e.target.checked)} />
            <span style={{ fontWeight: 700, fontSize: 14 }}>Use my {formatMoney(Math.min(walletPence, total))} wallet balance first</span>
          </label>
        )}
      </fieldset>

      <details className="acd cp-rise cp-d4">
        <summary>Promo Code <Svg name="down" /></summary>
        <form className="ab row g8" onSubmit={(e) => { e.preventDefault(); setPromoApplied(promo.trim() || null); }}>
          <input className="inp" placeholder="Enter code" aria-label="Promo code" value={promo} onChange={(e) => setPromo(e.target.value)} />
          <button type="submit" className="btn btn-o">Apply</button>
        </form>
      </details>

      <div className="card card-hot card-p stack g12 cp-rise cp-d5">
        <div className="row between"><span className="d" style={{ fontSize: 22 }}>To Pay</span><span className="d ny" style={{ fontSize: 38 }} data-testid="checkout-total">{formatMoney(total)}</span></div>
        {pay === "card" && walletPart > 0 && <div className="hint">{formatMoney(walletPart)} from your wallet · {formatMoney(cardPart)} by card</div>}
        {cardTooSmall && <div className="notice notice-y" role="alert"><Svg name="info" size={18} /><span>Card payments start at {formatMoney(minCardPence)}. Add a few more tickets, or <a href="/account/wallet" style={{ color: "#2FD36B" }}>top up your wallet</a> and pay from there.</span></div>}
        {error && <div className="notice notice-p" role="alert"><Svg name="info" size={18} /><span>{error}</span></div>}
        <button type="button" className="btn btn-y btn-lg btn-block cp-cta" onClick={submit} disabled={busy || blocked || cardTooSmall} data-testid="pay">
          {busy ? <span className="spin" /> : pay === "wallet" ? `Pay ${formatMoney(total)} From Wallet` : `Pay ${formatMoney(cardPart)} By Card`}
        </button>
        {pay === "card" && <div className="notice notice-c"><Svg name="lock" size={18} /><span>You’ll go to Cashflows’ secure payment page, then come straight back here to play.</span></div>}
        <p className="hint" style={{ margin: 0, lineHeight: 1.5 }}>By paying you confirm you’re 18+ and in the UK, accept the <a href="/legal/terms">T&amp;Cs</a>, and want your tickets now — so the 14-day cancellation right ends once they’re allocated.</p>
      </div>
    </div>
  );
}

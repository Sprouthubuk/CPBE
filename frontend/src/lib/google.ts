/**
 * Google tags (SOW 3.3): Google Tag Manager, Google Analytics 4 and Google Ads conversion tracking. Like the
 * Meta Pixel, nothing loads until the visitor accepts cookies (consent.ts), and only for IDs set in the admin
 * (Settings → Google). Search Console needs no script: its verification tag comes from the build environment.
 */
import { readConsent } from "./consent";

export interface GoogleConfig { gtmId: string | null; ga4Id: string | null; adsId: string | null; adsPurchaseLabel: string | null }

declare global {
  interface Window {
    dataLayer?: unknown[];
    gtag?: (...args: unknown[]) => void;
  }
}

let started: GoogleConfig | null = null;

function config(): GoogleConfig | null {
  if (started) return started;
  const raw = document.getElementById("cookie-banner")?.dataset.google;
  if (!raw) return null;
  try {
    return JSON.parse(raw) as GoogleConfig;
  } catch {
    return null;
  }
}

function addScript(src: string) {
  const s = document.createElement("script");
  s.async = true;
  s.src = src;
  document.head.appendChild(s);
}

/** Starts the configured Google tags once the visitor has accepted. Safe to call more than once. */
export function startGoogle(cfg?: GoogleConfig | null) {
  const c = cfg ?? config();
  if (!c || started || readConsent() !== "accepted") return;
  if (!c.gtmId && !c.ga4Id && !c.adsId) return;
  started = c;
  window.dataLayer = window.dataLayer || [];
  if (c.gtmId && /^GTM-[A-Z0-9]{4,12}$/.test(c.gtmId)) {
    window.dataLayer.push({ "gtm.start": Date.now(), event: "gtm.js" });
    addScript(`https://www.googletagmanager.com/gtm.js?id=${encodeURIComponent(c.gtmId)}`);
  }
  const tagIds = [c.ga4Id, c.adsId].filter((x): x is string => !!x && /^(G|AW)-[A-Z0-9-]{4,20}$/.test(x));
  if (tagIds.length) {
    window.gtag = function gtag() {
      // gtag needs the arguments object itself on the data layer.
      // eslint-disable-next-line prefer-rest-params
      window.dataLayer!.push(arguments);
    };
    window.gtag("js", new Date());
    for (const id of tagIds) window.gtag("config", id);
    addScript(`https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(tagIds[0])}`);
  }
}

/** A purchase, once per order: GA4 "purchase", the Ads conversion, and a "purchase" event for Tag Manager. Real money only. */
export function googlePurchaseOnce(orderNumber: string, valuePence: number) {
  startGoogle();
  if (!started || valuePence <= 0) return;
  try {
    const key = `cp-g-purchase:${orderNumber}`;
    if (localStorage.getItem(key)) return;
    localStorage.setItem(key, "1");
  } catch {
    // ignore
  }
  const value = Math.round(valuePence) / 100;
  window.dataLayer?.push({ event: "purchase", ecommerce: { transaction_id: orderNumber, value, currency: "GBP" } });
  if (window.gtag) {
    if (started.ga4Id) window.gtag("event", "purchase", { transaction_id: orderNumber, value, currency: "GBP" });
    if (started.adsId && started.adsPurchaseLabel) window.gtag("event", "conversion", { send_to: `${started.adsId}/${started.adsPurchaseLabel}`, value, currency: "GBP", transaction_id: orderNumber });
  }
}

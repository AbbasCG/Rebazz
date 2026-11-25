import { load, save } from './storage';

/* Cookie-toestemming. Trackers (analytics, advertentiepixels) mogen ALLEEN via loadWithConsent worden geladen:
   zonder opgeslagen toestemming voor die categorie wordt er niets geladen. Standaard is alles geweigerd. */
const KEY = 'rebazz.cookie.v1';
const EVENT = 'rebazz:consent';
const REOPEN = 'rebazz:cookie-settings';

export type Category = 'analytics' | 'marketing';
export interface Consent { analytics: boolean; marketing: boolean; date: string }

export function getConsent(): Consent | null {
  return load<Consent | null>(KEY, null);
}

export function hasConsent(category: Category): boolean {
  return getConsent()?.[category] === true;
}

export function setConsent(analytics: boolean, marketing: boolean): void {
  save(KEY, { analytics, marketing, date: new Date().toISOString() });
  window.dispatchEvent(new Event(EVENT));
}

export function onConsentChange(fn: () => void): () => void {
  window.addEventListener(EVENT, fn);
  return () => window.removeEventListener(EVENT, fn);
}

/** Opent de cookie-instellingen opnieuw (toestemming intrekken moet even makkelijk zijn als geven). */
export function openCookieSettings(): void {
  window.dispatchEvent(new Event(REOPEN));
}
export function onOpenCookieSettings(fn: () => void): () => void {
  window.addEventListener(REOPEN, fn);
  return () => window.removeEventListener(REOPEN, fn);
}

/** Laadt een extern script pas nadat de bezoeker toestemming gaf voor de categorie. Vereist ook een CSP-uitzondering voor de bron. */
export function loadWithConsent(category: Category, src: string): void {
  const run = () => {
    if (!hasConsent(category) || document.querySelector(`script[data-consent-src="${src}"]`)) return;
    const s = document.createElement('script');
    s.async = true;
    s.src = src;
    s.dataset.consentSrc = src;
    document.head.appendChild(s);
  };
  run();
  onConsentChange(run);
}

/* Eigen statistieken zonder derden. Er gebeurt NIETS zonder toestemming voor "analytisch" (cookiebanner) en ook niet als de browser
   "Do Not Track" of Global Privacy Control aanzet. De bezoeker-id is een willekeurig nummer in localStorage; trekt de bezoeker de
   toestemming in, dan wissen we het nummer én vragen we de server alles bij dat nummer te wissen. Geen cookies, geen IP-adres, geen vingerafdruk. */
import { SHARE } from './env';
import { hasConsent, onConsentChange } from './consent';

const BASE = (import.meta.env.VITE_API_URL as string | undefined)?.replace(/\/$/, '') ?? '';
const VID = 'rebazz.vid';
const SID = 'rebazz.sid';
const ATTR = 'rebazz.attr';

type EventType = 'page' | 'product' | 'cart' | 'checkout' | 'purchase';
interface Attr { src?: string; med?: string; camp?: string; ref?: string }

const get = (s: Storage, k: string): string | null => { try { return s.getItem(k); } catch { return null; } };
const set = (s: Storage, k: string, v: string) => { try { s.setItem(k, v); } catch { /* geen opslag */ } };
const del = (s: Storage, k: string) => { try { s.removeItem(k); } catch { /* */ } };
const rnd = (): string => (crypto.randomUUID ? crypto.randomUUID() : Array.from(crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2, '0')).join(''));

/** Mag er gemeten worden? Toestemming, geen DNT/GPC en geen deelversie. */
export function analyticsEnabled(): boolean {
  if (SHARE || typeof window === 'undefined') return false;
  const nav = navigator as Navigator & { globalPrivacyControl?: boolean };
  return hasConsent('analytics') && nav.doNotTrack !== '1' && nav.globalPrivacyControl !== true;
}

function attribution(): Attr {
  const saved = get(sessionStorage, ATTR);
  if (saved) { try { return JSON.parse(saved) as Attr; } catch { /* nieuw bepalen */ } }
  const q = new URLSearchParams(window.location.search);
  const a: Attr = {};
  const take = (k: 'src' | 'med' | 'camp', p: string) => { const v = q.get(p); if (v) a[k] = v.slice(0, 80); };
  take('src', 'utm_source'); take('med', 'utm_medium'); take('camp', 'utm_campaign');
  try { const r = document.referrer && new URL(document.referrer); if (r && r.host !== window.location.host) a.ref = r.host; } catch { /* ongeldige verwijzer */ }
  set(sessionStorage, ATTR, JSON.stringify(a));
  return a;
}

const device = (): 'mobile' | 'tablet' | 'desktop' => (window.innerWidth < 768 ? 'mobile' : window.innerWidth < 1100 ? 'tablet' : 'desktop');

function send(type: EventType, extra: { path?: string; product_id?: string; value?: number } = {}): void {
  if (!analyticsEnabled()) return;
  let vid = get(localStorage, VID);
  if (!vid) { vid = rnd(); set(localStorage, VID, vid); }
  let sid = get(sessionStorage, SID);
  if (!sid) { sid = rnd(); set(sessionStorage, SID, sid); }
  const body = { vid, sid, type, path: extra.path ?? window.location.pathname, device: device(), ...attribution(), ...(extra.product_id ? { product_id: extra.product_id } : {}), ...(extra.value != null ? { value: extra.value } : {}) };
  fetch(BASE + '/api/analytics', { method: 'POST', keepalive: true, headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(body) }).catch(() => { /* meten mag nooit de winkel storen */ });
}

let lastPage = '';
export function trackPage(path: string): void {
  if (path.startsWith('/beheer') || path === lastPage) return; // beheer niet meten; dubbele melding van dezelfde pagina voorkomen
  if (!analyticsEnabled()) return;
  lastPage = path;
  send('page', { path });
}
export const trackProduct = (id: string): void => send('product', { product_id: id });
export const trackCart = (id: string): void => send('cart', { product_id: id });
export const trackCheckout = (): void => send('checkout', { path: '/checkout' });
/** Eén keer per bestelling (ook na het herladen van de bedankpagina). */
export function trackPurchase(orderNo: string, value: number): void {
  const key = 'rebazz.purchase.' + orderNo;
  if (!analyticsEnabled() || get(localStorage, key)) return;
  set(localStorage, key, '1');
  send('purchase', { path: '/bedankt', value });
}

/** Toestemming gewijzigd: ingetrokken = nummer en sessiegegevens wissen en de server vragen alles te vergeten; net gegeven = huidige pagina meten. */
function onChange(): void {
  if (analyticsEnabled()) { lastPage = ''; trackPage(window.location.pathname); return; }
  const vid = get(localStorage, VID);
  del(localStorage, VID); del(sessionStorage, SID); del(sessionStorage, ATTR);
  lastPage = '';
  if (vid) fetch(BASE + '/api/analytics/forget', { method: 'POST', keepalive: true, headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ vid }) }).catch(() => { /* */ });
}
if (typeof window !== 'undefined') onConsentChange(onChange);

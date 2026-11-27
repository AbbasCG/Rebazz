import { useEffect } from 'react';

const site = (): string => window.location.origin; // zelfde domein als de server in de canonical zet (FRONTEND_URL)

function setMeta(selector: string, create: () => HTMLElement, attr: string, value: string) {
  let el = document.head.querySelector<HTMLElement>(selector);
  if (!el) { el = create(); document.head.appendChild(el); }
  el.setAttribute(attr, value);
}
const og = (prop: string) => () => { const m = document.createElement('meta'); m.setAttribute('property', prop); return m; };

export interface PageMetaExtra { description?: string; image?: string; jsonLd?: object }

/** Titel, canonical en indexeerbaarheid per pagina (de winkel is een SPA; de server levert dezelfde waarden al in de eerste HTML). */
export function usePageMeta(path: string, title: string, noindex = false, extra: PageMetaExtra = {}) {
  const ld = extra.jsonLd ? JSON.stringify(extra.jsonLd) : '';
  useEffect(() => {
    if (title) {
      document.title = title;
      setMeta('meta[property="og:title"]', og('og:title'), 'content', title);
    }
    if (path) { // lege path = deze pagina regelt canonical/robots zelf (productpagina)
      setMeta('link[rel="canonical"]', () => Object.assign(document.createElement('link'), { rel: 'canonical' }), 'href', site() + (path === '/' ? '/' : path));
      setMeta('meta[property="og:url"]', og('og:url'), 'content', site() + path);
      setMeta('meta[name="robots"]', () => Object.assign(document.createElement('meta'), { name: 'robots' }), 'content', noindex ? 'noindex, nofollow' : 'index, follow');
    }
    if (extra.description) {
      setMeta('meta[name="description"]', () => Object.assign(document.createElement('meta'), { name: 'description' }), 'content', extra.description);
      setMeta('meta[property="og:description"]', og('og:description'), 'content', extra.description);
    }
    if (extra.image) setMeta('meta[property="og:image"]', og('og:image'), 'content', extra.image);
  }, [path, title, noindex, extra.description, extra.image]);

  // Productgegevens (JSON-LD) voor zoekmachines; wordt weggehaald zodra de pagina verlaat.
  useEffect(() => {
    if (!ld) return;
    // De server zet dezelfde productgegevens al in de eerste HTML: die vervangen, zodat er niet twee Product-blokken staan.
    document.head.querySelectorAll('script[type="application/ld+json"]:not([data-page])').forEach(old => {
      try { if (JSON.parse(old.textContent ?? '')['@type'] === 'Product') old.remove(); } catch { /* geen geldige JSON: laten staan */ }
    });
    const s = document.createElement('script');
    s.type = 'application/ld+json';
    s.dataset.page = '1';
    s.textContent = ld;
    document.head.appendChild(s);
    return () => { s.remove(); };
  }, [ld]);
}

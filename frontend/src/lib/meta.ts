import { useEffect } from 'react';

const SITE = 'https://rebazz.nl';

function setMeta(selector: string, create: () => HTMLElement, attr: string, value: string) {
  let el = document.head.querySelector<HTMLElement>(selector);
  if (!el) { el = create(); document.head.appendChild(el); }
  el.setAttribute(attr, value);
}

/** Titel, canonical en indexeerbaarheid per pagina (de winkel is een SPA; zoekmachines lezen deze waarden na het renderen). */
export function usePageMeta(path: string, title: string, noindex = false) {
  useEffect(() => {
    if (title) document.title = title;
    setMeta('link[rel="canonical"]', () => Object.assign(document.createElement('link'), { rel: 'canonical' }), 'href', SITE + (path === '/' ? '/' : path));
    setMeta('meta[property="og:title"]', () => { const m = document.createElement('meta'); m.setAttribute('property', 'og:title'); return m; }, 'content', title);
    setMeta('meta[property="og:url"]', () => { const m = document.createElement('meta'); m.setAttribute('property', 'og:url'); return m; }, 'content', SITE + path);
    setMeta('meta[name="robots"]', () => Object.assign(document.createElement('meta'), { name: 'robots' }), 'content', noindex ? 'noindex, nofollow' : 'index, follow');
  }, [path, title, noindex]);
}

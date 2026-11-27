/* Winkelstatus: catalogus (uit de API), winkelwagen, valuta en bezorgland. */
import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import fallbackCatalog from '../data/catalog.json';
import { api } from './api';
import { CURRENCIES } from './i18n';
import { load, loadText, save, saveText } from './storage';
import type { CartLine, Catalog } from './types';

interface Shop {
  catalog: Catalog;
  online: boolean;            // true = gegevens komen uit de API/database
  cart: CartLine[];
  count: number;
  currency: string;
  country: string;
  addToCart: (id: string, qty?: number) => void;
  setQty: (id: string, qty: number) => void;
  clearCart: () => void;
  setCurrency: (c: string) => void;
  setCountry: (c: string) => void;
  money: (eur: number, currency?: string, locale?: string) => string;
}

const Ctx = createContext<Shop | null>(null);
const FALLBACK = { ...(fallbackCatalog as unknown as Catalog), products: (fallbackCatalog as unknown as Catalog).products.filter(p => p.status === 'active') };

export function ShopProvider({ children, locale }: { children: ReactNode; locale: string }) {
  const [catalog, setCatalog] = useState<Catalog>(FALLBACK);
  const [online, setOnline] = useState(false);
  const [cart, setCart] = useState<CartLine[]>(() => load('rebazz.cart.v1', []));
  const [currency, setCurrencyState] = useState(() => load('rebazz.currency', 'EUR'));
  const [country, setCountryState] = useState(() => loadText('rebazz.country') || 'NL');

  useEffect(() => {
    let alive = true;
    api.catalog().then(c => { if (alive) { setCatalog(c); setOnline(true); } }).catch(() => { /* blijft bij ingebouwde catalogus */ });
    return () => { alive = false; };
  }, []);

  useEffect(() => { save('rebazz.cart.v1', cart); }, [cart]);

  /* Maximaal aantal per product: 20, en niet meer dan er op voorraad is (als het product voorraad bijhoudt). */
  const maxFor = useCallback((id: string) => {
    const p = catalog.products.find(x => x.id === id);
    if (p && p.inStock === false) return 0;
    return Math.min(20, p?.stockLeft ?? 20);
  }, [catalog]);
  const addToCart = useCallback((id: string, qty = 1) => {
    const max = maxFor(id);
    if (max < 1) return;
    setCart(c => {
      const line = c.find(l => l.id === id);
      return line ? c.map(l => (l.id === id ? { ...l, qty: Math.min(max, l.qty + qty) } : l)) : [...c, { id, qty: Math.min(max, qty) }];
    });
  }, [maxFor]);
  const setQty = useCallback((id: string, qty: number) => {
    setCart(c => (qty <= 0 ? c.filter(l => l.id !== id) : c.map(l => (l.id === id ? { ...l, qty: Math.min(maxFor(id) || l.qty, qty) } : l))));
  }, [maxFor]);

  const value = useMemo<Shop>(() => ({
    catalog,
    online,
    cart,
    count: cart.reduce((s, l) => s + l.qty, 0),
    currency,
    country,
    addToCart,
    setQty,
    clearCart: () => setCart([]),
    setCurrency: c => { setCurrencyState(c); save('rebazz.currency', c); },
    setCountry: c => { setCountryState(c); saveText('rebazz.country', c); },
    money: (eur, cur, loc) => {
      const code = cur ?? currency;
      const rate = CURRENCIES[code] ?? 1;
      try { return new Intl.NumberFormat(loc ?? locale, { style: 'currency', currency: code }).format(eur * rate); }
      catch { return code + ' ' + (eur * rate).toFixed(2); }
    }
  }), [catalog, online, cart, currency, country, addToCart, setQty, locale]);

  return <Ctx.Provider value={value}>{children}</Ctx.Provider>;
}

export function useShop(): Shop {
  const v = useContext(Ctx);
  if (!v) throw new Error('useShop buiten ShopProvider');
  return v;
}

export function deliveryWindow(days: [number, number], locale: string): string {
  const fmt = (d: Date) => d.toLocaleDateString(locale, { weekday: 'short', day: 'numeric', month: 'short' });
  const a = new Date(); a.setDate(a.getDate() + days[0]);
  const b = new Date(); b.setDate(b.getDate() + days[1]);
  return fmt(a) + ' – ' + fmt(b);
}

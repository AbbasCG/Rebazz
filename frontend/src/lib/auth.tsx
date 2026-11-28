/* Klantaccount: ingelogde klant + verlanglijst. Zonder account blijft de verlanglijst in localStorage (gast) en wordt na het inloggen samengevoegd. */
import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { api, customerToken } from './api';
import { load, save } from './storage';

interface Customer { email: string; name: string; newsletter: boolean }
interface Auth {
  customer: Customer | null;
  ready: boolean;
  wish: string[];
  toggleWish: (id: string) => void;
  requestCode: (email: string, lang: string) => Promise<void>;
  verify: (email: string, code: string) => Promise<void>;
  logout: () => Promise<void>;
  setCustomer: (c: Customer | null) => void;
}

const Ctx = createContext<Auth | null>(null);
const WISH_KEY = 'rebazz.wish.v1';

export function AuthProvider({ children }: { children: ReactNode }) {
  const [customer, setCustomer] = useState<Customer | null>(null);
  const [ready, setReady] = useState(!customerToken.get());
  const [wish, setWish] = useState<string[]>(() => load<string[]>(WISH_KEY, []));

  const drop = useCallback(() => { customerToken.clear(); setCustomer(null); setWish(load<string[]>(WISH_KEY, [])); }, []);

  /* Bij het laden: is er een bewaard token, dan het profiel en de verlanglijst ophalen. Een verlopen token wordt stil verwijderd. */
  useEffect(() => {
    if (!customerToken.get()) return;
    let alive = true;
    Promise.all([api.account.me(), api.account.wishlist()])
      .then(([me, w]) => { if (alive) { setCustomer(me); setWish(w.ids); } })
      .catch(() => { if (alive) drop(); })
      .finally(() => { if (alive) setReady(true); });
    return () => { alive = false; };
  }, [drop]);

  const toggleWish = useCallback((id: string) => {
    const has = wish.includes(id);
    const next = has ? wish.filter(x => x !== id) : [id, ...wish];
    setWish(next); // meteen zichtbaar; bij een fout herstellen we de oude lijst
    if (customerToken.get()) {
      (has ? api.account.wishRemove(id) : api.account.wishAdd(id)).then(r => setWish(r.ids)).catch(() => setWish(wish));
    } else save(WISH_KEY, next);
  }, [wish]);

  const requestCode = useCallback(async (email: string, lang: string) => { await api.account.requestCode(email, lang); }, []);

  const verify = useCallback(async (email: string, code: string) => {
    const res = await api.account.verify(email, code);
    customerToken.set(res.token);
    const guest = load<string[]>(WISH_KEY, []);
    const [me, w] = await Promise.all([api.account.me(), guest.length ? api.account.wishMerge(guest) : api.account.wishlist()]);
    save(WISH_KEY, []); // gastlijst is nu onderdeel van het account
    setCustomer(me); setWish(w.ids); setReady(true);
  }, []);

  const logout = useCallback(async () => { try { await api.account.logout(); } catch { /* token al ongeldig */ } drop(); }, [drop]);

  const value = useMemo<Auth>(() => ({ customer, ready, wish, toggleWish, requestCode, verify, logout, setCustomer }), [customer, ready, wish, toggleWish, requestCode, verify, logout]);
  return <Ctx.Provider value={value}>{children}</Ctx.Provider>;
}

export function useAuth(): Auth {
  const c = useContext(Ctx);
  if (!c) throw new Error('AuthProvider ontbreekt');
  return c;
}

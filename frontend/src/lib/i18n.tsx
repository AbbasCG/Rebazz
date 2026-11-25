/* Meertaligheid: 8 volledig vertaalde talen (incl. Arabisch, rechts-naar-links).
   Nederlands en Engels zitten in de app; andere talen worden pas geladen als de bezoeker ze kiest. */
import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import meta from '../data/i18n-meta.json';
import en from '../data/lang/en.json';
import nl from '../data/lang/nl.json';
import { loadText, saveText } from './storage';
import type { Product } from './types';

interface Language { code: string; label: string; native: string; locale: string; full?: boolean; rtl?: boolean }
interface LangPack { texts: Record<string, unknown>; productNames: Record<string, string>; productEn: Record<string, [string, string, string[]]> }

export const LANGUAGES = meta.languages as Language[];
export const COUNTRIES = meta.countries as Record<string, string>;
export const CURRENCIES = meta.currencies as Record<string, number>;
export const PAYMENT_METHODS = meta.paymentMethods as { id: string; name: string; detail: string; region: string }[];
export const CHECKLIST = meta.checklist as { group: string; items: string[] }[];
export const SEED_CAMPAIGNS = meta.campaigns as unknown[];

const PACKS: Record<string, LangPack> = { nl: nl as unknown as LangPack, en: en as unknown as LangPack };
const LOADERS = import.meta.glob<LangPack>('../data/lang/*.json', { import: 'default' });
const FULL = LANGUAGES.filter(l => l.full).map(l => l.code);
const KEY = 'rebazz.lang';

async function loadPack(code: string): Promise<LangPack> {
  if (PACKS[code]) return PACKS[code];
  const loader = LOADERS['../data/lang/' + code + '.json'];
  if (!loader) return PACKS.en;
  PACKS[code] = await loader();
  return PACKS[code];
}

function initialLang(): string {
  const saved = loadText(KEY);
  if (saved && FULL.includes(saved)) return saved;
  const browser = (navigator.language || 'nl').slice(0, 2);
  return FULL.includes(browser) ? browser : 'nl';
}

export interface I18n {
  lang: string;
  locale: string;
  rtl: boolean;
  languages: Language[];
  setLang: (code: string) => void;
  /** Met `html = true` worden de variabelen ge-escaped: gebruik dat altijd als het resultaat in dangerouslySetInnerHTML belandt. */
  t: (key: string, vars?: Record<string, string | number>, html?: boolean) => string;
  list: <T = unknown>(key: string) => T;
  country: (code: string) => string;
  category: (c: string) => string;
  pName: (p: Product) => string;
  pDesc: (p: Product) => string;
  pFeatures: (p: Product) => string[];
}

export function escapeHtml(s: string): string {
  return s.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c] as string);
}

const Ctx = createContext<I18n | null>(null);

export function I18nProvider({ children }: { children: ReactNode }) {
  const [lang, setLangState] = useState(initialLang);
  const [pack, setPack] = useState<LangPack>(() => PACKS[lang] ?? PACKS.en);
  const meta2 = LANGUAGES.find(l => l.code === lang) ?? LANGUAGES[0];

  useEffect(() => {
    let alive = true;
    loadPack(lang).then(p => { if (alive) setPack(p); });
    return () => { alive = false; };
  }, [lang]);

  useEffect(() => {
    document.documentElement.lang = lang;
    document.documentElement.dir = meta2.rtl ? 'rtl' : 'ltr';
  }, [lang, meta2.rtl]);

  const raw = useCallback((key: string): unknown => {
    const v = pack.texts[key];
    if (v !== undefined) return v;
    if (PACKS.en.texts[key] !== undefined) return PACKS.en.texts[key];
    return PACKS.nl.texts[key] ?? key;
  }, [pack]);

  const value = useMemo<I18n>(() => {
    const regionNames = (() => { try { return new Intl.DisplayNames([meta2.locale], { type: 'region' }); } catch { return null; } })();
    const productEn = PACKS.en.productEn;
    return {
      lang,
      locale: meta2.locale,
      rtl: !!meta2.rtl,
      languages: LANGUAGES.filter(l => l.full),
      setLang: (code: string) => { setLangState(code); saveText(KEY, code); },
      t: (key, vars, html) => {
        let s = String(raw(key));
        if (vars) for (const k of Object.keys(vars)) s = s.split('{' + k + '}').join(html ? escapeHtml(String(vars[k])) : String(vars[k]));
        return s;
      },
      list: <T,>(key: string) => raw(key) as T,
      country: code => (lang === 'nl' ? COUNTRIES[code] : regionNames?.of(code)) ?? COUNTRIES[code] ?? code,
      category: c => ((pack.texts.cats ?? PACKS.nl.texts.cats) as Record<string, string>)[c] ?? c,
      pName: p => (lang === 'nl' ? p.name : pack.productNames[p.id] ?? productEn[p.id]?.[0] ?? p.name),
      pDesc: p => (lang === 'nl' || !productEn[p.id] ? p.description : productEn[p.id][1]),
      pFeatures: p => (lang === 'nl' || !productEn[p.id] ? p.features : productEn[p.id][2])
    };
  }, [lang, meta2, raw, pack]);

  return <Ctx.Provider value={value}>{children}</Ctx.Provider>;
}

export function useI18n(): I18n {
  const v = useContext(Ctx);
  if (!v) throw new Error('useI18n buiten I18nProvider');
  return v;
}

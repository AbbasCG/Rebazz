/* Kop, voet, winkelwagen, productvenster, bestelling volgen en cookiebanner. */
import { AnimatePresence, motion } from 'motion/react';
import { createContext, useContext, useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { api } from '../lib/api';
import { trackProduct } from '../lib/analytics';
import { SHARE } from '../lib/env';
import { getConsent, onOpenCookieSettings, openCookieSettings, setConsent } from '../lib/consent';
import { CURRENCIES, useI18n } from '../lib/i18n';
import { quote, zoneFor } from '../lib/quote';
import { productPath } from '../lib/slug';
import { deliveryWindow, useShop } from '../lib/shop';
import type { Product, TrackedOrder } from '../lib/types';
import { useAuth } from '../lib/auth';
import { ReturnPanel } from './ReturnPanel';
import WishButton from './WishButton';
import { Icon, MButton, Overlay, ProductVisual, Stars, ThemeToggle, useToast } from './ui';

interface ShopUI { openProduct: (p: Product) => void; openCart: () => void; openTrack: () => void; query: string; setQuery: (q: string) => void }
const UICtx = createContext<ShopUI>({ openProduct: () => {}, openCart: () => {}, openTrack: () => {}, query: '', setQuery: () => {} });
export const useShopUI = () => useContext(UICtx);

const spring = { type: 'spring' as const, stiffness: 380, damping: 36 };

export default function ShopChrome({ children }: { children: ReactNode }) {
  const navigate = useNavigate();
  const [cartOpen, setCartOpen] = useState(false);
  const [trackOpen, setTrackOpen] = useState(false);
  const [query, setQuery] = useState('');
  const location = useLocation();

  useEffect(() => { if (location.hash === '#volgen') setTrackOpen(true); }, [location.hash]);
  useEffect(() => {
    const anyOpen = cartOpen || trackOpen;
    document.body.style.overflow = anyOpen ? 'hidden' : '';
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') { setCartOpen(false); setTrackOpen(false); } };
    window.addEventListener('keydown', onKey);
    return () => { window.removeEventListener('keydown', onKey); document.body.style.overflow = ''; };
  }, [cartOpen, trackOpen]);

  const closeAll = () => { setCartOpen(false); setTrackOpen(false); };

  return (
    <UICtx.Provider value={{ openProduct: (p: Product) => navigate(productPath(p)), openCart: () => setCartOpen(true), openTrack: () => setTrackOpen(true), query, setQuery }}>
      <Header />
      <main>{children}</main>
      <Footer />
      <Overlay open={cartOpen || trackOpen} onClose={closeAll} />
      <AnimatePresence>{cartOpen && <CartDrawer key="cart" onClose={() => setCartOpen(false)} />}</AnimatePresence>
      <AnimatePresence>{trackOpen && <TrackModal key="track" onClose={() => setTrackOpen(false)} />}</AnimatePresence>
      <CookieBanner />
    </UICtx.Provider>
  );
}

/* ---------- Kop ---------- */
function Header() {
  const { t, lang, setLang, languages } = useI18n();
  const { customer } = useAuth();
  const { currency, setCurrency, count, money } = useShop();
  const { openCart, openTrack, query, setQuery } = useShopUI();
  const [menu, setMenu] = useState(false);
  const navigate = useNavigate();
  const go = (hash: string) => { setMenu(false); navigate('/'); setTimeout(() => document.getElementById(hash.slice(1))?.scrollIntoView({ behavior: 'smooth' }), 60); };

  return (
    <>
      <div className="announce">
        <div className="container">
          <span><Icon name="truck" />{t('ann_free', { amount: money(49) })}</span>
          <span><Icon name="refresh" />{t('ann_days')}</span>
          <span><Icon name="shield" />{t('ann_secure')}</span>
        </div>
      </div>
      <header className="site-header">
        <div className="container">
          <button className="icon-btn menu-toggle" aria-label={t('menu')} aria-expanded={menu} onClick={() => setMenu(m => !m)}><Icon name="menu" size={20} /></button>
          <Link to="/" className="logo"><span className="logo-mark">R</span>Rebazz</Link>
          <nav className={'main-nav' + (menu ? ' open' : '')}>
            <a href="/#collectie" onClick={e => { e.preventDefault(); go('#collectie'); }}>{t('nav_collection')}</a>
            <a href="/#werkwijze" onClick={e => { e.preventDefault(); go('#werkwijze'); }}>{t('nav_how')}</a>
            <a href="/#faq" onClick={e => { e.preventDefault(); go('#faq'); }}>{t('nav_service')}</a>
            <a href="#" onClick={e => { e.preventDefault(); setMenu(false); openTrack(); }}>{t('nav_track')}</a>
          </nav>
          <div className="header-tools">
            <div className="search-box">
              <Icon name="search" size={16} />
              <input className="input" id="search" type="search" placeholder={t('search_ph')} aria-label={t('search_ph')} value={query}
                onChange={e => setQuery(e.target.value)} onKeyDown={e => { if (e.key === 'Enter') go('#collectie'); }} />
            </div>
            <select className="select lang-select" id="language" aria-label={t('lang_label')} value={lang} onChange={e => setLang(e.target.value)}>
              {languages.map(l => <option key={l.code} value={l.code}>{l.native}</option>)}
            </select>
            <select className="select currency-select" id="currency" aria-label={t('currency_label')} value={currency} onChange={e => setCurrency(e.target.value)}>
              {Object.keys(CURRENCIES).map(c => <option key={c}>{c}</option>)}
            </select>
            <ThemeToggle label={t('theme')} />
            {!SHARE && <Link className="icon-btn" to="/account" aria-label={t('acc_title')} title={customer ? customer.email : t('acc_title')}><Icon name="users" size={20} /></Link>}
            {!SHARE && <Link className="icon-btn" to="/beheer" id="adminLink" aria-label={t('admin')}><Icon name="dashboard" size={20} /></Link>}
            <MButton className="icon-btn" onClick={openCart} aria-label={t('cart_open')}>
              <Icon name="cart" size={20} />
              <AnimatePresence>
                {count > 0 && (
                  <motion.span key={count} className="cart-count" initial={{ scale: 0.4, opacity: 0 }} animate={{ scale: 1, opacity: 1 }} exit={{ scale: 0.4, opacity: 0 }} transition={spring}>{count}</motion.span>
                )}
              </AnimatePresence>
            </MButton>
          </div>
        </div>
      </header>
    </>
  );
}

/* ---------- Voet ---------- */
function Footer() {
  const { t, category } = useI18n();
  const { catalog } = useShop();
  const { openTrack } = useShopUI();
  const navigate = useNavigate();
  const goHome = (id: string) => { navigate('/'); setTimeout(() => document.getElementById(id)?.scrollIntoView({ behavior: 'smooth' }), 60); };
  const cats = [...new Set(catalog.products.map(p => p.category))].slice(0, 4);
  const b = catalog.brand;
  return (
    <footer className="site-footer">
      <div className="container">
        <div className="footer-grid">
          <div>
            <Link to="/" className="logo"><span className="logo-mark">R</span>Rebazz</Link>
            <p className="about">{t('f_about')}</p>
            <div className="pay-icons">{['iDEAL | Wero', 'Bancontact', 'VISA', 'Mastercard', 'AMEX', 'PayPal', 'Apple Pay', 'Google Pay', 'Klarna', 'BLIK'].map(p => <span key={p}>{p}</span>)}</div>
          </div>
          <div>
            <h4>{t('f_shop')}</h4>
            <ul><li><a href="#collectie" onClick={e => { e.preventDefault(); goHome('collectie'); }}>{t('f_all')}</a></li>{cats.map(c => <li key={c}><a href="#collectie" onClick={e => { e.preventDefault(); goHome('collectie'); }}>{category(c)}</a></li>)}</ul>
          </div>
          <div>
            <h4>{t('f_service')}</h4>
            <ul>
              <li><a href="#" onClick={e => { e.preventDefault(); openTrack(); }}>{t('nav_track')}</a></li>
              <li><Link to="/juridisch#verzending">{t('f_shipping')}</Link></li>
              <li><Link to="/juridisch#retour">{t('f_returns')}</Link></li>
              <li><a href="#faq" onClick={e => { e.preventDefault(); goHome('faq'); }}>{t('f_faq')}</a></li>
            </ul>
          </div>
          <div>
            <h4>{t('f_legal')}</h4>
            <ul>
              <li><Link to="/juridisch#voorwaarden">{t('f_terms')}</Link></li>
              <li><Link to="/juridisch#privacy">{t('f_privacy')}</Link></li>
              <li><Link to="/juridisch#cookies">{t('f_cookies')}</Link></li>
              <li><a href="#cookie-instellingen" onClick={e => { e.preventDefault(); openCookieSettings(); }}>{t('ck_prefs')}</a></li>
              <li><Link to="/juridisch#herroeping">{t('f_withdrawal')}</Link></li>
              <li><Link to="/privacy-verzoek">{t('f_mydata')}</Link></li>
              <li><Link to="/juridisch#contact">{t('f_contact')}</Link></li>
            </ul>
          </div>
        </div>
        <div className="footer-bottom">
          <span>© {new Date().getFullYear()} {b.company}. {t('rights')}</span>
          <span>KvK {b.kvk} · VAT {b.btw}</span>
        </div>
      </div>
    </footer>
  );
}

/* ---------- Winkelwagen ---------- */
function CartDrawer({ onClose }: { onClose: () => void }) {
  const { t, pName, country: countryName, rtl } = useI18n();
  const { catalog, cart, setQty, money, country } = useShop();
  const navigate = useNavigate();
  const q = quote(catalog.products, catalog.zones, cart, country);
  const zone = zoneFor(catalog.zones, country) ?? catalog.zones[catalog.zones.length - 1];
  const remaining = q ? Math.max(0, zone.freeFrom - q.subtotal) : 0;
  return (
    <motion.aside className="drawer react" aria-label={t('cart_title')} role="dialog" aria-modal="true"
      initial={{ x: rtl ? '-100%' : '100%' }} animate={{ x: 0 }} exit={{ x: rtl ? '-100%' : '100%' }} transition={spring}>
      <div className="drawer-head"><h3>{t('cart_title')}</h3><button className="icon-btn" onClick={onClose} aria-label={t('close')}><Icon name="x" size={20} /></button></div>
      <div className="drawer-body">
        {!q ? (
          <div style={{ textAlign: 'center', padding: '60px 10px' }} className="muted"><div style={{ display: 'grid', placeItems: 'center', marginBottom: 12 }}><Icon name="cart" size={36} /></div>{t('cart_empty')}</div>
        ) : (
          <>
            <div className="free-ship" style={{ marginTop: 12 }}>
              <span dangerouslySetInnerHTML={{ __html: remaining > 0 ? t('to_free', { amount: money(remaining), country: countryName(country) }, true) : t('free_reached') }} />
              <div className="bar"><motion.i initial={false} animate={{ width: Math.min(100, (q.subtotal / zone.freeFrom) * 100) + '%' }} /></div>
            </div>
            <AnimatePresence initial={false}>
              {q.lines.map(l => (
                <motion.div key={l.key} className="cart-line" layout initial={{ opacity: 0, height: 0 }} animate={{ opacity: 1, height: 'auto' }} exit={{ opacity: 0, height: 0 }}>
                  <ProductVisual p={{ ...l.product, image: l.variant?.image || l.product.image }} />
                  <div>
                    <h4>{pName(l.product)}</h4>
                    {l.variant && <span className="muted" style={{ fontSize: 12.5, display: 'block' }}>{l.variant.label}</span>}
                    <span className="muted" style={{ fontSize: 13 }}>{money(l.unit)}</span><br />
                    <div className="qty">
                      <button onClick={() => setQty(l.product.id, l.qty - 1, l.variant?.id)} aria-label={t('less')}><Icon name="minus" /></button>
                      <span>{l.qty}</span>
                      <button onClick={() => setQty(l.product.id, l.qty + 1, l.variant?.id)} aria-label={t('more')}><Icon name="plus" /></button>
                    </div>
                  </div>
                  <b style={{ fontSize: 14 }}>{money(l.unit * l.qty)}</b>
                </motion.div>
              ))}
            </AnimatePresence>
          </>
        )}
      </div>
      <div className="drawer-foot">
        {!q ? (
          <button className="btn btn-primary btn-block" onClick={onClose}>{t('continue')}</button>
        ) : (
          <>
            <div className="sum-row"><span>{t('subtotal')}</span><span>{money(q.subtotal)}</span></div>
            <div className="sum-row"><span>{t('shipping')} ({countryName(country)})</span><span>{q.shipping ? money(q.shipping) : t('free')}</span></div>
            <div className="sum-row total"><span>{t('total')}</span><span>{money(q.total)}</span></div>
            <MButton className="btn btn-accent btn-lg btn-block" onClick={() => { onClose(); navigate('/checkout'); }}><Icon name="lock" size={16} />{t('checkout_btn')}</MButton>
            <p className="hint" style={{ textAlign: 'center' }}>{t('cart_note')}</p>
          </>
        )}
      </div>
    </motion.aside>
  );
}

/* Labels voor de GPSR-productinformatie (EU-productveiligheid). */
const GPSR_TEXT: Record<string, { title: string; maker: string; eu: string; safety: string }> = {
  nl: { title: 'Productveiligheid en fabrikant', maker: 'Fabrikant', eu: 'Verantwoordelijke in de EU', safety: 'Veiligheidsinformatie' },
  en: { title: 'Product safety and manufacturer', maker: 'Manufacturer', eu: 'EU responsible person', safety: 'Safety information' },
  de: { title: 'Produktsicherheit und Hersteller', maker: 'Hersteller', eu: 'Verantwortliche Person in der EU', safety: 'Sicherheitshinweise' },
  fr: { title: 'Sécurité du produit et fabricant', maker: 'Fabricant', eu: "Personne responsable dans l'UE", safety: 'Informations de sécurité' },
  es: { title: 'Seguridad del producto y fabricante', maker: 'Fabricante', eu: 'Persona responsable en la UE', safety: 'Información de seguridad' },
  it: { title: 'Sicurezza del prodotto e produttore', maker: 'Produttore', eu: "Persona responsabile nell'UE", safety: 'Informazioni di sicurezza' },
  pl: { title: 'Bezpieczeństwo produktu i producent', maker: 'Producent', eu: 'Podmiot odpowiedzialny w UE', safety: 'Informacje o bezpieczeństwie' },
  ar: { title: 'سلامة المنتج والمصنِّع', maker: 'المصنِّع', eu: 'الجهة المسؤولة في الاتحاد الأوروبي', safety: 'معلومات السلامة' }
};

/* Fotogalerij: hoofdfoto (die van de gekozen variant als die er is) met miniaturen van de extra foto's. */
function Gallery({ p, variant }: { p: Product; variant?: { image: string } }) {
  const list = [...new Set([variant?.image, p.image, ...(p.images ?? [])].filter((x): x is string => !!x))];
  const [i, setI] = useState(0);
  const current = list[Math.min(i, Math.max(0, list.length - 1))];
  // Wisselt de gekozen variant van foto, dan laat de galerij die foto zien.
  const vimg = variant?.image;
  useEffect(() => { setI(0); }, [vimg]);
  return (
    <div className="gallery">
      <ProductVisual p={{ ...p, image: current ?? '' }} />
      {list.length > 1 && (
        <div className="gallery-thumbs" role="tablist" aria-label="Foto's">
          {list.map((src, n) => (
            <button type="button" key={src} role="tab" aria-selected={n === i} className={n === i ? 'on' : ''} onClick={() => setI(n)}><img src={src} alt="" loading="lazy" /></button>
          ))}
        </div>
      )}
    </div>
  );
}

/* ---------- Productdetail (gebruikt op de productpagina /product/:id) ---------- */
export function ProductDetail({ p, onAdded }: { p: Product; onAdded: () => void }) {
  const { t, pName, pDesc, pFeatures, category, country: countryName, locale, lang } = useI18n();
  const { catalog, money, country, setCountry, addToCart } = useShop();
  const toast = useToast();
  useEffect(() => { trackProduct(p.id); }, [p.id]); // alleen met toestemming
  const [qty, setQtyState] = useState(1);
  const variants = p.variants ?? [];
  const options = p.options ?? [];
  // Standaard de eerste leverbare variant voorselecteren; de bezoeker kan altijd wisselen.
  const first = variants.find(v => v.inStock) ?? variants[0];
  const [sel, setSel] = useState<Record<string, string>>(() => ({ ...(first?.values ?? {}) }));
  const [picked, setPicked] = useState<number | undefined>(first?.id);
  const variant = options.length
    ? variants.find(v => options.every(o => v.values[o.name] === sel[o.name]))
    : variants.find(v => v.id === picked);
  const unit = variant?.price ?? p.price;
  const soldOut = variant ? !variant.inStock : p.inStock === false;
  const left = variant ? variant.stockLeft : p.stockLeft;
  const zone = zoneFor(catalog.zones, country) ?? catalog.zones[catalog.zones.length - 1];
  const days: [number, number] = [Math.max(p.days[0], zone.days[0]), Math.max(p.days[1], zone.days[1])];
  const collator = new Intl.Collator(locale);
  const codes = catalog.zones.flatMap(z => z.countries).map(c => [c, countryName(c)] as const).sort((a, b) => collator.compare(a[1], b[1]));
  return (
    <article className="product-detail">
        <div className="pdp">
          <Gallery p={p} variant={variant} />
          <div className="pdp-info">
            <span className="eyebrow">{category(p.category)}</span>
            <div className="title-row"><h1>{pName(p)}</h1><WishButton id={p.id} /></div>
            {p.reviews > 0 && <div className="rating" style={{ display: 'flex', gap: 8, alignItems: 'center', fontSize: 13.5 }}><Stars rating={p.rating} /><span className="muted">{p.rating.toLocaleString(locale)} · {p.reviews} {t('reviews')}</span></div>}
            <div className="pdp-price">
              <span className="price">{money(unit)}</span>
              {!variants.length && p.compare && p.compare > p.price && <><span className="strike">{money(p.compare)}</span><span className="badge success plain">{t('save', { amount: money(p.compare - p.price) })}</span></>}
            </div>
            <p className="muted" style={{ fontSize: 12.5, marginTop: -10 }}>{zone.vat ? t('incl_vat') : t('excl_duties')}</p>
            {variants.length > 0 && (options.length ? options.map(o => (
              <fieldset key={o.name} className="opt-group">
                <legend>{o.name}: <b>{sel[o.name]}</b></legend>
                <div className="opt-values">
                  {o.values.map(val => {
                    const next = { ...sel, [o.name]: val };
                    const match = variants.find(v => options.every(x => v.values[x.name] === next[x.name]));
                    return <button type="button" key={val} className={'opt' + (sel[o.name] === val ? ' on' : '') + (match && !match.inStock ? ' out' : '')} aria-pressed={sel[o.name] === val} onClick={() => { setSel(next); setQtyState(1); }}>{val}</button>;
                  })}
                </div>
              </fieldset>
            )) : (
              <fieldset className="opt-group">
                <div className="opt-values">{variants.map(v => <button type="button" key={v.id} className={'opt' + (picked === v.id ? ' on' : '') + (!v.inStock ? ' out' : '')} aria-pressed={picked === v.id} onClick={() => { setPicked(v.id); setQtyState(1); }}>{v.label}</button>)}</div>
              </fieldset>
            ))}
            {variants.length > 0 && !variant && <p className="badge warn plain" role="status" style={{ height: 'auto', padding: '8px 12px', whiteSpace: 'normal', alignSelf: 'flex-start' }}>{t('co_variant')}</p>}
            {soldOut && <p className="badge danger plain" role="status" style={{ height: 'auto', padding: '8px 12px', whiteSpace: 'normal', alignSelf: 'flex-start' }}>{t('sold_out')}</p>}
            {left ? <p className="badge warn plain" role="status" style={{ height: 'auto', padding: '8px 12px', whiteSpace: 'normal', alignSelf: 'flex-start' }}>{t('low_stock', { n: left })}</p> : null}
            <p>{pDesc(p)}</p>
            <ul className="features">{pFeatures(p).map(f => <li key={f}><Icon name="check" size={16} />{f}</li>)}</ul>
            {(p.manufacturer || p.euResponsible || p.safetyInfo) && (
              <details className="gpsr" style={{ fontSize: 13, margin: '4px 0 12px' }}>
                <summary style={{ cursor: 'pointer' }}>{(GPSR_TEXT[lang] ?? GPSR_TEXT.en).title}</summary>
                {p.manufacturer && <p><b>{(GPSR_TEXT[lang] ?? GPSR_TEXT.en).maker}:</b> {p.manufacturer}</p>}
                {p.euResponsible && <p><b>{(GPSR_TEXT[lang] ?? GPSR_TEXT.en).eu}:</b> {p.euResponsible}</p>}
                {p.safetyInfo && <p><b>{(GPSR_TEXT[lang] ?? GPSR_TEXT.en).safety}:</b> {p.safetyInfo}</p>}
              </details>
            )}
            <div className="delivery-box">
              <div><Icon name="globe" /><span>{t('deliver_to')}</span>
                <select className="select" style={{ height: 34, width: 'auto', marginInlineStart: 'auto', maxWidth: '60%' }} value={country} onChange={e => setCountry(e.target.value)} aria-label={t('deliver_to')}>
                  {codes.map(([c, n]) => <option key={c} value={c}>{n}</option>)}
                </select>
              </div>
              <div><Icon name="truck" /><span>{t('expected')}: <b>{deliveryWindow(days, locale)}</b></span></div>
              <div><Icon name="package" /><span dangerouslySetInnerHTML={{ __html: t('ship_cost', { rate: money(zone.rate), free: money(zone.freeFrom) }, true) }} /></div>
              <div><Icon name="refresh" /><span>{t('returns_30')}</span></div>
            </div>
            <div className="pdp-actions">
              <div className="qty">
                <button onClick={() => setQtyState(q => Math.max(1, q - 1))} aria-label={t('less')}><Icon name="minus" /></button>
                <span>{qty}</span>
                <button onClick={() => setQtyState(q => Math.min(left ?? 20, q + 1))} aria-label={t('more')}><Icon name="plus" /></button>
              </div>
              <MButton className="btn btn-primary btn-lg" style={{ flex: 1 }} disabled={soldOut || (variants.length > 0 && !variant)} onClick={() => { addToCart(p.id, qty, variant?.id); toast(t('added', { name: pName(p) })); onAdded(); }}>
                <Icon name="cart" />{soldOut ? t('sold_out') : t('add_long')}
              </MButton>
            </div>
            <p className="hint" style={{ display: 'flex', gap: 8, alignItems: 'center' }}><Icon name="lock" size={15} />{t('secure_pay')} · SKU {variant?.sku || p.sku}</p>
          </div>
        </div>
    </article>
  );
}

/* ---------- Bestelling volgen ---------- */
function TrackModal({ onClose }: { onClose: () => void }) {
  const { t, list, locale } = useI18n();
  const [code, setCode] = useState('');
  const [email, setEmail] = useState('');
  const [state, setState] = useState<'idle' | 'loading' | 'none' | 'ok'>('idle');
  const [order, setOrder] = useState<TrackedOrder | null>(null);
  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setState('loading');
    try { setOrder(await api.track(code.trim(), email.trim())); setState('ok'); } catch { setState('none'); }
  };
  const steps = list<string[]>('st');
  return (
    <motion.div className="modal react" role="dialog" aria-modal="true" aria-label={t('track_title')} onClick={e => { if (e.target === e.currentTarget) onClose(); }}
      initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}>
      <motion.div className="modal-card small-modal" initial={{ y: 24, scale: 0.97 }} animate={{ y: 0, scale: 1 }} exit={{ y: 16 }} transition={spring}>
        <button className="icon-btn modal-close" onClick={onClose} aria-label={t('close')}><Icon name="x" size={20} /></button>
        <h3>{t('track_title')}</h3>
        <p className="muted" dangerouslySetInnerHTML={{ __html: t('track_lead2') }} />
        <form onSubmit={submit} style={{ display: 'grid', gap: 8, marginTop: 16 }}>
          <input className="input" id="trackInput" required placeholder={t('track_ph')} aria-label={t('track_ph')} value={code} onChange={e => setCode(e.target.value)} />
          <input className="input" id="trackEmail" type="email" required placeholder={t('track_email_ph')} aria-label={t('track_email_ph')} value={email} onChange={e => setEmail(e.target.value)} />
          <MButton className="btn btn-primary" type="submit">{t('track_btn')}</MButton>
        </form>
        <div className="track-result">
          {state === 'loading' && <p className="muted">{t('co_loading')}</p>}
          {state === 'none' && <p className="badge danger plain" style={{ height: 'auto', padding: '10px 14px', borderRadius: 10, whiteSpace: 'normal' }}>{t('track_none')}</p>}
          {state === 'ok' && order && (
            <>
              <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8, flexWrap: 'wrap' }}><b>{order.order_no}</b><span className="muted">{new Date(order.date).toLocaleDateString(locale, { day: 'numeric', month: 'long', year: 'numeric' })}</span></div>
              {order.tracking && <p className="muted" style={{ fontSize: 13, marginTop: 4 }}>{order.carrier} · {order.tracking}</p>}
              <ol className="timeline">
                {steps.map((s, i) => (
                  <motion.li key={s} className={(i <= order.step ? 'done' : '') + (i === order.step + 1 ? ' current' : '')} initial={{ opacity: 0, x: -8 }} animate={{ opacity: 1, x: 0 }} transition={{ delay: i * 0.06 }}>
                    <span className="dot">{i <= order.step && <Icon name="check" />}</span>
                    <div><b>{s}</b>{i === order.step && order.delayed && <><br /><span className="muted" style={{ fontSize: 13 }}>{t('track_delay')}</span></>}</div>
                  </motion.li>
                ))}
              </ol>
              <ReturnPanel order={order} code={code.trim()} email={email.trim()} onChange={patch => setOrder(o => (o ? { ...o, ...patch } : o))} />
            </>
          )}
        </div>
      </motion.div>
    </motion.div>
  );
}

/* ---------- Cookiebanner ---------- */
function CookieBanner() {
  const { t } = useI18n();
  const toast = useToast();
  const [show, setShow] = useState(() => !getConsent());
  const [prefs, setPrefs] = useState(false);
  const [analytics, setAnalytics] = useState(() => getConsent()?.analytics ?? false);
  const [marketing, setMarketing] = useState(() => getConsent()?.marketing ?? false);
  const saveChoice = (a: boolean, m: boolean) => { setConsent(a, m); setShow(false); toast(t('ck_saved')); };
  useEffect(() => onOpenCookieSettings(() => { setAnalytics(getConsent()?.analytics ?? false); setMarketing(getConsent()?.marketing ?? false); setPrefs(true); setShow(true); }), []);
  return (
    <AnimatePresence>
      {show && (
        <motion.div className="cookie" role="dialog" aria-label={t('ck_title')} initial={{ opacity: 0, y: 24 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: 24 }}>
          <h4>{t('ck_title')}</h4>
          <p dangerouslySetInnerHTML={{ __html: t('ck_text').replace('juridisch.html', '/juridisch') }} />
          {prefs && (
            <div className="cookie-prefs">
              <label><span>{t('ck_func')}</span><span className="switch"><input type="checkbox" checked disabled /><span /></span></label>
              <label><span>{t('ck_analytics')}</span><span className="switch"><input id="ckAnalytics" type="checkbox" checked={analytics} onChange={e => setAnalytics(e.target.checked)} /><span /></span></label>
              <label><span>{t('ck_marketing')}</span><span className="switch"><input id="ckMarketing" type="checkbox" checked={marketing} onChange={e => setMarketing(e.target.checked)} /><span /></span></label>
            </div>
          )}
          <div className="actions">
            <button className="btn btn-primary btn-sm" onClick={() => saveChoice(false, false)}>{t('ck_reject')}</button>
            <button className="btn btn-outline btn-sm" onClick={() => (prefs ? saveChoice(analytics, marketing) : setPrefs(true))}>{prefs ? t('ck_save') : t('ck_prefs')}</button>
            <button className="btn btn-primary btn-sm" onClick={() => saveChoice(true, true)}>{t('ck_accept')}</button>
          </div>
        </motion.div>
      )}
    </AnimatePresence>
  );
}

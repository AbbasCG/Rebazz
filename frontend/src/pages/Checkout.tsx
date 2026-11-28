/* Afrekenen: land + verzendmethode kiezen, daarna betalen bij Stripe.
   De server berekent de prijzen opnieuw uit de database. */
import { AnimatePresence, motion } from 'motion/react';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Icon, MButton, ThemeToggle } from '../components/ui';
import { api, ApiError } from '../lib/api';
import { trackCheckout } from '../lib/analytics';
import { useI18n } from '../lib/i18n';
import { quote } from '../lib/quote';
import { deliveryWindow, useShop } from '../lib/shop';

const VAT: Record<string, number> = { NL: 21, BE: 21, LU: 17, DE: 19, FR: 20, ES: 21, IT: 22, AT: 20, PT: 23, IE: 23, PL: 23, SE: 25, DK: 25, FI: 25.5, CZ: 21, GR: 24 };
const PAY_BY_COUNTRY: Record<string, string[]> = { NL: ['iDEAL | Wero'], BE: ['Bancontact'], AT: ['EPS'], PL: ['BLIK', 'Przelewy24'], PT: ['Multibanco'] };
const WH: Record<string, string> = { Polen: 'PL', Duitsland: 'DE', Frankrijk: 'FR', Nederland: 'NL' };

export default function Checkout() {
  const { t, lang, setLang, languages, pName, country: countryName, locale } = useI18n();
  const { catalog, cart, country, setCountry, money, currency } = useShop();
  const [ship, setShip] = useState<'standard' | 'express'>('standard');
  const [terms, setTerms] = useState(false);
  const [termsErr, setTermsErr] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  const q = quote(catalog.products, catalog.zones, cart, country, ship);
  const std = quote(catalog.products, catalog.zones, cart, country, 'standard');
  const exp = quote(catalog.products, catalog.zones, cart, country, 'express');
  const whLabel = (wh: string) => {
    if (lang === 'nl') return wh;
    const m = wh.match(/^EU \((.+)\)$/);
    if (m && WH[m[1]]) return 'EU (' + countryName(WH[m[1]]) + ')';
    if (wh === 'VS') return countryName('US');
    if (wh === 'China') return countryName('CN');
    return wh;
  };

  const header = (
    <header className="co-header">
      <div className="container">
        <Link to="/" className="logo"><span className="logo-mark">R</span>Rebazz</Link>
        <div className="tools">
          <select className="select lang-select" aria-label={t('lang_label')} value={lang} onChange={e => setLang(e.target.value)}>
            {languages.map(l => <option key={l.code} value={l.code}>{l.native}</option>)}
          </select>
          <ThemeToggle label={t('theme')} />
          <span className="co-secure"><Icon name="lock" size={16} /><span className="txt">{t('co_secure')}</span></span>
        </div>
      </div>
    </header>
  );

  if (!q || !std) {
    return (
      <>
        {header}
        <main className="container"><div className="confirm"><h1>{t('co_empty_title')}</h1><p className="muted" style={{ margin: '12px 0 24px' }}>{t('co_empty_text')}</p><Link className="btn btn-primary btn-lg" to="/#collectie">{t('co_to_collection')}</Link></div></main>
      </>
    );
  }

  const opts = [{ id: 'standard' as const, q: std, name: t('co_standard') }, ...(exp && exp.express ? [{ id: 'express' as const, q: exp, name: t('co_express') }] : [])];
  const vatRate = VAT[country];
  const vatAmount = vatRate ? q.total - q.total / (1 + vatRate / 100) : 0;
  const collator = new Intl.Collator(locale);
  const codes = catalog.zones.flatMap(z => z.countries).map(c => [c, countryName(c)] as const).sort((a, b) => collator.compare(a[1], b[1]));

  const pay = async () => {
    setError('');
    if (!terms) { setTermsErr(true); return; }
    setBusy(true);
    try {
      const items = q.lines.map(l => ({ id: l.product.id, qty: l.qty, name: pName(l.product), ...(l.variant ? { v: l.variant.id } : {}) }));
      trackCheckout();
      const res = await api.checkout({ items, country, ship, lang });
      window.location.href = res.url;
    } catch (e) {
      setBusy(false);
      const code = e instanceof ApiError ? (e.data as { error?: string } | null)?.error : undefined;
      setError(code === 'stock' ? t('co_stock') : code === 'variant' ? t('co_variant') : e instanceof ApiError && [0, 404, 405, 503].includes(e.status) ? t('co_notlive') : t('co_error'));
    }
  };

  return (
    <>
      {header}
      <main className="container">
        <form className="co-wrap" onSubmit={e => { e.preventDefault(); if (!busy) pay(); }} noValidate>
          <div>
            <section className="co-section">
              <h2><span className="n">1</span><span>{t('co_country')}</span></h2>
              <select className="select" id="country" aria-label={t('co_country')} value={country} onChange={e => setCountry(e.target.value)}>
                {codes.map(([c, n]) => <option key={c} value={c}>{n}</option>)}
              </select>
            </section>
            <section className="co-section">
              <h2><span className="n">2</span><span>{t('co_shipping')}</span></h2>
              <div className="stack">
                {opts.map(o => (
                  <motion.label key={o.id} className={'option' + (o.id === ship ? ' selected' : '')} whileTap={{ scale: 0.99 }} layout>
                    <input type="radio" name="ship" value={o.id} checked={o.id === ship} onChange={() => setShip(o.id)} />
                    <span className="pm-logo"><Icon name={o.id === 'express' ? 'zap' : 'truck'} size={18} /></span>
                    <span className="txt"><b>{o.name}</b><span>{t('expected')}: {deliveryWindow(o.q.days, locale)} · {o.q.days[0]}–{o.q.days[1]} {t('co_workdays')}</span></span>
                    <span className="right">{o.q.shipping ? money(o.q.shipping) : t('free')}</span>
                  </motion.label>
                ))}
                {!q.zone.vat && <div className="notice"><Icon name="info" size={16} /><span>{t('co_duties', { country: countryName(country) })}</span></div>}
              </div>
            </section>
            <section className="co-section">
              <h2><span className="n">3</span><span>{t('co_payment')}</span></h2>
              <div style={{ fontSize: 12.5, fontWeight: 600, color: 'var(--muted)', marginBottom: 10 }}>{t('co_methods')}</div>
              <div className="pay-icons" style={{ margin: '0 0 16px' }}>{(PAY_BY_COUNTRY[country] ?? []).concat(['VISA', 'Mastercard', 'AMEX', 'Apple Pay', 'Google Pay', 'PayPal', 'Klarna']).map(p => <span key={p}>{p}</span>)}</div>
              <div className="notice"><Icon name="info" size={16} /><span>{t('co_redirect')}</span></div>
              <div className="stack" style={{ marginTop: 18 }}>
                <label className="check"><input type="checkbox" id="terms" checked={terms} onChange={e => { setTerms(e.target.checked); if (e.target.checked) setTermsErr(false); }} /> <span dangerouslySetInnerHTML={{ __html: t('co_terms').split('juridisch.html').join('/juridisch') }} /></label>
                <AnimatePresence>
                  {termsErr && <motion.span className="error-text" initial={{ opacity: 0, height: 0 }} animate={{ opacity: 1, height: 'auto' }} exit={{ opacity: 0, height: 0 }}>{t('co_terms_err')}</motion.span>}
                  {error && <motion.span className="error-text" initial={{ opacity: 0, height: 0 }} animate={{ opacity: 1, height: 'auto' }} exit={{ opacity: 0, height: 0 }}>{error}</motion.span>}
                </AnimatePresence>
                <MButton className="btn btn-accent btn-lg btn-block" type="submit" disabled={busy} style={{ height: 'auto', minHeight: 52, whiteSpace: 'normal' }}>
                  <Icon name="lock" size={16} /><span>{busy ? t('co_loading') : t('co_pay_next') + ' · ' + money(q.total)}</span>
                </MButton>
                <p className="hint" style={{ textAlign: 'center' }}>{t('co_obligation')}</p>
              </div>
            </section>
          </div>
          <aside className="co-section summary">
            <h2 style={{ justifyContent: 'space-between' }}><span>{t('co_your_order')}</span><Link to="/" style={{ fontSize: 13, fontWeight: 500 }}>{t('co_edit')}</Link></h2>
            {q.lines.map(l => (
              <div className="line" key={l.key}>
                <div className={'pv' + ((l.variant?.image || l.product.image) ? ' has-img' : '')} style={{ ['--h' as string]: l.product.hue }}>
                  {(l.variant?.image || l.product.image) ? <img src={l.variant?.image || l.product.image} alt="" /> : <Icon name={l.product.icon} size={28} />}
                  <span className="q">{l.qty}</span>
                </div>
                <div><h4>{pName(l.product)}{l.variant ? ' — ' + l.variant.label : ''}</h4><span className="muted" style={{ fontSize: 12.5 }}>{t('co_from_wh', { wh: whLabel(l.product.warehouse) })}</span></div>
                <b style={{ fontSize: 14 }}>{money(l.unit * l.qty)}</b>
              </div>
            ))}
            <div className="totals">
              <div className="sum-row"><span>{t('subtotal')}</span><span>{money(q.subtotal)}</span></div>
              <div className="sum-row"><span>{t('shipping')}</span><span>{q.shipping ? money(q.shipping) : t('free')}</span></div>
              <div className="sum-row total" style={{ borderTop: '1px solid var(--line)', paddingTop: 12, marginTop: 4 }}><span>{t('total')}</span><motion.span key={q.total} initial={{ opacity: 0.4, y: -4 }} animate={{ opacity: 1, y: 0 }}>{money(q.total)}</motion.span></div>
              <span className="hint">
                {vatRate ? t('co_incl_vat', { amount: money(vatAmount), rate: vatRate.toLocaleString(locale), country: countryName(country) }) : t('co_excl_duties')}
                {currency !== 'EUR' ? ' · ' + t('co_charged_eur', { amount: money(q.total, 'EUR') }) : ''}
              </span>
            </div>
          </aside>
        </form>
      </main>
    </>
  );
}

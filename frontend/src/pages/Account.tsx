import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { ReturnPanel } from '../components/ReturnPanel';
import ShopChrome from '../components/ShopChrome';
import { MButton, ProductVisual, useToast } from '../components/ui';
import WishButton from '../components/WishButton';
import { api, ApiError } from '../lib/api';
import { useAuth } from '../lib/auth';
import { useI18n } from '../lib/i18n';
import { useShop } from '../lib/shop';
import { productPath } from '../lib/slug';
import type { AccountOrder, AccountOrderDetail, TrackedOrder } from '../lib/types';

export default function Account() {
  const { customer, ready } = useAuth();
  return (
    <ShopChrome>
      <div className="container" style={{ maxWidth: 780, padding: '40px 20px 80px' }}>
        {!ready ? null : customer ? <Dashboard /> : <Login />}
      </div>
    </ShopChrome>
  );
}

/* ---------- Inloggen: e-mail -> code ---------- */
function Login() {
  const { t, lang } = useI18n();
  const { requestCode, verify } = useAuth();
  const [email, setEmail] = useState('');
  const [code, setCode] = useState('');
  const [step, setStep] = useState<'email' | 'code'>('email');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  const send = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true); setError('');
    try { await requestCode(email.trim(), lang); setStep('code'); setCode(''); }
    catch (err) { setError(err instanceof ApiError && err.status === 429 ? err.message : t('co_error')); }
    finally { setBusy(false); }
  };
  const check = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true); setError('');
    try { await verify(email.trim(), code.trim()); }
    catch (err) { setError(err instanceof ApiError && (err.status === 422 || err.status === 401) ? t('acc_code_bad') : t('co_error')); }
    finally { setBusy(false); }
  };

  return (
    <>
      <h1 style={{ fontFamily: 'var(--serif)', fontWeight: 500, fontSize: 34 }}>{t('acc_title')}</h1>
      <p className="muted" style={{ margin: '10px 0 22px' }}>{t('acc_login_lead')}</p>
      {step === 'email' ? (
        <form onSubmit={send} style={{ display: 'grid', gap: 12 }}>
          <input className="input" type="email" required autoComplete="email" placeholder="E-mail" aria-label="E-mail" value={email} onChange={e => setEmail(e.target.value)} />
          {error && <p className="badge danger plain" role="alert" style={{ height: 'auto', padding: '10px 14px', whiteSpace: 'normal' }}>{error}</p>}
          <MButton className="btn btn-primary" type="submit" disabled={busy}>{t('acc_send_code')}</MButton>
        </form>
      ) : (
        <form onSubmit={check} style={{ display: 'grid', gap: 12 }}>
          <p role="status">{t('acc_code_sent', { email })}</p>
          <input className="input" inputMode="numeric" pattern="[0-9]{6}" maxLength={6} required autoComplete="one-time-code" autoFocus
            placeholder={t('acc_code_ph')} aria-label={t('acc_code_ph')} value={code} onChange={e => setCode(e.target.value.replace(/\D/g, ''))} />
          {error && <p className="badge danger plain" role="alert" style={{ height: 'auto', padding: '10px 14px', whiteSpace: 'normal' }}>{error}</p>}
          <div style={{ display: 'flex', gap: 8 }}>
            <MButton className="btn btn-primary" type="submit" disabled={busy || code.length !== 6}>{t('acc_verify')}</MButton>
            <button className="btn btn-ghost" type="button" onClick={() => { setStep('email'); setError(''); }}>{t('acc_other_email')}</button>
          </div>
        </form>
      )}
    </>
  );
}

/* ---------- Ingelogd: bestellingen / verlanglijst / profiel ---------- */
type Tab = 'orders' | 'wishlist' | 'profile';

function Dashboard() {
  const { t } = useI18n();
  const { customer, logout } = useAuth();
  const [tab, setTab] = useState<Tab>('orders');
  return (
    <>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', gap: 12, flexWrap: 'wrap' }}>
        <h1 style={{ fontFamily: 'var(--serif)', fontWeight: 500, fontSize: 34 }}>{t('acc_title')}</h1>
        <button className="btn btn-outline btn-sm" type="button" onClick={() => void logout()}>{t('acc_logout')}</button>
      </div>
      <p className="muted">{customer?.name || customer?.email}</p>
      <div className="acc-tabs" role="tablist">
        {(['orders', 'wishlist', 'profile'] as Tab[]).map(k => (
          <button key={k} type="button" role="tab" aria-selected={tab === k} onClick={() => setTab(k)}>{t('acc_' + k)}</button>
        ))}
      </div>
      {tab === 'orders' && <Orders />}
      {tab === 'wishlist' && <Wishlist />}
      {tab === 'profile' && <Profile />}
    </>
  );
}

function Orders() {
  const { t, locale } = useI18n();
  const { money } = useShop();
  const [orders, setOrders] = useState<AccountOrder[] | null>(null);
  const [open, setOpen] = useState('');
  useEffect(() => { api.account.orders().then(r => setOrders(r.orders)).catch(() => setOrders([])); }, []);
  if (orders === null) return null;
  if (!orders.length) return <p className="muted">{t('acc_no_orders')}</p>;
  return (
    <div style={{ display: 'grid', gap: 12 }}>
      {orders.map(o => (
        <article key={o.order_no} className="card" style={{ padding: 16 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8, flexWrap: 'wrap' }}>
            <b>{o.order_no}</b>
            <span className="muted">{o.date ? new Date(o.date).toLocaleDateString(locale) : ''} · {money(o.total)}</span>
          </div>
          <ul style={{ margin: '8px 0', paddingInlineStart: 18, fontSize: 14 }}>
            {o.items.map((i, n) => <li key={n}>{i.name}{i.variant ? ` (${i.variant})` : ''} × {i.qty}</li>)}
          </ul>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center' }}>
            <button className="btn btn-outline btn-sm" type="button" aria-expanded={open === o.order_no} onClick={() => setOpen(open === o.order_no ? '' : o.order_no)}>{t('acc_details')}</button>
            {o.invoiceUrl && <a className="btn btn-ghost btn-sm" href={o.invoiceUrl} target="_blank" rel="noopener">{t('acc_invoice')}</a>}
            {o.returnCount > 0 && <span className="muted" style={{ fontSize: 13 }}>{t('acc_return_count', { n: o.returnCount })}</span>}
          </div>
          {open === o.order_no && <Detail no={o.order_no} />}
        </article>
      ))}
    </div>
  );
}

function Detail({ no }: { no: string }) {
  const { list } = useI18n();
  const [d, setD] = useState<AccountOrderDetail | null>(null);
  const load = useCallback(() => { api.account.order(no).then(setD).catch(() => setD(null)); }, [no]);
  useEffect(load, [load]);
  if (!d) return null;
  const steps = list<string[]>('st');
  const asTracked: TrackedOrder = { order_no: d.order_no, date: d.date, step: d.step, carrier: d.carrier, tracking: d.tracking, delayed: d.delayed, refunded: d.status === 'refunded', returnable: d.returnable, returnDeadline: d.returnDeadline, items: d.returnItems, returns: d.returns };
  return (
    <div style={{ marginTop: 12 }}>
      <p className="muted" style={{ fontSize: 13.5 }}>{steps[Math.min(d.step, steps.length - 1)] ?? ''}{d.tracking ? ` · ${d.carrier} ${d.tracking}` : ''}</p>
      <ReturnPanel order={asTracked} code={d.order_no} email="" onChange={() => load()}
        submitReturn={async b => {
          const res = await api.account.createReturn({ order_no: d.order_no, ...b });
          setD(res);
          return { rma: res.rma, returnable: res.returnable, returnDeadline: res.returnDeadline, items: res.returnItems, returns: res.returns };
        }} />
    </div>
  );
}

function Wishlist() {
  const { t, pName } = useI18n();
  const { catalog, money } = useShop();
  const { wish } = useAuth();
  const list = wish.map(id => catalog.products.find(p => p.id === id)).filter((p): p is NonNullable<typeof p> => !!p);
  if (!list.length) return <p className="muted">{t('acc_no_wish')}</p>;
  return (
    <div style={{ display: 'grid', gap: 12, gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))' }}>
      {list.map(p => (
        <article key={p.id} className="product-card">
          <div className="media"><WishButton id={p.id} /><ProductVisual p={p} /></div>
          <div className="body">
            <h3><Link to={productPath(p)} style={{ color: 'inherit', textDecoration: 'none' }}>{pName(p)}</Link></h3>
            <span className="price">{money(p.price)}</span>
          </div>
        </article>
      ))}
    </div>
  );
}

function Profile() {
  const { t, lang } = useI18n();
  const toast = useToast();
  const { customer, setCustomer, logout } = useAuth();
  const [name, setName] = useState(customer?.name ?? '');
  const [news, setNews] = useState(customer?.newsletter ?? false);
  const [busy, setBusy] = useState(false);

  const save = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true);
    try { setCustomer(await api.account.update({ name, newsletter: news, lang })); toast(t('acc_saved')); }
    catch { toast(t('co_error')); }
    finally { setBusy(false); }
  };
  const download = async () => {
    try {
      const url = URL.createObjectURL(await api.account.export());
      const a = document.createElement('a'); a.href = url; a.download = 'mijn-gegevens.json'; a.click();
      URL.revokeObjectURL(url);
    } catch { toast(t('co_error')); }
  };
  const erase = async () => {
    if (!window.confirm(t('acc_delete_confirm'))) return;
    try { await api.account.erase(); await logout(); toast(t('acc_deleted')); } catch { toast(t('co_error')); }
  };

  return (
    <div style={{ display: 'grid', gap: 22 }}>
      <form onSubmit={save} style={{ display: 'grid', gap: 12 }}>
        <label style={{ display: 'grid', gap: 4, fontSize: 14 }}>E-mail<input className="input" value={customer?.email ?? ''} readOnly /></label>
        <label style={{ display: 'grid', gap: 4, fontSize: 14 }}>{t('acc_name')}<input className="input" maxLength={150} autoComplete="name" value={name} onChange={e => setName(e.target.value)} /></label>
        <label style={{ display: 'flex', gap: 10, alignItems: 'center', fontSize: 14 }}><input type="checkbox" checked={news} onChange={e => setNews(e.target.checked)} />{t('acc_newsletter')}</label>
        <div><MButton className="btn btn-primary" type="submit" disabled={busy}>{t('acc_save')}</MButton></div>
      </form>
      <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', paddingTop: 16, borderTop: '1px solid var(--line)' }}>
        <button className="btn btn-outline" type="button" onClick={() => void download()}>{t('acc_export')}</button>
        <button className="btn btn-outline" type="button" style={{ color: 'var(--danger, #c0392b)' }} onClick={() => void erase()}>{t('acc_delete')}</button>
      </div>
    </div>
  );
}

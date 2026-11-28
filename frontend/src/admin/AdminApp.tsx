/* Beheerdashboard: inloggen, menu en schermen. Alle gegevens komen uit de Laravel-API. */
import '../styles/dashboard.css';
import { AnimatePresence, motion } from 'motion/react';
import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { Link, Navigate, Route, Routes, useLocation, useNavigate } from 'react-router-dom';
import { Icon, MButton, ThemeToggle, useToast } from '../components/ui';
import { adminToken, api, ApiError } from '../lib/api';
import type { Order } from '../lib/types';
import { AdminCtx, useAdmin, type AdminData, type Status } from './context';
import Ads from './views/Ads';
import Automations from './views/Automations';
import Checklist from './views/Checklist';
import Orders from './views/Orders';
import Newsletter from './views/Newsletter';
import Privacy from './views/Privacy';
import Returns from './views/Returns';
import Overview from './views/Overview';
import Products from './views/Products';
import Research from './views/Research';
import Settings from './views/Settings';
import Studio from './views/Studio';
import Support from './views/Support';
import Tax from './views/Tax';

export const ROUTES = [
  { group: 'Overzicht', items: [{ id: 'overzicht', label: 'Dashboard', icon: 'dashboard', sub: 'Prestaties van uw winkel in één oogopslag' }] },
  { group: 'Groei', items: [
    { id: 'onderzoek', label: 'Productonderzoek', icon: 'search', ai: true, sub: 'Vind winnende producten met AI-analyse' },
    { id: 'producten', label: 'Producten', icon: 'package', sub: 'Assortiment, prijzen, foto’s en CJ-koppeling' },
    { id: 'studio', label: 'AI-videostudio', icon: 'video', ai: true, sub: 'Advertentievideo’s voor elk platform' },
    { id: 'advertenties', label: 'Advertenties', icon: 'megaphone', sub: 'Campagnes op Meta, TikTok, Google en Pinterest' },
    { id: 'nieuwsbrief', label: 'Nieuwsbrief', icon: 'mail', sub: 'Mails aan bevestigde abonnees; herinnering verlaten winkelwagen' }
  ] },
  { group: 'Operatie', items: [
    { id: 'bestellingen', label: 'Bestellingen & verzending', icon: 'truck', sub: 'Verwerk, volg en controleer alle zendingen' },
    { id: 'retouren', label: 'Retouren', icon: 'refresh', sub: 'Retouraanvragen beoordelen, ontvangen en terugbetalen' },
    { id: 'klantenservice', label: 'Klantenservice', icon: 'message', ai: true, sub: 'Meertalige inbox met AI-antwoordsuggesties' },
    { id: 'automatisering', label: 'Automatiseringen', icon: 'bot', sub: 'Terugkerende taken automatisch laten uitvoeren' }
  ] },
  { group: 'Beheer', items: [
    { id: 'privacy', label: 'Privacy (AVG)', icon: 'shield', sub: 'Inzage, export en wissen van persoonsgegevens; bewaartermijnen' },
    { id: 'belasting', label: 'Belasting (btw)', icon: 'receipt', sub: 'Btw per kwartaal, OSS-drempel en aangiftedeadlines' },
    { id: 'instellingen', label: 'Instellingen', icon: 'sliders', sub: 'Winkelgegevens, betalingen, verzending en koppelingen' },
    { id: 'checklist', label: 'Lanceringschecklist', icon: 'checklist', sub: 'Alles wat geregeld moet zijn vóór de lancering' }
  ] }
];
const ALL = ROUTES.flatMap(g => g.items);

export default function AdminApp() {
  const [phase, setPhase] = useState<'loading' | 'login' | 'ready' | 'offline'>('loading');
  const [status, setStatus] = useState<Status | null>(null);
  const [orders, setOrders] = useState<Order[]>([]);
  const [loginError, setLoginError] = useState('');

  useEffect(() => {
    document.body.classList.add('admin');
    document.title = 'Rebazz Beheer';
    return () => document.body.classList.remove('admin');
  }, []);

  const refresh = useCallback(async () => {
    const r = await api.admin.orders();
    setOrders(r.orders);
  }, []);

  const connect = useCallback(async () => {
    if (!adminToken.get()) { setPhase('login'); return; }
    try {
      setStatus(await api.admin.status());
      await refresh();
      setPhase('ready');
    } catch (e) {
      if (e instanceof ApiError && e.status === 401) { adminToken.clear(); setPhase('login'); }
      else setPhase('offline');
    }
  }, [refresh]);

  useEffect(() => { connect(); }, [connect]);

  const login = async (pw: string) => {
    setLoginError('');
    try {
      const r = await api.admin.login(pw);
      adminToken.set(r.token);
      setPhase('loading');
      connect();
    } catch (e) {
      setLoginError(e instanceof ApiError && e.status === 0 ? 'De server is niet bereikbaar.' : e instanceof ApiError && e.status === 429 ? 'Te veel pogingen. Probeer het over een minuut opnieuw.' : 'Onjuist wachtwoord.');
    }
  };

  if (phase === 'loading') return <div className="m-wrap" style={{ background: 'var(--dash-bg)' }}><p className="muted">Verbinden…</p></div>;
  if (phase === 'login') return <Login onLogin={login} error={loginError} />;
  if (phase === 'offline') return (
    <div className="m-wrap" style={{ background: 'var(--dash-bg)' }}>
      <div className="m-card" style={{ padding: 32, width: 'min(440px,100%)' }}>
        <h3 style={{ fontSize: 18 }}>Server niet bereikbaar</h3>
        <p className="muted" style={{ margin: '10px 0 18px', fontSize: 14 }}>Het dashboard kon de Laravel-server niet bereiken. Start de backend (zie HANDLEIDING.md) en probeer het opnieuw.</p>
        <button className="btn btn-primary" onClick={() => { setPhase('loading'); connect(); }}>Opnieuw proberen</button>
      </div>
    </div>
  );

  const data: AdminData = {
    status: status!,
    orders,
    setOrders,
    refresh,
    replaceOrder: (o: Order) => setOrders(list => list.map(x => (x.id === o.id ? o : x)))
  };
  return (
    <AdminCtx.Provider value={data}>
      <Shell onLogout={async () => { try { await api.admin.logout(); } catch { /* */ } adminToken.clear(); setPhase('login'); }} />
    </AdminCtx.Provider>
  );
}

function Login({ onLogin, error }: { onLogin: (pw: string) => void; error: string }) {
  const [pw, setPw] = useState('');
  const [busy, setBusy] = useState(false);
  const submit = async (e: FormEvent) => { e.preventDefault(); setBusy(true); await onLogin(pw); setBusy(false); };
  return (
    <div className="m-wrap" style={{ background: '#121419' }}>
      <motion.form className="m-card" onSubmit={submit} style={{ width: 'min(400px,100%)', padding: 32 }} initial={{ opacity: 0, y: 20, scale: 0.98 }} animate={{ opacity: 1, y: 0, scale: 1 }}>
        <div style={{ fontFamily: 'var(--serif)', fontSize: 24, fontWeight: 600, display: 'flex', gap: 10, alignItems: 'center', marginBottom: 20 }}>
          <span style={{ width: 30, height: 30, borderRadius: 8, background: 'var(--solid)', color: 'var(--on-solid)', display: 'grid', placeItems: 'center' }}>R</span>Rebazz Beheer
        </div>
        <div className="field"><label htmlFor="adminPw">Wachtwoord</label><input className="input" id="adminPw" type="password" autoComplete="current-password" required value={pw} onChange={e => setPw(e.target.value)} autoFocus /></div>
        <AnimatePresence>{error && <motion.p className="hint" style={{ color: 'var(--danger)', marginTop: 10 }} initial={{ opacity: 0, x: -6 }} animate={{ opacity: 1, x: [6, -4, 2, 0] }} exit={{ opacity: 0 }}>{error}</motion.p>}</AnimatePresence>
        <MButton className="btn btn-primary btn-block" style={{ marginTop: 18 }} type="submit" disabled={busy}>{busy ? 'Bezig…' : 'Inloggen'}</MButton>
        <p className="hint" style={{ marginTop: 14 }}>Het wachtwoord staat in het <code>.env</code>-bestand van de server als <b>ADMIN_PASSWORD</b>.</p>
      </motion.form>
    </div>
  );
}

function Shell({ onLogout }: { onLogout: () => void }) {
  const location = useLocation();
  const navigate = useNavigate();
  const toast = useToast();
  const [open, setOpen] = useState(false);
  const current = location.pathname.split('/')[2] || 'overzicht';
  const meta = ALL.find(r => r.id === current) ?? ALL[0];
  const ctx = useAdminCounts();

  useEffect(() => { document.title = meta.label + ' — Rebazz Beheer'; setOpen(false); window.scrollTo(0, 0); }, [meta.label]);

  return (
    <div className="app">
      <aside className={'sidebar' + (open ? ' open' : '')}>
        <Link to="/beheer" className="logo"><span className="logo-mark">R</span>Rebazz<small>Beheer</small></Link>
        <nav aria-label="Dashboardnavigatie">
          {ROUTES.map(g => (
            <div className="nav-group" key={g.group}>
              <h6>{g.group}</h6>
              {g.items.map(it => (
                <Link key={it.id} to={'/beheer/' + it.id} className={'nav-link' + (it.id === current ? ' active' : '')} aria-current={it.id === current ? 'page' : undefined} style={{ position: 'relative' }}>
                  {it.id === current && <motion.span layoutId="navActive" style={{ position: 'absolute', inset: 0, borderRadius: 9, background: '#23262f', zIndex: -1 }} transition={{ type: 'spring', stiffness: 450, damping: 38 }} />}
                  <Icon name={it.icon} size={18} />{it.label}
                  {ctx[it.id] ? <span className="count hot">{ctx[it.id]}</span> : 'ai' in it && it.ai ? <span className="ai">AI</span> : null}
                </Link>
              ))}
            </div>
          ))}
        </nav>
        <div className="sidebar-foot">
          <a className="nav-link" href="/" target="_blank" rel="noopener"><Icon name="store" size={18} />Naar de webwinkel<span style={{ marginInlineStart: 'auto' }}><Icon name="external" size={14} /></span></a>
          <button className="nav-link" style={{ width: '100%', border: 0, background: 'none', cursor: 'pointer' }} onClick={onLogout}><Icon name="lock" size={18} />Uitloggen</button>
        </div>
      </aside>
      <div className="main">
        <header className="topbar">
          <button className="icon-btn mobile-menu" aria-label="Menu openen" onClick={() => setOpen(o => !o)}><Icon name="menu" size={20} /></button>
          <div><h1>{meta.label}</h1><div className="sub">{meta.sub}</div></div>
          <div className="right">
            <StatusPill />
            <ThemeToggle label="Licht of donker thema" />
            <button className="icon-btn" aria-label="Meldingen" onClick={() => { navigate('/beheer/overzicht'); toast('Bekijk de aandachtspunten op het dashboard'); }}><Icon name="bell" size={20} /></button>
            <div className="avatar" title="Eigenaar">EI</div>
          </div>
        </header>
        <AnimatePresence mode="wait">
          <motion.main key={current} className="view" initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -6 }} transition={{ duration: 0.2 }}>
            <Routes>
              <Route index element={<Overview />} />
              <Route path="overzicht" element={<Overview />} />
              <Route path="onderzoek" element={<Research />} />
              <Route path="producten" element={<Products />} />
              <Route path="studio" element={<Studio />} />
              <Route path="advertenties" element={<Ads />} />
              <Route path="bestellingen" element={<Orders />} />
              <Route path="retouren" element={<Returns />} />
              <Route path="klantenservice" element={<Support />} />
              <Route path="automatisering" element={<Automations />} />
              <Route path="nieuwsbrief" element={<Newsletter />} />
              <Route path="privacy" element={<Privacy />} />
              <Route path="belasting" element={<Tax />} />
              <Route path="instellingen" element={<Settings />} />
              <Route path="checklist" element={<Checklist />} />
              <Route path="*" element={<Navigate to="/beheer" replace />} />
            </Routes>
          </motion.main>
        </AnimatePresence>
      </div>
    </div>
  );
}

function useAdminCounts(): Record<string, number> {
  const { orders } = useAdmin();
  return { bestellingen: orders.filter(o => o.step === 0 && !o.refunded).length };
}

function StatusPill() {
  const { status } = useAdmin();
  const live = status.stripe === 'live';
  const label = status.stripe === 'missing' ? 'Stripe niet ingesteld' : live ? 'Live — echte betalingen' : 'Stripe testmodus';
  return (
    <span className="demo-pill" style={live ? { background: 'var(--success-soft)', color: 'var(--success)' } : status.stripe === 'missing' ? undefined : { background: 'var(--info-soft)', color: 'var(--info)' }}>
      <Icon name={live ? 'check' : 'info'} size={14} /><span className="txt">{label}</span>
    </span>
  );
}

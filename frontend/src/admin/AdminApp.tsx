/* Beheerdashboard: inloggen, menu en schermen. Alle gegevens komen uit de Laravel-API. */
import '../styles/dashboard.css';
import { AnimatePresence, motion } from 'motion/react';
import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { Link, Navigate, Route, Routes, useLocation, useNavigate } from 'react-router-dom';
import { Icon, MButton, ThemeToggle, useToast } from '../components/ui';
import { adminToken, api, ApiError } from '../lib/api';
import type { Order, StaffMe } from '../lib/types';
import { AdminCtx, useAdmin, type AdminData, type Status } from './context';
import Account from './views/Account';
import Ads from './views/Ads';
import Automations from './views/Automations';
import Checklist from './views/Checklist';
import Orders from './views/Orders';
import Newsletter from './views/Newsletter';
import Stats from './views/Stats';
import Privacy from './views/Privacy';
import Returns from './views/Returns';
import Overview from './views/Overview';
import Products from './views/Products';
import Research from './views/Research';
import Settings from './views/Settings';
import Studio from './views/Studio';
import Support from './views/Support';
import Tax from './views/Tax';
import Team from './views/Team';

export const ROUTES = [
  { group: 'Overzicht', items: [{ id: 'overzicht', label: 'Dashboard', icon: 'dashboard', sub: 'Prestaties van uw winkel in één oogopslag' }] },
  { group: 'Groei', items: [
    { id: 'onderzoek', label: 'Productonderzoek', icon: 'search', ai: true, sub: 'Vind winnende producten met AI-analyse' },
    { id: 'producten', label: 'Producten', icon: 'package', sub: 'Assortiment, prijzen, foto’s en CJ-koppeling' },
    { id: 'studio', label: 'AI-videostudio', icon: 'video', ai: true, sub: 'Advertentievideo’s voor elk platform' },
    { id: 'advertenties', label: 'Advertenties', icon: 'megaphone', sub: 'Campagnes op Meta, TikTok, Google en Pinterest' },
    { id: 'statistieken', label: 'Statistieken', icon: 'trending', sub: 'Bezoekers, funnel, producten en bronnen (alleen met toestemming)' },
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
    { id: 'team', label: 'Team & rollen', icon: 'users', sub: 'Medewerkers, rollen en het activiteitenlogboek' },
    { id: 'account', label: 'Mijn account', icon: 'lock', sub: 'Wachtwoord en tweestapsverificatie' },
    { id: 'checklist', label: 'Lanceringschecklist', icon: 'checklist', sub: 'Alles wat geregeld moet zijn vóór de lancering' }
  ] }
];
const ALL = ROUTES.flatMap(g => g.items);

/** Werkgebied per scherm (zie User::ROLES in de backend); 'any' = iedere medewerker. De server controleert dit ook: dit bepaalt alleen wat getoond wordt. */
const AREA: Record<string, string> = {
  overzicht: 'orders', onderzoek: 'products', producten: 'products', studio: 'marketing', advertenties: 'marketing', statistieken: 'marketing', nieuwsbrief: 'marketing',
  bestellingen: 'orders', retouren: 'returns', klantenservice: 'orders', automatisering: 'settings', privacy: 'privacy', belasting: 'tax', instellingen: 'settings',
  team: 'team', account: 'any', checklist: 'any'
};
const allowed = (me: StaffMe, id: string) => AREA[id] === 'any' || me.areas.includes(AREA[id] ?? '');

export default function AdminApp() {
  const [phase, setPhase] = useState<'loading' | 'login' | 'ready' | 'offline' | 'setup2fa'>('loading');
  const [status, setStatus] = useState<Status | null>(null);
  const [me, setMe] = useState<StaffMe | null>(null);
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
      const who = await api.admin.me();
      setMe(who);
      if (who.require2fa && !who.twoFactor.enabled) { setPhase('setup2fa'); return; } // verplichte 2FA: eerst instellen
      setStatus(await api.admin.status());
      if (who.areas.includes('orders')) await refresh();
      setPhase('ready');
    } catch (e) {
      if (e instanceof ApiError && (e.status === 401 || e.status === 403)) { adminToken.clear(); setPhase('login'); }
      else setPhase('offline');
    }
  }, [refresh]);

  useEffect(() => { connect(); }, [connect]);

  /** Geeft true terug als er nog een 2FA-code nodig is. */
  const login = async (pw: string, email: string, code: string): Promise<boolean> => {
    setLoginError('');
    try {
      const r = await api.admin.login(pw, email, code);
      adminToken.set(r.token);
      setPhase('loading');
      connect();
    } catch (e) {
      if (e instanceof ApiError && e.status === 422 && (e.data as { error?: string } | null)?.error === 'two_factor_required') return true;
      setLoginError(e instanceof ApiError && e.status === 0 ? 'De server is niet bereikbaar.' : e instanceof ApiError && e.status === 429 ? 'Te veel pogingen. Probeer het later opnieuw.' : e instanceof ApiError && e.status === 401 && code ? 'Onjuiste of verlopen code.' : 'Onjuiste inloggegevens.');
    }
    return false;
  };

  if (phase === 'loading') return <div className="m-wrap" style={{ background: 'var(--dash-bg)' }}><p className="muted">Verbinden…</p></div>;
  if (phase === 'login') return <Login onLogin={login} error={loginError} />;
  if (phase === 'setup2fa' && me) return (
    <div className="m-wrap" style={{ background: 'var(--dash-bg)', alignItems: 'flex-start', overflow: 'auto' }}>
      <div style={{ width: 'min(760px,100%)', padding: 24 }}>
        <Account me={me} forced onMe={m => { setMe(m); if (m.twoFactor.enabled) setTimeout(() => { setPhase('loading'); void connect(); }, 0); }} />
        <button className="btn btn-ghost" style={{ marginTop: 16 }} onClick={async () => { try { await api.admin.logout(); } catch { /* */ } adminToken.clear(); setPhase('login'); }}>Uitloggen</button>
      </div>
    </div>
  );
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
    me: me!,
    can: (area: string) => me!.areas.includes(area),
    setMe,
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

function Login({ onLogin, error }: { onLogin: (pw: string, email: string, code: string) => Promise<boolean>; error: string }) {
  const [pw, setPw] = useState('');
  const [email, setEmail] = useState('');
  const [code, setCode] = useState('');
  const [needCode, setNeedCode] = useState(false);
  const [busy, setBusy] = useState(false);
  const submit = async (e: FormEvent) => { e.preventDefault(); setBusy(true); const more = await onLogin(pw, email.trim(), code.trim()); if (more) setNeedCode(true); setBusy(false); };
  return (
    <div className="m-wrap" style={{ background: '#121419' }}>
      <motion.form className="m-card" onSubmit={submit} style={{ width: 'min(400px,100%)', padding: 32 }} initial={{ opacity: 0, y: 20, scale: 0.98 }} animate={{ opacity: 1, y: 0, scale: 1 }}>
        <div style={{ fontFamily: 'var(--serif)', fontSize: 24, fontWeight: 600, display: 'flex', gap: 10, alignItems: 'center', marginBottom: 20 }}>
          <span style={{ width: 30, height: 30, borderRadius: 8, background: 'var(--solid)', color: 'var(--on-solid)', display: 'grid', placeItems: 'center' }}>R</span>Rebazz Beheer
        </div>
        <div className="field" style={{ marginBottom: 12 }}><label htmlFor="adminEmail">E-mail <span className="muted">(leeg laten als eigenaar)</span></label><input className="input" id="adminEmail" type="email" autoComplete="username" value={email} onChange={e => setEmail(e.target.value)} disabled={needCode} /></div>
        <div className="field"><label htmlFor="adminPw">Wachtwoord</label><input className="input" id="adminPw" type="password" autoComplete="current-password" required value={pw} onChange={e => setPw(e.target.value)} autoFocus disabled={needCode} /></div>
        {needCode && <div className="field" style={{ marginTop: 12 }}><label htmlFor="adminCode">Code uit uw authenticator-app (of herstelcode)</label><input className="input" id="adminCode" autoComplete="one-time-code" required autoFocus value={code} onChange={e => setCode(e.target.value)} /></div>}
        <AnimatePresence>{error && <motion.p className="hint" style={{ color: 'var(--danger)', marginTop: 10 }} initial={{ opacity: 0, x: -6 }} animate={{ opacity: 1, x: [6, -4, 2, 0] }} exit={{ opacity: 0 }}>{error}</motion.p>}</AnimatePresence>
        <MButton className="btn btn-primary btn-block" style={{ marginTop: 18 }} type="submit" disabled={busy}>{busy ? 'Bezig…' : 'Inloggen'}</MButton>
        <p className="hint" style={{ marginTop: 14 }}>Eigenaar: het wachtwoord staat in het <code>.env</code>-bestand van de server als <b>ADMIN_PASSWORD</b>. Medewerkers loggen in met hun e-mailadres.</p>
      </motion.form>
    </div>
  );
}

function Shell({ onLogout }: { onLogout: () => void }) {
  const location = useLocation();
  const navigate = useNavigate();
  const toast = useToast();
  const [open, setOpen] = useState(false);
  const current = location.pathname.split('/')[2] || '';
  const meta = ALL.find(r => r.id === current) ?? ALL.find(r => r.id === 'overzicht')!;
  const ctx = useAdminCounts();
  const { me, setMe } = useAdmin();
  const visible = ROUTES.map(g => ({ ...g, items: g.items.filter(it => allowed(me, it.id)) })).filter(g => g.items.length > 0);
  const home = visible[0]?.items[0]?.id ?? 'account';
  const initials = me.name.split(/\s+/).map(w => w[0]).join('').slice(0, 2).toUpperCase() || 'EI';

  useEffect(() => { document.title = meta.label + ' — Rebazz Beheer'; setOpen(false); window.scrollTo(0, 0); }, [meta.label]);

  return (
    <div className="app">
      <aside className={'sidebar' + (open ? ' open' : '')}>
        <Link to="/beheer" className="logo"><span className="logo-mark">R</span>Rebazz<small>Beheer</small></Link>
        <nav aria-label="Dashboardnavigatie">
          {visible.map(g => (
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
            <div className="avatar" title={me.name + ' · ' + me.email}>{initials}</div>
          </div>
        </header>
        <AnimatePresence mode="wait">
          <motion.main key={current} className="view" initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -6 }} transition={{ duration: 0.2 }}>
            <Routes>
              <Route index element={<Navigate to={'/beheer/' + home} replace />} />
              <Route path="overzicht" element={<Guard id="overzicht"><Overview /></Guard>} />
              <Route path="onderzoek" element={<Guard id="onderzoek"><Research /></Guard>} />
              <Route path="producten" element={<Guard id="producten"><Products /></Guard>} />
              <Route path="studio" element={<Guard id="studio"><Studio /></Guard>} />
              <Route path="advertenties" element={<Guard id="advertenties"><Ads /></Guard>} />
              <Route path="bestellingen" element={<Guard id="bestellingen"><Orders /></Guard>} />
              <Route path="retouren" element={<Guard id="retouren"><Returns /></Guard>} />
              <Route path="klantenservice" element={<Guard id="klantenservice"><Support /></Guard>} />
              <Route path="automatisering" element={<Guard id="automatisering"><Automations /></Guard>} />
              <Route path="statistieken" element={<Guard id="statistieken"><Stats /></Guard>} />
              <Route path="nieuwsbrief" element={<Guard id="nieuwsbrief"><Newsletter /></Guard>} />
              <Route path="privacy" element={<Guard id="privacy"><Privacy /></Guard>} />
              <Route path="belasting" element={<Guard id="belasting"><Tax /></Guard>} />
              <Route path="instellingen" element={<Guard id="instellingen"><Settings /></Guard>} />
              <Route path="team" element={<Guard id="team"><Team /></Guard>} />
              <Route path="account" element={<Account me={me} onMe={setMe} />} />
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

/** Toont een scherm alleen als de rol het mag; anders terug naar het eerste toegestane scherm (de server weigert het in elk geval). */
function Guard({ id, children }: { id: string; children: React.ReactNode }) {
  const { me } = useAdmin();
  return allowed(me, id) ? <>{children}</> : <Navigate to="/beheer" replace />;
}

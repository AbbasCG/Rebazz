import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Icon, MButton, useToast } from '../../components/ui';
import { api } from '../../lib/api';
import type { AnalyticsReport } from '../../lib/types';
import { eur } from '../context';
import { Kpi } from '../parts';

const DEVICE: Record<string, string> = { mobile: 'Mobiel', tablet: 'Tablet', desktop: 'Computer', unknown: 'Onbekend' };
const n0 = (n: number) => String(Math.round(n));
const pct = (a: number, b: number) => (b > 0 ? ((a / b) * 100).toFixed(1).replace('.', ',') + '%' : '—');

/** Statistieken uit eigen, anonieme metingen: alleen bezoekers die in de cookiebanner "analytisch" toestonden zijn zichtbaar. */
export default function Stats() {
  const toast = useToast();
  const [days, setDays] = useState(30);
  const [r, setR] = useState<AnalyticsReport | null>(null);

  useEffect(() => {
    let alive = true;
    api.admin.analytics(days).then(x => { if (alive) setR(x); }).catch(e => toast('Statistieken laden mislukt: ' + (e as Error).message, 'error'));
    return () => { alive = false; };
  }, [days, toast]);

  if (!r) return <div className="skeleton" style={{ height: 200 }} />;
  const f = r.funnel;
  const steps: [string, number][] = [['Bezoekers', f.visitors], ['Product bekeken', f.product], ['In winkelwagen', f.cart], ['Naar de kassa', f.checkout], ['Gekocht', f.purchase]];
  const max = Math.max(1, ...r.series.map(s => s.visitors));
  const none = r.totals.visitors === 0;

  return (
    <>
      <div className="page-intro">
        <p>Alleen bezoekers die in de cookiebanner <b>analytisch</b> toestonden (en geen "Do Not Track" aan hebben) worden gemeten: zonder IP-adres, zonder derden, {r.retentionMonths} maanden bewaard. De cijfers zijn dus een <b>steekproef</b>; echte omzet ziet u bij Bestellingen.</p>
        <div style={{ display: 'flex', gap: 8 }}>
          {[7, 30, 90].map(d => <MButton key={d} className={'btn ' + (d === days ? 'btn-primary' : 'btn-outline')} onClick={() => setDays(d)}>{d} dagen</MButton>)}
        </div>
      </div>

      <div className="kpis">
        <Kpi label="Bezoekers" value={r.totals.visitors} format={n0} delta={r.totals.sessions + ' sessies'} icon="users" />
        <Kpi label="Paginaweergaven" value={r.totals.pageviews} format={n0} delta={r.totals.visitors ? (r.totals.pageviews / r.totals.visitors).toFixed(1).replace('.', ',') + ' per bezoeker' : 'Nog geen metingen'} icon="search" />
        <Kpi label="Conversie (gemeten)" value={f.visitors ? (f.purchase / f.visitors) * 100 : 0} format={n => n.toFixed(1).replace('.', ',') + '%'} delta={f.purchase + ' van ' + f.visitors + ' bezoekers kocht'} icon="trending" />
        <Kpi label="Bestellingen (alle)" value={r.orders.count} format={n0} delta={eur(r.orders.revenue) + ' · ' + r.tracked.purchases + ' gemeten'} icon="cart" />
      </div>

      {none && (
        <div className="card" style={{ padding: 16, margin: '16px 0' }}>
          <b>Nog geen metingen.</b>
          <p className="muted" style={{ marginTop: 6 }}>Open de winkel, kies in de cookiebanner <i>Alles accepteren</i> (of zet Analytisch aan) en blader wat rond. Zonder toestemming meten we niets. Zet in de browser "Do Not Track" of Global Privacy Control uit om uzelf te meten. Zie ook <Link to="/beheer/privacy">Privacy</Link>.</p>
        </div>
      )}

      <div className="grid-main" style={{ margin: '20px 0' }}>
        <div className="card">
          <div className="card-head"><div><h3>Bezoekers per dag</h3><p>Unieke bezoekers, laatste {r.days} dagen</p></div></div>
          <div className="card-body">
            <svg viewBox={'0 0 ' + r.series.length * 10 + ' 80'} preserveAspectRatio="none" style={{ width: '100%', height: 160 }} role="img" aria-label="Bezoekers per dag">
              {r.series.map((s, i) => {
                const h = (s.visitors / max) * 74;
                return <rect key={s.date} x={i * 10 + 1} y={78 - h} width={8} height={Math.max(h, s.visitors ? 1.5 : 0.5)} rx={1.5} fill="var(--accent)" opacity={s.visitors ? 1 : 0.25}><title>{s.date}: {s.visitors} bezoekers, {s.pageviews} weergaven</title></rect>;
              })}
            </svg>
          </div>
        </div>
        <div className="card">
          <div className="card-head"><div><h3>Funnel</h3><p>Unieke bezoekers per stap</p></div></div>
          <div className="card-body" style={{ display: 'grid', gap: 10 }}>
            {steps.map(([label, v], i) => (
              <div key={label}>
                <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 13.5 }}><span>{label}</span><b>{v}{i > 0 && <span className="muted"> · {pct(v, steps[i - 1][1])}</span>}</b></div>
                <div style={{ height: 8, borderRadius: 4, background: 'var(--surface-2)', marginTop: 4 }}><div style={{ height: 8, borderRadius: 4, background: 'var(--accent)', width: (f.visitors ? (v / f.visitors) * 100 : 0) + '%' }} /></div>
              </div>
            ))}
          </div>
        </div>
      </div>

      <div className="grid-2" style={{ marginBottom: 20 }}>
        <div className="card">
          <div className="card-head"><div><h3>Producten</h3><p>Meest bekeken</p></div></div>
          <div className="table-wrap"><table className="t"><thead><tr><th>Product</th><th className="num">Bekeken</th><th className="num">In wagen</th></tr></thead><tbody>
            {r.products.length === 0 && <tr><td colSpan={3} className="muted" style={{ textAlign: 'center', padding: 20 }}>Geen gegevens</td></tr>}
            {r.products.map(p => <tr key={p.id}><td>{p.name}</td><td className="num">{p.views}</td><td className="num">{p.carts}</td></tr>)}
          </tbody></table></div>
        </div>
        <div className="card">
          <div className="card-head"><div><h3>Bronnen</h3><p>Waar bezoekers vandaan komen (sessies)</p></div></div>
          <div className="table-wrap"><table className="t"><thead><tr><th>Bron</th><th className="num">Sessies</th><th className="num">Gekocht</th><th className="num">Omzet</th></tr></thead><tbody>
            {r.sources.length === 0 && <tr><td colSpan={4} className="muted" style={{ textAlign: 'center', padding: 20 }}>Geen gegevens</td></tr>}
            {r.sources.map(s => <tr key={s.source}><td>{s.source}</td><td className="num">{s.sessions}</td><td className="num">{s.purchases}</td><td className="num">{eur(s.revenue)}</td></tr>)}
          </tbody></table></div>
        </div>
      </div>

      <div className="grid-2" style={{ marginBottom: 20 }}>
        <div className="card">
          <div className="card-head"><div><h3>Pagina's</h3><p>Meest bekeken</p></div></div>
          <div className="table-wrap"><table className="t"><thead><tr><th>Pagina</th><th className="num">Weergaven</th><th className="num">Bezoekers</th></tr></thead><tbody>
            {r.pages.length === 0 && <tr><td colSpan={3} className="muted" style={{ textAlign: 'center', padding: 20 }}>Geen gegevens</td></tr>}
            {r.pages.map(p => <tr key={p.path}><td>{p.path}</td><td className="num">{p.views}</td><td className="num">{p.visitors}</td></tr>)}
          </tbody></table></div>
        </div>
        <div className="card">
          <div className="card-head"><div><h3>Campagnes en apparaten</h3><p>Gebruik <code>?utm_source=…&amp;utm_medium=…&amp;utm_campaign=…</code> in uw links</p></div></div>
          <div className="table-wrap"><table className="t"><thead><tr><th>Campagne</th><th className="num">Sessies</th><th className="num">Gekocht</th><th className="num">Omzet</th></tr></thead><tbody>
            {r.campaigns.length === 0 && <tr><td colSpan={4} className="muted" style={{ textAlign: 'center', padding: 20 }}>Geen campagnes met utm-parameters</td></tr>}
            {r.campaigns.map(c => <tr key={c.campaign + c.source + c.medium}><td>{c.campaign}<br /><span className="muted" style={{ fontSize: 12 }}>{[c.source, c.medium].filter(Boolean).join(' / ')}</span></td><td className="num">{c.sessions}</td><td className="num">{c.purchases}</td><td className="num">{eur(c.revenue)}</td></tr>)}
          </tbody></table></div>
          <div className="card-body" style={{ display: 'flex', gap: 14, flexWrap: 'wrap', fontSize: 13.5 }}>
            <Icon name="info" size={16} />{r.devices.length === 0 ? 'Geen apparaatgegevens' : r.devices.map(d => (DEVICE[d.device] ?? d.device) + ': ' + d.sessions).join(' · ')}
          </div>
        </div>
      </div>
    </>
  );
}

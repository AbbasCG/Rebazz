import { useMemo, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { Icon, ProductVisual } from '../../components/ui';
import { useShop } from '../../lib/shop';
import { eur, fmtDate, profit, useAdmin } from '../context';
import { Kpi, StatusBadge } from '../parts';

export default function Overview() {
  const { orders, status } = useAdmin();
  const { catalog } = useShop();
  const paid = orders.filter(o => !o.refunded);
  const recent = paid.filter(o => Date.now() - new Date(o.date).getTime() < 30 * 864e5);
  const revenue = recent.reduce((s, o) => s + o.total, 0);
  const profit30 = recent.reduce((s, o) => s + profit(o), 0);
  const toProcess = orders.filter(o => o.step === 0 && !o.refunded);
  const attention = orders.filter(o => !o.refunded && ((o.delayed && o.step < 4) || o.cjStatus === 'error' || o.cjStatus === 'manual'));

  const series = useMemo(() => {
    const days: { date: Date; revenue: number }[] = [];
    for (let i = 29; i >= 0; i--) {
      const d = new Date(); d.setHours(0, 0, 0, 0); d.setDate(d.getDate() - i);
      const key = d.toDateString();
      days.push({ date: d, revenue: paid.filter(o => new Date(o.date).toDateString() === key).reduce((s, o) => s + o.total, 0) });
    }
    return days;
  }, [paid]);

  const sold: Record<string, number> = {};
  paid.forEach(o => o.items.forEach(i => { sold[i.id] = (sold[i.id] || 0) + i.qty; }));
  const top = catalog.products.map(p => ({ p, n: sold[p.id] || 0 })).sort((a, b) => b.n - a.n).slice(0, 5);

  return (
    <>
      <div className="kpis">
        <Kpi label="Omzet (30 dagen)" value={revenue} format={eur} delta="Betaald, excl. terugbetalingen" icon="euro" />
        <Kpi label="Bestellingen (30 dagen)" value={recent.length} format={n => String(Math.round(n))} delta={orders.length + ' in totaal'} icon="cart" />
        <Kpi label="Geschatte winst (30 dagen)" value={profit30} format={eur} delta="Na inkoop en transactiekosten" icon="trending" good={profit30 >= 0} />
        <Kpi label="Te verwerken" value={toProcess.length} format={n => String(Math.round(n))} delta={toProcess.length ? 'Bestellen bij de leverancier' : 'Alles is verwerkt'} icon="package" good={!toProcess.length} />
      </div>

      <div className="grid-main" style={{ marginBottom: 20 }}>
        <div className="card">
          <div className="card-head"><div><h3>Omzet per dag</h3><p>Laatste 30 dagen</p></div><div className="legend"><span><i style={{ background: 'var(--accent)' }} />Omzet</span></div></div>
          <div className="card-body" style={{ position: 'relative' }}><Chart series={series} /></div>
        </div>
        <div className="card">
          <div className="card-head"><div><h3>Aandachtspunten</h3><p>Zaken die uw actie vereisen</p></div></div>
          <div className="card-body"><div className="list">
            {toProcess.length > 0 && <Attn kind="ok" icon="cart" title={toProcess.length + ' nieuwe bestelling(en)'} text={status.cj ? 'Worden automatisch bij CJ geplaatst.' : 'Bestel deze bij de leverancier.'} />}
            {attention.map(o => <Attn key={o.id} kind={o.cjStatus === 'error' || o.cjStatus === 'manual' ? 'danger' : 'warn'} icon={o.delayed ? 'clock' : 'alert'} title={(o.delayed ? 'Vertraging bij ' : 'Actie nodig bij ') + o.id} text={o.cjError || ((o.carrier || 'Vervoerder') + ' · klant informeren aanbevolen.')} />)}
            {!status.cj && <Attn kind="warn" icon="link" title="CJdropshipping niet gekoppeld" text="Vul CJ_API_KEY in om automatisch te bestellen." />}
            {status.stripe === 'missing' && <Attn kind="danger" icon="card" title="Stripe niet ingesteld" text="Vul STRIPE_SECRET in het .env-bestand in." />}
            {!toProcess.length && !attention.length && status.cj && status.stripe !== 'missing' && <p className="muted">Er zijn geen aandachtspunten.</p>}
          </div></div>
        </div>
      </div>

      <div className="grid-main">
        <div className="card">
          <div className="card-head"><div><h3>Recente bestellingen</h3><p>{toProcess.length} wachten op verwerking</p></div><Link to="/beheer/bestellingen" className="btn btn-outline btn-sm">Alle bestellingen</Link></div>
          <div className="card-body" style={{ padding: 8 }}><div className="table-wrap"><table className="t">
            <thead><tr><th>Bestelling</th><th>Klant</th><th>Status</th><th className="num">Totaal</th></tr></thead>
            <tbody>
              {orders.slice(0, 6).map(o => (
                <tr key={o.id}><td><b>{o.id}</b><br /><span className="muted" style={{ fontSize: 12 }}>{fmtDate(o.date)}</span></td><td>{o.customer}<br /><span className="muted" style={{ fontSize: 12 }}>{o.country}</span></td><td><StatusBadge o={o} /></td><td className="num">{eur(o.total)}</td></tr>
              ))}
              {!orders.length && <tr><td colSpan={4} className="muted" style={{ textAlign: 'center', padding: 28 }}>Nog geen bestellingen. Zodra iemand betaalt, verschijnt de bestelling hier.</td></tr>}
            </tbody>
          </table></div></div>
        </div>
        <div className="card">
          <div className="card-head"><div><h3>Bestsellers</h3><p>Op basis van verkochte stuks</p></div></div>
          <div className="card-body"><div className="list">
            {top.map(({ p, n }) => (
              <div className="list-item" key={p.id}><div className="prod-cell" style={{ minWidth: 0, flex: 1 }}><ProductVisual p={p} /><div><b>{p.name}</b><span>{n} verkocht</span></div></div><b>{eur(p.price)}</b></div>
            ))}
          </div></div>
        </div>
      </div>
    </>
  );
}

function Attn({ kind, icon, title, text }: { kind: string; icon: string; title: string; text: string }) {
  return <div className="list-item"><div className={'ic ' + kind}><Icon name={icon} size={17} /></div><div><b>{title}</b><p>{text}</p></div></div>;
}

function Chart({ series }: { series: { date: Date; revenue: number }[] }) {
  const W = 720, H = 240, pl = 52, pr = 10, pt = 10, pb = 26;
  const max = Math.max(100, Math.ceil(Math.max(...series.map(d => d.revenue)) / 100) * 100);
  const x = (i: number) => pl + (i * (W - pl - pr)) / (series.length - 1);
  const y = (v: number) => pt + (H - pt - pb) * (1 - v / max);
  const line = series.map((d, i) => (i ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(d.revenue).toFixed(1)).join(' ');
  const [hover, setHover] = useState<number | null>(null);
  const ref = useRef<SVGSVGElement>(null);
  return (
    <div style={{ position: 'relative' }}>
      <svg ref={ref} className="chart" viewBox={`0 0 ${W} ${H}`} preserveAspectRatio="none" role="img" aria-label="Omzet per dag"
        onMouseMove={e => { const r = ref.current!.getBoundingClientRect(); const rel = ((e.clientX - r.left) / r.width) * W; setHover(Math.max(0, Math.min(series.length - 1, Math.round((rel - pl) / ((W - pl - pr) / (series.length - 1)))))); }}
        onMouseLeave={() => setHover(null)}>
        <defs><linearGradient id="area" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stopColor="var(--accent)" stopOpacity=".2" /><stop offset="1" stopColor="var(--accent)" stopOpacity="0" /></linearGradient></defs>
        {[0, 1, 2, 3, 4].map(k => { const v = (max * k) / 4; return <g key={k}><line className="grid-line" x1={pl} x2={W - pr} y1={y(v)} y2={y(v)} /><text x={pl - 8} y={y(v) + 4} textAnchor="end">€{v >= 1000 ? (v / 1000).toFixed(1) + 'k' : Math.round(v)}</text></g>; })}
        {series.map((d, i) => (i % 6 === 0 || i === series.length - 1) && <text key={i} x={x(i)} y={H - 6} textAnchor="middle">{d.date.toLocaleDateString('nl-NL', { day: 'numeric', month: 'short' })}</text>)}
        <path d={line + ` L${x(series.length - 1)} ${y(0)} L${x(0)} ${y(0)} Z`} fill="url(#area)" />
        <path d={line} fill="none" stroke="var(--accent)" strokeWidth="2.2" vectorEffect="non-scaling-stroke" strokeLinejoin="round" />
        {hover !== null && <line x1={x(hover)} x2={x(hover)} y1={pt} y2={H - pb} stroke="var(--line-strong)" vectorEffect="non-scaling-stroke" />}
      </svg>
      {hover !== null && (
        <div className="chart-tip" style={{ left: (x(hover) / W) * 100 + '%', top: 30 }}>
          <b>{series[hover].date.toLocaleDateString('nl-NL', { weekday: 'short', day: 'numeric', month: 'short' })}</b><br />Omzet {eur(series[hover].revenue)}
        </div>
      )}
    </div>
  );
}

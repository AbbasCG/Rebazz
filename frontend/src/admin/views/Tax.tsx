import { motion } from 'motion/react';
import { useCallback, useEffect, useState } from 'react';
import { Icon, MButton, useToast } from '../../components/ui';
import { api, type TaxSummary } from '../../lib/api';
import { COUNTRIES } from '../../lib/i18n';
import { download, eur } from '../context';
import { Kpi } from '../parts';

const qLabel = (q: number) => (q ? 'K' + q : 'Heel jaar');
const regimeLabel = { nl: <span className="badge info plain">Aangifte NL</span>, oss: <span className="badge accent plain">OSS</span>, export: <span className="badge plain">Export (0%)</span> };

export default function Tax() {
  const toast = useToast();
  const [year, setYear] = useState(new Date().getFullYear());
  const [quarter, setQuarter] = useState(Math.floor(new Date().getMonth() / 3) + 1);
  const [data, setData] = useState<TaxSummary | null>(null);
  const [inputVat, setInputVat] = useState('');

  const load = useCallback(async () => {
    try { const d = await api.admin.tax(year, quarter); setData(d); setInputVat(d.inputVat ? d.inputVat.toFixed(2) : ''); }
    catch (e) { toast('Laden mislukt: ' + (e as Error).message, 'error'); }
  }, [year, quarter, toast]);
  useEffect(() => { load(); }, [load]);

  const saveSetting = async (key: string, value: unknown, msg: string) => {
    try { await api.admin.saveSetting(key, value); toast(msg); load(); } catch (e) { toast('Opslaan mislukt: ' + (e as Error).message, 'error'); }
  };

  if (!data) return <div className="kpis">{[0, 1, 2, 3].map(i => <div key={i} className="kpi skeleton" style={{ height: 112 }} />)}</div>;
  const pctThreshold = Math.min(100, (data.crossBorderYear / data.threshold) * 100);
  const periodKey = year + '-' + (quarter || 'jaar');

  return (
    <>
      <div className="page-intro">
        <p>Overzicht van de btw die in uw verkopen zit, per kwartaal en per land. Berekend door de server uit de bestellingen in de database, exclusief terugbetaalde bestellingen.</p>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center' }}>
          <select className="select" style={{ width: 'auto', height: 38 }} aria-label="Jaar" value={year} onChange={e => setYear(parseInt(e.target.value))}>{data.years.map(y => <option key={y}>{y}</option>)}</select>
          <MButton className="btn btn-outline btn-sm" onClick={() => {
            const fmt = (n: number) => n.toFixed(2).replace('.', ',');
            const rows: (string | number)[][] = [['Bestelling', 'Datum', 'Klant', 'Land', 'Aangifte', 'Btw-tarief', 'Bedrag incl. btw', 'Bedrag excl. btw', 'Btw', 'Inkoop']];
            data.lines.forEach(l => rows.push([l.order, new Date(l.date).toLocaleDateString('nl-NL'), l.customer, l.country, l.regime.toUpperCase(), String(l.rate).replace('.', ','), fmt(l.incl), fmt(l.excl), fmt(l.vat), fmt(l.cost)]));
            rows.push(['Totaal', '', '', '', '', '', fmt(data.totals.incl), fmt(data.totals.excl), fmt(data.totals.vat), '']);
            download('btw-' + year + '-' + qLabel(quarter).replace(' ', '-').toLowerCase() + '.csv', rows);
            toast('Btw-overzicht gedownload (te openen in Excel)');
          }}><Icon name="package" size={15} />Exporteren voor boekhouder</MButton>
        </div>
      </div>
      <div className="card" style={{ marginBottom: 20 }}>
        <div className="tabs" role="tablist">
          {[1, 2, 3, 4, 0].map(q => (
            <button key={q} className={'tab' + (q === quarter ? ' active' : '')} role="tab" aria-selected={q === quarter} onClick={() => setQuarter(q)} style={{ position: 'relative' }}>
              {qLabel(q)}{q === quarter && <motion.span layoutId="taxTab" style={{ position: 'absolute', left: 0, right: 0, bottom: -1, height: 2, background: 'var(--ink)' }} />}
            </button>
          ))}
        </div>
      </div>
      <div className="kpis">
        <Kpi label="Omzet incl. btw" value={data.totals.incl} format={eur} delta={data.totals.orders + ' bestelling(en) · ' + qLabel(quarter) + ' ' + year} icon="euro" />
        <Kpi label="Omzet excl. btw" value={data.totals.excl} format={eur} delta="Basis voor winst en aangifte" icon="trending" />
        <Kpi label="Btw Nederland (te betalen)" value={data.nl.pay} format={eur} delta={'Na aftrek voorbelasting ' + eur(data.inputVat)} icon="receipt" good={data.nl.pay <= 0} />
        <Kpi label="Btw OSS (te betalen)" value={data.oss.vat} format={eur} delta={data.useOss ? data.oss.orders + ' EU-bestelling(en) buiten NL' : 'OSS staat uit'} icon="globe" />
      </div>
      <div className="grid-main" style={{ marginBottom: 20 }}>
        <div className="card">
          <div className="card-head"><div><h3>Btw per land</h3><p>{qLabel(quarter)} {year} · verzendkosten en kortingen zijn meegerekend</p></div></div>
          <div className="card-body" style={{ padding: 8 }}><div className="table-wrap"><table className="t">
            <thead><tr><th>Land</th><th>Aangifte</th><th className="num">Tarief</th><th className="num">Best.</th><th className="num">Incl. btw</th><th className="num">Excl. btw</th><th className="num">Btw</th></tr></thead>
            <tbody>
              {data.rows.map(r => <tr key={r.country + r.regime}><td>{COUNTRIES[r.country] ?? r.country}</td><td>{regimeLabel[r.regime]}</td><td className="num">{String(r.rate).replace('.', ',')}%</td><td className="num">{r.orders}</td><td className="num">{eur(r.incl)}</td><td className="num">{eur(r.excl)}</td><td className="num"><b>{eur(r.vat)}</b></td></tr>)}
              {data.rows.length ? <tr><td colSpan={4}><b>Totaal</b></td><td className="num"><b>{eur(data.totals.incl)}</b></td><td className="num"><b>{eur(data.totals.excl)}</b></td><td className="num"><b>{eur(data.totals.vat)}</b></td></tr>
                : <tr><td colSpan={7} className="muted" style={{ textAlign: 'center', padding: 28 }}>Geen bestellingen in deze periode.</td></tr>}
            </tbody>
          </table></div></div>
        </div>
        <div className="stack-20">
          <div className="card">
            <div className="card-head"><div><h3>Aangifte Nederland</h3><p>Rubriek 1a — leveringen hoog tarief</p></div></div>
            <div className="card-body">
              <div className="kv">
                <span>Omzet (excl. btw)</span><b>{eur(data.nl.excl)}</b>
                <span>Btw 21%</span><b>{eur(data.nl.vat)}</b>
                <span>Voorbelasting</span>
                <span><input className="input price-input" id="inputVat" type="number" min={0} step="0.01" placeholder="0,00" aria-label="Voorbelasting" style={{ width: 120, textAlign: 'start' }}
                  value={inputVat} onChange={e => setInputVat(e.target.value)}
                  onBlur={() => saveSetting('tax.input.' + periodKey, Math.max(0, parseFloat(inputVat) || 0), 'Voorbelasting opgeslagen voor ' + qLabel(quarter) + ' ' + year)} /></span>
                <span>Te betalen</span><b className={data.nl.pay > 0 ? 'down' : 'up'}>{eur(data.nl.pay)}</b>
                <span>Export buiten EU</span><span style={{ color: 'var(--ink)' }}>{eur(data.export.incl)} (rubriek 3a, 0%)</span>
              </div>
              <p className="hint" style={{ marginTop: 10 }}>Voorbelasting = btw op uw zakelijke kosten met een Nederlandse factuur. Inkopen bij CJ hebben meestal geen Nederlandse btw.</p>
            </div>
          </div>
          <div className="card">
            <div className="card-head"><div><h3>OSS-regeling</h3><p>Btw van het land van de klant, via één aangifte</p></div>
              <label className="switch"><input type="checkbox" checked={data.useOss} onChange={e => saveSetting('tax.oss', e.target.checked, e.target.checked ? 'OSS staat aan' : 'OSS staat uit')} /><span /></label></div>
            <div className="card-body">
              <p style={{ fontSize: 13.5 }}>Verkopen aan consumenten in andere EU-landen {year}: <b>{eur(data.crossBorderYear)}</b> van de drempel van {eur(data.threshold)} (excl. btw).</p>
              <div className="progress-big"><motion.i initial={{ width: 0 }} animate={{ width: pctThreshold + '%' }} transition={{ duration: 0.8, ease: 'easeOut' }} style={{ background: data.crossBorderYear > data.threshold ? 'var(--warn)' : 'var(--success)' }} /></div>
              <p className="hint" style={{ marginTop: 10 }}>{data.crossBorderYear > data.threshold ? 'De drempel is overschreden: u moet de btw van het land van de klant rekenen (OSS).' : 'Onder de drempel mag u ook Nederlandse btw rekenen; met OSS rekent u direct het tarief van het land van de klant.'} Aanmelden voor OSS doet u bij de Belastingdienst.</p>
            </div>
          </div>
          <div className="card">
            <div className="card-head"><div><h3>Deadlines</h3><p>Kwartaalaangifte</p></div></div>
            <div className="card-body"><div className="list">
              {data.deadlines.map(d => {
                const date = new Date(d.date); const days = Math.ceil((date.getTime() - Date.now()) / 864e5);
                return <div className="list-item" key={d.quarter}><div className={'ic ' + (days < 0 ? '' : days <= 14 ? 'warn' : 'ok')}><Icon name="clock" size={16} /></div><div><b>Btw-aangifte en OSS-aangifte K{d.quarter} {year}</b><p>Uiterlijk {date.toLocaleDateString('nl-NL', { day: 'numeric', month: 'long', year: 'numeric' })}{days >= 0 ? ' · nog ' + days + ' dagen' : ' · verstreken'}</p></div></div>;
              })}
            </div></div>
          </div>
        </div>
      </div>
      <div className="card"><div className="card-body" style={{ display: 'flex', gap: 12, alignItems: 'flex-start' }}><Icon name="info" size={18} /><p className="muted" style={{ fontSize: 13 }}>Dit overzicht is een hulpmiddel en geen belastingadvies. Het gaat uit van het standaardtarief per land. Bij dropshipping vanuit landen buiten de EU gelden extra regels (o.a. IOSS). Laat uw eerste aangiftes controleren door een boekhouder.</p></div></div>
    </>
  );
}

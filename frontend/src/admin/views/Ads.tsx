import { useEffect, useState } from 'react';
import { Icon, MButton, useToast } from '../../components/ui';
import { SEED_CAMPAIGNS } from '../../lib/i18n';
import { api } from '../../lib/api';
import { AI } from '../ai';
import { eur } from '../context';
import { Kpi, Modal } from '../parts';
import { useAdminProducts } from './Products';

interface Campaign { id: string; name: string; platform: string; product: string; budget: number; spend: number; revenue: number; status: 'active' | 'paused'; audience: string; example?: boolean }
const TARGET = 2.5;
const roas = (c: Campaign) => (c.spend ? c.revenue / c.spend : 0);
const SEED = (SEED_CAMPAIGNS as Campaign[]).map(c => ({ ...c, example: true }));

export default function Ads() {
  const toast = useToast();
  const [products] = useAdminProducts();
  const [list, setList] = useState<Campaign[]>([]);
  const [modal, setModal] = useState(false);
  const [form, setForm] = useState({ platform: 'Meta', goal: 'Verkopen', product: '', market: 'Nederland & België', budget: 30 });
  const [copy, setCopy] = useState<ReturnType<typeof AI.adCopy> | null>(null);

  useEffect(() => { api.admin.setting<Campaign[]>('campaigns').then(r => setList(r.value ?? SEED)).catch(() => setList(SEED)); }, []);
  const persist = async (next: Campaign[], msg: string) => { setList(next); try { await api.admin.saveSetting('campaigns', next); toast(msg); } catch { toast('Opslaan mislukt', 'error'); } };

  const spend = list.reduce((s, c) => s + c.spend, 0), rev = list.reduce((s, c) => s + c.revenue, 0);
  const prod = products.find(p => p.id === (form.product || products[0]?.id));

  return (
    <>
      <div className="kpis">
        <Kpi label="Advertentie-uitgaven" value={spend} format={eur} delta={list.filter(c => c.status === 'active').length + ' actieve campagnes'} icon="euro" />
        <Kpi label="Omzet uit advertenties" value={rev} format={eur} delta="Na koppeling: via Conversions API" icon="trending" />
        <Kpi label="ROAS" value={spend ? rev / spend : 0} format={n => n.toFixed(2).replace('.', ',')} delta={'Doel: ' + String(TARGET).replace('.', ',')} icon="megaphone" good={!spend || rev / spend >= TARGET} />
        <Kpi label="Campagnes" value={list.length} format={n => String(Math.round(n))} delta={list.some(c => c.example) ? 'Inclusief voorbeeldcampagnes' : 'Eigen campagnes'} icon="cart" />
      </div>
      <div className="card">
        <div className="card-head"><div><h3>Campagnes</h3><p>Voorbeeldcampagnes zijn gemarkeerd. Echte cijfers volgen na koppeling met Meta, TikTok en Google.</p></div><MButton className="btn btn-accent btn-sm" onClick={() => { setCopy(null); setModal(true); }}><Icon name="plus" size={15} />Nieuwe campagne</MButton></div>
        <div className="card-body" style={{ padding: 8 }}><div className="table-wrap"><table className="t">
          <thead><tr><th>Campagne</th><th>Platform</th><th className="num">Dagbudget</th><th className="num">Uitgegeven</th><th className="num">Omzet</th><th className="num">ROAS</th><th>Actief</th></tr></thead>
          <tbody>{list.map(c => (
            <tr key={c.id}>
              <td><b>{c.name}</b>{c.example && <span className="badge plain" style={{ marginInlineStart: 6, height: 18, fontSize: 10.5 }}>VOORBEELD</span>}<br /><span className="muted" style={{ fontSize: 12 }}>{c.audience}</span></td>
              <td><span className="badge plain">{c.platform}</span></td><td className="num">{eur(c.budget)}</td><td className="num">{eur(c.spend)}</td><td className="num">{eur(c.revenue)}</td>
              <td className="num"><b className={!c.spend ? '' : roas(c) >= TARGET ? 'up' : 'down'}>{c.spend ? roas(c).toFixed(2).replace('.', ',') : '—'}</b></td>
              <td><label className="switch"><input type="checkbox" checked={c.status === 'active'} onChange={e => persist(list.map(x => (x.id === c.id ? { ...x, status: e.target.checked ? 'active' : 'paused' } : x)), 'Campagne ' + (e.target.checked ? 'geactiveerd' : 'gepauzeerd'))} /><span /></label></td>
            </tr>
          ))}</tbody>
        </table></div></div>
      </div>
      <Modal title="Nieuwe campagne" open={modal} onClose={() => setModal(false)} footer={<><button className="btn btn-ghost" onClick={() => setModal(false)}>Annuleren</button><MButton className="btn btn-primary" onClick={() => {
        if (!prod) return;
        persist([{ id: 'c' + Date.now(), name: prod.name.split(' ').slice(0, 2).join(' ') + ' — ' + form.goal + ' ' + form.market, platform: form.platform, product: prod.id, budget: form.budget, spend: 0, revenue: 0, status: 'active', audience: form.market + ' · AI-doelgroep' }, ...list], 'Campagne is aangemaakt');
        setModal(false);
      }}>Campagne aanmaken</MButton></>}>
        <div className="form-grid">
          <div className="field"><label htmlFor="cPlatform">Platform</label><select className="select" id="cPlatform" value={form.platform} onChange={e => setForm({ ...form, platform: e.target.value })}>{['Meta', 'TikTok', 'Google', 'Pinterest', 'Snapchat'].map(x => <option key={x}>{x}</option>)}</select></div>
          <div className="field"><label htmlFor="cGoal">Doel</label><select className="select" id="cGoal" value={form.goal} onChange={e => setForm({ ...form, goal: e.target.value })}>{['Verkopen', 'Retargeting', 'Verkeer'].map(x => <option key={x}>{x}</option>)}</select></div>
          <div className="field" style={{ gridColumn: '1/-1' }}><label htmlFor="cProduct">Product</label><select className="select" id="cProduct" value={prod?.id ?? ''} onChange={e => setForm({ ...form, product: e.target.value })}>{products.map(p => <option key={p.id} value={p.id}>{p.name}</option>)}</select></div>
          <div className="field"><label htmlFor="cMarket">Landen</label><select className="select" id="cMarket" value={form.market} onChange={e => setForm({ ...form, market: e.target.value })}>{['Nederland & België', 'Europese Unie', 'VK, VS & Canada', 'Wereldwijd'].map(x => <option key={x}>{x}</option>)}</select></div>
          <div className="field"><label htmlFor="cBudget">Dagbudget (€)</label><input className="input" id="cBudget" type="number" min={5} value={form.budget} onChange={e => setForm({ ...form, budget: parseFloat(e.target.value) || 30 })} /></div>
        </div>
        <button className="btn btn-outline" type="button" onClick={() => prod && setCopy(AI.adCopy(prod, form.goal))}><Icon name="wand" size={16} />Advertentieteksten genereren</button>
        {copy && <div><div className="section-label">3 varianten voor A/B-test</div>{copy.map((v, i) => <div key={i} style={{ border: '1px solid var(--line)', borderRadius: 12, padding: 14, marginBottom: 10 }}><span className="badge accent plain">Variant {'ABC'[i]}</span><p style={{ fontWeight: 700, margin: '8px 0 4px' }}>{v.headline}</p><p style={{ whiteSpace: 'pre-line', fontSize: 13.5, color: 'var(--ink-2)' }}>{v.text}</p><p className="hint" style={{ marginTop: 6 }}>Knop: {v.cta}</p></div>)}</div>}
      </Modal>
    </>
  );
}

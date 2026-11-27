import { AnimatePresence, motion } from 'motion/react';
import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Icon, MButton, ProductVisual, useToast } from '../../components/ui';
import { api } from '../../lib/api';
import type { Product } from '../../lib/types';
import { eur, pct } from '../context';
import { Modal, useConfirm } from '../parts';

const margin = (p: Product) => (p.price ? (p.price - (p.cost ?? 0)) / p.price : 0);
export const emptyProduct = (): Product => ({
  id: 'p' + Date.now().toString(36), sku: 'RB-' + Date.now().toString(36).toUpperCase().slice(-6), name: '', category: 'Tech', price: 0, compare: null, cost: 0, image: '', icon: 'package',
  hue: Math.floor(Math.random() * 360), supplier: 'CJdropshipping', supplierUrl: '', cjVid: '', cjFrom: 'CN', cjLogistic: '', warehouse: 'China', days: [7, 12], stock: 100, trackStock: false,
  rating: 0, reviews: 0, sold: 0, status: 'draft', description: '', features: [], manufacturer: '', euResponsible: '', safetyInfo: ''
});

export function useAdminProducts(): [Product[], (p: Product[]) => void, boolean, () => Promise<void>] {
  const [products, setProducts] = useState<Product[]>([]);
  const [loading, setLoading] = useState(true);
  const load = async () => { const r = await api.admin.products(); setProducts(r.products); setLoading(false); };
  useEffect(() => { load().catch(() => setLoading(false)); }, []);
  return [products, setProducts, loading, load];
}

export default function Products() {
  const toast = useToast();
  const [products, setProducts, loading] = useAdminProducts();
  const [query, setQuery] = useState('');
  const [editing, setEditing] = useState<{ p: Product; isNew: boolean } | null>(null);
  const [ask, confirmNode] = useConfirm();

  const save = async (p: Product, isNew: boolean, msg = 'Product is opgeslagen') => {
    try {
      const r = await api.admin.saveProduct(p, isNew);
      setProducts(isNew ? [...products, r.product] : products.map(x => (x.id === r.product.id ? r.product : x)));
      toast(msg);
      return true;
    } catch (e) { toast('Opslaan mislukt: ' + (e as Error).message, 'error'); return false; }
  };
  const remove = async (p: Product) => {
    if (!(await ask('Weet u zeker dat u “' + p.name + '” wilt verwijderen? Het product verdwijnt direct uit de webwinkel.'))) return;
    try { await api.admin.deleteProduct(p.id); setProducts(products.filter(x => x.id !== p.id)); toast('Product is verwijderd'); }
    catch (e) { toast('Verwijderen mislukt: ' + (e as Error).message, 'error'); }
  };

  const list = products.filter(p => !query || (p.name + p.sku + p.category).toLowerCase().includes(query.toLowerCase()));
  const active = products.filter(p => p.status === 'active').length;

  return (
    <>
      <div className="page-intro">
        <p>{products.length} producten, waarvan {active} zichtbaar in de webwinkel. Wijzigingen worden direct in de database opgeslagen en zijn meteen zichtbaar voor klanten.</p>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          <Link to="/beheer/onderzoek" className="btn btn-outline"><Icon name="search" size={16} />Zoeken met AI</Link>
          <MButton className="btn btn-accent" onClick={() => setEditing({ p: emptyProduct(), isNew: true })}><Icon name="plus" size={16} />Nieuw product</MButton>
        </div>
      </div>
      <div className="card">
        <div className="toolbar"><input className="input" id="pq" placeholder="Zoek op naam, SKU of categorie" value={query} onChange={e => setQuery(e.target.value)} /><span className="muted" style={{ fontSize: 13 }}>Prijzen zijn inclusief btw.</span></div>
        <div className="table-wrap"><table className="t">
          <thead><tr><th>Product</th><th>Leverancier</th><th className="num">Inkoop</th><th className="num">Verkoopprijs</th><th className="num">Marge</th><th>Zichtbaar</th><th /></tr></thead>
          <tbody>
            {loading && [0, 1, 2].map(i => <tr key={i}><td colSpan={7}><div className="skeleton" style={{ height: 40 }} /></td></tr>)}
            <AnimatePresence initial={false}>
              {list.map(p => (
                <motion.tr key={p.id} layout initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}>
                  <td><div className="prod-cell"><ProductVisual p={p} /><div><b>{p.name}</b><span>{p.sku} · {p.category}{p.trackStock && <span style={{ color: (p.stock ?? 0) <= 0 ? 'var(--danger)' : (p.stock ?? 0) <= 5 ? 'var(--warn)' : undefined }}> · {(p.stock ?? 0) <= 0 ? 'uitverkocht' : 'voorraad ' + p.stock}</span>}{!p.image && <span style={{ color: 'var(--warn)' }}> · geen foto</span>}{!p.cjVid && <span style={{ color: 'var(--warn)' }}> · geen CJ-ID</span>}</span></div></div></td>
                  <td>{p.supplier}{p.supplierUrl && <> <a href={p.supplierUrl} target="_blank" rel="noopener" title="Leverancierspagina"><Icon name="external" size={13} /></a></>}<br /><span className="muted" style={{ fontSize: 12 }}>{p.warehouse} · {p.days[0]}–{p.days[1]} d</span></td>
                  <td className="num">{eur(p.cost ?? 0)}</td>
                  <td className="num"><input className="input price-input" type="number" step="0.01" min="0" defaultValue={p.price.toFixed(2)} aria-label={'Verkoopprijs ' + p.name}
                    onBlur={e => { const v = Math.round(parseFloat(e.target.value) * 100) / 100; if (v > 0 && v !== p.price) save({ ...p, price: v }, false, 'Prijs bijgewerkt naar ' + eur(v)); }} /></td>
                  <td className="num"><span className={margin(p) >= 0.6 ? 'up' : margin(p) < 0.4 ? 'down' : ''} style={{ fontWeight: 600 }}>{pct(margin(p))}</span></td>
                  <td><label className="switch"><input type="checkbox" checked={p.status === 'active'} onChange={e => save({ ...p, status: e.target.checked ? 'active' : 'draft' }, false, p.name + (e.target.checked ? ' is zichtbaar in de webwinkel' : ' is verborgen'))} /><span /></label></td>
                  <td style={{ whiteSpace: 'nowrap' }}>
                    <button className="icon-btn" title="Bewerken" onClick={() => setEditing({ p, isNew: false })}><Icon name="pencil" size={17} /></button>
                    <Link className="icon-btn" title="Video maken" to={'/beheer/studio?product=' + p.id}><Icon name="video" size={17} /></Link>
                    <button className="icon-btn" title="Verwijderen" onClick={() => remove(p)}><Icon name="trash" size={17} /></button>
                  </td>
                </motion.tr>
              ))}
            </AnimatePresence>
          </tbody>
        </table></div>
      </div>
      {editing && <ProductEditor key={editing.p.id} initial={editing.p} isNew={editing.isNew} onClose={() => setEditing(null)} onSave={async p => { if (await save(p, editing.isNew)) setEditing(null); }} />}
      {confirmNode}
    </>
  );
}

function ProductEditor({ initial, isNew, onClose, onSave }: { initial: Product; isNew: boolean; onClose: () => void; onSave: (p: Product) => void }) {
  const [p, setP] = useState<Product>(initial);
  const [features, setFeatures] = useState(initial.features.join('\n'));
  const toast = useToast();
  const set = <K extends keyof Product>(k: K, v: Product[K]) => setP(x => ({ ...x, [k]: v }));
  const num = (v: string) => { const n = parseFloat(v); return isNaN(n) ? 0 : Math.round(n * 100) / 100; };
  const F = ({ label, k, type = 'text', hint, full }: { label: string; k: keyof Product; type?: string; hint?: string; full?: boolean }) => (
    <div className="field" style={full ? { gridColumn: '1/-1' } : undefined}>
      <label htmlFor={'e-' + String(k)}>{label}</label>
      <input className="input" id={'e-' + String(k)} type={type} step={type === 'number' ? '0.01' : undefined} min={type === 'number' ? 0 : undefined}
        value={(p[k] as string | number | null) ?? ''} onChange={e => set(k, (type === 'number' ? (e.target.value === '' ? null : k === 'stock' ? Math.max(0, Math.round(num(e.target.value))) : num(e.target.value)) : e.target.value) as Product[typeof k])} />
      {hint && <span className="hint">{hint}</span>}
    </div>
  );
  const submit = () => {
    const out: Product = { ...p, features: features.split('\n').map(s => s.trim()).filter(Boolean), cjFrom: (p.cjFrom || 'CN').toUpperCase().slice(0, 2) };
    if (!out.name.trim() || !(out.price > 0)) { toast('Vul minimaal een naam en een verkoopprijs in', 'error'); return; }
    if (out.compare !== null && !(Number(out.compare) > out.price)) out.compare = null;
    if (out.days[1] < out.days[0]) out.days = [out.days[0], out.days[0]];
    onSave(out);
  };
  return (
    <Modal title={isNew ? 'Nieuw product' : 'Product bewerken'} open onClose={onClose} footer={<><button className="btn btn-ghost" onClick={onClose}>Annuleren</button><MButton className="btn btn-primary" onClick={submit}>Opslaan</MButton></>}>
      <div className="form-grid">
        {F({ label: 'Productnaam', k: 'name', full: true })}
        {F({ label: 'Categorie', k: 'category' })}
        {F({ label: 'SKU', k: 'sku' })}
        {F({ label: 'Verkoopprijs (€, incl. btw)', k: 'price', type: 'number' })}
        {F({ label: 'Inkoopprijs (€, incl. verzending)', k: 'cost', type: 'number' })}
        {F({ label: '“Van”-prijs (optioneel)', k: 'compare', type: 'number', hint: 'Alleen invullen als u deze prijs de afgelopen 30 dagen echt hanteerde.', full: true })}
        {F({ label: 'Productfoto (URL)', k: 'image', hint: 'Bijv. https://… (een foto van uw leverancier of eigen opname).', full: true })}
        {F({ label: 'Leverancier', k: 'supplier' })}
        {F({ label: 'Magazijn', k: 'warehouse' })}
        {F({ label: 'Link naar product bij leverancier', k: 'supplierUrl', type: 'url', full: true })}
        <div style={{ gridColumn: '1/-1', borderTop: '1px solid var(--line)', paddingTop: 12 }}><b style={{ fontSize: 14 }}>Automatisch bestellen bij CJdropshipping</b><p className="hint">Variant-ID (VID) vindt u in CJ bij het product onder de varianten.</p></div>
        {F({ label: 'CJ variant-ID (VID)', k: 'cjVid' })}
        {F({ label: 'CJ magazijnland (CN, US, DE, PL …)', k: 'cjFrom' })}
        {F({ label: 'CJ verzendmethode (optioneel)', k: 'cjLogistic', hint: 'Leeg = automatisch de goedkoopste.', full: true })}
        <div className="field"><label htmlFor="e-dmin">Levertijd min. (werkdagen)</label><input className="input" id="e-dmin" type="number" min={1} value={p.days[0]} onChange={e => set('days', [Math.max(1, parseInt(e.target.value) || 1), p.days[1]])} /></div>
        <div className="field"><label htmlFor="e-dmax">Levertijd max. (werkdagen)</label><input className="input" id="e-dmax" type="number" min={1} value={p.days[1]} onChange={e => set('days', [p.days[0], Math.max(1, parseInt(e.target.value) || 1)])} /></div>
        <label className="check" style={{ gridColumn: '1/-1', display: 'flex', gap: 8, alignItems: 'center', fontSize: 14 }}><input type="checkbox" checked={!!p.trackStock} onChange={e => set('trackStock', e.target.checked)} />Voorraad bijhouden (niet doen bij dropshipping: de leverancier heeft de voorraad)</label>
        {p.trackStock && F({ label: 'Voorraad (stuks)', k: 'stock', type: 'number', hint: 'Verkochte stuks worden automatisch afgeschreven; op 0 staat het product als "uitverkocht" in de winkel.', full: true })}
        <div className="field" style={{ gridColumn: '1/-1' }}><label htmlFor="e-desc">Beschrijving</label><textarea className="textarea" id="e-desc" rows={4} value={p.description} onChange={e => set('description', e.target.value)} /></div>
        <div className="field" style={{ gridColumn: '1/-1' }}><label htmlFor="e-feat">Kenmerken (één per regel)</label><textarea className="textarea" id="e-feat" rows={4} value={features} onChange={e => setFeatures(e.target.value)} /></div>
        <div style={{ gridColumn: '1/-1', borderTop: '1px solid var(--line)', paddingTop: 12 }}><b style={{ fontSize: 14 }}>Productveiligheid (GPSR)</b><p className="hint">Verplicht voor producten die u in de EU verkoopt: gegevens van de fabrikant en van een in de EU gevestigde verantwoordelijke. Deze worden bij het product getoond.</p></div>
        {F({ label: 'Fabrikant (naam, adres)', k: 'manufacturer', full: true })}
        {F({ label: 'Verantwoordelijke in de EU (naam, adres, e-mail)', k: 'euResponsible', full: true })}
        <div className="field" style={{ gridColumn: '1/-1' }}><label htmlFor="e-safety">Veiligheidsinformatie en waarschuwingen</label><textarea className="textarea" id="e-safety" rows={3} value={p.safetyInfo ?? ''} onChange={e => set('safetyInfo', e.target.value)} /></div>
        <label className="check" style={{ gridColumn: '1/-1', display: 'flex', gap: 8, alignItems: 'center', fontSize: 14 }}><input type="checkbox" checked={p.status === 'active'} onChange={e => set('status', e.target.checked ? 'active' : 'draft')} /> Zichtbaar in de webwinkel</label>
      </div>
    </Modal>
  );
}

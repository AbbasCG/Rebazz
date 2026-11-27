import { motion } from 'motion/react';
import { useState, type FormEvent } from 'react';
import { Icon, MButton, useToast } from '../../components/ui';
import { api } from '../../lib/api';
import { COUNTRIES, PAYMENT_METHODS } from '../../lib/i18n';
import type { Order } from '../../lib/types';
import { download, eur, fee, fmtDate, ORDER_STEPS, profit, useAdmin } from '../context';
import { Drawer, StatusBadge, useConfirm } from '../parts';

const FILTERS: [string, string, (o: Order) => boolean][] = [
  ['all', 'Alle', () => true],
  ['new', 'Te verwerken', o => o.step === 0 && !o.refunded],
  ['supplier', 'Bij leverancier', o => o.step === 1 && !o.refunded],
  ['transit', 'Onderweg', o => (o.step === 2 || o.step === 3) && !o.refunded],
  ['delivered', 'Afgeleverd', o => o.step === 4 && !o.refunded],
  ['attention', 'Actie nodig', o => !o.refunded && (o.cjStatus === 'error' || o.cjStatus === 'manual' || o.cjStatus === 'placing' || (o.delayed && o.step < 4))],
  ['refunded', 'Terugbetaald', o => o.refunded]
];
const CARRIERS = ['PostNL', 'DHL', 'DPD', 'GLS', 'UPS', 'FedEx', 'USPS', 'Royal Mail', 'YunExpress', '4PX', 'Cainiao', 'Anders'];

export default function Orders() {
  const { orders, status, refresh } = useAdmin();
  const toast = useToast();
  const [filter, setFilter] = useState('all');
  const [openId, setOpenId] = useState<string | null>(null);
  const f = FILTERS.find(x => x[0] === filter)!;
  const list = orders.filter(f[2]);
  const toProcess = orders.filter(FILTERS[1][2]);
  const open = orders.find(o => o.id === openId) ?? null;

  const exportToOrder = () => {
    const rows: (string | number)[][] = [['Bestelling', 'Datum', 'Naam', 'E-mail', 'Telefoon', 'Adres', 'Land', 'SKU', 'Product', 'Aantal', 'Leverancier', 'Leverancierslink']];
    toProcess.forEach(o => o.items.forEach(i => rows.push([o.id, fmtDate(o.date), o.customer, o.email, o.phone, o.address, o.country, i.sku, i.name, i.qty, i.supplier, i.supplierUrl])));
    download('te-bestellen-' + new Date().toISOString().slice(0, 10) + '.csv', rows);
    toast('Exportbestand is gedownload (te openen in Excel)');
  };

  return (
    <>
      <div className="page-intro">
        <p>{status.cj
          ? 'Betaalde bestellingen worden automatisch bij CJdropshipping geplaatst' + (status.cjAutoPay ? ' en betaald met uw CJ-saldo' : ' (betalen doet u in CJ)') + '. Trackingcodes worden elke 2 uur opgehaald en naar de klant gemaild.'
          : 'Werkwijze: open een bestelling, bestel bij de leverancier met het bezorgadres, markeer als besteld en vul de trackingcode in.'}</p>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          <MButton className="btn btn-outline" onClick={async () => { try { await refresh(); toast('Bestellingen zijn vernieuwd'); } catch (e) { toast('Vernieuwen mislukt: ' + (e as Error).message, 'error'); } }}><Icon name="refresh" size={16} />Vernieuwen</MButton>
          {status.cj && <MButton className="btn btn-outline" onClick={async () => { try { const r = await api.admin.cjSync(); await refresh(); toast(r.checked + ' gecontroleerd, ' + r.changed + ' bijgewerkt'); } catch (e) { toast('Mislukt: ' + (e as Error).message, 'error'); } }}><Icon name="truck" size={16} />Tracking ophalen (CJ)</MButton>}
          <MButton className="btn btn-accent" disabled={!toProcess.length} onClick={exportToOrder}><Icon name="package" size={16} />Te bestellen exporteren ({toProcess.length})</MButton>
        </div>
      </div>
      <div className="card">
        <div className="tabs" role="tablist">
          {FILTERS.map(x => (
            <button key={x[0]} className={'tab' + (x[0] === filter ? ' active' : '')} role="tab" aria-selected={x[0] === filter} onClick={() => setFilter(x[0])} style={{ position: 'relative' }}>
              {x[1]}<span className="c">{orders.filter(x[2]).length}</span>
              {x[0] === filter && <motion.span layoutId="ordersTab" style={{ position: 'absolute', left: 0, right: 0, bottom: -1, height: 2, background: 'var(--ink)' }} />}
            </button>
          ))}
        </div>
        <div className="table-wrap"><table className="t">
          <thead><tr><th>Bestelling</th><th>Klant</th><th>Producten</th><th>Status</th><th>Vervoerder</th><th className="num">Totaal</th><th className="num">Winst</th></tr></thead>
          <tbody>
            {list.map((o, i) => (
              <motion.tr key={o.id} className="click" onClick={() => setOpenId(o.id)} initial={{ opacity: 0 }} animate={{ opacity: 1 }} transition={{ delay: Math.min(i, 12) * 0.02 }}>
                <td><b>{o.id}</b>{!o.live && <span className="badge plain" style={{ marginInlineStart: 6, height: 18, fontSize: 10.5 }}>TEST</span>}<br /><span className="muted" style={{ fontSize: 12 }}>{fmtDate(o.date)}</span></td>
                <td>{o.customer}<br /><span className="muted" style={{ fontSize: 12 }}>{COUNTRIES[o.country] ?? o.country}</span></td>
                <td>{o.items.map(i => <div key={i.id}>{i.qty}× {i.name}</div>)}</td>
                <td><StatusBadge o={o} /></td>
                <td>{o.tracking ? <>{o.carrier}<br /><span className="muted" style={{ fontSize: 12 }}>{o.tracking}</span></> : <span className="muted">—</span>}</td>
                <td className="num">{eur(o.total)}</td>
                <td className={'num ' + (profit(o) >= 0 ? 'up' : 'down')}>{eur(profit(o))}</td>
              </motion.tr>
            ))}
            {!list.length && <tr><td colSpan={7} className="muted" style={{ textAlign: 'center', padding: 32 }}>{orders.length ? 'Geen bestellingen in deze categorie.' : 'Nog geen bestellingen.'}</td></tr>}
          </tbody>
        </table></div>
      </div>
      <Drawer open={!!open} onClose={() => setOpenId(null)}>{open && <OrderDetail o={open} onClose={() => setOpenId(null)} />}</Drawer>
    </>
  );
}

function OrderDetail({ o, onClose }: { o: Order; onClose: () => void }) {
  const { status, replaceOrder } = useAdmin();
  const toast = useToast();
  const [ask, confirmNode] = useConfirm();
  const [carrier, setCarrier] = useState(o.carrier || 'PostNL');
  const [code, setCode] = useState(o.tracking);
  const [notify, setNotify] = useState(true);
  const [busy, setBusy] = useState(false);
  const pm = PAYMENT_METHODS.find(m => m.id === o.payment);

  const save = async (patch: Partial<Order> & { notify?: boolean }, msg: string) => {
    setBusy(true);
    try {
      const r = await api.admin.updateOrder(o.id, patch);
      replaceOrder(r.order);
      toast(msg + (patch.notify ? (r.mailed ? ' — klant is gemaild' : ' — e-mail niet verzonden (nog niet ingesteld)') : ''));
    } catch (e) { toast('Opslaan mislukt: ' + (e as Error).message, 'error'); }
    setBusy(false);
  };
  const openDoc = async (kind: 'invoice' | 'credit-note') => {
    try {
      const url = URL.createObjectURL(await api.admin.document(o.id, kind));
      const a = document.createElement('a');
      a.href = url; a.download = (kind === 'invoice' ? 'factuur-' : 'creditnota-') + o.id + '.pdf';
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(() => URL.revokeObjectURL(url), 10000);
    } catch (e) { toast('Document openen mislukt: ' + (e as Error).message, 'error'); }
  };
  const action = async (a: 'refund' | 'notify' | 'cj-order' | 'cj-check', msg: string) => {
    setBusy(true);
    try { const r = await api.admin.orderAction(o.id, a); replaceOrder(r.order); toast(a === 'notify' && !r.mailed ? 'E-mail is nog niet ingesteld (zie HANDLEIDING.md)' : msg); }
    catch (e) { toast('Mislukt: ' + (e as Error).message, 'error'); }
    setBusy(false);
  };
  const copyAddress = async () => {
    const text = [o.customer, o.address, o.phone].filter(Boolean).join('\n');
    try { await navigator.clipboard.writeText(text); toast('Adres is gekopieerd'); } catch { toast('Kopiëren lukte niet; selecteer het adres handmatig', 'error'); }
  };
  const submitTracking = (e: FormEvent) => {
    e.preventDefault();
    if (!code.trim()) { toast('Vul eerst een trackingcode in', 'error'); return; }
    save({ step: Math.max(2, o.step), carrier, tracking: code.trim(), notify }, 'Trackingcode is opgeslagen');
  };

  const cjLabel: Record<string, [string, string]> = {
    paid: ['success', 'Automatisch besteld en betaald bij CJ'], created: ['info', 'Aangemaakt bij CJ — betaal de bestelling in uw CJ-account'],
    placing: ['warn', 'Wordt besteld (blijft dit hangen? Controleer CJ)'], error: ['danger', 'Automatisch bestellen mislukt'], manual: ['warn', 'Niet automatisch besteld']
  };
  const cj = cjLabel[o.cjStatus] ?? ['plain', status.cj ? 'Wacht op automatische verwerking' : 'CJ-koppeling niet ingesteld'];

  return (
    <>
      <div className="d-head">
        <div><h3 style={{ fontSize: 18 }}>{o.id}</h3><span className="muted" style={{ fontSize: 13 }}>{fmtDate(o.date)}{!o.live && ' · TESTBETALING'}</span></div>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}><StatusBadge o={o} /><button className="icon-btn" onClick={onClose} aria-label="Sluiten"><Icon name="x" size={20} /></button></div>
      </div>
      <div className="d-body">
        <div>
          <div className="section-label">Producten</div>
          {o.items.map(i => (
            <div className="prod-cell" style={{ marginBottom: 10 }} key={i.id}>
              <div style={{ flex: 1 }}><b>{i.qty}× {i.name}</b><span>{i.sku} · {i.supplier}</span></div><b>{eur(i.price * i.qty)}</b>
            </div>
          ))}
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            {o.items.filter(i => i.supplierUrl).map(i => <a key={i.id} className="btn btn-outline btn-sm" href={i.supplierUrl} target="_blank" rel="noopener"><Icon name="external" size={14} />Bestellen: {i.name.split(' ').slice(0, 3).join(' ')}</a>)}
          </div>
        </div>
        {!o.refunded && (
          <div>
            <div className="section-label">CJdropshipping</div>
            <div className="card" style={{ padding: '12px 14px', fontSize: 13.5, display: 'grid', gap: 8 }}>
              <span className={'badge ' + cj[0]} style={{ justifySelf: 'start', height: 'auto', whiteSpace: 'normal', padding: '4px 10px' }}>{cj[1]}</span>
              {o.cjOrders && <span className="muted">CJ-bestelnummer(s): {o.cjOrders}</span>}
              {o.cjError && <span style={{ color: 'var(--danger)' }}>{o.cjError}</span>}
              <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                {!o.cjOrders && status.cj && <MButton className="btn btn-primary btn-sm" disabled={busy} onClick={async () => { if (await ask('Bestelling ' + o.id + ' nu bij CJdropshipping plaatsen' + (status.cjAutoPay ? ' en betalen met uw CJ-saldo' : '') + '?')) action('cj-order', 'Bestelling staat bij CJ'); }}><Icon name="package" size={14} />Nu naar CJ sturen</MButton>}
                {o.cjOrders && <MButton className="btn btn-outline btn-sm" disabled={busy} onClick={() => action('cj-check', 'Gegevens bijgewerkt')}><Icon name="refresh" size={14} />Tracking ophalen bij CJ</MButton>}
              </div>
            </div>
          </div>
        )}
        <div>
          <div className="section-label">Bezorgadres</div>
          <div className="card" style={{ padding: '12px 14px', fontSize: 13.5, background: 'var(--bg)' }}><b>{o.customer}</b><br />{o.address || COUNTRIES[o.country]}{o.phone && <><br />{o.phone}</>}</div>
          <button className="btn btn-ghost btn-sm" style={{ marginTop: 6 }} onClick={copyAddress}><Icon name="checklist" size={14} />Adres kopiëren</button>
        </div>
        <div className="kv">
          <span>E-mail</span><span style={{ color: 'var(--ink)' }}>{o.email}</span>
          <span>Taal klant</span><span style={{ color: 'var(--ink)' }}>{o.lang.toUpperCase()}</span>
          <span>Betaalmethode</span><span style={{ color: 'var(--ink)' }}>{pm?.name ?? o.payment}</span>
          <span>Verzending</span><span style={{ color: 'var(--ink)' }}>{o.shipping ? eur(o.shipping) : 'Gratis'}{o.shipMethod === 'express' ? ' · Express' : ''}</span>
          {o.discount > 0 && <><span>Korting</span><span style={{ color: 'var(--ink)' }}>−{eur(o.discount)}</span></>}
          <span>Totaal</span><b>{eur(o.total)}</b>
          <span>Inkoop</span><span style={{ color: 'var(--ink)' }}>{eur(o.cost)}</span>
          <span>Transactiekosten (ca.)</span><span style={{ color: 'var(--ink)' }}>{eur(fee(o.total))}</span>
          <span>Winst (ca.)</span><b className={profit(o) >= 0 ? 'up' : 'down'}>{eur(profit(o))}</b>
        </div>
        <div>
          <div className="section-label">Documenten</div>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            <button className="btn btn-outline btn-sm" onClick={() => openDoc('invoice')}><Icon name="external" size={14} />Factuur (PDF)</button>
            {o.refunded && <button className="btn btn-outline btn-sm" onClick={() => openDoc('credit-note')}><Icon name="external" size={14} />Creditnota (PDF)</button>}
          </div>
        </div>
        {!o.refunded && o.step >= 1 && o.step < 4 && (
          <div>
            <div className="section-label">Trackingcode</div>
            <form className="form-grid" style={{ gap: 10 }} onSubmit={submitTracking}>
              <select className="select" id="tCarrier" aria-label="Vervoerder" value={carrier} onChange={e => setCarrier(e.target.value)}>{CARRIERS.map(c => <option key={c}>{c}</option>)}</select>
              <input className="input" id="tCode" placeholder="Trackingcode" aria-label="Trackingcode" value={code} onChange={e => setCode(e.target.value)} />
              <label className="check" style={{ gridColumn: '1/-1', display: 'flex', gap: 8, fontSize: 13.5 }}><input type="checkbox" checked={notify} onChange={e => setNotify(e.target.checked)} /> Klant per e-mail informeren (in de taal van de klant)</label>
              <MButton className="btn btn-primary" type="submit" disabled={busy} style={{ gridColumn: '1/-1' }}><Icon name="truck" size={16} />Opslaan als verzonden</MButton>
            </form>
          </div>
        )}
        <div>
          <div className="section-label">Verloop</div>
          <ol className="timeline">
            {ORDER_STEPS.map((s, i) => (
              <li key={s} className={i <= o.step ? 'done' : ''}>
                <span className="dot">{i <= o.step && <Icon name="check" />}</span>
                <div><b>{s}</b>{i === 2 && o.tracking && <><br /><span className="muted" style={{ fontSize: 12.5 }}>{o.carrier} · {o.tracking}</span></>}{i === o.step && o.delayed && !o.refunded && <><br /><span className="badge warn" style={{ marginTop: 4 }}>Langer onderweg dan verwacht</span></>}</div>
              </li>
            ))}
          </ol>
        </div>
      </div>
      <div className="d-foot">
        {o.refunded ? <span className="muted">Deze bestelling is terugbetaald.</span> : (
          <>
            {o.step === 0 && <MButton className="btn btn-primary" disabled={busy} onClick={() => save({ step: 1 }, 'Gemarkeerd als besteld bij de leverancier')}><Icon name="check" size={16} />Besteld bij leverancier</MButton>}
            {o.step === 2 && <MButton className="btn btn-primary" disabled={busy} onClick={() => save({ step: 3 }, 'Gemarkeerd als onderweg')}><Icon name="truck" size={16} />Markeren als onderweg</MButton>}
            {o.step === 3 && <MButton className="btn btn-primary" disabled={busy} onClick={() => save({ step: 4, delayed: false }, 'Gemarkeerd als afgeleverd')}><Icon name="check" size={16} />Markeren als afgeleverd</MButton>}
            {o.step >= 1 && o.step < 4 && <MButton className="btn btn-outline" disabled={busy} onClick={() => save({ delayed: !o.delayed, notify: !o.delayed }, o.delayed ? 'Vertraging opgeheven' : 'Vertraging gemeld')}><Icon name="clock" size={16} />{o.delayed ? 'Vertraging opheffen' : 'Vertraging melden'}</MButton>}
            <MButton className="btn btn-outline" disabled={busy} onClick={() => action('notify', 'Statusupdate verzonden naar ' + o.email)}><Icon name="mail" size={16} />Klant informeren</MButton>
            <MButton className="btn btn-danger" disabled={busy} onClick={async () => { if (await ask('Weet u zeker dat u ' + eur(o.total) + ' wilt terugbetalen aan ' + o.customer + '? Het geld gaat terug via Stripe en dit kan niet ongedaan worden gemaakt.')) action('refund', 'Terugbetaling van ' + eur(o.total) + ' is uitgevoerd via Stripe'); }}>Terugbetalen</MButton>
          </>
        )}
      </div>
      {confirmNode}
    </>
  );
}

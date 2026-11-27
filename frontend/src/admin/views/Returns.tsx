import { useCallback, useEffect, useState } from 'react';
import { Icon, MButton, useToast } from '../../components/ui';
import { api } from '../../lib/api';
import type { AdminReturn } from '../../lib/types';
import { eur } from '../context';
import { Modal } from '../parts';

const STATUS: Record<AdminReturn['status'], [string, string]> = {
  requested: ['Te beoordelen', 'warn'],
  approved: ['Goedgekeurd', 'info'],
  received: ['Ontvangen', 'info'],
  refunded: ['Terugbetaald', 'success'],
  rejected: ['Afgewezen', 'danger']
};
const REASON: Record<string, string> = {
  defective: 'Defect', damaged: 'Beschadigd ontvangen', wrong_item: 'Verkeerd artikel', not_as_described: 'Wijkt af van beschrijving', changed_mind: 'Bedacht (herroeping)', other: 'Anders'
};
const FILTERS: [string, string, (r: AdminReturn) => boolean][] = [
  ['all', 'Alle', () => true],
  ['open', 'Te beoordelen', r => r.status === 'requested'],
  ['progress', 'Onderweg / ontvangen', r => r.status === 'approved' || r.status === 'received'],
  ['done', 'Afgerond', r => r.status === 'refunded' || r.status === 'rejected']
];
const fmt = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString('nl-NL', { day: 'numeric', month: 'short', year: 'numeric' }) : '—');

/** Retouren (RMA): beoordelen, ontvangst bevestigen (met terugboeken van voorraad) en (deels) terugbetalen met creditnota. */
export default function Returns() {
  const toast = useToast();
  const [list, setList] = useState<AdminReturn[]>([]);
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState('all');
  const [openId, setOpenId] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [note, setNote] = useState('');
  const [tracking, setTracking] = useState('');
  const [amount, setAmount] = useState('');
  const [restock, setRestock] = useState(true);

  const load = useCallback(async () => {
    try { setList((await api.admin.returns()).returns); }
    catch (e) { toast('Retouren laden mislukt: ' + (e as Error).message, 'error'); }
    finally { setLoading(false); }
  }, [toast]);
  useEffect(() => { void load(); }, [load]);

  const open = list.find(r => r.id === openId) ?? null;
  const shown = list.filter(FILTERS.find(f => f[0] === filter)![2]);
  const select = (r: AdminReturn) => { setOpenId(r.id); setNote(''); setTracking(''); setAmount(r.suggestedRefund.toFixed(2)); setRestock(r.items.some(i => i.tracked)); };

  const run = async (action: 'approve' | 'reject' | 'receive' | 'refund', body: Record<string, unknown>, msg: string) => {
    if (!open) return;
    setBusy(true);
    try {
      const res = await api.admin.returnAction(open.id, action, body);
      setList(l => l.map(r => (r.id === res.return.id ? res.return : r)));
      toast(msg);
    } catch (e) { toast((e as Error).message, 'error'); }
    finally { setBusy(false); }
  };
  const creditNote = async (r: AdminReturn) => {
    try {
      const url = URL.createObjectURL(await api.admin.returnCreditNote(r.id));
      const a = document.createElement('a');
      a.href = url; a.download = 'creditnota-' + (r.creditNote ?? r.id) + '.pdf';
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(() => URL.revokeObjectURL(url), 10000);
    } catch (e) { toast('Creditnota openen mislukt: ' + (e as Error).message, 'error'); }
  };

  return (
    <>
      <div className="page-intro">
        <p>Klanten vragen een retour aan via "Bestelling volgen". U beoordeelt de aanvraag, bevestigt de ontvangst en betaalt (deels) terug; de klant krijgt bij elke stap een e-mail en na de terugbetaling een creditnota.</p>
        <MButton className="btn btn-outline" onClick={() => { setLoading(true); void load(); }}><Icon name="refresh" size={16} />Vernieuwen</MButton>
      </div>
      <div className="card">
        <div className="tabs" role="tablist">
          {FILTERS.map(f => (
            <button key={f[0]} className={'tab' + (f[0] === filter ? ' active' : '')} role="tab" aria-selected={f[0] === filter} onClick={() => setFilter(f[0])}>
              {f[1]}<span className="c">{list.filter(f[2]).length}</span>
            </button>
          ))}
        </div>
        <div className="table-wrap"><table className="t">
          <thead><tr><th>Retour</th><th>Bestelling</th><th>Klant</th><th>Artikelen</th><th>Reden</th><th>Status</th><th /></tr></thead>
          <tbody>
            {loading && [0, 1, 2].map(i => <tr key={i}><td colSpan={7}><div className="skeleton" style={{ height: 40 }} /></td></tr>)}
            {!loading && shown.length === 0 && <tr><td colSpan={7} className="muted" style={{ textAlign: 'center', padding: 28 }}>Geen retouren in deze lijst.</td></tr>}
            {shown.map(r => (
              <tr key={r.id} style={{ cursor: 'pointer' }} onClick={() => select(r)}>
                <td><b>{r.id}</b><br /><span className="muted" style={{ fontSize: 12 }}>{fmt(r.date)}</span></td>
                <td>{r.order.id}</td>
                <td>{r.order.customer}<br /><span className="muted" style={{ fontSize: 12 }}>{r.order.email}</span></td>
                <td>{r.items.map(i => i.name + ' × ' + i.qty).join(', ')}</td>
                <td>{REASON[r.reason] ?? r.reason}</td>
                <td><span className={'badge ' + STATUS[r.status][1]}>{STATUS[r.status][0]}</span></td>
                <td><button className="icon-btn" aria-label={'Open ' + r.id} onClick={e => { e.stopPropagation(); select(r); }}><Icon name="arrow" size={16} /></button></td>
              </tr>
            ))}
          </tbody>
        </table></div>
      </div>

      <Modal title={open ? 'Retour ' + open.id : ''} open={!!open} onClose={() => setOpenId(null)}>
        {open && (
          <div style={{ display: 'grid', gap: 14, fontSize: 14 }}>
            <div className="kv">
              <span>Status</span><span><span className={'badge ' + STATUS[open.status][1]}>{STATUS[open.status][0]}</span></span>
              <span>Bestelling</span><span style={{ color: 'var(--ink)' }}>{open.order.id} · {eur(open.order.total)}</span>
              <span>Klant</span><span style={{ color: 'var(--ink)' }}>{open.order.customer} · {open.order.email}</span>
              <span>Reden</span><span style={{ color: 'var(--ink)' }}>{REASON[open.reason] ?? open.reason}</span>
              <span>Aangevraagd</span><span style={{ color: 'var(--ink)' }}>{fmt(open.date)}</span>
              {open.refundAmount != null && <><span>Terugbetaald</span><b>{eur(open.refundAmount)}{open.creditNote ? ' · ' + open.creditNote : ''}</b></>}
            </div>
            {open.comment && <div><div className="section-label">Toelichting klant</div><div className="card" style={{ padding: '10px 12px', background: 'var(--bg)' }}>{open.comment}</div></div>}
            <div>
              <div className="section-label">Artikelen</div>
              {open.items.map(i => (
                <div key={i.name} style={{ display: 'flex', justifyContent: 'space-between', gap: 8, padding: '4px 0' }}>
                  <span>{i.name} × {i.qty}{i.tracked ? <span className="muted"> · voorraad bijgehouden{i.restocked ? ' (' + i.restocked + ' teruggeboekt)' : ''}</span> : null}</span><b>{eur(i.price * i.qty)}</b>
                </div>
              ))}
            </div>
            {open.adminNote && <div><div className="section-label">Uw bericht aan de klant</div><div className="card" style={{ padding: '10px 12px', background: 'var(--bg)' }}>{open.adminNote}</div></div>}

            {(open.status === 'requested' || open.status === 'approved') && (
              <div className="field">
                <label htmlFor="r-note">{open.status === 'requested' ? 'Bericht aan de klant (instructies bij goedkeuring, verplichte reden bij afwijzing)' : 'Reden van afwijzing'}</label>
                <textarea className="textarea" id="r-note" rows={2} value={note} onChange={e => setNote(e.target.value)} maxLength={1000} />
              </div>
            )}
            {open.status === 'requested' && (
              <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                <MButton className="btn btn-primary" disabled={busy} onClick={() => run('approve', { note, tracking }, 'Retour goedgekeurd; de klant is gemaild met het retouradres')}><Icon name="check" size={16} />Goedkeuren</MButton>
                <MButton className="btn btn-outline" disabled={busy || note.trim().length < 3} onClick={() => run('reject', { note }, 'Retour afgewezen; de klant is gemaild')}><Icon name="x" size={16} />Afwijzen</MButton>
              </div>
            )}
            {open.status === 'approved' && (
              <div style={{ display: 'grid', gap: 10 }}>
                <label className="check" style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                  <input type="checkbox" checked={restock} onChange={e => setRestock(e.target.checked)} />Artikelen terug op voorraad zetten (alleen bij producten met voorraadbeheer)
                </label>
                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                  <MButton className="btn btn-primary" disabled={busy} onClick={() => run('receive', { restock }, 'Ontvangst vastgelegd' + (restock ? '; voorraad bijgewerkt' : ''))}><Icon name="package" size={16} />Pakket ontvangen</MButton>
                  <MButton className="btn btn-outline" disabled={busy || note.trim().length < 3} onClick={() => run('reject', { note }, 'Retour afgewezen; de klant is gemaild')}>Alsnog afwijzen</MButton>
                </div>
              </div>
            )}
            {(open.status === 'approved' || open.status === 'received') && (
              <div style={{ display: 'grid', gap: 8, borderTop: '1px solid var(--line)', paddingTop: 12 }}>
                <div className="field">
                  <label htmlFor="r-amount">Terug te betalen bedrag (€, incl. btw)</label>
                  <input className="input" id="r-amount" type="number" step="0.01" min="0.01" value={amount} onChange={e => setAmount(e.target.value)} />
                  <span className="hint">Voorgesteld: waarde van de artikelen ({eur(open.suggestedRefund)}). Verzendkosten kunt u zelf optellen. Maximaal wat nog niet is terugbetaald. De terugbetaling gaat via Stripe naar de oorspronkelijke betaalmethode en kan niet ongedaan worden gemaakt.</span>
                </div>
                <MButton className="btn btn-accent" disabled={busy || !(parseFloat(amount) > 0)} onClick={() => run('refund', { amount: parseFloat(amount) }, 'Terugbetaling gestart; de klant krijgt een creditnota')}><Icon name="euro" size={16} />Terugbetalen en creditnota maken</MButton>
              </div>
            )}
            {open.status === 'refunded' && <button className="btn btn-outline" style={{ alignSelf: 'flex-start' }} onClick={() => creditNote(open)}><Icon name="external" size={15} />Creditnota (PDF)</button>}
          </div>
        )}
      </Modal>
    </>
  );
}

import { useState, type FormEvent } from 'react';
import { api, ApiError } from '../lib/api';
import { useI18n } from '../lib/i18n';
import type { TrackedOrder } from '../lib/types';
import { Icon, MButton } from './ui';

const REASONS = ['defective', 'damaged', 'wrong_item', 'not_as_described', 'changed_mind', 'other'] as const;

/** Retouren in het venster "Bestelling volgen": bestaande retouren met status, en een formulier om een nieuwe aan te vragen. */
export function ReturnPanel({ order, code, email, onChange, submitReturn }: {
  order: TrackedOrder; code: string; email: string; onChange: (patch: Partial<TrackedOrder>) => void;
  /** Ingelogde klant: eigen aanroep (geen bestelnummer/e-mail nodig). */
  submitReturn?: (body: { items: { id: number; qty: number }[]; reason: string; comment: string }) => Promise<{ rma: string } & Pick<TrackedOrder, 'returnable' | 'returnDeadline' | 'items' | 'returns'>>;
}) {
  const { t, locale } = useI18n();
  const [open, setOpen] = useState(false);
  const [qty, setQty] = useState<Record<number, number>>({});
  const [reason, setReason] = useState<string>('changed_mind');
  const [comment, setComment] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [sent, setSent] = useState('');

  const money = (v: number) => new Intl.NumberFormat(locale, { style: 'currency', currency: 'EUR' }).format(v);
  const submit = async (e: FormEvent) => {
    e.preventDefault();
    const items = Object.entries(qty).filter(([, q]) => q > 0).map(([id, q]) => ({ id: Number(id), qty: q }));
    if (!items.length) { setError(t('rt_pick_one')); return; }
    setBusy(true); setError('');
    try {
      const res = submitReturn ? await submitReturn({ items, reason, comment }) : await api.createReturn({ order_no: code, email, items, reason, comment });
      onChange({ returnable: res.returnable, returnDeadline: res.returnDeadline, items: res.items, returns: res.returns });
      setSent(t('rt_sent', { rma: res.rma }));
      setOpen(false); setQty({}); setComment('');
    } catch (err) {
      const msg = err instanceof ApiError && (err.data as { message?: string } | null)?.message;
      setError(msg || t('co_error'));
    } finally { setBusy(false); }
  };

  const returnable = order.items.filter(i => i.returnable > 0);
  return (
    <section className="return-panel" aria-label={t('rt_title')} style={{ marginTop: 18, paddingTop: 14, borderTop: '1px solid var(--line)' }}>
      {order.returns.length > 0 && (
        <div style={{ display: 'grid', gap: 10, marginBottom: 12 }}>
          <b>{t('rt_title')}</b>
          {order.returns.map(r => (
            <div key={r.rma} className="card" style={{ padding: '10px 12px', fontSize: 13.5, background: 'var(--bg)' }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8, flexWrap: 'wrap' }}>
                <b>{r.rma}</b><span className={'badge ' + (r.status === 'refunded' ? 'success' : r.status === 'rejected' ? 'danger' : 'warn')}>{t('rt_status_' + r.status)}</span>
              </div>
              <ul style={{ margin: '6px 0 0', paddingInlineStart: 18 }}>{r.items.map(i => <li key={i.name}>{i.name} × {i.qty}</li>)}</ul>
              {r.note && <p style={{ margin: '6px 0 0' }}>{r.note}</p>}
              {r.returnAddress && <p style={{ margin: '6px 0 0' }}><b>{t('rt_address')}:</b> {r.returnAddress}</p>}
              {r.refundAmount != null && <p style={{ margin: '6px 0 0' }}>{money(r.refundAmount)}</p>}
              {r.creditNoteUrl && <a className="btn btn-outline btn-sm" style={{ marginTop: 6 }} href={r.creditNoteUrl} target="_blank" rel="noopener"><Icon name="external" size={14} />{t('rt_credit')}</a>}
            </div>
          ))}
        </div>
      )}

      {sent && <p className="badge success plain" role="status" style={{ height: 'auto', padding: '10px 14px', whiteSpace: 'normal' }}>{sent}</p>}

      {order.returnable && !open && <button className="btn btn-outline" type="button" onClick={() => { setOpen(true); setSent(''); }}><Icon name="refresh" size={16} />{t('rt_request')}</button>}
      {!order.returnable && !order.refunded && order.returns.length === 0 && order.returnDeadline && new Date(order.returnDeadline) < new Date() && (
        <p className="muted" style={{ fontSize: 13 }}>{t('rt_closed', { date: new Date(order.returnDeadline).toLocaleDateString(locale) })}</p>
      )}

      {open && (
        <form onSubmit={submit} style={{ display: 'grid', gap: 10 }}>
          <fieldset style={{ border: 0, padding: 0, margin: 0, display: 'grid', gap: 8 }}>
            <legend style={{ fontWeight: 600, marginBottom: 6 }}>{t('rt_choose')}</legend>
            {returnable.map(i => (
              <label key={i.id} style={{ display: 'flex', gap: 10, alignItems: 'center', fontSize: 14 }}>
                <input type="checkbox" checked={(qty[i.id] ?? 0) > 0} onChange={e => setQty(q => ({ ...q, [i.id]: e.target.checked ? 1 : 0 }))} />
                <span style={{ flex: 1 }}>{i.name}</span>
                {i.returnable > 1 && (qty[i.id] ?? 0) > 0 && (
                  <select className="select" aria-label={i.name} style={{ width: 'auto', height: 34 }} value={qty[i.id]} onChange={e => setQty(q => ({ ...q, [i.id]: Number(e.target.value) }))}>
                    {Array.from({ length: i.returnable }, (_, n) => n + 1).map(n => <option key={n} value={n}>{n}</option>)}
                  </select>
                )}
              </label>
            ))}
          </fieldset>
          <label style={{ display: 'grid', gap: 4, fontSize: 14 }}>{t('rt_reason')}
            <select className="select" value={reason} onChange={e => setReason(e.target.value)}>{REASONS.map(r => <option key={r} value={r}>{t('rt_r_' + r)}</option>)}</select>
          </label>
          <label style={{ display: 'grid', gap: 4, fontSize: 14 }}>{t('rt_comment')}
            <textarea className="textarea" rows={3} maxLength={1000} value={comment} onChange={e => setComment(e.target.value)} />
          </label>
          {error && <p className="badge danger plain" role="alert" style={{ height: 'auto', padding: '10px 14px', whiteSpace: 'normal' }}>{error}</p>}
          <div style={{ display: 'flex', gap: 8 }}>
            <MButton className="btn btn-primary" type="submit" disabled={busy}>{t('rt_submit')}</MButton>
            <button className="btn btn-ghost" type="button" onClick={() => { setOpen(false); setError(''); }}>{t('close')}</button>
          </div>
        </form>
      )}
    </section>
  );
}

import { useCallback, useEffect, useState } from 'react';
import { Icon, MButton, useToast } from '../../components/ui';
import { api } from '../../lib/api';
import { LANGUAGES } from '../../lib/i18n';
import type { Campaign } from '../../lib/types';
import { Modal, useConfirm } from '../parts';

const STATUS: Record<Campaign['status'], [string, string]> = { draft: ['Concept', ''], sending: ['Wordt verstuurd', 'warn'], sent: ['Verstuurd', 'success'] };
const fmt = (iso: string | null) => (iso ? new Date(iso).toLocaleString('nl-NL', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—');
const EMPTY = { subject: '', body: '', cta_label: '', cta_url: '', lang: '' };

/** Nieuwsbrief: concept schrijven, testmail sturen en versturen aan bevestigde abonnees (in porties, nooit dubbel). */
export default function Newsletter() {
  const toast = useToast();
  const [ask, confirmNode] = useConfirm();
  const [list, setList] = useState<Campaign[]>([]);
  const [subs, setSubs] = useState(0);
  const [byLang, setByLang] = useState<Record<string, number>>({});
  const [loading, setLoading] = useState(true);
  const [edit, setEdit] = useState<{ id: number | null; f: typeof EMPTY } | null>(null);
  const [busy, setBusy] = useState(false);
  const [testTo, setTestTo] = useState('');

  const load = useCallback(async () => {
    try { const r = await api.admin.newsletters(); setList(r.campaigns); setSubs(r.subscribers); setByLang(r.byLang); }
    catch (e) { toast('Nieuwsbrieven laden mislukt: ' + (e as Error).message, 'error'); }
    finally { setLoading(false); }
  }, [toast]);
  useEffect(() => { void load(); }, [load]);
  /* Terwijl er een nieuwsbrief wordt verstuurd houden we de voortgang bij. */
  const sending = list.some(c => c.status === 'sending');
  useEffect(() => { if (!sending) return; const t = setInterval(() => void load(), 8000); return () => clearInterval(t); }, [sending, load]);

  const payload = (f: typeof EMPTY) => ({ subject: f.subject, body: f.body, cta_label: f.cta_label || null, cta_url: f.cta_url || null, lang: f.lang || null });
  const current = edit?.id ? list.find(c => c.id === edit.id) : undefined;
  const locked = !!current && current.status !== 'draft';

  const save = async (): Promise<number | null> => {
    if (!edit) return null;
    setBusy(true);
    try { const c = await api.admin.saveNewsletter(edit.id, payload(edit.f)); setEdit({ id: c.id, f: edit.f }); await load(); return c.id; }
    catch (e) { toast((e as Error).message, 'error'); return null; }
    finally { setBusy(false); }
  };
  const test = async () => {
    const id = await save();
    if (!id) return;
    setBusy(true);
    try { const r = await api.admin.testNewsletter(id, testTo.trim()); toast('Testmail verstuurd naar ' + r.to); }
    catch (e) { toast((e as Error).message, 'error'); }
    finally { setBusy(false); }
  };
  const send = async () => {
    const id = await save();
    if (!id) return;
    const aud = (await api.admin.newsletters()).campaigns.find(c => c.id === id)?.audience ?? 0;
    if (!(await ask('Deze nieuwsbrief nu versturen aan ' + aud + ' bevestigde abonnee(s)? Dit kan niet worden teruggedraaid.'))) return;
    setBusy(true);
    try { await api.admin.sendNewsletter(id); toast('Verzending gestart; de scheduler verstuurt de mails in porties.'); setEdit(null); await load(); }
    catch (e) { toast((e as Error).message, 'error'); }
    finally { setBusy(false); }
  };
  const remove = async (c: Campaign) => {
    if (!(await ask('Concept "' + c.subject + '" verwijderen?'))) return;
    try { await api.admin.deleteNewsletter(c.id); await load(); } catch (e) { toast((e as Error).message, 'error'); }
  };
  const set = (k: keyof typeof EMPTY, v: string) => setEdit(e => (e ? { ...e, f: { ...e.f, [k]: v } } : e));

  return (
    <>
      <div className="page-intro">
        <p>Alleen bevestigde abonnees (dubbele opt-in) ontvangen een nieuwsbrief; iedere mail bevat uw bedrijfsgegevens en een afmeldlink. Er zijn nu <b>{subs}</b> bevestigde abonnee(s){Object.keys(byLang).length > 0 ? ' (' + Object.entries(byLang).map(([l, n]) => l + ': ' + n).join(', ') + ')' : ''}. De scheduler (<code>php artisan schedule:run</code> elke minuut) verstuurt de mails in porties van 50.</p>
        <MButton className="btn btn-primary" onClick={() => setEdit({ id: null, f: EMPTY })}><Icon name="plus" size={16} />Nieuwe nieuwsbrief</MButton>
      </div>

      <div className="card" style={{ marginBottom: 20 }}>
        <div className="table-wrap"><table className="t">
          <thead><tr><th>Onderwerp</th><th>Doelgroep</th><th>Status</th><th>Verstuurd</th><th /></tr></thead>
          <tbody>
            {loading && <tr><td colSpan={5}><div className="skeleton" style={{ height: 40 }} /></td></tr>}
            {!loading && list.length === 0 && <tr><td colSpan={5} className="muted" style={{ textAlign: 'center', padding: 28 }}>Nog geen nieuwsbrieven.</td></tr>}
            {list.map(c => (
              <tr key={c.id} style={{ cursor: 'pointer' }} onClick={() => setEdit({ id: c.id, f: { subject: c.subject, body: c.body, cta_label: c.cta_label ?? '', cta_url: c.cta_url ?? '', lang: c.lang ?? '' } })}>
                <td><b>{c.subject}</b><br /><span className="muted" style={{ fontSize: 12 }}>{fmt(c.created_at)}</span></td>
                <td>{c.lang ? c.lang.toUpperCase() : 'Alle talen'} · {c.audience}</td>
                <td><span className={'badge ' + STATUS[c.status][1]}>{STATUS[c.status][0]}</span></td>
                <td>{c.status === 'draft' ? '—' : c.sent + (c.failed ? ' (' + c.failed + ' mislukt)' : '') + ' · ' + fmt(c.finished_at ?? c.started_at)}</td>
                <td>{c.status !== 'sending' && <button className="icon-btn" aria-label={'Verwijder ' + c.subject} onClick={e => { e.stopPropagation(); void remove(c); }}><Icon name="trash" size={16} /></button>}</td>
              </tr>
            ))}
          </tbody>
        </table></div>
      </div>

      <div className="card" style={{ padding: 16, fontSize: 14 }}>
        <b>Herinnering bij verlaten winkelwagen</b>
        <p className="muted" style={{ marginTop: 6 }}>Staat standaard aan (<code>SHOP_ABANDONED_CART=on</code>). In de Stripe-kassa verschijnt een (niet voorgevinkt) vakje voor toestemming; alleen als de klant dat aanvinkt en de kassa verloopt, ontvangt die één herinnering met een link om de bestelling af te ronden. Schakel de gebeurtenis <code>checkout.session.expired</code> in bij uw Stripe-webhook.</p>
      </div>

      <Modal title={edit?.id ? 'Nieuwsbrief bewerken' : 'Nieuwe nieuwsbrief'} open={!!edit} onClose={() => setEdit(null)}
        footer={edit && !locked ? <>
          <button className="btn btn-ghost" onClick={() => void save().then(id => { if (id) toast('Concept opgeslagen'); })} disabled={busy}>Opslaan</button>
          <button className="btn btn-outline" onClick={() => void test()} disabled={busy}>Testmail</button>
          <button className="btn btn-primary" onClick={() => void send()} disabled={busy}>Versturen…</button>
        </> : undefined}>
        {edit && (
          <div style={{ display: 'grid', gap: 12, fontSize: 14 }}>
            {locked && <p className="badge warn plain" style={{ height: 'auto', padding: '10px 14px', whiteSpace: 'normal' }}>Deze nieuwsbrief is al gestart of verstuurd en kan niet meer worden aangepast.</p>}
            <div className="field"><label htmlFor="nl-subject">Onderwerp</label><input id="nl-subject" className="input" maxLength={150} value={edit.f.subject} disabled={locked} onChange={e => set('subject', e.target.value)} /></div>
            <div className="field"><label htmlFor="nl-body">Tekst (lege regel = nieuwe alinea; geen HTML)</label><textarea id="nl-body" className="textarea" rows={9} maxLength={10000} value={edit.f.body} disabled={locked} onChange={e => set('body', e.target.value)} /></div>
            <div className="form-grid">
              <div className="field"><label htmlFor="nl-cta">Knoptekst (optioneel)</label><input id="nl-cta" className="input" maxLength={60} value={edit.f.cta_label} disabled={locked} onChange={e => set('cta_label', e.target.value)} /></div>
              <div className="field"><label htmlFor="nl-url">Knoplink (https)</label><input id="nl-url" className="input" type="url" placeholder="https://" value={edit.f.cta_url} disabled={locked} onChange={e => set('cta_url', e.target.value)} /></div>
            </div>
            <div className="field"><label htmlFor="nl-lang">Taal van de abonnees</label>
              <select id="nl-lang" className="select" value={edit.f.lang} disabled={locked} onChange={e => set('lang', e.target.value)}>
                <option value="">Alle talen (schrijf dan een tekst voor iedereen)</option>
                {LANGUAGES.map(l => <option key={l.code} value={l.code}>{l.native}{byLang[l.code] != null ? ' (' + byLang[l.code] + ')' : ''}</option>)}
              </select>
            </div>
            {!locked && <div className="field"><label htmlFor="nl-test">Testmail naar (leeg = e-mailadres van de winkel)</label><input id="nl-test" className="input" type="email" value={testTo} onChange={e => setTestTo(e.target.value)} /></div>}
          </div>
        )}
      </Modal>
      {confirmNode}
    </>
  );
}

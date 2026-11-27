import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { Icon, MButton, useToast } from '../../components/ui';
import { api, type PrivacyEraseResult, type PrivacyLogRow, type PrivacyLookup } from '../../lib/api';
import { eur } from '../context';
import { useConfirm } from '../parts';

const fmt = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString('nl-NL', { day: 'numeric', month: 'short', year: 'numeric' }) : '—');

/** AVG: gegevens van een klant opzoeken, exporteren (JSON) en wissen met inachtneming van de wettelijke bewaartermijn; logboek van verzoeken. */
export default function Privacy() {
  const toast = useToast();
  const [ask, confirmNode] = useConfirm();
  const [email, setEmail] = useState('');
  const [data, setData] = useState<PrivacyLookup | null>(null);
  const [result, setResult] = useState<PrivacyEraseResult | null>(null);
  const [busy, setBusy] = useState(false);
  const [log, setLog] = useState<PrivacyLogRow[]>([]);

  const loadLog = useCallback(async () => {
    try { setLog((await api.admin.privacyRequests()).requests); } catch { /* logboek is aanvullend */ }
  }, []);
  useEffect(() => { void loadLog(); }, [loadLog]);

  const search = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true); setResult(null);
    try { setData(await api.admin.privacyLookup(email.trim())); }
    catch (err) { setData(null); toast((err as Error).message, 'error'); }
    finally { setBusy(false); }
  };
  const exportData = async () => {
    if (!data) return;
    try {
      const url = URL.createObjectURL(await api.admin.privacyExport(data.email));
      const a = document.createElement('a');
      a.href = url; a.download = 'gegevens-' + data.email.replace(/[^a-z0-9]+/gi, '-') + '.json';
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(() => URL.revokeObjectURL(url), 10000);
      void loadLog();
    } catch (err) { toast('Export mislukt: ' + (err as Error).message, 'error'); }
  };
  const erase = async () => {
    if (!data) return;
    if (!(await ask('Gegevens van ' + data.email + ' wissen? De nieuwsbriefaanmelding wordt verwijderd, telefoonnummers en toelichtingen gewist en bestellingen ouder dan ' + data.retentionYears + ' jaar volledig geanonimiseerd. Bestel- en factuurgegevens binnen de bewaartermijn blijven wettelijk bewaard. Dit kan niet ongedaan worden gemaakt.'))) return;
    setBusy(true);
    try { setResult(await api.admin.privacyErase(data.email)); setData(await api.admin.privacyLookup(data.email)); void loadLog(); toast('Verzoek verwerkt'); }
    catch (err) { toast((err as Error).message, 'error'); }
    finally { setBusy(false); }
  };

  const nothing = data && data.summary.orders === 0 && !data.summary.newsletter;
  return (
    <>
      {confirmNode}
      <div className="page-intro">
        <p>Klanten kunnen zelf inzage of wissen aanvragen via "Mijn gegevens" in de winkel (met verificatie per e-mail). Hier handelt u verzoeken af die u op een andere manier ontvangt. Bestel- en factuurgegevens moeten fiscaal {data?.retentionYears ?? 7} jaar bewaard blijven (wettelijke verplichting); daarna worden ze dagelijks automatisch geanonimiseerd.</p>
      </div>

      <div className="card" style={{ padding: 18 }}>
        <form onSubmit={search} style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          <input className="input" type="email" required placeholder="E-mailadres van de klant" aria-label="E-mailadres van de klant" value={email} onChange={e => setEmail(e.target.value)} style={{ flex: 1, minWidth: 240 }} />
          <MButton className="btn btn-primary" type="submit" disabled={busy}><Icon name="search" size={16} />Zoeken</MButton>
        </form>

        {data && (
          <div style={{ marginTop: 18, display: 'grid', gap: 14 }}>
            {nothing ? <p className="muted">Geen gegevens gevonden voor {data.email}.</p> : (
              <>
                <div className="kv">
                  <span>Bestellingen</span><span style={{ color: 'var(--ink)' }}>{data.summary.orders}</span>
                  <span>Retouren</span><span style={{ color: 'var(--ink)' }}>{data.summary.returns}</span>
                  <span>Nieuwsbrief</span><span style={{ color: 'var(--ink)' }}>{data.summary.newsletter ? 'Aangemeld' : 'Niet aangemeld'}</span>
                </div>
                {data.orders.length > 0 && (
                  <div className="table-wrap"><table className="t">
                    <thead><tr><th>Bestelling</th><th>Datum</th><th className="num">Totaal</th><th>Status</th><th>Bewaren tot</th></tr></thead>
                    <tbody>{data.orders.map(o => (
                      <tr key={o.id}><td>{o.id}</td><td>{fmt(o.date)}</td><td className="num">{eur(o.total)}</td><td>{o.anonymized ? <span className="badge">Geanonimiseerd</span> : o.status}</td><td>{o.anonymized ? '—' : fmt(o.retainedUntil)}</td></tr>
                    ))}</tbody>
                  </table></div>
                )}
                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                  <button className="btn btn-outline" onClick={exportData}><Icon name="external" size={16} />Gegevens exporteren (JSON)</button>
                  <MButton className="btn btn-accent" disabled={busy} onClick={erase}><Icon name="trash" size={16} />Wissen / anonimiseren</MButton>
                </div>
              </>
            )}
            {result && (
              <div className="card" role="status" style={{ padding: '12px 14px', background: 'var(--bg)', fontSize: 14 }}>
                <b>Verwerkt</b>
                <ul style={{ margin: '6px 0 0', paddingInlineStart: 18 }}>
                  <li>Nieuwsbrief: {result.newsletter_deleted ? 'aanmelding verwijderd' : 'geen aanmelding'}</li>
                  <li>Bestellingen met gewiste telefoon/toelichting: {result.orders_minimized}</li>
                  <li>Volledig geanonimiseerde bestellingen: {result.orders_anonymized}</li>
                  {result.orders_retained.map(o => <li key={o.order}>{o.order} blijft wettelijk bewaard tot {fmt(o.until)}</li>)}
                </ul>
                <p className="hint" style={{ marginTop: 8 }}>Vergeet niet: ook Stripe (betalingen) en CJdropshipping (verzending) hebben gegevens van deze klant; verwijderverzoeken daar doet u in hun eigen omgeving.</p>
              </div>
            )}
          </div>
        )}
      </div>

      <div className="card" style={{ marginTop: 16 }}>
        <div className="section-label" style={{ padding: '14px 16px 0' }}>Logboek privacyverzoeken (bewaard 3 jaar, zonder e-mailadres)</div>
        <div className="table-wrap"><table className="t">
          <thead><tr><th>Datum</th><th>Soort</th><th>Via</th><th>Status</th><th>Referentie</th></tr></thead>
          <tbody>
            {log.length === 0 && <tr><td colSpan={5} className="muted" style={{ textAlign: 'center', padding: 22 }}>Nog geen verzoeken.</td></tr>}
            {log.map(r => (
              <tr key={r.id}>
                <td>{fmt(r.requestedAt)}</td>
                <td>{r.type === 'export' ? 'Inzage/export' : 'Wissen'}</td>
                <td>{r.source === 'admin' ? 'Beheer' : 'Klant (e-mail)'}</td>
                <td><span className={'badge ' + (r.status === 'completed' ? 'success' : 'warn')}>{r.status === 'completed' ? 'Afgerond' : 'Wacht op bevestiging'}</span></td>
                <td className="muted" style={{ fontFamily: 'monospace' }}>{r.who}</td>
              </tr>
            ))}
          </tbody>
        </table></div>
      </div>
    </>
  );
}

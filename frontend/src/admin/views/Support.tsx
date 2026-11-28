import { useState, type FormEvent } from 'react';
import { Icon, MButton, useToast } from '../../components/ui';
import { api } from '../../lib/api';
import type { AiSupportReply } from '../../lib/types';
import { AiNote, useAiStatus } from '../AiBits';

const CATEGORY: Record<string, string> = { tracking: 'Verzending / tracking', return: 'Retour', refund: 'Terugbetaling', damaged: 'Beschadigd / defect', product_question: 'Productvraag', payment: 'Betaling', other: 'Overig' };
const LANG: Record<string, string> = { nl: 'Nederlands', en: 'Engels', de: 'Duits', fr: 'Frans', es: 'Spaans', it: 'Italiaans', pl: 'Pools', ar: 'Arabisch' };

/**
 * Klantenservice-assistent: plak een klantbericht (en eventueel het bestelnummer), Claude vertaalt het naar het Nederlands, vat het samen
 * en stelt een antwoord voor in de taal van de klant. U leest en past het aan en verstuurt het zelf; er wordt niets automatisch verzonden.
 * E-mailadressen, telefoonnummers en IBAN's in het bericht worden vóór verzending uit de tekst gehaald; naam en adres van de klant gaan nooit mee.
 */
export default function Support() {
  const toast = useToast();
  const [message, setMessage] = useState('');
  const [orderNo, setOrderNo] = useState('');
  const [busy, setBusy] = useState(false);
  const [out, setOut] = useState<AiSupportReply | null>(null);
  const [reply, setReply] = useState('');
  const status = useAiStatus(out);

  const run = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true);
    try {
      const r = await api.admin.ai.supportReply({ message, ...(orderNo.trim() ? { order_no: orderNo.trim() } : {}) });
      setOut(r); setReply(r.reply);
    } catch (err) { toast((err as Error).message, 'error'); }
    finally { setBusy(false); }
  };
  const copy = async () => { try { await navigator.clipboard.writeText(reply); toast('Antwoord gekopieerd'); } catch { toast('Kopiëren is niet gelukt; selecteer de tekst handmatig', 'error'); } };

  return (
    <>
      <div className="page-intro"><p>Plak een bericht van een klant in elke taal. U krijgt een Nederlandse vertaling en samenvatting, en een antwoord in de taal van de klant om na te lezen, aan te passen en zelf te versturen. Met een bestelnummer gebruikt de AI de verzend- en retourgegevens van die bestelling (zonder naam, adres of e-mail).</p></div>
      <div className="grid-2">
        <form className="card" onSubmit={run}>
          <div className="card-head"><div><h3>Klantbericht</h3><p>Persoonsgegevens zoals e-mail en telefoon worden automatisch verwijderd voordat de tekst naar de AI gaat.</p></div></div>
          <div className="card-body" style={{ display: 'grid', gap: 12 }}>
            <div className="field"><label htmlFor="sup-msg">Bericht van de klant</label><textarea className="textarea" id="sup-msg" rows={9} maxLength={4000} required value={message} onChange={e => setMessage(e.target.value)} /></div>
            <div className="field"><label htmlFor="sup-order">Bestelnummer (optioneel)</label><input className="input" id="sup-order" placeholder="RB-…" maxLength={30} value={orderNo} onChange={e => setOrderNo(e.target.value)} /></div>
            <div><MButton className="btn btn-primary" type="submit" disabled={busy || message.trim().length < 2 || (status !== null && !status.enabled)}><Icon name="wand" size={16} />{busy ? 'Bezig…' : 'Antwoord voorstellen'}</MButton></div>
            <AiNote status={status} />
          </div>
        </form>

        <div className="card">
          <div className="card-head"><div><h3>Voorstel</h3><p>{out ? (LANG[out.language] ?? out.language) + ' · ' + (CATEGORY[out.category] ?? out.category) : 'Nog geen voorstel'}</p></div></div>
          <div className="card-body" style={{ display: 'grid', gap: 12, fontSize: 14 }}>
            {!out && <p className="muted">Het voorstel verschijnt hier.</p>}
            {out && (
              <>
                <div><div className="section-label">Samenvatting</div><p>{out.summary_nl}</p></div>
                {out.language !== 'nl' && <div><div className="section-label">Vertaling naar het Nederlands</div><p className="muted">{out.translation_nl}</p></div>}
                {out.actions.length > 0 && <div><div className="section-label">Voor u</div><ul style={{ margin: 0, paddingInlineStart: 18 }}>{out.actions.map(a => <li key={a}>{a}</li>)}</ul></div>}
                <div className="field">
                  <label htmlFor="sup-reply">Concept-antwoord ({LANG[out.language] ?? out.language})</label>
                  <textarea className="textarea" id="sup-reply" rows={10} dir="auto" value={reply} onChange={e => setReply(e.target.value)} />
                </div>
                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                  <button className="btn btn-primary" type="button" onClick={() => void copy()}><Icon name="mail" size={16} />Kopiëren</button>
                  <button className="btn btn-ghost" type="button" onClick={() => setReply(out.reply)}>Origineel herstellen</button>
                </div>
                <p className="hint">Controleer altijd de feiten (levertijd, tracking, retourregels) en beloof niets wat u niet kunt waarmaken.</p>
              </>
            )}
          </div>
        </div>
      </div>
    </>
  );
}

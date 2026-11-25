import { AnimatePresence, motion } from 'motion/react';
import { useState } from 'react';
import { Icon, MButton, useToast } from '../../components/ui';
import { useAdmin } from '../context';

/* Voorbeeld-inbox. Echte e-mails koppelen (bijv. via een mailbox-API) volgt in een volgende fase. */
const TICKETS = [
  { id: 't1', lang: 'DE', subject: 'Wo bleibt meine Bestellung?', from: 'M. Müller', time: '09:12', msg: 'Hallo, ich habe vor einer Woche bestellt und noch keine Versandbestätigung erhalten. Können Sie mir sagen, wann mein Paket ankommt?', nl: 'Hallo, ik heb een week geleden besteld en nog geen verzendbevestiging ontvangen. Kunt u mij vertellen wanneer mijn pakket aankomt?', reply: 'Sehr geehrte Kundin, sehr geehrter Kunde,\n\nvielen Dank für Ihre Nachricht. Ihre Bestellung wurde an unser Lager übermittelt und wird in Kürze versendet. Sobald Ihr Paket unterwegs ist, erhalten Sie die Sendungsverfolgungsnummer per E-Mail.\n\nMit freundlichen Grüßen\nKundenservice Rebazz' },
  { id: 't2', lang: 'EN', subject: 'Tracking not updating', from: 'O. Nilsson', time: '08:47', msg: 'Hi, my tracking hasn’t updated in 6 days. Is my package lost?', nl: 'Hallo, mijn tracking is al 6 dagen niet bijgewerkt. Is mijn pakket kwijt?', reply: 'Dear customer,\n\nThank you for reaching out, and please accept our apologies for the delay. We have contacted the carrier: the shipment is in transit and has been delayed at a sorting centre. Should there be no update within 5 working days, we will send a replacement free of charge or issue a full refund.\n\nKind regards,\nRebazz Customer Service' },
  { id: 't3', lang: 'NL', subject: 'Retour aanmelden', from: 'K. Peeters', time: 'Gisteren', msg: 'Ik wil graag een product retourneren. Hoe gaat dat in zijn werk?', nl: '', reply: 'Geachte heer/mevrouw Peeters,\n\nHartelijk dank voor uw bericht. U kunt uw product binnen 30 dagen na ontvangst retourneren. Wij sturen u per e-mail het retouradres. Zodra wij het product in goede staat hebben ontvangen, betalen wij het aankoopbedrag binnen 14 dagen terug.\n\nMet vriendelijke groet,\nKlantenservice Rebazz' }
];

export default function Support() {
  const toast = useToast();
  const { status } = useAdmin();
  const [active, setActive] = useState('t1');
  const [done, setDone] = useState<string[]>([]);
  const t = TICKETS.find(x => x.id === active)!;
  const [reply, setReply] = useState(t.reply);
  return (
    <>
      <div className="page-intro"><p>Voorbeeld van de meertalige inbox: berichten worden naar het Nederlands vertaald en de AI stelt een antwoord voor in de taal van de klant. {status.mail ? '' : 'Stel eerst e-mail in om antwoorden te kunnen versturen.'}</p></div>
      <div className="card inbox">
        <div className="inbox-list">
          {TICKETS.map(x => (
            <motion.div key={x.id} className={'ticket' + (x.id === active ? ' active' : '')} onClick={() => { setActive(x.id); setReply(x.reply); }} whileHover={{ x: 2 }}>
              <div className="row1"><b>{x.from}</b><span className="muted" style={{ fontSize: 12 }}>{x.time}</span></div>
              <div style={{ display: 'flex', gap: 6, alignItems: 'center', marginTop: 4 }}><span className="badge plain" style={{ height: 20, fontSize: 11 }}>{x.lang}</span><span className={'badge ' + (done.includes(x.id) ? 'success' : 'accent')} style={{ height: 20, fontSize: 11 }}>{done.includes(x.id) ? 'Beantwoord' : 'Open'}</span><span style={{ fontSize: 13, fontWeight: 600 }}>{x.subject}</span></div>
              <p>{x.nl || x.msg}</p>
            </motion.div>
          ))}
        </div>
        <AnimatePresence mode="wait">
          <motion.div key={t.id} className="thread" initial={{ opacity: 0, x: 10 }} animate={{ opacity: 1, x: 0 }} exit={{ opacity: 0 }}>
            <div><h3 style={{ fontSize: 17 }}>{t.subject}</h3><span className="muted" style={{ fontSize: 13 }}>{t.from} · taal: {t.lang}</span></div>
            <div className="msg">{t.msg}{t.nl && <div style={{ borderTop: '1px solid var(--line)', marginTop: 10, paddingTop: 10, fontSize: 13, color: 'var(--muted)' }}><b><Icon name="globe" size={13} /> AI-vertaling:</b> {t.nl}</div>}</div>
            <div className="ai-draft">
              <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 10, color: 'var(--accent)', fontWeight: 600, fontSize: 13.5 }}><Icon name="wand" size={16} />AI-antwoordsuggestie ({t.lang})</div>
              <textarea className="textarea" id="replyText" rows={9} value={reply} onChange={e => setReply(e.target.value)} />
              <div style={{ display: 'flex', gap: 8, marginTop: 12, flexWrap: 'wrap' }}><MButton className="btn btn-primary" onClick={() => { setDone(d => [...new Set([...d, t.id])]); toast('Voorbeeld: antwoord gemarkeerd als verzonden'); }}><Icon name="mail" size={16} />Antwoord versturen</MButton></div>
            </div>
          </motion.div>
        </AnimatePresence>
      </div>
    </>
  );
}

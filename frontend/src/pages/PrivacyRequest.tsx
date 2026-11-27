import { useState, type FormEvent } from 'react';
import { useLocation } from 'react-router-dom';
import ShopChrome from '../components/ShopChrome';
import { MButton } from '../components/ui';
import { api, ApiError, type PrivacyEraseResult } from '../lib/api';
import { useI18n } from '../lib/i18n';

/** AVG: "Mijn gegevens". Stap 1 (mode=request): e-mailadres + keuze. Stap 2 (mode=confirm): wissen bevestigen via de link uit de e-mail. */
export default function PrivacyRequest({ mode }: { mode: 'request' | 'confirm' }) {
  return <ShopChrome><div className="container" style={{ maxWidth: 640, padding: '40px 20px 80px' }}>{mode === 'request' ? <RequestForm /> : <Confirm />}</div></ShopChrome>;
}

function RequestForm() {
  const { t } = useI18n();
  const [email, setEmail] = useState('');
  const [type, setType] = useState<'export' | 'erase'>('export');
  const [busy, setBusy] = useState(false);
  const [sent, setSent] = useState(false);
  const [error, setError] = useState('');
  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true); setError('');
    try { await api.privacyRequest(email.trim(), type); setSent(true); }
    catch { setError(t('co_error')); }
    finally { setBusy(false); }
  };
  return (
    <>
      <h1 style={{ fontFamily: 'var(--serif)', fontWeight: 500, fontSize: 34 }}>{t('pv_title')}</h1>
      <p className="muted" style={{ margin: '10px 0 22px' }}>{t('pv_lead')}</p>
      {sent ? <p className="badge success plain" role="status" style={{ height: 'auto', padding: '14px 16px', whiteSpace: 'normal', lineHeight: 1.5 }}>{t('pv_sent')}</p> : (
        <form onSubmit={submit} style={{ display: 'grid', gap: 14 }}>
          <input className="input" type="email" required maxLength={200} placeholder={t('track_email_ph')} aria-label={t('track_email_ph')} value={email} onChange={e => setEmail(e.target.value)} />
          <fieldset style={{ border: 0, padding: 0, margin: 0, display: 'grid', gap: 8 }}>
            <label style={{ display: 'flex', gap: 10, alignItems: 'center' }}><input type="radio" name="pv" checked={type === 'export'} onChange={() => setType('export')} />{t('pv_export')}</label>
            <label style={{ display: 'flex', gap: 10, alignItems: 'center' }}><input type="radio" name="pv" checked={type === 'erase'} onChange={() => setType('erase')} />{t('pv_erase')}</label>
          </fieldset>
          {error && <p className="badge danger plain" role="alert" style={{ height: 'auto', padding: '10px 14px', whiteSpace: 'normal' }}>{error}</p>}
          <MButton className="btn btn-primary" type="submit" disabled={busy}>{t('pv_submit')}</MButton>
        </form>
      )}
    </>
  );
}

function Confirm() {
  const { t, locale } = useI18n();
  const { search } = useLocation();
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState<PrivacyEraseResult | null>(null);
  const [error, setError] = useState('');
  const go = async () => {
    setBusy(true); setError('');
    try { setDone(await api.privacyErase(search)); }
    catch (e) { setError(e instanceof ApiError && e.status === 403 ? t('pv_invalid') : t('co_error')); } // 403 = ongeldige/verlopen link; andere fouten (bijv. te veel verzoeken) zijn geen reden om de link ongeldig te noemen
    finally { setBusy(false); }
  };
  const date = (d: string) => new Date(d).toLocaleDateString(locale, { day: 'numeric', month: 'long', year: 'numeric' });
  return (
    <>
      <h1 style={{ fontFamily: 'var(--serif)', fontWeight: 500, fontSize: 34 }}>{t('pv_confirm_title')}</h1>
      {!done ? (
        <>
          <p className="muted" style={{ margin: '10px 0 22px', lineHeight: 1.55 }}>{t('pv_confirm_text')}</p>
          {error && <p className="badge danger plain" role="alert" style={{ height: 'auto', padding: '10px 14px', whiteSpace: 'normal', marginBottom: 12 }}>{error}</p>}
          <MButton className="btn btn-primary" onClick={go} disabled={busy || !search}>{t('pv_confirm_btn')}</MButton>
        </>
      ) : (
        <div role="status" style={{ display: 'grid', gap: 10, marginTop: 16 }}>
          <b>{t('pv_done')}</b>
          {done.newsletter_deleted && <p>{t('pv_done_newsletter')}</p>}
          {(done.orders_minimized > 0 || done.orders_anonymized > 0) && <p>{t('pv_done_orders', { n: done.orders_minimized, a: done.orders_anonymized })}</p>}
          {done.orders_retained.length > 0 && (
            <div className="card" style={{ padding: '10px 14px', fontSize: 14 }}>
              <b>{t('pv_done_kept')}</b>
              <ul style={{ margin: '6px 0 0', paddingInlineStart: 18 }}>{done.orders_retained.map(o => <li key={o.order}>{o.order}: {date(o.until)}</li>)}</ul>
            </div>
          )}
        </div>
      )}
    </>
  );
}

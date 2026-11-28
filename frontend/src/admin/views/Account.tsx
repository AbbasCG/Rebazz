import { useState, type FormEvent } from 'react';
import { MButton, useToast } from '../../components/ui';
import { api } from '../../lib/api';
import type { StaffMe } from '../../lib/types';

export const ROLE_LABEL: Record<StaffMe['role'], string> = { owner: 'Eigenaar', manager: 'Manager', support: 'Klantenservice', marketing: 'Marketing' };

const group = (s: string) => s.replace(/(.{4})/g, '$1 ').trim();

/** Eigen account: wachtwoord wijzigen en tweestapsverificatie (authenticator-app + herstelcodes). Ook het scherm dat verplichte 2FA afdwingt. */
export default function Account({ me, onMe, forced = false }: { me: StaffMe; onMe: (m: StaffMe) => void; forced?: boolean }) {
  const toast = useToast();
  const [setup, setSetup] = useState<{ secret: string; uri: string } | null>(null);
  const [code, setCode] = useState('');
  const [codes, setCodes] = useState<string[] | null>(null);
  const [busy, setBusy] = useState(false);
  const [cur, setCur] = useState('');
  const [next, setNext] = useState('');
  const [mode, setMode] = useState<'' | 'disable' | 'recovery'>('');
  const [confirmPw, setConfirmPw] = useState('');
  const [confirmCode, setConfirmCode] = useState('');

  const run = async (fn: () => Promise<void>) => {
    setBusy(true);
    try { await fn(); } catch (e) { toast((e as Error).message, 'error'); } finally { setBusy(false); }
  };
  const start = () => run(async () => { setSetup(await api.admin.twoFactorSetup()); setCode(''); });
  const enable = (e: FormEvent) => { e.preventDefault(); void run(async () => { const r = await api.admin.twoFactorEnable(code.trim()); setCodes(r.recoveryCodes); setSetup(null); onMe(r); toast('Tweestapsverificatie staat aan'); }); };
  const confirm = (e: FormEvent) => {
    e.preventDefault();
    void run(async () => {
      if (mode === 'disable') { onMe(await api.admin.twoFactorDisable(confirmPw, confirmCode.trim())); toast('Tweestapsverificatie staat uit'); }
      else { const r = await api.admin.twoFactorRecovery(confirmPw, confirmCode.trim()); setCodes(r.recoveryCodes); onMe(r); toast('Nieuwe herstelcodes aangemaakt'); }
      setMode(''); setConfirmPw(''); setConfirmCode('');
    });
  };
  const changePw = (e: FormEvent) => { e.preventDefault(); void run(async () => { await api.admin.changePassword(cur, next); setCur(''); setNext(''); toast('Wachtwoord gewijzigd; andere sessies zijn uitgelogd'); }); };
  const download = () => {
    const url = URL.createObjectURL(new Blob(['Rebazz beheer: herstelcodes voor ' + me.email + '\nElke code werkt één keer.\n\n' + (codes ?? []).join('\n') + '\n'], { type: 'text/plain' }));
    const a = document.createElement('a'); a.href = url; a.download = 'rebazz-herstelcodes.txt'; a.click(); setTimeout(() => URL.revokeObjectURL(url), 5000);
  };

  return (
    <div style={{ display: 'grid', gap: 20, maxWidth: 720 }}>
      {forced && <p className="badge warn plain" style={{ height: 'auto', padding: '12px 16px', whiteSpace: 'normal' }} role="alert">Voor dit beheer is tweestapsverificatie verplicht. Stel het eerst in om verder te gaan.</p>}

      <div className="card">
        <div className="card-head"><div><h3>Mijn account</h3><p>{me.name} · {me.email} · {ROLE_LABEL[me.role]}</p></div></div>
      </div>

      <div className="card">
        <div className="card-head"><div><h3>Tweestapsverificatie</h3><p>{me.twoFactor.enabled ? 'Aan' + (me.twoFactor.since ? ' sinds ' + new Date(me.twoFactor.since).toLocaleDateString('nl-NL') : '') + ' · ' + me.twoFactor.recoveryLeft + ' herstelcode(s) over' : 'Uit: inloggen met alleen een wachtwoord'}</p></div>
          <span className={'badge ' + (me.twoFactor.enabled ? 'success' : 'warn')}>{me.twoFactor.enabled ? 'Aan' : 'Uit'}</span></div>
        <div className="card-body" style={{ display: 'grid', gap: 14, fontSize: 14 }}>
          {codes && (
            <div className="card" style={{ padding: 14, background: 'var(--bg)' }} role="status">
              <b>Bewaar deze herstelcodes nu veilig.</b> Ze worden maar één keer getoond; elke code werkt één keer als u uw telefoon kwijt bent.
              <pre style={{ margin: '10px 0', fontSize: 15, lineHeight: 1.7, userSelect: 'all' }}>{codes.join('\n')}</pre>
              <div style={{ display: 'flex', gap: 8 }}>
                <button className="btn btn-outline btn-sm" type="button" onClick={download}>Downloaden</button>
                <button className="btn btn-ghost btn-sm" type="button" onClick={() => setCodes(null)}>Ik heb ze bewaard</button>
              </div>
            </div>
          )}

          {!me.twoFactor.enabled && !setup && <div><MButton className="btn btn-primary" onClick={() => void start()} disabled={busy}>Instellen</MButton></div>}

          {setup && (
            <form onSubmit={enable} style={{ display: 'grid', gap: 12 }}>
              <ol style={{ margin: 0, paddingInlineStart: 20, display: 'grid', gap: 6 }}>
                <li>Open een authenticator-app (Google Authenticator, Microsoft Authenticator, 1Password, Authy…).</li>
                <li>Kies <i>Account toevoegen → Sleutel handmatig invoeren</i> en typ deze sleutel (op een telefoon opent <a href={setup.uri}>deze link</a> de app):<br />
                  <code style={{ fontSize: 16, letterSpacing: 1, userSelect: 'all' }}>{group(setup.secret)}</code></li>
                <li>Voer hieronder de code van 6 cijfers uit de app in.</li>
              </ol>
              <div className="field"><label htmlFor="tf-code">Code uit de app</label><input id="tf-code" className="input" inputMode="numeric" autoComplete="one-time-code" maxLength={7} required value={code} onChange={e => setCode(e.target.value.replace(/[^\d ]/g, ''))} /></div>
              <div style={{ display: 'flex', gap: 8 }}><MButton className="btn btn-primary" type="submit" disabled={busy || code.replace(/\s/g, '').length !== 6}>Bevestigen en inschakelen</MButton><button className="btn btn-ghost" type="button" onClick={() => setSetup(null)}>Annuleren</button></div>
            </form>
          )}

          {me.twoFactor.enabled && !mode && (
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
              <button className="btn btn-outline" type="button" onClick={() => setMode('recovery')}>Nieuwe herstelcodes</button>
              {!me.require2fa && <button className="btn btn-outline" type="button" onClick={() => setMode('disable')}>Uitzetten</button>}
            </div>
          )}
          {mode && (
            <form onSubmit={confirm} style={{ display: 'grid', gap: 12 }}>
              <p className="muted">{mode === 'disable' ? 'Bevestig met uw wachtwoord en een code om tweestapsverificatie uit te zetten.' : 'Bevestig met uw wachtwoord en een code; de oude herstelcodes vervallen.'}</p>
              <div className="field"><label htmlFor="tf-pw">Wachtwoord</label><input id="tf-pw" className="input" type="password" autoComplete="current-password" required value={confirmPw} onChange={e => setConfirmPw(e.target.value)} /></div>
              <div className="field"><label htmlFor="tf-c2">Code uit de app (of herstelcode)</label><input id="tf-c2" className="input" autoComplete="one-time-code" required value={confirmCode} onChange={e => setConfirmCode(e.target.value)} /></div>
              <div style={{ display: 'flex', gap: 8 }}><MButton className="btn btn-primary" type="submit" disabled={busy}>Bevestigen</MButton><button className="btn btn-ghost" type="button" onClick={() => setMode('')}>Annuleren</button></div>
            </form>
          )}
          {!me.twoFactor.enabled && <p className="muted" style={{ fontSize: 13 }}>Uw eigenaar kan 2FA voor u terugzetten als u uw telefoon kwijtraakt; bewaar daarom ook de herstelcodes.</p>}
        </div>
      </div>

      {!forced && (
        <div className="card">
          <div className="card-head"><div><h3>Wachtwoord wijzigen</h3><p>{me.breakGlass ? 'Het eigenaarswachtwoord wijzigt u via ADMIN_PASSWORD in het .env-bestand van de server.' : 'Minimaal 12 tekens. Andere sessies worden uitgelogd.'}</p></div></div>
          {!me.breakGlass && (
            <form className="card-body" onSubmit={changePw} style={{ display: 'grid', gap: 12 }}>
              <div className="field"><label htmlFor="pw-cur">Huidig wachtwoord</label><input id="pw-cur" className="input" type="password" autoComplete="current-password" required value={cur} onChange={e => setCur(e.target.value)} /></div>
              <div className="field"><label htmlFor="pw-new">Nieuw wachtwoord</label><input id="pw-new" className="input" type="password" autoComplete="new-password" minLength={12} required value={next} onChange={e => setNext(e.target.value)} /></div>
              <div><MButton className="btn btn-primary" type="submit" disabled={busy || next.length < 12 || !cur}>Wijzigen</MButton></div>
            </form>
          )}
        </div>
      )}
    </div>
  );
}

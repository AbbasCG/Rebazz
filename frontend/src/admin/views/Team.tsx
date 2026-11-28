import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { Icon, MButton, useToast } from '../../components/ui';
import { api } from '../../lib/api';
import type { AuditEntry, StaffMe, TeamMember } from '../../lib/types';
import { Modal, useConfirm } from '../parts';
import { ROLE_LABEL } from './Account';

const ROLE_INFO: Record<string, string> = {
  owner: 'Alles, inclusief medewerkers beheren',
  manager: 'Alles behalve medewerkers beheren (bestellingen, terugbetalen, producten, marketing, privacy, btw, instellingen)',
  support: 'Bestellingen en retouren (zonder terugbetalen)',
  marketing: 'Producten, nieuwsbrief, statistieken en advertenties; geen klantgegevens'
};
const fmt = (iso: string | null) => (iso ? new Date(iso).toLocaleString('nl-NL', { day: 'numeric', month: 'short', year: '2-digit', hour: '2-digit', minute: '2-digit' }) : 'Nog nooit');
const EMPTY = { name: '', email: '', role: 'support', password: '' };

/** Medewerkers en rollen (alleen eigenaar) plus het activiteitenlogboek. */
export default function Team() {
  const toast = useToast();
  const [ask, confirmNode] = useConfirm();
  const [tab, setTab] = useState<'team' | 'log'>('team');
  const [members, setMembers] = useState<TeamMember[]>([]);
  const [log, setLog] = useState<AuditEntry[]>([]);
  const [loading, setLoading] = useState(true);
  const [form, setForm] = useState<{ id: number | null; f: typeof EMPTY } | null>(null);
  const [busy, setBusy] = useState(false);
  const [require2fa, setRequire2fa] = useState(false);

  const load = useCallback(async () => {
    try { const r = await api.admin.team(); setMembers(r.members); setRequire2fa(r.require2fa); }
    catch (e) { toast('Team laden mislukt: ' + (e as Error).message, 'error'); }
    finally { setLoading(false); }
  }, [toast]);
  useEffect(() => { void load(); }, [load]);
  useEffect(() => { if (tab === 'log') api.admin.audit().then(r => setLog(r.log)).catch(e => toast((e as Error).message, 'error')); }, [tab, toast]);

  const save = async (e: FormEvent) => {
    e.preventDefault();
    if (!form) return;
    setBusy(true);
    try {
      if (form.id) await api.admin.updateMember(form.id, { name: form.f.name, role: form.f.role, ...(form.f.password ? { password: form.f.password } : {}) });
      else await api.admin.createMember(form.f);
      setForm(null); await load(); toast(form.id ? 'Medewerker bijgewerkt' : 'Medewerker toegevoegd');
    } catch (err) { toast((err as Error).message, 'error'); } finally { setBusy(false); }
  };
  const act = async (fn: () => Promise<unknown>, msg: string) => { try { await fn(); await load(); toast(msg); } catch (e) { toast((e as Error).message, 'error'); } };
  const toggle = (m: TeamMember) => act(() => api.admin.updateMember(m.id, { active: !m.active }), m.active ? 'Gedeactiveerd en uitgelogd' : 'Geactiveerd');
  const reset = async (m: TeamMember) => { if (await ask('Tweestapsverificatie van ' + m.name + ' uitzetten? De medewerker wordt uitgelogd en stelt het opnieuw in.')) await act(() => api.admin.resetMember2fa(m.id), '2FA gereset'); };
  const remove = async (m: TeamMember) => { if (await ask(m.name + ' definitief verwijderen? De sessies worden direct beëindigd.')) await act(() => api.admin.deleteMember(m.id), 'Verwijderd'); };
  const set = (k: keyof typeof EMPTY, v: string) => setForm(s => (s ? { ...s, f: { ...s.f, [k]: v } } : s));

  return (
    <>
      <div className="page-intro">
        <p>Geef medewerkers een eigen account met een rol, zodat niemand het eigenaarswachtwoord hoeft te kennen. {require2fa ? 'Tweestapsverificatie is verplicht voor iedereen.' : <>Zet <code>SHOP_REQUIRE_2FA=on</code> in <code>.env</code> om tweestapsverificatie voor iedereen te verplichten.</>}</p>
        <MButton className="btn btn-primary" onClick={() => setForm({ id: null, f: EMPTY })}><Icon name="plus" size={16} />Medewerker toevoegen</MButton>
      </div>

      <div className="card">
        <div className="tabs" role="tablist">
          <button className={'tab' + (tab === 'team' ? ' active' : '')} role="tab" aria-selected={tab === 'team'} onClick={() => setTab('team')}>Team<span className="c">{members.length}</span></button>
          <button className={'tab' + (tab === 'log' ? ' active' : '')} role="tab" aria-selected={tab === 'log'} onClick={() => setTab('log')}>Activiteit</button>
        </div>

        {tab === 'team' && (
          <div className="table-wrap"><table className="t">
            <thead><tr><th>Medewerker</th><th>Rol</th><th>2FA</th><th>Laatste login</th><th /></tr></thead>
            <tbody>
              {loading && <tr><td colSpan={5}><div className="skeleton" style={{ height: 40 }} /></td></tr>}
              {members.map(m => (
                <tr key={m.id} style={m.active ? undefined : { opacity: 0.55 }}>
                  <td><b>{m.name}</b>{m.breakGlass && <span className="badge" style={{ marginInlineStart: 8 }}>ADMIN_PASSWORD</span>}{!m.active && <span className="badge danger" style={{ marginInlineStart: 8 }}>Inactief</span>}<br /><span className="muted" style={{ fontSize: 12 }}>{m.email}</span></td>
                  <td title={ROLE_INFO[m.role]}>{ROLE_LABEL[m.role]}</td>
                  <td><span className={'badge ' + (m.twoFactor ? 'success' : 'warn')}>{m.twoFactor ? 'Aan' : 'Uit'}</span></td>
                  <td>{fmt(m.lastLogin)}</td>
                  <td style={{ whiteSpace: 'nowrap', textAlign: 'end' }}>
                    {!m.breakGlass && <>
                      <button className="btn btn-ghost btn-sm" onClick={() => setForm({ id: m.id, f: { name: m.name, email: m.email, role: m.role, password: '' } })}>Wijzigen</button>
                      <button className="btn btn-ghost btn-sm" onClick={() => void toggle(m)}>{m.active ? 'Deactiveren' : 'Activeren'}</button>
                      {m.twoFactor && <button className="btn btn-ghost btn-sm" onClick={() => void reset(m)}>2FA resetten</button>}
                      <button className="icon-btn" aria-label={'Verwijder ' + m.name} onClick={() => void remove(m)}><Icon name="trash" size={16} /></button>
                    </>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table></div>
        )}

        {tab === 'log' && (
          <div className="table-wrap"><table className="t">
            <thead><tr><th>Moment</th><th>Wie</th><th>Actie</th><th>Details</th><th>IP</th></tr></thead>
            <tbody>
              {log.length === 0 && <tr><td colSpan={5} className="muted" style={{ textAlign: 'center', padding: 24 }}>Nog geen activiteit.</td></tr>}
              {log.map(l => <tr key={l.id}><td style={{ whiteSpace: 'nowrap' }}>{fmt(l.created_at)}</td><td>{l.user_label ?? '—'}</td><td>{l.action}</td><td className="muted">{l.detail}</td><td className="muted">{l.ip}</td></tr>)}
            </tbody>
          </table></div>
        )}
      </div>

      <div className="card" style={{ padding: 16, marginTop: 20, fontSize: 14 }}>
        <b>Rollen</b>
        <ul style={{ margin: '8px 0 0', paddingInlineStart: 18, display: 'grid', gap: 4 }}>
          {(Object.keys(ROLE_INFO) as StaffMe['role'][]).map(r => <li key={r}><b>{ROLE_LABEL[r]}</b>: {ROLE_INFO[r]}</li>)}
        </ul>
      </div>

      <Modal title={form?.id ? 'Medewerker wijzigen' : 'Medewerker toevoegen'} open={!!form} onClose={() => setForm(null)}>
        {form && (
          <form onSubmit={save} style={{ display: 'grid', gap: 12, fontSize: 14 }}>
            <div className="field"><label htmlFor="tm-name">Naam</label><input id="tm-name" className="input" required maxLength={100} value={form.f.name} onChange={e => set('name', e.target.value)} /></div>
            <div className="field"><label htmlFor="tm-email">E-mail (inlognaam)</label><input id="tm-email" className="input" type="email" required disabled={!!form.id} value={form.f.email} onChange={e => set('email', e.target.value)} /></div>
            <div className="field"><label htmlFor="tm-role">Rol</label>
              <select id="tm-role" className="select" value={form.f.role} onChange={e => set('role', e.target.value)}>
                {(Object.keys(ROLE_INFO) as StaffMe['role'][]).map(r => <option key={r} value={r}>{ROLE_LABEL[r]}</option>)}
              </select>
              <p className="hint">{ROLE_INFO[form.f.role]}</p>
            </div>
            <div className="field"><label htmlFor="tm-pw">{form.id ? 'Nieuw wachtwoord (leeg = ongewijzigd)' : 'Startwachtwoord (minimaal 12 tekens)'}</label>
              <input id="tm-pw" className="input" type="password" autoComplete="new-password" minLength={12} required={!form.id} value={form.f.password} onChange={e => set('password', e.target.value)} />
              <p className="hint">Geef het wachtwoord veilig door (niet per e-mail); de medewerker wijzigt het daarna onder <i>Mijn account</i>. Een rol- of wachtwoordwijziging logt de medewerker direct uit.</p>
            </div>
            <div style={{ display: 'flex', gap: 8 }}><MButton className="btn btn-primary" type="submit" disabled={busy}>Opslaan</MButton><button className="btn btn-ghost" type="button" onClick={() => setForm(null)}>Annuleren</button></div>
          </form>
        )}
      </Modal>
      {confirmNode}
    </>
  );
}

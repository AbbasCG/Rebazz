import { useState, type FormEvent } from 'react';
import { api } from '../lib/api';

/** Online herroepingsformulier (bevestiging per e-mail naar de klant, melding naar de winkel). */
export function WithdrawalForm() {
  const [f, setF] = useState({ name: '', email: '', order_no: '', items: '', message: '' });
  const [state, setState] = useState<'idle' | 'sending' | 'done' | 'error'>('idle');
  const [error, setError] = useState('');
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF(v => ({ ...v, [k]: e.target.value }));

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setState('sending');
    try {
      await api.withdrawal(f);
      setState('done');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Er ging iets mis.');
      setState('error');
    }
  };

  if (state === 'done') {
    return <p className="badge success plain" role="status" style={{ height: 'auto', padding: '12px 16px', whiteSpace: 'normal' }}>Uw herroeping is verzonden. U ontvangt een bevestiging per e-mail.</p>;
  }
  return (
    <form onSubmit={submit} style={{ display: 'grid', gap: 10, maxWidth: 520, marginTop: 16 }} aria-label="Herroepingsformulier">
      <h4>Online herroepen</h4>
      <input className="input" required placeholder="Naam" aria-label="Naam" value={f.name} onChange={set('name')} maxLength={150} />
      <input className="input" required type="email" placeholder="E-mailadres" aria-label="E-mailadres" value={f.email} onChange={set('email')} maxLength={200} />
      <input className="input" required placeholder="Bestelnummer (RB-…)" aria-label="Bestelnummer" value={f.order_no} onChange={set('order_no')} maxLength={30} />
      <input className="input" required placeholder="Producten die u herroept" aria-label="Producten" value={f.items} onChange={set('items')} maxLength={1000} />
      <textarea className="input" placeholder="Opmerking (optioneel)" aria-label="Opmerking" value={f.message} onChange={set('message')} maxLength={2000} rows={3} />
      <button className="btn btn-primary" type="submit" disabled={state === 'sending'}>{state === 'sending' ? 'Verzenden…' : 'Herroeping versturen'}</button>
      {state === 'error' && <p className="badge danger plain" role="alert" style={{ height: 'auto', padding: '10px 14px', whiteSpace: 'normal' }}>{error}</p>}
    </form>
  );
}

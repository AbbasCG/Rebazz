import { useEffect, useState } from 'react';
import { useToast } from '../../components/ui';
import { api } from '../../lib/api';
import { useAdmin } from '../context';

interface Auto { id: string; group: string; name: string; desc: string; on: boolean }
const DEFAULTS: Omit<Auto, 'on'>[] = [
  { id: 'a8', group: 'Marketing', name: 'Verlaten winkelwagen', desc: 'Herinneringsmail na 1 uur en 24 uur, met optionele korting.' },
  { id: 'a9', group: 'Marketing', name: 'Welkomstreeks', desc: 'Drie e-mails voor nieuwe abonnees van de nieuwsbrief.' },
  { id: 'a10', group: 'Marketing', name: 'Reviewverzoek', desc: '7 dagen na aflevering wordt om een beoordeling gevraagd.' },
  { id: 'a6', group: 'Producten', name: 'Prijsbewaking leverancier', desc: 'Verkoopprijs past zich aan bij inkoopwijzigingen, met behoud van marge.' },
  { id: 'a12', group: 'Klantenservice', name: 'AI-antwoordsuggesties', desc: 'Stelt antwoorden voor op vragen over levering en retouren.' }
];

export default function Automations() {
  const { status } = useAdmin();
  const toast = useToast();
  const [state, setState] = useState<Record<string, boolean>>({});
  useEffect(() => { api.admin.setting<Record<string, boolean>>('automations').then(r => setState(r.value ?? {})).catch(() => {}); }, []);

  const live: [string, string, boolean][] = [
    ['Automatische orderverwerking (CJ)', 'Betaalde bestellingen gaan direct naar CJdropshipping.', status.cj && status.webhook],
    ['Automatisch betalen met CJ-saldo', 'CJ-bestellingen worden direct betaald.', status.cj && status.cjAutoPay],
    ['Track & trace synchroniseren', 'Elke 2 uur trackingcodes ophalen bij CJ (Laravel-scheduler).', status.cj],
    ['Statusmails naar klanten', 'Mail in de taal van de klant bij verzending.', status.mail]
  ];

  return (
    <div className="grid-2">
      <div className="card">
        <div className="card-head"><div><h3>Actief op de server</h3><p>Ingeschakeld via instellingen in het .env-bestand</p></div></div>
        <div className="card-body" style={{ paddingTop: 4 }}>
          {live.map(([n, d, on]) => <div className="toggle-row" key={n}><div><b>{n}</b><span className="d">{d}</span></div><span className={'badge ' + (on ? 'success' : 'plain')}>{on ? 'Actief' : 'Uit'}</span></div>)}
        </div>
      </div>
      <div className="card">
        <div className="card-head"><div><h3>Gepland</h3><p>Voorkeuren worden opgeslagen; de koppeling volgt in een volgende fase</p></div></div>
        <div className="card-body" style={{ paddingTop: 4 }}>
          {DEFAULTS.map(a => (
            <div className="toggle-row" key={a.id}><div><b>{a.name}</b><span className="d">{a.group} · {a.desc}</span></div>
              <label className="switch"><input type="checkbox" checked={!!state[a.id]} onChange={async e => { const next = { ...state, [a.id]: e.target.checked }; setState(next); try { await api.admin.saveSetting('automations', next); toast(a.name + (e.target.checked ? ' staat aan' : ' staat uit')); } catch { toast('Opslaan mislukt', 'error'); } }} /><span /></label>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

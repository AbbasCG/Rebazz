import { useEffect, useState } from 'react';
import { Icon, MButton, useToast } from '../../components/ui';
import { api } from '../../lib/api';
import { LANGUAGES, PAYMENT_METHODS } from '../../lib/i18n';
import { useShop } from '../../lib/shop';
import type { Brand } from '../../lib/types';
import { eur, useAdmin } from '../context';

const BRAND_FIELDS: [keyof Brand, string, boolean?][] = [
  ['name', 'Handelsnaam'], ['company', 'Bedrijfsnaam (zoals in KvK)'], ['kvk', 'KvK-nummer'], ['btw', 'Btw-nummer'],
  ['email', 'E-mail klantenservice'], ['domain', 'Domein'], ['address', 'Vestigingsadres', true], ['returnAddress', 'Retouradres', true]
];

export default function Settings() {
  const { status } = useAdmin();
  const { catalog } = useShop();
  const toast = useToast();
  const [brand, setBrand] = useState<Brand>(catalog.brand);
  const [subs, setSubs] = useState<{ email: string; lang: string; created_at: string }[]>([]);

  useEffect(() => { setBrand(catalog.brand); }, [catalog.brand]);
  useEffect(() => { api.admin.subscribers().then(r => setSubs(r.subscribers)).catch(() => {}); }, []);

  const conn: [string, boolean, string][] = [
    ['Stripe (betalingen)', status.stripe !== 'missing', status.stripe === 'live' ? 'Live' : status.stripe === 'test' ? 'Testmodus' : 'STRIPE_SECRET ontbreekt'],
    ['Stripe-webhook', status.webhook, status.webhook ? 'Ingesteld' : 'STRIPE_WEBHOOK_SECRET ontbreekt'],
    ['CJdropshipping', status.cj, status.cj ? (status.cjAutoPay ? 'Automatisch bestellen + betalen' : 'Automatisch bestellen') : 'CJ_API_KEY ontbreekt'],
    ['E-mail naar klanten', status.mail, status.mail ? 'Ingesteld' : 'MAIL_MAILER niet ingesteld']
  ];

  return (
    <>
      <div className="grid-2" style={{ marginBottom: 20 }}>
        <div className="card">
          <div className="card-head"><div><h3>Winkelgegevens</h3><p>Wettelijk verplicht zichtbaar op de website. Opgeslagen in de database.</p></div></div>
          <div className="card-body">
            <div className="form-grid">
              {BRAND_FIELDS.map(([k, label, full]) => (
                <div className="field" key={k} style={full ? { gridColumn: '1/-1' } : undefined}>
                  <label htmlFor={'b-' + k}>{label}</label>
                  <input className="input" id={'b-' + k} value={brand[k]} onChange={e => setBrand(b => ({ ...b, [k]: e.target.value }))} />
                </div>
              ))}
            </div>
            <MButton className="btn btn-primary" style={{ marginTop: 16 }} onClick={async () => { try { await api.admin.saveSetting('brand', brand); toast('Winkelgegevens zijn opgeslagen'); } catch (e) { toast('Opslaan mislukt: ' + (e as Error).message, 'error'); } }}>Opslaan</MButton>
          </div>
        </div>
        <div className="card">
          <div className="card-head"><div><h3>Koppelingen</h3><p>Sleutels staan in het <code>.env</code>-bestand op de server, nooit in de browser.</p></div></div>
          <div className="card-body" style={{ paddingTop: 4 }}>
            {conn.map(([name, ok, text]) => (
              <div className="toggle-row" key={name}><div><b>{name}</b><span className="d">{text}</span></div><span className={'badge ' + (ok ? 'success' : 'warn')}>{ok ? 'Gekoppeld' : 'Niet ingesteld'}</span></div>
            ))}
            <a className="btn btn-outline btn-sm" style={{ marginTop: 12 }} href="https://dashboard.stripe.com/settings/payment_methods" target="_blank" rel="noopener"><Icon name="external" size={14} />Betaalmethoden in Stripe</a>
          </div>
        </div>
      </div>
      <div className="grid-2" style={{ marginBottom: 20 }}>
        <div className="card">
          <div className="card-head"><div><h3>Verzendzones</h3><p>Tarieven en levertijden zoals getoond aan klanten.</p></div></div>
          <div className="card-body" style={{ padding: 8 }}><div className="table-wrap"><table className="t">
            <thead><tr><th>Zone</th><th className="num">Tarief</th><th className="num">Gratis vanaf</th><th className="num">Levertijd</th></tr></thead>
            <tbody>{catalog.zones.map(z => <tr key={z.id}><td><b>{z.name}</b><br /><span className="muted" style={{ fontSize: 12 }}>{z.countries.length} landen{z.vat ? ' · incl. btw' : ' · excl. invoerrechten'}</span></td><td className="num">{eur(z.rate)}</td><td className="num">{eur(z.freeFrom)}</td><td className="num">{z.days[0]}–{z.days[1]} dagen</td></tr>)}</tbody>
          </table></div></div>
        </div>
        <div className="card">
          <div className="card-head"><div><h3>Betaalmethoden</h3><p>Aan te zetten in Stripe → Instellingen → Betaalmethoden.</p></div></div>
          <div className="card-body" style={{ paddingTop: 4 }}>{PAYMENT_METHODS.map(m => <div className="toggle-row" key={m.id}><div><b>{m.name}</b><span className="d">{m.detail} · {m.region}</span></div></div>)}</div>
        </div>
      </div>
      <div className="grid-2">
        <div className="card">
          <div className="card-head"><div><h3>Nieuwsbrief</h3><p>{subs.length} aanmelding(en), opgeslagen in de database</p></div></div>
          <div className="card-body" style={{ padding: 8 }}><div className="table-wrap"><table className="t">
            <thead><tr><th>E-mail</th><th>Taal</th><th>Datum</th></tr></thead>
            <tbody>{subs.slice(0, 50).map(s => <tr key={s.email}><td>{s.email}</td><td>{s.lang.toUpperCase()}</td><td>{new Date(s.created_at).toLocaleDateString('nl-NL')}</td></tr>)}
              {!subs.length && <tr><td colSpan={3} className="muted" style={{ textAlign: 'center', padding: 24 }}>Nog geen aanmeldingen.</td></tr>}</tbody>
          </table></div></div>
        </div>
        <div className="card">
          <div className="card-head"><div><h3>Talen</h3><p>8 talen volledig vertaald; overige talen via AI-vertaling (later).</p></div></div>
          <div className="card-body"><div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>{LANGUAGES.map(l => <span key={l.code} className={'badge ' + (l.full ? 'success' : 'plain')}>{l.native}</span>)}</div></div>
        </div>
      </div>
    </>
  );
}

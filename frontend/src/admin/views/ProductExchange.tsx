import { useEffect, useRef, useState } from 'react';
import { MButton, useToast } from '../../components/ui';
import { api } from '../../lib/api';
import type { FeedCheck, ImportResult } from '../../lib/types';
import { Modal } from '../parts';

const ACTION: Record<string, [string, string]> = { create: ['Nieuw', 'success'], update: ['Wijzigt', 'info'], unchanged: ['Ongewijzigd', ''] };
const FIELD: Record<string, string> = {
  price: 'prijs', compare: 'vergelijkingsprijs', cost: 'inkoop', stock: 'voorraad', trackStock: 'voorraad bijhouden', name: 'naam', category: 'categorie', status: 'zichtbaar', description: 'beschrijving',
  features: 'kenmerken', image: 'foto', images: "foto's", options: 'opties', variants: 'varianten', manufacturer: 'fabrikant', euResponsible: 'EU-verantwoordelijke', safetyInfo: 'veiligheid', days: 'levertijd', sku: 'sku'
};

/** Producten in bulk: CSV exporteren, importeren (met voorbeeld; alles of niets) en de productfeed voor advertentieplatforms. */
export default function ProductExchange({ open, onClose, onDone }: { open: boolean; onClose: () => void; onDone: () => void }) {
  const toast = useToast();
  const input = useRef<HTMLInputElement>(null);
  const [file, setFile] = useState<File | null>(null);
  const [res, setRes] = useState<ImportResult | null>(null);
  const [busy, setBusy] = useState(false);
  const [feed, setFeed] = useState<FeedCheck | null>(null);
  const [done, setDone] = useState<ImportResult | null>(null);

  useEffect(() => { if (open) api.admin.feedCheck().then(setFeed).catch(() => setFeed(null)); }, [open]);
  const reset = () => { setFile(null); setRes(null); setDone(null); if (input.current) input.current.value = ''; };

  const download = async (sep: 'semicolon' | 'comma') => {
    try {
      const url = URL.createObjectURL(await api.admin.exportProducts(sep));
      const a = document.createElement('a'); a.href = url; a.download = 'rebazz-producten-' + new Date().toISOString().slice(0, 10) + '.csv'; a.click();
      setTimeout(() => URL.revokeObjectURL(url), 5000);
    } catch (e) { toast((e as Error).message, 'error'); }
  };
  const pick = async (f: File | null) => {
    setFile(f); setRes(null); setDone(null);
    if (!f) return;
    setBusy(true);
    try { setRes(await api.admin.importProducts(f, true)); }
    catch (e) { toast((e as Error).message, 'error'); setFile(null); if (input.current) input.current.value = ''; }
    finally { setBusy(false); }
  };
  const apply = async () => {
    if (!file || !res) return;
    setBusy(true);
    try { const r = await api.admin.importProducts(file, false, res.checksum); setDone(r); setRes(null); setFile(null); if (input.current) input.current.value = ''; onDone(); toast('Import voltooid'); }
    catch (e) { toast((e as Error).message, 'error'); }
    finally { setBusy(false); }
  };

  const s = res?.summary;
  const ok = !!res && res.errorCount === 0;
  return (
    <Modal title="Producten importeren en exporteren" open={open} onClose={() => { onClose(); reset(); }}>
      <div style={{ display: 'grid', gap: 18, fontSize: 14 }}>
        <section>
          <div className="section-label">1. Exporteren</div>
          <p className="muted">Alle producten (ook concepten) met varianten, in één bestand voor Excel of Google Sheets. Gebruik het als back-up of als sjabloon voor een import.</p>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginTop: 8 }}>
            <button className="btn btn-outline" type="button" onClick={() => void download('semicolon')}>Voor Excel (NL, puntkomma)</button>
            <button className="btn btn-outline" type="button" onClick={() => void download('comma')}>Standaard (komma)</button>
          </div>
        </section>

        <section>
          <div className="section-label">2. Importeren</div>
          <p className="muted">Eén rij per product, varianten op extra rijen eronder (kolommen <code>variant_*</code>). Producten worden gevonden op <code>id</code> (of <code>sku</code>): bestaand = bijwerken, anders aanmaken als concept. Ontbrekende kolommen en lege cellen blijven ongewijzigd; schrijf <code>[leeg]</code> om een veld leeg te maken. Alles of niets: bij één fout wordt niets opgeslagen. U ziet eerst een voorbeeld.</p>
          <input ref={input} type="file" accept=".csv,text/csv,text/plain" aria-label="CSV-bestand kiezen" onChange={e => void pick(e.target.files?.[0] ?? null)} style={{ marginTop: 8 }} />
          {busy && <p className="muted" style={{ marginTop: 8 }}>Bezig…</p>}

          {res && s && (
            <div style={{ display: 'grid', gap: 10, marginTop: 12 }} role="status">
              <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                <span className="badge success">{s.create} nieuw</span><span className="badge info">{s.update} wijzigen</span><span className="badge">{s.unchanged} ongewijzigd</span>
                {(s.variantsCreate + s.variantsUpdate + s.variantsDelete > 0) && <span className="badge">varianten: +{s.variantsCreate} ~{s.variantsUpdate} −{s.variantsDelete}</span>}
                {s.warnings > 0 && <span className="badge warn">{s.warnings} waarschuwing(en)</span>}
                {res.errorCount > 0 && <span className="badge danger">{res.errorCount} fout(en)</span>}
              </div>

              {res.errorCount > 0 && (
                <div className="card" style={{ padding: 12, background: 'var(--bg)' }}>
                  <b>Er is niets opgeslagen. Verbeter deze regels en kies het bestand opnieuw:</b>
                  <ul style={{ margin: '6px 0 0', paddingInlineStart: 18, display: 'grid', gap: 4 }}>
                    {res.errors.map(e => <li key={e.line + e.id}>regel {e.line}{e.id ? ' (' + e.id + ')' : ''}: {e.messages.join(' · ')}</li>)}
                  </ul>
                  {res.errorCount > res.errors.length && <p className="muted">…en nog {res.errorCount - res.errors.length} andere.</p>}
                </div>
              )}

              {res.items.length > 0 && (
                <div className="table-wrap" style={{ maxHeight: 260, overflow: 'auto' }}><table className="t">
                  <thead><tr><th>Regel</th><th>Product</th><th>Actie</th><th>Wijzigingen</th></tr></thead>
                  <tbody>
                    {res.items.slice(0, 100).map(i => (
                      <tr key={i.line}>
                        <td>{i.line}</td><td>{i.name || i.id}<br /><span className="muted" style={{ fontSize: 12 }}>{i.id}</span></td>
                        <td><span className={'badge ' + ACTION[i.action][1]}>{ACTION[i.action][0]}</span></td>
                        <td>{i.changes.map(c => FIELD[c] ?? c).join(', ')}{i.variants && (i.variants.create || i.variants.update || i.variants.delete) ? <span className="muted"> · varianten +{i.variants.create} ~{i.variants.update} −{i.variants.delete}</span> : null}
                          {i.warnings.map(w => <div key={w} style={{ color: 'var(--warning, #b7791f)', fontSize: 12.5 }}>⚠ {w}</div>)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table></div>
              )}
              {res.items.length > 100 && <p className="muted">Eerste 100 van {res.items.length} producten getoond.</p>}

              <div style={{ display: 'flex', gap: 8 }}>
                <MButton className="btn btn-primary" onClick={() => void apply()} disabled={busy || !ok || s.create + s.update === 0}>{ok ? (s.create + s.update === 0 ? 'Niets te wijzigen' : 'Nu importeren (' + (s.create + s.update) + ')') : 'Eerst fouten verbeteren'}</MButton>
                <button className="btn btn-ghost" type="button" onClick={reset}>Annuleren</button>
              </div>
            </div>
          )}
          {done && <p className="badge success plain" style={{ height: 'auto', padding: '10px 14px', whiteSpace: 'normal', marginTop: 12 }} role="status">Klaar: {done.summary.create} nieuw, {done.summary.update} gewijzigd. Nieuwe producten staan als concept; zet ze zelf op zichtbaar.</p>}
        </section>

        <section>
          <div className="section-label">3. Productfeed voor advertenties</div>
          <p className="muted">Voor Google Shopping (Merchant Center), Meta (Facebook/Instagram-catalogus), Pinterest en TikTok. Alleen zichtbare producten, zonder inkoopprijzen. Plak dit adres in het platform als geplande feed.</p>
          {feed ? (
            <div style={{ display: 'grid', gap: 8, marginTop: 8 }}>
              <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center' }}>
                <code style={{ userSelect: 'all', wordBreak: 'break-all' }}>{feed.url}</code>
                <button className="btn btn-outline btn-sm" type="button" onClick={() => { void navigator.clipboard?.writeText(feed.url); toast('Adres gekopieerd'); }}>Kopiëren</button>
                <a className="btn btn-ghost btn-sm" href={feed.url} target="_blank" rel="noopener">Openen</a>
              </div>
              <p>{feed.items} product(en)/variant(en) in de feed.{!feed.https && <b style={{ color: 'var(--danger)' }}> Let op: het adres is geen https; advertentieplatforms eisen dat en foto's uit /media worden dan overgeslagen.</b>}</p>
              {feed.excluded.length > 0 && (
                <div className="card" style={{ padding: 12, background: 'var(--bg)' }}>
                  <b>{feed.excluded.length} zichtbaar(e) product(en) staan niet in de feed:</b>
                  <ul style={{ margin: '6px 0 0', paddingInlineStart: 18 }}>{feed.excluded.slice(0, 12).map(e => <li key={e.id}>{e.name}: {e.reason}</li>)}</ul>
                  {feed.excluded.length > 12 && <p className="muted">…en {feed.excluded.length - 12} andere.</p>}
                </div>
              )}
            </div>
          ) : <p className="muted">Feed laden…</p>}
        </section>
      </div>
    </Modal>
  );
}

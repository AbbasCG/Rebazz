import { AnimatePresence, motion } from 'motion/react';
import { useState, type FormEvent } from 'react';
import { Icon, MButton, ProductVisual, useToast } from '../../components/ui';
import { api } from '../../lib/api';
import { AI, CANDIDATES } from '../ai';
import { eur, pct } from '../context';
import { Sparkline } from '../parts';
import { emptyProduct, useAdminProducts } from './Products';

const STEPS = ['Trends analyseren (TikTok, Google Trends, Amazon, AliExpress)', 'Leveranciers en magazijnen vergelijken', 'Marges berekenen inclusief verzending en transactiekosten', 'Concurrentie en advertentieverzadiging beoordelen', 'Resultaten rangschikken op winstpotentieel'];
const charm = (v: number) => Math.max(4.95, Math.round(v) - 0.05);
const WORDS: Record<string, string[]> = { slaap: ['wekker', 'slaap'], thuis: ['laptop', 'werkplek'], huisdier: ['huisdier'], fitness: ['massage', 'water', 'sport'], sport: ['massage', 'water', 'sport'], keuken: ['blender', 'keuken'], reis: ['reis'], tech: ['tech', 'powerbank'] };

export default function Research() {
  const toast = useToast();
  const [products, setProducts] = useAdminProducts();
  const [q, setQ] = useState('');
  const [minM, setMinM] = useState(0.6);
  const [euOnly, setEuOnly] = useState(false);
  const [markup, setMarkup] = useState(3);
  const [step, setStep] = useState(-1);
  const [results, setResults] = useState<(typeof CANDIDATES[number] & { price: number })[] | null>(null);

  const run = (e: FormEvent) => {
    e.preventDefault();
    setResults(null);
    setStep(0);
    let i = 0;
    const timer = setInterval(() => {
      i++;
      setStep(i);
      if (i >= STEPS.length) {
        clearInterval(timer);
        let list = CANDIDATES.map(c => ({ ...c, price: charm(c.cost * markup) })).filter(c => (c.price - c.cost) / c.price >= minM && (!euOnly || /EU/.test(c.warehouse)));
        const query = q.trim().toLowerCase();
        if (query) {
          const keys = Object.keys(WORDS).filter(k => query.includes(k)).flatMap(k => WORDS[k]).concat([query]);
          const hit = list.filter(c => keys.some(k => (c.name + ' ' + c.category).toLowerCase().includes(k)));
          if (hit.length) list = hit.concat(list.filter(c => !hit.includes(c)));
        }
        setResults(list.slice(0, 6));
        setStep(-1);
      }
    }, 450);
  };

  const importIt = async (c: typeof CANDIDATES[number] & { price: number }) => {
    const p = { ...emptyProduct(), name: c.name, category: c.category, price: c.price, cost: c.cost, icon: c.icon, hue: c.hue, supplier: c.supplier, warehouse: c.warehouse, days: c.days, features: c.features, status: 'draft' as const };
    p.description = AI.description(p);
    try { const r = await api.admin.saveProduct(p, true); setProducts([...products, r.product]); toast(c.name + ' is als concept toegevoegd. Vul foto en CJ-ID in en zet het product aan.'); }
    catch (err) { toast('Importeren mislukt: ' + (err as Error).message, 'error'); }
  };

  const names = products.map(p => p.name);
  return (
    <>
      <div className="card" style={{ marginBottom: 20 }}>
        <div className="card-head"><div><h3>AI-productonderzoek</h3><p>Voorbeeldanalyse op basis van trends, leveranciers en marges. De echte AI-koppeling volgt in een volgende fase.</p></div></div>
        <div className="card-body">
          <form className="research-form" onSubmit={run}>
            <div className="field wide"><label htmlFor="rq">Niche of zoekterm</label><input className="input" id="rq" placeholder="Bijv. slaap, thuiswerken, huisdieren, fitness" value={q} onChange={e => setQ(e.target.value)} /></div>
            <div className="field"><label htmlFor="rmargin">Minimale marge</label><select className="select" id="rmargin" value={minM} onChange={e => setMinM(parseFloat(e.target.value))}><option value={0.5}>50%</option><option value={0.6}>60%</option><option value={0.7}>70%</option></select></div>
            <div className="field"><label htmlFor="rwh">Magazijn</label><select className="select" id="rwh" value={euOnly ? 'eu' : 'all'} onChange={e => setEuOnly(e.target.value === 'eu')}><option value="all">Alle locaties</option><option value="eu">Alleen EU</option></select></div>
            <div className="field"><label htmlFor="rmk">Opslagfactor</label><input className="input" id="rmk" type="number" step="0.1" min="1.2" value={markup} onChange={e => setMarkup(parseFloat(e.target.value) || 3)} /></div>
            <MButton className="btn btn-accent" type="submit" style={{ height: 44 }} disabled={step >= 0}><Icon name="wand" size={17} />Analyse starten</MButton>
          </form>
        </div>
      </div>
      <AnimatePresence mode="wait">
        {step >= 0 && (
          <motion.div key="steps" className="card" initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0 }}>
            <div className="card-body"><div className="progress-steps">
              {STEPS.map((s, k) => <div key={s} className={k < step ? 'done' : ''}>{k < step ? <Icon name="check" /> : k === step ? <span className="spinner" /> : <span style={{ width: 18 }} />}{s}</div>)}
            </div></div>
          </motion.div>
        )}
        {results && (
          <motion.div key="res" initial={{ opacity: 0 }} animate={{ opacity: 1 }}>
            <div className="page-intro"><div><h3 style={{ fontSize: 17 }}>{results.length} kansrijke producten gevonden</h3><p>Verkoopprijs = inkoop × {String(markup).replace('.', ',')}, afgerond op ,95.</p></div></div>
            <div className="cand-grid">
              {results.map((c, i) => {
                const imported = names.includes(c.name);
                return (
                  <motion.div className="cand" key={c.name} initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: i * 0.06 }}>
                    <div style={{ position: 'relative' }}><span className="score">Score <b>{c.score}</b>/100</span><ProductVisual p={{ ...c, image: '' }} /></div>
                    <div className="cand-body">
                      <div><span className="badge plain" style={{ marginBottom: 6 }}>{c.category}</span><h4>{c.name}</h4></div>
                      <div className="metrics">
                        <div><span>Inkoop</span><b>{eur(c.cost)}</b></div><div><span>Verkoop</span><b>{eur(c.price)}</b></div><div><span>Marge</span><b className="up">{pct((c.price - c.cost) / c.price)}</b></div>
                        <div><span>Trend 90d</span><b className="up">{c.trend}</b></div><div><span>Concurrentie</span><b>{c.comp}</b></div><div><span>Levertijd</span><b>{c.days[0]}–{c.days[1]} d</b></div>
                      </div>
                      <Sparkline seed={i + 3} />
                      <p className="hint"><Icon name="truck" size={14} /> {c.supplier} · {c.warehouse}</p>
                      <div className="why"><b>AI-advies:</b> {c.why}</div>
                      <div className="actions">{imported ? <button className="btn btn-outline btn-sm" disabled><Icon name="check" size={15} />Geïmporteerd</button> : <MButton className="btn btn-primary btn-sm" onClick={() => importIt(c)}><Icon name="plus" size={15} />Importeren</MButton>}</div>
                    </div>
                  </motion.div>
                );
              })}
            </div>
          </motion.div>
        )}
      </AnimatePresence>
    </>
  );
}

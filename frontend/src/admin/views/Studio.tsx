import { useGSAP } from '@gsap/react';
import gsap from 'gsap';
import { AnimatePresence, motion } from 'motion/react';
import { useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Icon, MButton, ProductVisual, useToast } from '../../components/ui';
import { api } from '../../lib/api';
import { LANGUAGES } from '../../lib/i18n';
import type { Product } from '../../lib/types';
import { AI } from '../ai';
import { eur } from '../context';
import { useAdminProducts } from './Products';

const PLATFORMS: Record<string, [string, string, string]> = { tiktok: ['TikTok', '9:16', ''], reels: ['Instagram Reels', '9:16', ''], shorts: ['YouTube Shorts', '9:16', ''], feed: ['Meta-feed', '1:1', 'square'], story: ['Pinterest / feed 4:5', '4:5', 'portrait'] };
const STYLES: Record<string, string> = { ugc: 'UGC-testimonial', demo: 'Productdemonstratie', luxury: 'Luxe / cinematografisch', problem: 'Probleem → oplossing' };
type Scene = ReturnType<typeof AI.script>[number];
interface Video { id: string; productId: string; name: string; platform: string; duration: number; lang: string; date: string }

export default function Studio() {
  const toast = useToast();
  const [params] = useSearchParams();
  const [products] = useAdminProducts();
  const [productId, setProductId] = useState(params.get('product') ?? '');
  const [platform, setPlatform] = useState('tiktok');
  const [style, setStyle] = useState('ugc');
  const [duration, setDuration] = useState(30);
  const [lang, setLang] = useState('nl');
  const [voice, setVoice] = useState('Vrouw (warm)');
  const [cta, setCta] = useState('Bestel nu met gratis verzending');
  const [step, setStep] = useState(-1);
  const [result, setResult] = useState<{ p: Product; scenes: Scene[] } | null>(null);
  const [videos, setVideos] = useState<Video[]>([]);

  useEffect(() => { if (!productId && products[0]) setProductId(products[0].id); }, [products, productId]);
  useEffect(() => { api.admin.setting<Video[]>('videos').then(r => setVideos(r.value ?? [])).catch(() => {}); }, []);

  const generate = () => {
    const p = products.find(x => x.id === productId);
    if (!p) return;
    setResult(null);
    let i = 0;
    setStep(0);
    const timer = setInterval(() => {
      i++; setStep(i);
      if (i >= 5) { clearInterval(timer); setStep(-1); setResult({ p, scenes: AI.script(p, { style, duration, lang: lang === 'nl' ? 'nl' : 'en', cta }) }); }
    }, 420);
  };
  const saveVideo = async () => {
    if (!result) return;
    const next = [{ id: 'v' + Date.now(), productId: result.p.id, name: result.p.name, platform: PLATFORMS[platform][0], duration, lang, date: new Date().toISOString() }, ...videos].slice(0, 60);
    setVideos(next);
    try { await api.admin.saveSetting('videos', next); toast('Video is opgeslagen in de bibliotheek'); } catch { toast('Opslaan mislukt', 'error'); }
  };

  const Seg = ({ value, set, options }: { value: string | number; set: (v: string) => void; options: Record<string, string | [string, string, string]> }) => (
    <div className="seg">{Object.entries(options).map(([k, v]) => <motion.button type="button" key={k} className={String(k) === String(value) ? 'on' : ''} onClick={() => set(k)} whileTap={{ scale: 0.95 }}>{Array.isArray(v) ? v[0] : v}</motion.button>)}</div>
  );

  return (
    <div className="studio">
      <div className="card">
        <div className="card-head"><div><h3>Videoconfiguratie</h3><p>Voorbeeldgenerator: script, storyboard en voorbeeldweergave.</p></div></div>
        <div className="card-body" style={{ display: 'grid', gap: 16 }}>
          <div className="field"><label htmlFor="vProduct">Product</label><select className="select" id="vProduct" value={productId} onChange={e => setProductId(e.target.value)}>{products.map(p => <option key={p.id} value={p.id}>{p.name}</option>)}</select></div>
          <div className="field"><span className="label">Platform en formaat</span>{Seg({ value: platform, set: setPlatform, options: PLATFORMS })}</div>
          <div className="field"><span className="label">Stijl</span>{Seg({ value: style, set: setStyle, options: STYLES })}</div>
          <div className="field"><span className="label">Duur</span>{Seg({ value: duration, set: v => setDuration(parseInt(v)), options: { 15: '15 sec', 30: '30 sec', 45: '45 sec' } })}</div>
          <div className="form-grid">
            <div className="field"><label htmlFor="vLang">Taal</label><select className="select" id="vLang" value={lang} onChange={e => setLang(e.target.value)}>{LANGUAGES.map(l => <option key={l.code} value={l.code}>{l.label}</option>)}</select></div>
            <div className="field"><label htmlFor="vVoice">Voice-over</label><select className="select" id="vVoice" value={voice} onChange={e => setVoice(e.target.value)}>{['Vrouw (warm)', 'Man (zakelijk)', 'Vrouw (energiek)', 'Geen voice-over'].map(v => <option key={v}>{v}</option>)}</select></div>
          </div>
          <div className="field"><label htmlFor="vCta">Call-to-action</label><input className="input" id="vCta" value={cta} onChange={e => setCta(e.target.value)} /></div>
          <MButton className="btn btn-accent btn-lg" onClick={generate} disabled={step >= 0 || !productId}><Icon name="wand" size={18} />Video genereren</MButton>
        </div>
      </div>
      <div className="stack-20">
        <div className="card"><div className="card-body">
          <AnimatePresence mode="wait">
            {step >= 0 && (
              <motion.div key="s" className="progress-steps" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}>
                {['Productgegevens analyseren', 'Script schrijven', 'Scènes genereren', 'Voice-over: ' + voice, 'Montage en ondertiteling'].map((s, k) => <div key={s} className={k < step ? 'done' : ''}>{k < step ? <Icon name="check" /> : k === step ? <span className="spinner" /> : <span style={{ width: 18 }} />}{s}</div>)}
              </motion.div>
            )}
            {step < 0 && !result && <motion.div key="e" className="muted" style={{ textAlign: 'center', padding: '50px 20px' }} initial={{ opacity: 0 }} animate={{ opacity: 1 }}><div style={{ display: 'grid', placeItems: 'center', marginBottom: 12 }}><Icon name="video" size={40} /></div>Kies een product en klik op <b>Video genereren</b>.</motion.div>}
            {step < 0 && result && (
              <motion.div key="r" className="studio-out" initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }}>
                <div><Player p={result.p} scenes={result.scenes} ratio={PLATFORMS[platform][2]} duration={duration} /></div>
                <div style={{ display: 'grid', gap: 16, alignContent: 'start' }}>
                  <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}><span className="badge accent plain">{PLATFORMS[platform][0]} · {PLATFORMS[platform][1]}</span><span className="badge plain">{STYLES[style]}</span><span className="badge plain">{duration} sec</span></div>
                  <div><div className="section-label">Script en storyboard</div><div className="table-wrap"><table className="t script-table"><thead><tr><th>Tijd</th><th>Scène</th><th>Voice-over / tekst</th></tr></thead>
                    <tbody>{result.scenes.map(s => <tr key={s.t}><td>{s.t}</td><td><b>{s.role}</b><br /><span className="muted" style={{ fontSize: 12.5 }}>{s.visual}</span></td><td>{s.vo}</td></tr>)}</tbody></table></div></div>
                  <div><div className="section-label">Bijschrift en hashtags</div><p style={{ fontSize: 13.5 }}>{result.p.features[0]} · {result.p.features[1]} ✨ {AI.hashtags(result.p).join(' ')}</p></div>
                  <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}><MButton className="btn btn-primary" onClick={saveVideo}><Icon name="check" size={16} />Opslaan in bibliotheek</MButton><button className="btn btn-ghost" onClick={() => toast('Export als MP4 volgt zodra de video-API is gekoppeld')}>Exporteren (MP4)</button></div>
                </div>
              </motion.div>
            )}
          </AnimatePresence>
        </div></div>
        <div className="card"><div className="card-head"><div><h3>Videobibliotheek</h3><p>{videos.length} opgeslagen video’s</p></div></div><div className="card-body">
          {videos.length ? <div className="video-lib">{videos.map(v => { const p = products.find(x => x.id === v.productId); return <div className="vid" key={v.id}>{p && <ProductVisual p={p} />}<div className="meta"><b>{v.name}</b><span className="muted">{v.platform} · {v.duration}s · {v.lang.toUpperCase()}</span></div></div>; })}</div> : <p className="muted">Nog geen video’s opgeslagen.</p>}
        </div></div>
      </div>
    </div>
  );
}

/* Voorbeeldspeler: GSAP-tijdlijn met Ken Burns-effect en ondertitels. */
function Player({ p, scenes, ratio, duration }: { p: Product; scenes: Scene[]; ratio: string; duration: number }) {
  const ref = useRef<HTMLDivElement>(null);
  const [run, setRun] = useState(0);
  useGSAP(() => {
    const per = Math.min(2.6, duration / scenes.length / 3);
    const mm = gsap.matchMedia();
    mm.add({ ok: '(prefers-reduced-motion: no-preference)', reduce: '(prefers-reduced-motion: reduce)' }, ctx => {
      const ok = (ctx.conditions as { ok: boolean }).ok;
      const tl = gsap.timeline();
      gsap.set('.scene', { autoAlpha: 0 });
      gsap.set('.player-bar b', { width: 0 });
      scenes.forEach((_, i) => {
        const sel = '.scene:nth-child(' + (i + 2) + ')';
        tl.to(sel, { autoAlpha: 1, duration: ok ? 0.35 : 0 }, i * per)
          .to('.player-bar i:nth-child(' + (i + 1) + ') b', { width: '100%', duration: per, ease: 'none' }, i * per);
        if (ok) tl.fromTo(sel + ' .pv .icon', { scale: 1, rotate: -3 }, { scale: 1.14, rotate: 3, duration: per, ease: 'sine.inOut' }, i * per)
          .from(sel + ' .cap', { y: 14, opacity: 0, duration: 0.35 }, i * per + 0.1);
        if (i < scenes.length - 1) tl.to(sel, { autoAlpha: 0, duration: ok ? 0.3 : 0 }, (i + 1) * per);
      });
    });
    return () => mm.revert();
  }, { scope: ref, dependencies: [run, scenes] });
  return (
    <>
      <div className={'phone ' + ratio} ref={ref}>
        <div className="screen">
          <div className="player-bar">{scenes.map(s => <i key={s.t}><b /></i>)}</div>
          {scenes.map((s, i) => (
            <div key={s.t} className="scene on">
              <ProductVisual p={p} />
              <span className="tagline">{s.role}</span>
              <div className="cap"><span>{s.cap}</span></div>
              {i === scenes.length - 1 && <span className="cta-btn">{eur(p.price)} · Shop nu</span>}
            </div>
          ))}
        </div>
      </div>
      <div style={{ display: 'flex', justifyContent: 'center', marginTop: 14 }}><button className="btn btn-outline btn-sm" onClick={() => setRun(r => r + 1)}><Icon name="refresh" size={15} />Opnieuw afspelen</button></div>
    </>
  );
}

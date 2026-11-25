/* Gedeelde onderdelen van het dashboard. */
import { useGSAP } from '@gsap/react';
import gsap from 'gsap';
import { AnimatePresence, motion } from 'motion/react';
import { useRef, useState, type ReactNode } from 'react';
import { Icon } from '../components/ui';
import type { Order } from '../lib/types';

gsap.registerPlugin(useGSAP);

/* KPI-tegel met optellende waarde (GSAP), behalve bij "minder beweging". */
export function Kpi({ label, value, format, delta, icon, good = true }: { label: string; value: number; format: (n: number) => string; delta: string; icon: string; good?: boolean }) {
  const ref = useRef<HTMLDivElement>(null);
  useGSAP(() => {
    const el = ref.current?.querySelector('.val');
    if (!el) return;
    const mm = gsap.matchMedia();
    mm.add('(prefers-reduced-motion: no-preference)', () => {
      const obj = { v: 0 };
      gsap.to(obj, { v: value, duration: 0.9, ease: 'power2.out', onUpdate: () => { el.textContent = format(obj.v); } });
    });
    return () => mm.revert();
  }, { dependencies: [value], scope: ref });
  return (
    <div className="kpi" ref={ref}>
      <div className="top"><span>{label}</span><span className="ic"><Icon name={icon} size={17} /></span></div>
      <div className="val">{format(value)}</div>
      <div className={'delta ' + (good ? 'up' : 'down')}>{delta}</div>
    </div>
  );
}

export function StatusBadge({ o }: { o: Order }) {
  if (o.refunded) return <span className="badge danger">Terugbetaald</span>;
  if (o.step === 0 && (o.cjStatus === 'error' || o.cjStatus === 'manual')) return <span className="badge danger">Actie nodig</span>;
  if (o.delayed && o.step < 4) return <span className="badge warn">Vertraagd</span>;
  const map = ['accent', 'info', 'info', 'info', 'success'];
  const labels = ['Te verwerken', 'Bij leverancier', 'Verzonden', 'Onderweg', 'Afgeleverd'];
  return <span className={'badge ' + map[o.step]}>{labels[o.step]}</span>;
}

export function Drawer({ open, onClose, children }: { open: boolean; onClose: () => void; children: ReactNode }) {
  return (
    <AnimatePresence>
      {open && (
        <>
          <motion.div className="d-overlay open" onClick={onClose} initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} />
          <motion.aside className="d-drawer open" style={{ transform: 'none' }} role="dialog" aria-modal="true"
            initial={{ x: '100%' }} animate={{ x: 0 }} exit={{ x: '100%' }} transition={{ type: 'spring', stiffness: 380, damping: 38 }}>
            {children}
          </motion.aside>
        </>
      )}
    </AnimatePresence>
  );
}

export function Modal({ title, open, onClose, children, footer }: { title: string; open: boolean; onClose: () => void; children: ReactNode; footer?: ReactNode }) {
  return (
    <AnimatePresence>
      {open && (
        <motion.div className="m-wrap" onClick={e => { if (e.target === e.currentTarget) onClose(); }} initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}>
          <motion.div className="m-card" role="dialog" aria-modal="true" aria-label={title} initial={{ y: 20, scale: 0.97 }} animate={{ y: 0, scale: 1 }} exit={{ y: 12, scale: 0.98 }} transition={{ type: 'spring', stiffness: 400, damping: 34 }}>
            <div className="d-head"><h3 style={{ fontSize: 17 }}>{title}</h3><button className="icon-btn" onClick={onClose} aria-label="Sluiten"><Icon name="x" size={20} /></button></div>
            <div className="d-body">{children}</div>
            {footer && <div className="d-foot">{footer}</div>}
          </motion.div>
        </motion.div>
      )}
    </AnimatePresence>
  );
}

/* Bevestigingsvenster in de pagina zelf (in plaats van confirm()). */
export function useConfirm(): [(text: string) => Promise<boolean>, ReactNode] {
  const [state, setState] = useState<{ text: string; resolve: (v: boolean) => void } | null>(null);
  const ask = (text: string) => new Promise<boolean>(resolve => setState({ text, resolve }));
  const close = (v: boolean) => { state?.resolve(v); setState(null); };
  const node = (
    <Modal title="Bevestigen" open={!!state} onClose={() => close(false)}
      footer={<><button className="btn btn-ghost" onClick={() => close(false)}>Annuleren</button><button className="btn btn-primary" onClick={() => close(true)}>Ja, doorgaan</button></>}>
      <p style={{ fontSize: 14.5 }}>{state?.text}</p>
    </Modal>
  );
  return [ask, node];
}

export function Sparkline({ seed, up = true }: { seed: number; up?: boolean }) {
  let s = seed, v = 20;
  const r = () => { s = (s * 16807) % 2147483647; return (s - 1) / 2147483646; };
  const pts: number[] = [];
  for (let i = 0; i < 16; i++) { v += (up ? 1.4 : 0.4) + (r() - 0.45) * 5; pts.push(v); }
  const min = Math.min(...pts), max = Math.max(...pts);
  const d = pts.map((p, i) => (i * (100 / 15)).toFixed(1) + ',' + (30 - ((p - min) / (max - min || 1)) * 26 - 2).toFixed(1)).join(' ');
  return <svg className="spark" viewBox="0 0 100 32" preserveAspectRatio="none" aria-hidden="true"><polyline points={d} fill="none" stroke="var(--success)" strokeWidth="1.8" vectorEffect="non-scaling-stroke" /></svg>;
}

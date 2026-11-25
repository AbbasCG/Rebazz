/* Gedeelde UI-bouwstenen. */
import { AnimatePresence, motion, type HTMLMotionProps } from 'motion/react';
import { createContext, useCallback, useContext, useState, type ReactNode } from 'react';
import icons from '../data/icons.json';
import { useTheme } from '../lib/theme';
import type { Product } from '../lib/types';

const ICONS = icons as Record<string, string>;

export function Icon({ name, size = 18, className = '' }: { name: string; size?: number; className?: string }) {
  return (
    <svg
      className={'icon' + (name === 'star' ? ' icon-fill' : '') + (className ? ' ' + className : '')}
      width={size}
      height={size}
      viewBox="0 0 24 24"
      aria-hidden="true"
      dangerouslySetInnerHTML={{ __html: ICONS[name] ?? ICONS.package }}
    />
  );
}

export function ProductVisual({ p, className = '' }: { p: Pick<Product, 'image' | 'hue' | 'icon' | 'name'>; className?: string }) {
  if (p.image) {
    return (
      <div className={'pv has-img ' + className} style={{ ['--h' as string]: p.hue || 220 }}>
        <img src={p.image} alt={p.name} loading="lazy" />
      </div>
    );
  }
  return (
    <div className={'pv ' + className} style={{ ['--h' as string]: p.hue || 220 }}>
      <Icon name={p.icon || 'package'} size={64} />
    </div>
  );
}

export function Stars({ rating }: { rating: number }) {
  return (
    <span className="stars" role="img" aria-label={rating + ' / 5'}>
      {[1, 2, 3, 4, 5].map(i => <span key={i} className={i <= Math.round(rating) ? 'on' : ''}><Icon name="star" size={14} /></span>)}
    </span>
  );
}

/* Knop met subtiele Motion-feedback (schaal bij hover/tik). */
export function MButton({ children, className = '', ...rest }: HTMLMotionProps<'button'> & { children: ReactNode }) {
  return (
    <motion.button whileHover={{ y: -1 }} whileTap={{ scale: 0.97 }} transition={{ type: 'spring', stiffness: 500, damping: 30 }} className={className} {...rest}>
      {children}
    </motion.button>
  );
}

export function ThemeToggle({ label }: { label: string }) {
  const { dark, toggle } = useTheme();
  return (
    <MButton className="icon-btn theme-toggle" type="button" onClick={toggle} aria-label={label} title={label}>
      <AnimatePresence mode="wait" initial={false}>
        <motion.span key={dark ? 'moon' : 'sun'} style={{ display: 'grid' }} initial={{ rotate: -90, opacity: 0, scale: 0.6 }} animate={{ rotate: 0, opacity: 1, scale: 1 }} exit={{ rotate: 90, opacity: 0, scale: 0.6 }} transition={{ duration: 0.22 }}>
          <Icon name={dark ? 'moon' : 'sun'} size={19} />
        </motion.span>
      </AnimatePresence>
    </MButton>
  );
}

/* ---------- Meldingen (toasts) ---------- */
interface ToastItem { id: number; text: string; kind: 'ok' | 'error' }
const ToastCtx = createContext<(text: string, kind?: 'ok' | 'error') => void>(() => {});

export function ToastProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<ToastItem[]>([]);
  const push = useCallback((text: string, kind: 'ok' | 'error' = 'ok') => {
    const id = Date.now() + Math.random();
    setItems(list => [...list, { id, text, kind }]);
    setTimeout(() => setItems(list => list.filter(t => t.id !== id)), 3400);
  }, []);
  return (
    <ToastCtx.Provider value={push}>
      {children}
      <div className="toast-wrap" role="status" aria-live="polite">
        <AnimatePresence>
          {items.map(t => (
            <motion.div key={t.id} className="toast" initial={{ opacity: 0, y: 12, scale: 0.98 }} animate={{ opacity: 1, y: 0, scale: 1 }} exit={{ opacity: 0, y: 8 }} layout>
              <Icon name={t.kind === 'ok' ? 'check' : 'alert'} size={18} />
              <span>{t.text}</span>
            </motion.div>
          ))}
        </AnimatePresence>
      </div>
    </ToastCtx.Provider>
  );
}

export const useToast = () => useContext(ToastCtx);

/* Overlay + lade/modaal met Motion-overgangen. */
export function Overlay({ open, onClose }: { open: boolean; onClose: () => void }) {
  return (
    <AnimatePresence>
      {open && <motion.div className="overlay open" onClick={onClose} initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} />}
    </AnimatePresence>
  );
}

/* Licht/donker: volgt het systeem, tenzij de bezoeker zelf kiest (onthouden). */
import { useCallback, useEffect, useState } from 'react';
import { loadText, saveText } from './storage';

const KEY = 'rebazz.theme';
const media = () => window.matchMedia?.('(prefers-color-scheme: dark)');

export function applySavedTheme(): void {
  const saved = loadText(KEY);
  if (saved === 'light' || saved === 'dark') document.documentElement.setAttribute('data-theme', saved);
}

function currentIsDark(): boolean {
  const attr = document.documentElement.getAttribute('data-theme');
  if (attr) return attr === 'dark';
  return !!media()?.matches;
}

export function useTheme(): { dark: boolean; toggle: () => void } {
  const [dark, setDark] = useState(currentIsDark);
  useEffect(() => {
    const m = media();
    const onChange = () => setDark(currentIsDark());
    m?.addEventListener('change', onChange);
    return () => m?.removeEventListener('change', onChange);
  }, []);
  const toggle = useCallback(() => {
    const next = currentIsDark() ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    saveText(KEY, next);
    setDark(next === 'dark');
  }, []);
  return { dark, toggle };
}

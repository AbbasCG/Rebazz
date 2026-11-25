import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Link, useLocation } from 'react-router-dom';
import { ThemeToggle } from '../components/ui';
import { WithdrawalForm } from '../components/WithdrawalForm';
import legal from '../data/legal.html.json';
import { useI18n } from '../lib/i18n';
import { useShop } from '../lib/shop';

const esc = (s: string) => s.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]!));

export default function Legal() {
  const { t } = useI18n();
  const { catalog, money } = useShop();
  const ref = useRef<HTMLDivElement>(null);
  const location = useLocation();
  const b = catalog.brand;
  const [formRoot, setFormRoot] = useState<HTMLElement | null>(null);

  useEffect(() => { setFormRoot(ref.current?.querySelector<HTMLElement>('#withdrawalRoot') ?? null); }, []);

  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    el.querySelectorAll('.mail').forEach(n => { n.innerHTML = '<a href="mailto:' + esc(b.email) + '">' + esc(b.email) + '</a>'; });
    el.querySelectorAll('.company').forEach(n => { n.textContent = b.company; });
    el.querySelectorAll('.addr').forEach(n => { n.textContent = b.address; });
    const set = (id: string, v: string) => { const n = el.querySelector('#' + id); if (n) n.textContent = v; };
    set('kvk', b.kvk); set('btw', b.btw); set('retaddr', b.returnAddress);
    const tb = el.querySelector('#zoneTable tbody');
    if (tb) tb.innerHTML = catalog.zones.map(z => '<tr><td>' + esc(z.name) + '</td><td>' + money(z.rate, 'EUR', 'nl-NL') + '</td><td>' + money(z.freeFrom, 'EUR', 'nl-NL') + '</td><td>' + z.days[0] + '–' + z.days[1] + ' werkdagen</td></tr>').join('');
    const btn = el.querySelector<HTMLButtonElement>('#resetCookies');
    if (btn) btn.onclick = () => { try { localStorage.removeItem('rebazz.cookie.v1'); } catch { /* */ } window.location.href = '/'; };
    el.querySelectorAll<HTMLAnchorElement>('.legal-nav a').forEach(a => { a.onclick = e => { e.preventDefault(); document.querySelector(a.getAttribute('href')!)?.scrollIntoView({ behavior: 'smooth' }); }; });
  }, [b, catalog.zones, money]);

  useEffect(() => {
    if (location.hash) setTimeout(() => document.querySelector(location.hash)?.scrollIntoView(), 50);
  }, [location.hash]);

  return (
    <>
      <header className="simple-header">
        <div className="container">
          <Link to="/" className="logo"><span className="logo-mark">R</span>Rebazz</Link>
          <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
            <ThemeToggle label={t('theme')} />
            <Link to="/" className="btn btn-outline btn-sm">{t('continue')}</Link>
          </div>
        </div>
      </header>
      <div className="container legal-wrap" ref={ref} dangerouslySetInnerHTML={{ __html: legal.html }} />
      {formRoot && createPortal(<WithdrawalForm />, formRoot)}
    </>
  );
}

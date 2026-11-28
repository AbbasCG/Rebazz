import { motion } from 'motion/react';
import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { Icon } from '../components/ui';
import { api } from '../lib/api';
import { trackPurchase } from '../lib/analytics';
import { useI18n } from '../lib/i18n';
import { useShop } from '../lib/shop';

export default function Thanks() {
  const { t } = useI18n();
  const { clearCart } = useShop();
  const [params] = useSearchParams();
  const [msg, setMsg] = useState<string>(t('co_loading'));

  useEffect(() => {
    const id = params.get('session_id') ?? '';
    api.confirm(id)
      .then(o => {
        const esc = (s: string) => s.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]!));
        if (o.paid) { trackPurchase(o.order_no, o.total); clearCart(); setMsg(t('th_text', { id: esc(o.order_no), email: esc(o.email) })); }
        else setMsg(t('th_pending'));
      })
      .catch(() => setMsg(t('th_unknown')));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <>
      <header className="simple-header"><div className="container"><Link to="/" className="logo"><span className="logo-mark">R</span>Rebazz</Link></div></header>
      <main className="container">
        <motion.div className="confirm" initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }}>
          <motion.div className="ok" initial={{ scale: 0 }} animate={{ scale: 1 }} transition={{ type: 'spring', stiffness: 260, damping: 14, delay: 0.15 }}><Icon name="check" size={30} /></motion.div>
          <h1>{t('co_thanks')}</h1>
          <p className="muted" style={{ margin: '12px 0 24px' }} dangerouslySetInnerHTML={{ __html: msg }} />
          <div style={{ display: 'flex', gap: 10, justifyContent: 'center', flexWrap: 'wrap' }}>
            <Link className="btn btn-primary" to="/">{t('continue')}</Link>
            <Link className="btn btn-outline" to="/#volgen">{t('nav_track')}</Link>
          </div>
        </motion.div>
      </main>
    </>
  );
}

import { Link } from 'react-router-dom';
import { useI18n } from '../lib/i18n';

export default function NotFound() {
  const { t } = useI18n();
  return (
    <main className="container" style={{ minHeight: '80vh', display: 'grid', placeItems: 'center', textAlign: 'center' }}>
      <div>
        <Link to="/" className="logo" style={{ justifyContent: 'center', marginBottom: 28 }}><span className="logo-mark">R</span>Rebazz</Link>
        <h1 style={{ fontFamily: 'var(--serif)', fontWeight: 500, fontSize: 44 }}>404</h1>
        <p className="muted" style={{ margin: '10px 0 24px' }}>Deze pagina bestaat niet (meer). · This page could not be found.</p>
        <Link to="/" className="btn btn-primary btn-lg">{t('co_to_collection')}</Link>
      </div>
    </main>
  );
}

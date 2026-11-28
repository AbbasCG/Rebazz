import { AnimatePresence, MotionConfig, motion } from 'motion/react';
import { lazy, Suspense, useEffect } from 'react';
import { BrowserRouter, MemoryRouter, Route, Routes, useLocation } from 'react-router-dom';

import { trackPage } from './lib/analytics';
import { SHARE } from './lib/env';

/* In de deelversie (zonder server) onthoudt de app de pagina in het geheugen. */
const Router = SHARE ? MemoryRouter : BrowserRouter;
import { ErrorBoundary } from './components/ErrorBoundary';
import { ToastProvider } from './components/ui';
import { AuthProvider } from './lib/auth';
import { I18nProvider, useI18n } from './lib/i18n';
import { usePageMeta } from './lib/meta';
import { ShopProvider } from './lib/shop';
import Home from './pages/Home';

/* Alleen de homepage zit in het eerste bestand; andere pagina's en het dashboard
   worden pas geladen als iemand ze opent (snellere winkel op mobiel). */
const Admin = lazy(() => import('./admin/AdminApp'));
const Checkout = lazy(() => import('./pages/Checkout'));
const Thanks = lazy(() => import('./pages/Thanks'));
const Legal = lazy(() => import('./pages/Legal'));
const ProductPage = lazy(() => import('./pages/ProductPage'));
const PrivacyRequest = lazy(() => import('./pages/PrivacyRequest'));
const Account = lazy(() => import('./pages/Account'));
const NotFound = lazy(() => import('./pages/NotFound'));

function Page({ children }: { children: React.ReactNode }) {
  return (
    <motion.div className="page-fade" initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -6 }} transition={{ duration: 0.25, ease: 'easeOut' }}>
      <Suspense fallback={<div style={{ minHeight: '60vh' }} />}>{children}</Suspense>
    </motion.div>
  );
}

function ShopRoutes() {
  const location = useLocation();
  const { locale, t } = useI18n();
  const path = location.pathname;
  const isAdmin = path.startsWith('/beheer');
  const isProduct = path.startsWith('/product/'); // de productpagina zet zelf titel, canonical en gegevens
  const title = isAdmin || isProduct ? '' // het beheer zet zijn eigen titel
    : path === '/checkout' ? `${t('co_title')} — Rebazz`
    : path === '/juridisch' ? `${t('f_legal')} — Rebazz`
    : 'Rebazz';
  usePageMeta(isProduct ? '' : path, title, !['/', '/juridisch'].includes(path));
  useEffect(() => { trackPage(path); }, [path]); // alleen met toestemming; zie lib/analytics.ts
  return (
    <ShopProvider locale={locale}>
      <AnimatePresence mode="wait">
        <Routes location={location} key={location.pathname}>
          <Route path="/" element={<Page><Home /></Page>} />
          <Route path="/checkout" element={<Page><Checkout /></Page>} />
          <Route path="/bedankt" element={<Page><Thanks /></Page>} />
          <Route path="/product/:id/*" element={<Page><ProductPage /></Page>} />
          <Route path="/privacy-verzoek" element={<Page><PrivacyRequest mode="request" /></Page>} />
          <Route path="/privacy-verzoek/bevestigen" element={<Page><PrivacyRequest mode="confirm" /></Page>} />
          <Route path="/account" element={<Page><Account /></Page>} />
          <Route path="/juridisch" element={<Page><Legal /></Page>} />
          <Route path="/beheer/*" element={<Suspense fallback={<div style={{ padding: 40 }}>Laden…</div>}><Admin /></Suspense>} />
          <Route path="*" element={<Page><NotFound /></Page>} />
        </Routes>
      </AnimatePresence>
    </ShopProvider>
  );
}

export default function App() {
  return (
    /* reducedMotion="user": Motion respecteert de systeeminstelling "minder beweging" */
    <MotionConfig reducedMotion="user">
      <Router>
        <I18nProvider>
          <ToastProvider>
            <ErrorBoundary>
              <AuthProvider>
                <ShopRoutes />
              </AuthProvider>
            </ErrorBoundary>
          </ToastProvider>
        </I18nProvider>
      </Router>
    </MotionConfig>
  );
}

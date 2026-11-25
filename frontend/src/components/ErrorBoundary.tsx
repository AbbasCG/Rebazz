import { Component, type ErrorInfo, type ReactNode } from 'react';
import { useI18n } from '../lib/i18n';

const TEXT: Record<string, [string, string]> = {
  nl: ['Er ging iets mis', 'Vernieuw de pagina. Blijft het probleem, neem dan contact met ons op.'],
  en: ['Something went wrong', 'Please refresh the page. If the problem persists, contact us.'],
  de: ['Etwas ist schiefgelaufen', 'Bitte laden Sie die Seite neu. Besteht das Problem weiter, kontaktieren Sie uns.'],
  fr: ['Une erreur est survenue', 'Veuillez actualiser la page. Si le problème persiste, contactez-nous.'],
  es: ['Algo salió mal', 'Actualice la página. Si el problema continúa, contáctenos.'],
  it: ['Qualcosa è andato storto', 'Aggiorni la pagina. Se il problema persiste, ci contatti.'],
  pl: ['Coś poszło nie tak', 'Odśwież stronę. Jeśli problem nie ustąpi, skontaktuj się z nami.'],
  ar: ['حدث خطأ ما', 'يرجى تحديث الصفحة. وإذا استمرت المشكلة فتواصلوا معنا.']
};

function Fallback() {
  const { lang } = useI18n();
  const [title, text] = TEXT[lang] ?? TEXT.en;
  return (
    <div role="alert" className="container" style={{ padding: '80px 20px', textAlign: 'center' }}>
      <h1>{title}</h1>
      <p className="muted">{text}</p>
      <button className="btn btn-primary" onClick={() => window.location.reload()}>↻</button>
    </div>
  );
}

/** Vangt renderfouten op zodat een fout in één onderdeel niet de hele winkel wit maakt. */
export class ErrorBoundary extends Component<{ children: ReactNode }, { failed: boolean }> {
  state = { failed: false };

  static getDerivedStateFromError() {
    return { failed: true };
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    console.error('Renderfout', error, info.componentStack);
  }

  render() {
    return this.state.failed ? <Fallback /> : this.props.children;
  }
}

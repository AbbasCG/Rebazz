import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import '@fontsource-variable/inter';
import '@fontsource-variable/fraunces/wght.css';
import App from './App';
import { applySavedTheme } from './lib/theme';
import './styles/common.css';
import './styles/store.css';
import './styles/pages.css';
import './styles/motion.css';

applySavedTheme();

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>
);

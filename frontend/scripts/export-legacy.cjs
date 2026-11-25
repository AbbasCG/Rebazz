/* Zet de vertalingen en de catalogus van de oude website om naar JSON
   (voor de React-frontend en de database-seeder van Laravel). */
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.resolve(__dirname, '..', '..');
const sandbox = {
  module: {}, console,
  localStorage: { getItem: () => null, setItem: () => {} },
  navigator: { language: 'nl' },
  document: { documentElement: { getAttribute: () => null, setAttribute: () => {} }, body: null, addEventListener: () => {} },
  window: { matchMedia: () => ({ matches: false }) },
  Intl
};
vm.createContext(sandbox);
const run = file => vm.runInContext(fs.readFileSync(path.join(root, file), 'utf8'), sandbox, { filename: file });
run('js/icons.js');
const icons = vm.runInContext('ICONS', sandbox);
fs.mkdirSync(path.join(root, 'frontend', 'src', 'data'), { recursive: true });
fs.writeFileSync(path.join(root, 'frontend', 'src', 'data', 'icons.json'), JSON.stringify(icons));

run('js/catalog.js');
run('js/data.js');
run('js/i18n.js');

const catalog = vm.runInContext('({ brand: CATALOG.brand, currency: CATALOG.currency, zones: CATALOG.zones, reviews: CATALOG.reviews, products: CATALOG.products })', sandbox);
const i18n = vm.runInContext('({ languages: LANGUAGES, texts: T, productEn: PRODUCT_EN, productNames: PRODUCT_NAMES, countries: COUNTRIES, currencies: CURRENCIES, paymentMethods: PAYMENT_METHODS, checklist: CHECKLIST, automations: SEED_AUTOMATIONS, campaigns: SEED_CAMPAIGNS })', sandbox);

const outFront = path.join(root, 'frontend', 'src', 'data');
fs.mkdirSync(outFront, { recursive: true });
fs.writeFileSync(path.join(outFront, 'i18n.json'), JSON.stringify(i18n, null, 1));
fs.writeFileSync(path.join(outFront, 'catalog.json'), JSON.stringify(catalog, null, 1));
const outBack = path.join(root, 'backend-seed');
fs.mkdirSync(outBack, { recursive: true });
fs.writeFileSync(path.join(outBack, 'catalog.json'), JSON.stringify(catalog, null, 1));
console.log('talen:', Object.keys(i18n.texts).join(','), '| sleutels nl:', Object.keys(i18n.texts.nl).length, '| producten:', catalog.products.length);

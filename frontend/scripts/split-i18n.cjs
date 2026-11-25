/* Splitst i18n.json in losse taalbestanden (alleen laden wat de bezoeker nodig heeft). */
const fs = require('fs');
const path = require('path');
const dir = path.join(__dirname, '..', 'src', 'data');
const d = JSON.parse(fs.readFileSync(path.join(dir, 'i18n.json'), 'utf8'));
fs.mkdirSync(path.join(dir, 'lang'), { recursive: true });
for (const code of Object.keys(d.texts)) {
  const out = { texts: d.texts[code], productNames: d.productNames[code] || {}, productEn: code === 'nl' ? {} : d.productEn };
  fs.writeFileSync(path.join(dir, 'lang', code + '.json'), JSON.stringify(out));
}
const meta = { languages: d.languages, countries: d.countries, currencies: d.currencies, paymentMethods: d.paymentMethods, checklist: d.checklist, automations: d.automations, campaigns: d.campaigns };
fs.writeFileSync(path.join(dir, 'i18n-meta.json'), JSON.stringify(meta));
fs.unlinkSync(path.join(dir, 'i18n.json'));
console.log('talen:', Object.keys(d.texts).join(', '));

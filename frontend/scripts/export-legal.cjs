/* Neemt de juridische teksten over uit de oude juridisch.html. */
const fs = require('fs');
const path = require('path');
const root = path.resolve(__dirname, '..', '..');
const html = fs.readFileSync(path.join(root, 'juridisch.html'), 'utf8');
const start = html.indexOf('<nav class="legal-nav"');
const end = html.indexOf('<script', start);
let body = html.slice(start, end).trim();
body = body.replace(/<\/div>\s*<\/div>\s*$/, '</div>'); // sluitende container-div eraf
fs.writeFileSync(path.join(root, 'frontend', 'src', 'data', 'legal.html.json'), JSON.stringify({ html: body }));
console.log('juridische tekst:', body.length, 'tekens');

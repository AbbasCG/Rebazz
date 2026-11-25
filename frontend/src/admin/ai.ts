/* AI-sjablonen (gesimuleerd). In een volgende fase te vervangen door echte AI-koppelingen. */
import type { Product } from '../lib/types';

const eur = (v: number) => new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' }).format(v);

export const AI = {
  description: (p: Pick<Product, 'name' | 'features' | 'warehouse'>) =>
    'Ontdek de ' + p.name.toLowerCase() + ': ontworpen voor wie kwaliteit en gemak waardeert. ' + p.features.slice(0, 3).join(', ').toLowerCase() + '. Snel geleverd vanuit ' + p.warehouse + ' en gedekt door onze 30 dagen bedenktijd.',
  script(p: Product, opt: { style: string; duration: number; lang: string; cta: string }) {
    const en = opt.lang === 'en';
    const d = opt.duration;
    const cut = (n: number) => Math.round(d * n);
    const f = p.features;
    const hooks: Record<string, string> = en
      ? { ugc: 'I didn’t expect this to be so good…', demo: 'Watch this in 3 seconds.', luxury: 'Designed for those who expect more.', problem: 'Still struggling with this every day?' }
      : { ugc: 'Ik had niet verwacht dat dit zó goed zou zijn…', demo: 'Kijk wat dit in 3 seconden doet.', luxury: 'Ontworpen voor wie meer verwacht.', problem: 'Heeft u hier elke dag nog last van?' };
    const hook = hooks[opt.style];
    return [
      { t: '0–' + cut(0.15) + 's', role: 'Hook', visual: 'Close-up van het product, snelle inzoom', vo: hook, cap: hook },
      { t: cut(0.15) + '–' + cut(0.4) + 's', role: en ? 'Problem' : 'Probleem', visual: 'Herkenbare situatie zonder het product', vo: en ? 'Most alternatives are expensive, slow or simply disappointing.' : 'De meeste alternatieven zijn duur, traag of stellen simpelweg teleur.', cap: en ? 'Sound familiar?' : 'Herkenbaar?' },
      { t: cut(0.4) + '–' + cut(0.7) + 's', role: en ? 'Solution' : 'Oplossing', visual: 'Product in gebruik, kenmerken in beeld', vo: (en ? 'Meet the ' : 'Maak kennis met de ') + p.name + '. ' + f.slice(0, 2).join('. ') + '.', cap: f[0] ?? p.name },
      { t: cut(0.7) + '–' + cut(0.88) + 's', role: en ? 'Proof' : 'Bewijs', visual: 'Kenmerken en garantie in beeld', vo: en ? '30-day return period and fast delivery.' : '30 dagen bedenktijd en snelle levering.', cap: en ? '30 days to try it' : '30 dagen proberen' },
      { t: cut(0.88) + '–' + d + 's', role: 'CTA', visual: 'Productshot met prijs en knop', vo: opt.cta, cap: opt.cta }
    ];
  },
  adCopy(p: Product, goal: string) {
    const save = p.compare && p.compare > p.price ? Math.round((1 - p.price / p.compare) * 100) : 0;
    return [
      { headline: p.name + (save ? ' — nu ' + save + '% voordeliger' : ''), text: p.features.slice(0, 3).map(x => '✓ ' + x).join('\n') + '\nGratis verzending vanaf € 49 · 30 dagen bedenktijd.', cta: 'Nu bestellen' },
      { headline: 'Waarom kiezen voor de ' + p.name.split(' ')[0] + '?', text: p.description.split('.')[0] + '. Vandaag besteld, snel verzonden.', cta: 'Meer informatie' },
      { headline: (goal === 'Retargeting' ? 'Nog steeds aan het twijfelen?' : 'Nieuw bij Rebazz') + ' — vanaf ' + eur(p.price), text: goal === 'Retargeting' ? 'Uw ' + p.name.toLowerCase() + ' ligt nog klaar.' : 'Ontdek de ' + p.name.toLowerCase() + '. ' + (p.features[1] ?? '') + '.', cta: goal === 'Retargeting' ? 'Afronden' : 'Shop nu' }
    ];
  },
  hashtags: (p: Product) => ['#' + p.category.toLowerCase(), '#rebazz', '#musthave', '#' + p.name.split(' ')[0].toLowerCase(), '#review']
};

export const CANDIDATES = [
  { name: 'LED-zonsopgangwekker met natuurgeluiden', category: 'Wonen', icon: 'lamp', hue: 44, cost: 11.2, score: 91, comp: 'Laag', trend: '+184%', supplier: 'CJdropshipping', warehouse: 'EU (Polen)', days: [3, 6] as [number, number], why: 'Sterk stijgende zoekvraag rond slaapgezondheid in de herfst- en wintermaanden; weinig gevestigde aanbieders in de Benelux.', features: ['Simuleert zonsopgang in 30 minuten', '7 natuurgeluiden', 'Dimbaar leeslicht', 'FM-radio en USB-poort'] },
  { name: 'Magnetische powerbank 10.000 mAh', category: 'Tech', icon: 'zap', hue: 255, cost: 9.1, score: 89, comp: 'Hoog', trend: '+96%', supplier: 'CJdropshipping', warehouse: 'EU (Polen)', days: [3, 6] as [number, number], why: 'Hoog volume en impulsaankoop; onderscheid u met snelle EU-levering en een sterke videodemonstratie.', features: ['Magnetisch bevestigen', '10.000 mAh capaciteit', '20W snelladen', 'Ultradun ontwerp'] },
  { name: 'Ergonomische laptopstandaard (aluminium)', category: 'Werkplek', icon: 'package', hue: 210, cost: 8.9, score: 88, comp: 'Midden', trend: '+62%', supplier: 'Zendrop', warehouse: 'EU (Duitsland)', days: [3, 7] as [number, number], why: 'Thuiswerken blijft populair; geschikt voor bundels met de draadloze lader.', features: ['6 hoogtestanden', 'Gemaakt van aluminium', 'Opvouwbaar en licht', 'Geschikt tot 17 inch'] },
  { name: 'Zelfreinigende huisdierenborstel', category: 'Huisdieren', icon: 'heart', hue: 350, cost: 2.9, score: 87, comp: 'Laag', trend: '+71%', supplier: 'AliExpress (via DSers)', warehouse: 'China', days: [8, 14] as [number, number], why: 'Zeer hoge marge en uitstekend geschikt voor UGC-video’s.', features: ['Eén druk op de knop reinigt', 'Zachte, huidvriendelijke pinnen', 'Voor honden en katten', 'Ergonomische greep'] },
  { name: 'Massagepistool Compact Pro', category: 'Sport', icon: 'dumbbell', hue: 8, cost: 19.5, score: 86, comp: 'Midden', trend: '+44%', supplier: 'Spocket', warehouse: 'EU (Nederland)', days: [2, 4] as [number, number], why: 'Hogere prijsklasse met goede marge; lokale voorraad maakt levering binnen 2–4 dagen mogelijk.', features: ['6 snelheden', '4 opzetstukken', 'Stille motor (<45 dB)', '6 uur batterijduur'] },
  { name: 'Noise-cancelling slaapoordopjes', category: 'Audio', icon: 'headphones', hue: 228, cost: 12.3, score: 85, comp: 'Midden', trend: '+58%', supplier: 'CJdropshipping', warehouse: 'China', days: [7, 12] as [number, number], why: 'Sluit aan op uw bestaande audiocategorie.', features: ['Comfortabel bij zijslapen', 'Ruisonderdrukking', '10 uur afspeeltijd', 'Slaapgeluiden-app'] },
  { name: 'Draagbare mini-blender (USB-C)', category: 'Keuken', icon: 'coffee', hue: 120, cost: 7.4, score: 84, comp: 'Hoog', trend: '+51%', supplier: 'CJdropshipping', warehouse: 'EU (Polen)', days: [3, 6] as [number, number], why: 'Visueel sterk product voor korte video’s; onderscheid u met branding.', features: ['Oplaadbaar via USB-C', '6 roestvrijstalen messen', '400 ml', 'Vaatwasmachinebestendig'] },
  { name: 'Slimme waterfles met drinkherinnering', category: 'Sport', icon: 'clock', hue: 185, cost: 6.8, score: 81, comp: 'Laag', trend: '+47%', supplier: 'CJdropshipping', warehouse: 'China', days: [7, 12] as [number, number], why: 'Gezondheidstrend met weinig sterke merken in Europa.', features: ['LED-drinkherinnering', 'Temperatuurweergave', 'Dubbelwandig RVS', 'Lekvrij'] },
  { name: 'Opvouwbare reisorganizer-set (6-delig)', category: 'Reizen', icon: 'bag', hue: 168, cost: 5.6, score: 80, comp: 'Laag', trend: '+28%', supplier: 'Zendrop', warehouse: 'EU (Duitsland)', days: [3, 7] as [number, number], why: 'Seizoenspiek in vakantieperiodes; upsell bij de rugzak.', features: ['6 formaten', 'Waterafstotend', 'Ademend gaas', 'Compact opvouwbaar'] }
];

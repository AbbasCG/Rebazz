import { Link, useParams } from 'react-router-dom';
import ShopChrome, { ProductDetail, useShopUI } from '../components/ShopChrome';
import { useI18n } from '../lib/i18n';
import { usePageMeta } from '../lib/meta';
import { useShop } from '../lib/shop';
import { productPath } from '../lib/slug';
import NotFound from './NotFound';

function Inner({ id }: { id: string }) {
  const { t, pName, pDesc } = useI18n();
  const { catalog } = useShop();
  const { openCart } = useShopUI();
  const p = catalog.products.find(x => x.id === id && x.status === 'active');
  const path = p ? productPath(p) : '/product/' + id;
  const desc = p ? pDesc(p).slice(0, 300) : '';
  const origin = window.location.origin;
  const image = p?.image ? (p.image.startsWith('/') ? origin + p.image : p.image) : undefined;
  usePageMeta(path, p ? `${pName(p)} — Rebazz` : '', !p, {
    description: desc || undefined,
    image,
    jsonLd: p ? {
      '@context': 'https://schema.org', '@type': 'Product', name: pName(p), description: desc, sku: p.sku, ...(image ? { image } : {}),
      brand: { '@type': 'Brand', name: 'Rebazz' },
      ...(p.reviews > 0 ? { aggregateRating: { '@type': 'AggregateRating', ratingValue: p.rating, reviewCount: p.reviews } } : {}),
      offers: { '@type': 'Offer', url: origin + path, priceCurrency: 'EUR', price: p.price.toFixed(2), availability: 'https://schema.org/InStock', itemCondition: 'https://schema.org/NewCondition' }
    } : undefined
  });
  if (!p) return <NotFound />;
  return (
    <div className="container product-page">
      <nav className="crumbs muted" aria-label="breadcrumb"><Link to="/">Rebazz</Link><span>/</span><span>{pName(p)}</span></nav>
      <ProductDetail p={p} onAdded={openCart} />
      <p style={{ marginTop: 20 }}><Link to="/#collectie" className="btn btn-outline btn-sm">{t('co_to_collection')}</Link></p>
    </div>
  );
}

export default function ProductPage() {
  const { id = '' } = useParams();
  return <ShopChrome><Inner id={decodeURIComponent(id)} /></ShopChrome>;
}

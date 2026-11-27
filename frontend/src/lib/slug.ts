import type { Product } from './types';

/** URL-vriendelijke naam (zonder accenten). De server levert hetzelfde in `slug`; dit is de terugval. */
export function slugify(s: string): string {
  return s.normalize('NFKD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 60);
}

/** Vaste pagina per product: /product/<id>/<naam>. De naam is cosmetisch; het id bepaalt het product. */
export function productPath(p: Pick<Product, 'id' | 'name' | 'slug'>): string {
  const slug = p.slug ?? slugify(p.name);
  return '/product/' + encodeURIComponent(p.id) + (slug ? '/' + slug : '');
}

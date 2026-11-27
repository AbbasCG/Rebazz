/* Rekenregels voor de winkelwagen. De server rekent dezelfde regels opnieuw
   uit met de prijzen uit de database (bron van waarheid). */
import type { CartLine, Product, ProductVariant, Zone } from './types';

/** Eén regel van de bestelling; `unit` is de prijs per stuk (variantprijs of productprijs). */
export interface QuoteLine { product: Product; variant?: ProductVariant; qty: number; unit: number; key: string }

export const lineKey = (id: string, v?: number) => id + (v ? '#' + v : '');
export interface Quote {
  zone: Zone;
  lines: QuoteLine[];
  subtotal: number;
  shipping: number;
  total: number;
  days: [number, number];
  express: boolean;
}

export function zoneFor(zones: Zone[], country: string): Zone | null {
  return zones.find(z => z.countries.includes(country)) ?? null;
}

export function quote(products: Product[], zones: Zone[], items: CartLine[], country: string, shipMethod: 'standard' | 'express' = 'standard'): Quote | null {
  const zone = zoneFor(zones, country);
  if (!zone) return null;
  const lines: QuoteLine[] = [];
  for (const it of items) {
    const p = products.find(x => x.id === it.id && x.status === 'active');
    if (!p || it.qty < 1) continue;
    const hasVariants = !!p.variants?.length;
    const variant = hasVariants ? p.variants!.find(x => x.id === it.v) : undefined;
    if (hasVariants && !variant) continue; // keuze ontbreekt of bestaat niet meer: regel wordt overgeslagen
    lines.push({ product: p, variant, qty: Math.min(20, Math.floor(it.qty)), unit: variant?.price ?? p.price, key: lineKey(p.id, variant?.id) });
  }
  if (!lines.length) return null;
  const subtotal = lines.reduce((s, l) => s + l.unit * l.qty, 0);
  const minDays = lines.reduce((m, l) => Math.max(m, l.product.days[0]), zone.days[0]);
  const maxDays = lines.reduce((m, l) => Math.max(m, l.product.days[1]), zone.days[1]);
  const express = shipMethod === 'express' && zone.express > 0;
  const shipping = express ? zone.express : subtotal >= zone.freeFrom ? 0 : zone.rate;
  const days: [number, number] = express ? [Math.max(2, Math.ceil(minDays / 2)), Math.ceil(maxDays / 2) + 1] : [minDays, maxDays];
  return { zone, lines, subtotal, shipping, total: subtotal + shipping, days, express };
}

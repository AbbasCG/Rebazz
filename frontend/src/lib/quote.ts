/* Rekenregels voor de winkelwagen. De server rekent dezelfde regels opnieuw
   uit met de prijzen uit de database (bron van waarheid). */
import type { CartLine, Product, Zone } from './types';

export interface QuoteLine { product: Product; qty: number }
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
    if (p && it.qty >= 1) lines.push({ product: p, qty: Math.min(20, Math.floor(it.qty)) });
  }
  if (!lines.length) return null;
  const subtotal = lines.reduce((s, l) => s + l.product.price * l.qty, 0);
  const minDays = lines.reduce((m, l) => Math.max(m, l.product.days[0]), zone.days[0]);
  const maxDays = lines.reduce((m, l) => Math.max(m, l.product.days[1]), zone.days[1]);
  const express = shipMethod === 'express' && zone.express > 0;
  const shipping = express ? zone.express : subtotal >= zone.freeFrom ? 0 : zone.rate;
  const days: [number, number] = express ? [Math.max(2, Math.ceil(minDays / 2)), Math.ceil(maxDays / 2) + 1] : [minDays, maxDays];
  return { zone, lines, subtotal, shipping, total: subtotal + shipping, days, express };
}

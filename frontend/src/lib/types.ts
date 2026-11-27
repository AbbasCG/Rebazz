export type LangCode = 'nl' | 'en' | 'de' | 'fr' | 'es' | 'it' | 'pl' | 'ar' | string;

export interface Brand {
  name: string;
  domain: string;
  email: string;
  company: string;
  kvk: string;
  btw: string;
  address: string;
  returnAddress: string;
}

export interface Zone {
  id: string;
  name: string;
  countries: string[];
  rate: number;
  freeFrom: number;
  days: [number, number];
  vat: boolean;
  express: number;
}

export interface Review {
  text: string;
  name: string;
  city?: string;
  country?: string;
  rating?: number;
}

/** Keuze zoals maat of kleur: de namen en waarden worden door de eigenaar ingevoerd. */
export interface ProductOption { name: string; values: string[] }

/** Eén combinatie van opties (bijv. Zwart / M) met eigen prijs, voorraad en foto. */
export interface ProductVariant {
  id: number;
  label: string;
  values: Record<string, string>;
  sku: string;
  price: number;          // geldende prijs (eigen prijs of die van het product)
  image: string;
  inStock: boolean;
  stockLeft: number | null;
  /* Alleen in het beheer: */
  ownPrice?: number | null;
  cost?: number | null;
  cjVid?: string;
  stock?: number;
}

export interface Product {
  id: string;
  slug?: string;
  sku: string;
  name: string;
  category: string;
  price: number;
  compare: number | null;
  cost?: number;
  image: string;
  images?: string[];
  options?: ProductOption[];
  variants?: ProductVariant[];
  icon: string;
  hue: number;
  supplier?: string;
  supplierUrl?: string;
  cjVid?: string;
  cjFrom?: string;
  cjLogistic?: string;
  warehouse: string;
  days: [number, number];
  stock?: number;
  /** Voorraad: alleen als het product voorraad bijhoudt. inStock=false => uitverkocht; stockLeft = nog maar een paar stuks. */
  trackStock?: boolean;
  inStock?: boolean;
  stockLeft?: number | null;
  rating: number;
  reviews: number;
  sold?: number;
  status: 'active' | 'draft';
  description: string;
  features: string[];
  /** GPSR: fabrikant, in de EU gevestigde verantwoordelijke en veiligheidsinformatie. */
  manufacturer?: string;
  euResponsible?: string;
  safetyInfo?: string;
}

export interface Catalog {
  brand: Brand;
  currency: string;
  zones: Zone[];
  reviews: Review[];
  products: Product[];
}

export interface CartLine {
  id: string;
  qty: number;
  v?: number; // variant-id (alleen bij producten met varianten)
}

export interface OrderItem {
  id: string;
  sku: string;
  name: string;
  price: number;
  cost: number;
  qty: number;
  supplier: string;
  supplierUrl: string;
}

export interface Order {
  id: string;
  pi: string;
  live: boolean;
  date: string;
  customer: string;
  email: string;
  phone: string;
  country: string;
  address: string;
  items: OrderItem[];
  subtotal: number;
  discount: number;
  shipping: number;
  total: number;
  cost: number;
  payment: string;
  shipMethod: string;
  lang: string;
  step: number;
  carrier: string;
  tracking: string;
  delayed: boolean;
  refunded: boolean;
  paid: boolean;
  cjStatus: string;
  cjOrders: string;
  cjError: string;
}

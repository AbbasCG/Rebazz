/* Verbinding met de Laravel-API. In ontwikkeling stuurt Vite /api door naar Laravel. */
import type { Catalog, Order, Product } from './types';

export class ApiError extends Error {
  status: number;
  data: unknown;
  constructor(message: string, status: number, data: unknown) {
    super(message);
    this.status = status;
    this.data = data;
  }
}

const BASE = (import.meta.env.VITE_API_URL as string | undefined)?.replace(/\/$/, '') ?? '';
const TOKEN_KEY = 'rebazz.admin.token';

/* Standaardfoutmeldingen in de taal van de bezoeker (winkel). Het beheer is Nederlandstalig en gebruikt eigen teksten. */
const MSG: Record<string, { offline: string; generic: string }> = {
  nl: { offline: 'De server is niet bereikbaar.', generic: 'Er ging iets mis' },
  en: { offline: 'The server cannot be reached.', generic: 'Something went wrong' },
  de: { offline: 'Der Server ist nicht erreichbar.', generic: 'Etwas ist schiefgelaufen' },
  fr: { offline: 'Le serveur est injoignable.', generic: 'Une erreur est survenue' },
  es: { offline: 'No se puede acceder al servidor.', generic: 'Algo salió mal' },
  it: { offline: 'Il server non è raggiungibile.', generic: 'Qualcosa è andato storto' },
  pl: { offline: 'Serwer jest niedostępny.', generic: 'Coś poszło nie tak' },
  ar: { offline: 'تعذّر الوصول إلى الخادم.', generic: 'حدث خطأ ما' }
};
const texts = (): { offline: string; generic: string } => {
  let l = 'nl';
  try { l = localStorage.getItem('rebazz.lang') ?? 'nl'; } catch { /* */ }
  return MSG[l] ?? MSG.en;
};

export const adminToken = {
  get: (): string => { try { return sessionStorage.getItem(TOKEN_KEY) ?? ''; } catch { return ''; } },
  set: (t: string) => { try { sessionStorage.setItem(TOKEN_KEY, t); } catch { /* */ } },
  clear: () => { try { sessionStorage.removeItem(TOKEN_KEY); } catch { /* */ } }
};

async function request<T>(method: string, path: string, body?: unknown, admin = false): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (admin && adminToken.get()) headers.Authorization = 'Bearer ' + adminToken.get();
  let res: Response;
  try {
    res = await fetch(BASE + '/api' + path, { method, headers, body: body !== undefined ? JSON.stringify(body) : undefined });
  } catch {
    throw new ApiError(texts().offline, 0, null);
  }
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    const msg = (data && (data.message || data.error)) || texts().generic + ' (' + res.status + ')';
    throw new ApiError(String(msg), res.status, data);
  }
  return data as T;
}

export const api = {
  catalog: () => request<Catalog>('GET', '/catalog'),
  checkout: (body: { items: { id: string; qty: number; name: string }[]; country: string; ship: string; lang: string }) =>
    request<{ url: string; order_no: string }>('POST', '/checkout', body),
  confirm: (sessionId: string) => request<{ order_no: string; paid: boolean; total: number; email: string; country: string }>('GET', '/orders/confirm?session_id=' + encodeURIComponent(sessionId)),
  track: (orderNo: string, email: string) => request<{ order_no: string; date: string; step: number; carrier: string; tracking: string; delayed: boolean; refunded: boolean }>('POST', '/orders/track', { order_no: orderNo, email }),
  withdrawal: (body: { name: string; email: string; order_no: string; items: string; message: string }) => request<{ ok: boolean }>('POST', '/withdrawal', body),
  newsletter: (email: string, lang: string) => request<{ ok: boolean }>('POST', '/newsletter', { email, lang }),

  admin: {
    login: (password: string) => request<{ token: string }>('POST', '/admin/login', { password }),
    logout: () => request<{ ok: boolean }>('POST', '/admin/logout', {}, true),
    status: () => request<{ stripe: string; webhook: boolean; cj: boolean; cjAutoPay: boolean; mail: boolean }>('GET', '/admin/status', undefined, true),
    orders: () => request<{ orders: Order[] }>('GET', '/admin/orders', undefined, true),
    updateOrder: (id: string, patch: Partial<Pick<Order, 'step' | 'carrier' | 'tracking' | 'delayed'>> & { notify?: boolean }) =>
      request<{ order: Order; mailed: boolean }>('PATCH', '/admin/orders/' + encodeURIComponent(id), patch, true),
    orderAction: (id: string, action: 'refund' | 'notify' | 'cj-order' | 'cj-check') =>
      request<{ order: Order; mailed?: boolean }>('POST', '/admin/orders/' + encodeURIComponent(id) + '/' + action, {}, true),
    cjSync: () => request<{ checked: number; changed: number }>('POST', '/admin/cj-sync', {}, true),
    products: () => request<{ products: Product[] }>('GET', '/admin/products', undefined, true),
    saveProduct: (p: Product, isNew: boolean) =>
      request<{ product: Product }>(isNew ? 'POST' : 'PUT', isNew ? '/admin/products' : '/admin/products/' + encodeURIComponent(p.id), p, true),
    deleteProduct: (id: string) => request<{ ok: boolean }>('DELETE', '/admin/products/' + encodeURIComponent(id), undefined, true),
    tax: (year: number, quarter: number) => request<TaxSummary>('GET', '/admin/tax?year=' + year + '&quarter=' + quarter, undefined, true),
    setting: <T>(key: string) => request<{ value: T | null }>('GET', '/admin/settings/' + key, undefined, true),
    saveSetting: (key: string, value: unknown) => request<{ value: unknown }>('PUT', '/admin/settings/' + key, { value }, true),
    subscribers: () => request<{ subscribers: { email: string; lang: string; created_at: string }[] }>('GET', '/admin/subscribers', undefined, true)
  }
};

export interface TaxRow { country: string; regime: 'nl' | 'oss' | 'export'; rate: number; orders: number; incl: number; excl: number; vat: number }
export interface TaxSummary {
  year: number;
  quarter: number;
  years: number[];
  useOss: boolean;
  inputVat: number;
  rows: TaxRow[];
  totals: { incl: number; excl: number; vat: number; orders: number };
  nl: { excl: number; vat: number; pay: number };
  oss: { vat: number; orders: number };
  export: { incl: number };
  crossBorderYear: number;
  threshold: number;
  deadlines: { quarter: number; date: string }[];
  lines: { order: string; date: string; customer: string; country: string; regime: string; rate: number; incl: number; excl: number; vat: number; cost: number }[];
}

/* Verbinding met de Laravel-API. In ontwikkeling stuurt Vite /api door naar Laravel. */
import type { AdminReturn, Catalog, Order, Product, TrackedOrder } from './types';

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
  track: (orderNo: string, email: string) => request<TrackedOrder>('POST', '/orders/track', { order_no: orderNo, email }),
  /** Retour aanvragen (bestelnummer + e-mailadres als controle). Geeft de bijgewerkte retourgegevens terug. */
  createReturn: (body: { order_no: string; email: string; items: { id: number; qty: number }[]; reason: string; comment: string }) =>
    request<{ ok: boolean; rma: string } & Pick<TrackedOrder, 'returnable' | 'returnDeadline' | 'items' | 'returns'>>('POST', '/returns', body),
  /** AVG: inzage of wissen aanvragen. Het antwoord is altijd hetzelfde; bij bekende gegevens volgt een e-mail met een tijdelijke link. */
  privacyRequest: (email: string, type: 'export' | 'erase') => request<{ ok: boolean }>('POST', '/privacy/request', { email, type }),
  /** Wissen bevestigen met de ondertekende query (email, expires, signature) uit de e-mail. */
  privacyErase: (signedQuery: string) => request<PrivacyEraseResult>('POST', '/privacy/erase?' + signedQuery.replace(/^\?/, ''), {}),
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
    returns: () => request<{ returns: AdminReturn[] }>('GET', '/admin/returns', undefined, true),
    privacyLookup: (email: string) => request<PrivacyLookup>('GET', '/admin/privacy/lookup?email=' + encodeURIComponent(email), undefined, true),
    privacyErase: (email: string) => request<PrivacyEraseResult>('POST', '/admin/privacy/erase', { email }, true),
    privacyRequests: () => request<{ requests: PrivacyLogRow[] }>('GET', '/admin/privacy/requests', undefined, true),
    /** Alle gegevens over een e-mailadres als JSON-bestand (art. 15/20 AVG). */
    privacyExport: async (email: string): Promise<Blob> => {
      const res = await fetch(BASE + '/api/admin/privacy/export?email=' + encodeURIComponent(email), { headers: { Authorization: 'Bearer ' + adminToken.get(), Accept: 'application/json' } });
      if (!res.ok) throw new ApiError('Export niet beschikbaar (' + res.status + ')', res.status, null);
      return res.blob();
    },
    returnAction: (id: string, action: 'approve' | 'reject' | 'receive' | 'refund', body: Record<string, unknown> = {}) =>
      request<{ return: AdminReturn }>('POST', '/admin/returns/' + encodeURIComponent(id) + '/' + action, body, true),
    /** Creditnota (PDF) van een retour. */
    returnCreditNote: async (id: string): Promise<Blob> => {
      const res = await fetch(BASE + '/api/admin/returns/' + encodeURIComponent(id) + '/credit-note', { headers: { Authorization: 'Bearer ' + adminToken.get(), Accept: 'application/pdf' } });
      if (!res.ok) throw new ApiError('Creditnota niet beschikbaar (' + res.status + ')', res.status, null);
      return res.blob();
    },
    /** Productfoto uploaden (JPG, PNG of WebP, max. 4 MB). Geeft het adres terug, bijv. /media/products/abc.jpg */
    upload: async (file: File): Promise<string> => {
      const body = new FormData();
      body.append('image', file);
      let res: Response;
      try { res = await fetch(BASE + '/api/admin/uploads', { method: 'POST', headers: { Accept: 'application/json', Authorization: 'Bearer ' + adminToken.get() }, body }); }
      catch { throw new ApiError('De server is niet bereikbaar.', 0, null); }
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new ApiError(String(data.message ?? 'Uploaden mislukt (' + res.status + ')'), res.status, data);
      return data.url as string;
    },
    /** Factuur of creditnota (PDF) van een bestelling. */
    document: async (id: string, kind: 'invoice' | 'credit-note'): Promise<Blob> => {
      const res = await fetch(BASE + '/api/admin/orders/' + encodeURIComponent(id) + '/' + kind, { headers: { Authorization: 'Bearer ' + adminToken.get(), Accept: 'application/pdf' } });
      if (!res.ok) throw new ApiError('Document niet beschikbaar (' + res.status + ')', res.status, null);
      return res.blob();
    },
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

export interface PrivacyLookup {
  email: string;
  summary: { orders: number; returns: number; newsletter: boolean };
  orders: { id: string; date: string | null; total: number; status: string; anonymized: boolean; retainedUntil: string }[];
  retentionYears: number;
}

export interface PrivacyLogRow {
  id: number; who: string; type: 'export' | 'erase'; source: string; status: string; requestedAt: string; completedAt: string | null;
  result: Record<string, unknown> | null;
}

export interface PrivacyEraseResult {
  newsletter_deleted: boolean;
  orders_minimized: number;
  orders_anonymized: number;
  orders_retained: { order: string; until: string }[];
}

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

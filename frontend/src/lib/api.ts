/* Verbinding met de Laravel-API. In ontwikkeling stuurt Vite /api door naar Laravel. */
import type { AccountOrder, AnalyticsReport, AuditEntry, StaffMe, TeamMember, Campaign, CampaignInput, AccountOrderDetail, AdminReturn, Catalog, Order, Product, TrackedOrder } from './types';

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

export const customerToken = {
  get: (): string => { try { return localStorage.getItem('rebazz.customer.token') ?? ''; } catch { return ''; } },
  set: (t: string) => { try { localStorage.setItem('rebazz.customer.token', t); } catch { /* */ } },
  clear: () => { try { localStorage.removeItem('rebazz.customer.token'); } catch { /* */ } }
};

/** admin = beheertoken (sessionStorage); customer = klanttoken (localStorage), alleen meegestuurd als de klant is ingelogd. */
async function request<T>(method: string, path: string, body?: unknown, admin = false, customer = false): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (admin && adminToken.get()) headers.Authorization = 'Bearer ' + adminToken.get();
  else if (customer && customerToken.get()) headers.Authorization = 'Bearer ' + customerToken.get();
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
    request<{ url: string; order_no: string }>('POST', '/checkout', body, false, true), // ingelogd: e-mail vooraf ingevuld en bestelling aan het account gekoppeld
  confirm: (sessionId: string) => request<{ order_no: string; paid: boolean; total: number; email: string; country: string }>('GET', '/orders/confirm?session_id=' + encodeURIComponent(sessionId)),
  track: (orderNo: string, email: string) => request<TrackedOrder>('POST', '/orders/track', { order_no: orderNo, email }),
  /** Retour aanvragen (bestelnummer + e-mailadres als controle). Geeft de bijgewerkte retourgegevens terug. */
  createReturn: (body: { order_no: string; email: string; items: { id: number; qty: number }[]; reason: string; comment: string }) =>
    request<{ ok: boolean; rma: string } & Pick<TrackedOrder, 'returnable' | 'returnDeadline' | 'items' | 'returns'>>('POST', '/returns', body),
  /** AVG: inzage of wissen aanvragen. Het antwoord is altijd hetzelfde; bij bekende gegevens volgt een e-mail met een tijdelijke link. */
  privacyRequest: (email: string, type: 'export' | 'erase') => request<{ ok: boolean }>('POST', '/privacy/request', { email, type }),
  /** Wissen bevestigen met de ondertekende query (email, expires, signature) uit de e-mail. */
  privacyErase: (signedQuery: string) => request<PrivacyEraseResult>('POST', '/privacy/erase?' + signedQuery.replace(/^\?/, ''), {}),
  account: {
    requestCode: (email: string, lang: string) => request<{ ok: boolean }>('POST', '/account/login', { email, lang }),
    verify: (email: string, code: string) => request<{ token: string; customer: { email: string; name: string } }>('POST', '/account/verify', { email, code }),
    logout: () => request<{ ok: boolean }>('POST', '/account/logout', {}, false, true),
    me: () => request<{ email: string; name: string; newsletter: boolean }>('GET', '/account/me', undefined, false, true),
    update: (patch: { name?: string; newsletter?: boolean; lang?: string }) => request<{ email: string; name: string; newsletter: boolean }>('PATCH', '/account/me', patch, false, true),
    orders: () => request<{ orders: AccountOrder[] }>('GET', '/account/orders', undefined, false, true),
    order: (no: string) => request<AccountOrderDetail>('GET', '/account/orders/' + encodeURIComponent(no), undefined, false, true),
    createReturn: (body: { order_no: string; items: { id: number; qty: number }[]; reason: string; comment: string }) =>
      request<{ ok: boolean; rma: string } & AccountOrderDetail>('POST', '/account/returns', body, false, true),
    wishlist: () => request<{ ids: string[] }>('GET', '/account/wishlist', undefined, false, true),
    wishAdd: (id: string) => request<{ ids: string[] }>('POST', '/account/wishlist/' + encodeURIComponent(id), {}, false, true),
    wishRemove: (id: string) => request<{ ids: string[] }>('DELETE', '/account/wishlist/' + encodeURIComponent(id), undefined, false, true),
    wishMerge: (ids: string[]) => request<{ ids: string[] }>('POST', '/account/wishlist/merge', { ids }, false, true),
    erase: () => request<PrivacyEraseResult & { account_deleted: boolean }>('POST', '/account/erase', {}, false, true),
    /** Eigen gegevens als JSON-bestand (AVG). */
    export: async (): Promise<Blob> => {
      const res = await fetch(BASE + '/api/account/export', { headers: { Authorization: 'Bearer ' + customerToken.get(), Accept: 'application/json' } });
      if (!res.ok) throw new ApiError('Export niet beschikbaar (' + res.status + ')', res.status, null);
      return res.blob();
    }
  },
  withdrawal: (body: { name: string; email: string; order_no: string; items: string; message: string }) => request<{ ok: boolean }>('POST', '/withdrawal', body),
  newsletter: (email: string, lang: string) => request<{ ok: boolean }>('POST', '/newsletter', { email, lang }),

  admin: {
    login: (password: string, email = '', code = '') => request<{ token: string }>('POST', '/admin/login', { password, ...(email ? { email } : {}), ...(code ? { code } : {}) }),
    me: () => request<StaffMe>('GET', '/admin/me', undefined, true),
    changePassword: (current_password: string, password: string) => request<{ ok: boolean }>('PUT', '/admin/me/password', { current_password, password }, true),
    twoFactorSetup: () => request<{ secret: string; uri: string }>('POST', '/admin/me/2fa/setup', {}, true),
    twoFactorEnable: (code: string) => request<StaffMe & { recoveryCodes: string[] }>('POST', '/admin/me/2fa/enable', { code }, true),
    twoFactorDisable: (password: string, code: string) => request<StaffMe>('POST', '/admin/me/2fa/disable', { password, code }, true),
    twoFactorRecovery: (password: string, code: string) => request<StaffMe & { recoveryCodes: string[] }>('POST', '/admin/me/2fa/recovery', { password, code }, true),
    team: () => request<{ members: TeamMember[]; roles: Record<string, string[]>; require2fa: boolean }>('GET', '/admin/team', undefined, true),
    createMember: (b: { name: string; email: string; role: string; password: string }) => request<TeamMember>('POST', '/admin/team', b, true),
    updateMember: (id: number, b: Partial<{ name: string; role: string; active: boolean; password: string }>) => request<TeamMember>('PUT', '/admin/team/' + id, b, true),
    deleteMember: (id: number) => request<{ ok: boolean }>('DELETE', '/admin/team/' + id, undefined, true),
    resetMember2fa: (id: number) => request<TeamMember>('POST', '/admin/team/' + id + '/reset-2fa', {}, true),
    audit: () => request<{ log: AuditEntry[] }>('GET', '/admin/team/audit', undefined, true),
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
    subscribers: () => request<{ subscribers: { email: string; lang: string; created_at: string }[] }>('GET', '/admin/subscribers', undefined, true),
    analytics: (days: number) => request<AnalyticsReport>('GET', '/admin/analytics?days=' + days, undefined, true),
    newsletters: () => request<{ campaigns: Campaign[]; subscribers: number; byLang: Record<string, number> }>('GET', '/admin/newsletters', undefined, true),
    saveNewsletter: (id: number | null, body: CampaignInput) => request<Campaign>(id ? 'PUT' : 'POST', '/admin/newsletters' + (id ? '/' + id : ''), body, true),
    deleteNewsletter: (id: number) => request<{ ok: boolean }>('DELETE', '/admin/newsletters/' + id, undefined, true),
    testNewsletter: (id: number, email: string) => request<{ ok: boolean; to: string }>('POST', '/admin/newsletters/' + id + '/test', email ? { email } : {}, true),
    sendNewsletter: (id: number) => request<Campaign>('POST', '/admin/newsletters/' + id + '/send', {}, true)
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

import { createContext, useContext, type Dispatch, type SetStateAction } from 'react';
import type { Order } from '../lib/types';

export interface Status { stripe: string; webhook: boolean; cj: boolean; cjAutoPay: boolean; mail: boolean }
export interface AdminData {
  status: Status;
  orders: Order[];
  setOrders: Dispatch<SetStateAction<Order[]>>;
  refresh: () => Promise<void>;
  replaceOrder: (o: Order) => void;
}

export const AdminCtx = createContext<AdminData | null>(null);
export function useAdmin(): AdminData {
  const v = useContext(AdminCtx);
  if (!v) throw new Error('useAdmin buiten AdminCtx');
  return v;
}

export const eur = (v: number) => new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' }).format(v || 0);
export const pct = (v: number) => (v * 100).toFixed(1).replace('.', ',') + '%';
export const fee = (total: number) => total * 0.015 + 0.25;
export const profit = (o: Order) => (o.refunded ? -o.cost : o.total - o.cost - fee(o.total));
export const fmtDate = (iso: string) => {
  const d = new Date(iso);
  return d.toLocaleDateString('nl-NL', { day: 'numeric', month: 'short' }) + ', ' + d.toLocaleTimeString('nl-NL', { hour: '2-digit', minute: '2-digit' });
};
export const ORDER_STEPS = ['Betaald', 'Besteld bij leverancier', 'Verzonden', 'Onderweg', 'Afgeleverd'];

export function download(filename: string, rows: (string | number)[][]): void {
  const csv = '﻿' + rows.map(r => r.map(v => '"' + String(v ?? '').replace(/"/g, '""') + '"').join(';')).join('\r\n');
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
  a.download = filename;
  a.click();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}

import client from './client';
import type { LedgerEntry, PaginatedResponse } from '@/types';

export async function listEntries(params?: {
  page?: number;
  per_page?: number;
  entry_type?: string;
  currency?: string;
}): Promise<PaginatedResponse<LedgerEntry>> {
  const { data } = await client.get<PaginatedResponse<LedgerEntry>>('/ledger', { params });
  return data;
}

export async function getEntriesForBill(billId: string): Promise<LedgerEntry[]> {
  const { data } = await client.get<LedgerEntry[]>(`/ledger/bill/${billId}`);
  return data;
}

export async function getEntriesForBooking(bookingId: string): Promise<LedgerEntry[]> {
  const { data } = await client.get<LedgerEntry[]>(`/ledger/booking/${bookingId}`);
  return data;
}

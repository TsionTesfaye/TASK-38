import client from './client';
import type { Bill, PaginatedResponse } from '@/types';

export async function listBills(params?: {
  page?: number;
  per_page?: number;
  status?: string;
  bill_type?: string;
  tenant_user_id?: string;
  booking_id?: string;
}): Promise<PaginatedResponse<Bill>> {
  const { data } = await client.get<PaginatedResponse<Bill>>('/bills', { params });
  return data;
}

export async function getBill(billId: string): Promise<Bill> {
  const { data } = await client.get<Bill>(`/bills/${billId}`);
  return data;
}

export async function createSupplementalBill(bill: {
  booking_id: string;
  amount: string;
  reason: string;
}): Promise<Bill> {
  const { data } = await client.post<Bill>('/bills', bill);
  return data;
}

export async function voidBill(billId: string): Promise<Bill> {
  const { data } = await client.post<Bill>(`/bills/${billId}/void`);
  return data;
}

export async function downloadPdf(billId: string): Promise<Blob> {
  const { data } = await client.get<Blob>(`/bills/${billId}/pdf`, {
    responseType: 'blob',
  });
  return data;
}

import client from './client';
import type { Refund, PaginatedResponse } from '@/types';

export async function issueRefund(refund: {
  bill_id: string;
  payment_id?: string;
  amount: string;
  reason: string;
}): Promise<Refund> {
  const { data } = await client.post<Refund>('/refunds', refund);
  return data;
}

export async function listRefunds(params?: {
  page?: number;
  per_page?: number;
  bill_id?: string;
  status?: string;
}): Promise<PaginatedResponse<Refund>> {
  const { data } = await client.get<PaginatedResponse<Refund>>('/refunds', { params });
  return data;
}

export async function getRefund(refundId: string): Promise<Refund> {
  const { data } = await client.get<Refund>(`/refunds/${refundId}`);
  return data;
}

import client from './client';
import type { Payment, PaginatedResponse } from '@/types';

export async function initiatePayment(payment: {
  bill_id: string;
  amount: string;
  currency: string;
}): Promise<Payment> {
  const { data } = await client.post<Payment>('/payments', payment);
  return data;
}

export async function listPayments(params?: {
  page?: number;
  per_page?: number;
  bill_id?: string;
  status?: string;
}): Promise<PaginatedResponse<Payment>> {
  const { data } = await client.get<PaginatedResponse<Payment>>('/payments', { params });
  return data;
}

export async function getPayment(paymentId: string): Promise<Payment> {
  const { data } = await client.get<Payment>(`/payments/${paymentId}`);
  return data;
}

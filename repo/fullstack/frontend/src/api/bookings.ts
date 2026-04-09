import client from './client';
import type { BookingHold, Booking, PaginatedResponse } from '@/types';

export async function createHold(hold: {
  inventory_item_id: string;
  request_key: string;
  held_units: number;
  start_at: string;
  end_at: string;
}): Promise<BookingHold> {
  const { data } = await client.post<BookingHold>('/holds', hold);
  return data;
}

export async function confirmHold(holdId: string, body?: { request_key: string }): Promise<Booking> {
  const { data } = await client.post<Booking>(`/holds/${holdId}/confirm`, body);
  return data;
}

export async function releaseHold(holdId: string): Promise<void> {
  await client.post(`/holds/${holdId}/release`);
}

export async function getHold(holdId: string): Promise<BookingHold> {
  const { data } = await client.get<BookingHold>(`/holds/${holdId}`);
  return data;
}

export async function listBookings(params?: {
  page?: number;
  per_page?: number;
  status?: string;
  inventory_item_id?: string;
  tenant_user_id?: string;
}): Promise<PaginatedResponse<Booking>> {
  const { data } = await client.get<PaginatedResponse<Booking>>('/bookings', { params });
  return data;
}

export async function getBooking(bookingId: string): Promise<Booking> {
  const { data } = await client.get<Booking>(`/bookings/${bookingId}`);
  return data;
}

export async function checkIn(bookingId: string): Promise<Booking> {
  const { data } = await client.post<Booking>(`/bookings/${bookingId}/check-in`);
  return data;
}

export async function complete(bookingId: string): Promise<Booking> {
  const { data } = await client.post<Booking>(`/bookings/${bookingId}/complete`);
  return data;
}

export async function cancel(bookingId: string): Promise<Booking> {
  const { data } = await client.post<Booking>(`/bookings/${bookingId}/cancel`);
  return data;
}

export async function markNoShow(bookingId: string): Promise<Booking> {
  const { data } = await client.post<Booking>(`/bookings/${bookingId}/no-show`);
  return data;
}

export async function reschedule(
  bookingId: string,
  schedule: { new_hold_id: string },
): Promise<Booking> {
  const { data } = await client.post<Booking>(
    `/bookings/${bookingId}/reschedule`,
    schedule,
  );
  return data;
}

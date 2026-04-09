import client from './client';
import type { InventoryItem, InventoryPricing, PaginatedResponse } from '@/types';

export async function listItems(params?: {
  page?: number;
  per_page?: number;
  asset_type?: string;
  location_name?: string;
  is_active?: boolean;
}): Promise<PaginatedResponse<InventoryItem>> {
  const { data } = await client.get<PaginatedResponse<InventoryItem>>('/inventory', { params });
  return data;
}

export async function getItem(itemId: string): Promise<InventoryItem> {
  const { data } = await client.get<InventoryItem>(`/inventory/${itemId}`);
  return data;
}

export async function createItem(item: {
  asset_code: string;
  name: string;
  asset_type: string;
  location_name: string;
  capacity_mode: string;
  total_capacity: number;
  timezone: string;
}): Promise<InventoryItem> {
  const { data } = await client.post<InventoryItem>('/inventory', item);
  return data;
}

export async function updateItem(
  itemId: string,
  updates: Partial<{
    name: string;
    asset_type: string;
    location_name: string;
    total_capacity: number;
    timezone: string;
    is_active: boolean;
  }>,
): Promise<InventoryItem> {
  const { data } = await client.put<InventoryItem>(`/inventory/${itemId}`, updates);
  return data;
}

export async function deactivateItem(itemId: string): Promise<void> {
  await client.post(`/inventory/${itemId}/deactivate`);
}

export interface AvailabilityResult {
  available_units: number;
  requested_units: number;
  total_capacity: number;
  can_reserve: boolean;
}

export async function checkAvailability(
  itemId: string,
  params: { start_at: string; end_at: string; units?: number },
): Promise<AvailabilityResult> {
  const { data } = await client.get<AvailabilityResult>(
    `/inventory/${itemId}/availability`,
    { params },
  );
  return data;
}

export async function getCalendar(
  itemId: string,
  params: { from: string; to: string },
): Promise<Array<{ date: string; available_units: number; total_capacity: number }>> {
  const { data } = await client.get<
    Array<{ date: string; available_units: number; total_capacity: number }>
  >(`/inventory/${itemId}/calendar`, { params });
  return data;
}

export async function listPricing(
  itemId: string,
): Promise<InventoryPricing[]> {
  const { data } = await client.get<InventoryPricing[]>(`/inventory/${itemId}/pricing`);
  return data;
}

export async function createPricing(
  itemId: string,
  pricing: {
    rate_type: string;
    amount: string;
    currency: string;
    effective_from: string;
    effective_to?: string | null;
  },
): Promise<InventoryPricing> {
  const { data } = await client.post<InventoryPricing>(
    `/inventory/${itemId}/pricing`,
    pricing,
  );
  return data;
}

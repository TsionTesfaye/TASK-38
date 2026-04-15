import client from './client';
import type { User, Settings, AuditLog, PaginatedResponse } from '@/types';

export async function listUsers(params?: {
  page?: number;
  per_page?: number;
  role?: string;
  is_active?: boolean;
}): Promise<PaginatedResponse<User>> {
  const { data } = await client.get<PaginatedResponse<User>>('/users', { params });
  return data;
}

export async function createUser(user: {
  username: string;
  password: string;
  display_name: string;
  role: string;
}): Promise<User> {
  const { data } = await client.post<User>('/users', user);
  return data;
}

export async function getUser(userId: string): Promise<User> {
  const { data } = await client.get<User>(`/users/${userId}`);
  return data;
}

export async function updateUser(
  userId: string,
  updates: Partial<{
    display_name: string;
    role: string;
    is_active: boolean;
  }>,
): Promise<User> {
  const { data } = await client.put<User>(`/users/${userId}`, updates);
  return data;
}

export async function freezeUser(userId: string): Promise<User> {
  const { data } = await client.post<User>(`/users/${userId}/freeze`);
  return data;
}

export async function unfreezeUser(userId: string): Promise<User> {
  const { data } = await client.post<User>(`/users/${userId}/unfreeze`);
  return data;
}

export async function getSettings(): Promise<Settings> {
  const { data } = await client.get<Settings>('/settings');
  return data;
}

export async function updateSettings(
  updates: Partial<Settings>,
): Promise<Settings> {
  const { data } = await client.put<Settings>('/settings', updates);
  return data;
}

export async function listAuditLogs(params?: {
  page?: number;
  per_page?: number;
  actor_user_id?: string;
  action_code?: string;
  object_type?: string;
  object_id?: string;
}): Promise<PaginatedResponse<AuditLog>> {
  const { data } = await client.get<PaginatedResponse<AuditLog>>('/audit-logs', {
    params,
  });
  return data;
}

export async function getMetrics(): Promise<Record<string, unknown>> {
  const { data } = await client.get<Record<string, unknown>>('/metrics');
  return data;
}

export async function createBackup(): Promise<{
  filename: string;
  created_at: string;
  tables: string[];
}> {
  const { data } = await client.post<{
    filename: string;
    created_at: string;
    tables: string[];
  }>('/backups');
  return data;
}

export async function listBackups(params?: {
  page?: number;
  per_page?: number;
}): Promise<Array<{ filename: string; size_bytes: number; modified_at: string }>> {
  const { data } = await client.get<
    | Array<{ filename: string; size_bytes: number; modified_at: string }>
    | { data: Array<{ filename: string; size_bytes: number; modified_at: string }>; meta: unknown }
  >('/backups', { params });
  // The backup endpoint returns a paginated envelope {data:[...], meta:{...}}
  // which the axios interceptor does NOT unwrap (it only unwraps non-paginated).
  return Array.isArray(data) ? data : (data as any).data ?? [];
}

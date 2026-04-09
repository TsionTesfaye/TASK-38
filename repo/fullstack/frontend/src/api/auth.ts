import client from './client';
import type { AuthTokenResponse, Organization, User } from '@/types';

export async function login(
  username: string,
  password: string,
  deviceLabel: string,
  clientDeviceId: string,
): Promise<AuthTokenResponse> {
  const { data } = await client.post<AuthTokenResponse>('/auth/login', {
    username,
    password,
    device_label: deviceLabel,
    client_device_id: clientDeviceId,
  });
  return data;
}

export async function refresh(
  refreshToken: string,
): Promise<{ access_token: string; expires_in: number }> {
  const { data } = await client.post<{ access_token: string; expires_in: number }>(
    '/auth/refresh',
    { refresh_token: refreshToken },
  );
  return data;
}

export async function logout(sessionId: string | null): Promise<void> {
  await client.post('/auth/logout', { session_id: sessionId });
}

export async function changePassword(
  currentPassword: string,
  newPassword: string,
): Promise<void> {
  await client.post('/auth/change-password', {
    current_password: currentPassword,
    new_password: newPassword,
  });
}

export async function bootstrap(data: {
  organization_name: string;
  organization_code: string;
  admin_username: string;
  admin_password: string;
  admin_display_name: string;
  default_currency: string;
}): Promise<{ organization: Organization; user: User }> {
  const { data: result } = await client.post<{ organization: Organization; user: User }>(
    '/bootstrap',
    data,
  );
  return result;
}

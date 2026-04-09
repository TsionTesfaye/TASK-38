import client from './client';
import type { Notification, NotificationPreference, PaginatedResponse } from '@/types';

export async function listNotifications(params?: {
  page?: number;
  per_page?: number;
  status?: string;
}): Promise<PaginatedResponse<Notification>> {
  const { data } = await client.get<PaginatedResponse<Notification>>('/notifications', {
    params,
  });
  return data;
}

export async function markRead(notificationId: string): Promise<Notification> {
  const { data } = await client.post<Notification>(`/notifications/${notificationId}/read`);
  return data;
}

export async function getPreferences(): Promise<NotificationPreference[]> {
  const { data } = await client.get<NotificationPreference[]>('/notifications/preferences');
  return data;
}

export async function updatePreference(
  eventCode: string,
  updates: Partial<{
    enabled: boolean;
    dnd_start: string;
    dnd_end: string;
  }>,
): Promise<NotificationPreference> {
  const { data } = await client.put<NotificationPreference>(
    `/notifications/preferences/${eventCode}`,
    updates,
  );
  return data;
}

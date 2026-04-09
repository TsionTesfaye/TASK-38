import { useEffect, useRef } from 'react';
import { useAuthStore } from '@/state/authStore';
import { useNotificationStore } from '@/state/notificationStore';
import client from '@/api/client';
import { NOTIFICATION_POLL_INTERVAL_MS } from '@/utils/constants';
import type { Notification, PaginatedResponse } from '@/types';

export function useNotifications() {
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated);
  const { notifications, unreadCount, setNotifications, markAsRead, markAllAsRead } =
    useNotificationStore();
  const intervalRef = useRef<ReturnType<typeof setInterval> | null>(null);

  useEffect(() => {
    if (!isAuthenticated) return;

    const fetchNotifications = async () => {
      try {
        const { data } = await client.get<PaginatedResponse<Notification>>(
          '/notifications',
          { params: { per_page: 50 } },
        );
        setNotifications(data.data);
      } catch {
        // silently ignore polling errors
      }
    };

    fetchNotifications();
    intervalRef.current = setInterval(fetchNotifications, NOTIFICATION_POLL_INTERVAL_MS);

    return () => {
      if (intervalRef.current) {
        clearInterval(intervalRef.current);
        intervalRef.current = null;
      }
    };
  }, [isAuthenticated, setNotifications]);

  return { notifications, unreadCount, markAsRead, markAllAsRead };
}

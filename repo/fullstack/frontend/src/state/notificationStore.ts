import { create } from 'zustand';
import type { Notification } from '@/types';

interface NotificationState {
  notifications: Notification[];
  unreadCount: number;
  setNotifications: (notifications: Notification[]) => void;
  markAsRead: (id: string) => void;
  markAllAsRead: () => void;
}

export const useNotificationStore = create<NotificationState>((set) => ({
  notifications: [],
  unreadCount: 0,

  setNotifications: (notifications) =>
    set({
      notifications,
      unreadCount: notifications.filter((n) => !n.read_at).length,
    }),

  markAsRead: (id) =>
    set((state) => {
      const now = new Date().toISOString();
      const updated = state.notifications.map((n) =>
        n.id === id ? { ...n, read_at: now } : n,
      );
      return {
        notifications: updated,
        unreadCount: updated.filter((n) => !n.read_at).length,
      };
    }),

  markAllAsRead: () =>
    set((state) => {
      const now = new Date().toISOString();
      return {
        notifications: state.notifications.map((n) => ({
          ...n,
          read_at: n.read_at ?? now,
        })),
        unreadCount: 0,
      };
    }),
}));

import { describe, it, expect, beforeEach } from 'vitest';
import { useNotificationStore } from '../notificationStore';
import { useAuthStore } from '../authStore';
import type { Notification, User } from '../../types';

describe('notificationStore', () => {
  beforeEach(() => {
    useNotificationStore.setState({ notifications: [], unreadCount: 0 });
  });

  const makeNotif = (id: string, read = false): Notification => ({
    id,
    organization_id: 'org-1',
    user_id: 'u-1',
    event_code: 'e',
    title: 't',
    body: 'b',
    status: read ? 'read' : 'delivered',
    scheduled_for: '2026-01-01T00:00:00Z',
    delivered_at: '2026-01-01T00:00:00Z',
    read_at: read ? '2026-01-01T00:00:00Z' : null,
    created_at: '2026-01-01T00:00:00Z',
  });

  it('setNotifications updates unreadCount correctly', () => {
    const notifs = [makeNotif('1'), makeNotif('2', true), makeNotif('3')];
    useNotificationStore.getState().setNotifications(notifs);
    expect(useNotificationStore.getState().notifications).toHaveLength(3);
    expect(useNotificationStore.getState().unreadCount).toBe(2);
  });

  it('markAsRead marks specific notification', () => {
    const notifs = [makeNotif('1'), makeNotif('2')];
    useNotificationStore.getState().setNotifications(notifs);
    useNotificationStore.getState().markAsRead('1');
    const state = useNotificationStore.getState();
    expect(state.notifications[0].read_at).not.toBeNull();
    expect(state.notifications[1].read_at).toBeNull();
    expect(state.unreadCount).toBe(1);
  });

  it('markAllAsRead marks all and zeros unreadCount', () => {
    const notifs = [makeNotif('1'), makeNotif('2'), makeNotif('3')];
    useNotificationStore.getState().setNotifications(notifs);
    useNotificationStore.getState().markAllAsRead();
    const state = useNotificationStore.getState();
    expect(state.unreadCount).toBe(0);
    expect(state.notifications.every(n => n.read_at !== null)).toBe(true);
  });

  it('markAsRead on unknown id is a no-op', () => {
    useNotificationStore.getState().setNotifications([makeNotif('1')]);
    useNotificationStore.getState().markAsRead('unknown');
    expect(useNotificationStore.getState().unreadCount).toBe(1);
  });
});

describe('authStore', () => {
  beforeEach(() => {
    localStorage.clear();
    useAuthStore.setState({
      user: null, accessToken: null, refreshToken: null,
      sessionId: null, isAuthenticated: false,
    });
  });

  const mockUser: User = {
    id: 'u-1',
    username: 'test',
    display_name: 'Test',
    role: 'administrator' as any,
    is_active: true,
    is_frozen: false,
    organization_id: 'org-1',
    created_at: '2026-01-01T00:00:00Z',
  };

  it('setAuth persists to localStorage and updates state', () => {
    useAuthStore.getState().setAuth({
      access_token: 'at',
      refresh_token: 'rt',
      expires_in: 900,
      session_id: 's-1',
      user: mockUser,
    });
    const state = useAuthStore.getState();
    expect(state.user).toEqual(mockUser);
    expect(state.accessToken).toBe('at');
    expect(state.refreshToken).toBe('rt');
    expect(state.sessionId).toBe('s-1');
    expect(state.isAuthenticated).toBe(true);

    expect(localStorage.getItem('access_token')).toBe('at');
    expect(localStorage.getItem('refresh_token')).toBe('rt');
    expect(localStorage.getItem('session_id')).toBe('s-1');
  });

  it('clearAuth removes localStorage and resets state', () => {
    useAuthStore.getState().setAuth({
      access_token: 'at', refresh_token: 'rt', expires_in: 900,
      session_id: 's-1', user: mockUser,
    });
    useAuthStore.getState().clearAuth();

    const state = useAuthStore.getState();
    expect(state.user).toBeNull();
    expect(state.accessToken).toBeNull();
    expect(state.isAuthenticated).toBe(false);
    expect(localStorage.getItem('access_token')).toBeNull();
  });

  it('updateUser updates user without touching tokens', () => {
    useAuthStore.getState().setAuth({
      access_token: 'at', refresh_token: 'rt', expires_in: 900,
      session_id: 's-1', user: mockUser,
    });
    const updated = { ...mockUser, display_name: 'New Name' };
    useAuthStore.getState().updateUser(updated);
    expect(useAuthStore.getState().user?.display_name).toBe('New Name');
    expect(useAuthStore.getState().accessToken).toBe('at');
  });
});

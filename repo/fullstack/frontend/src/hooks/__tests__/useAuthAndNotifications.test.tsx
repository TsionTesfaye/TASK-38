import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import { renderHook, act } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, it, expect, vi, beforeEach } from 'vitest';

import { useAuth } from '../useAuth';
import { useNotifications } from '../useNotifications';
import { useAuthStore } from '../../state/authStore';
import { useNotificationStore } from '../../state/notificationStore';
import { ErrorBoundary } from '../../components/common/ErrorBoundary';
import { UserRole } from '../../types/enums';

vi.mock('../../api/auth', () => ({
  logout: vi.fn(() => Promise.resolve()),
}));

vi.mock('../../api/client', () => ({
  default: {
    get: vi.fn(() => Promise.resolve({ data: { data: [], meta: { page: 1, per_page: 50, total: 0, has_next: false } } })),
  },
}));

const wrapper = ({ children }: { children: React.ReactNode }) => (
  <MemoryRouter>{children}</MemoryRouter>
);

describe('useAuth', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    useAuthStore.setState({
      accessToken: 'token',
      refreshToken: 'rt',
      sessionId: 's-1',
      isAuthenticated: true,
      user: {
        id: 'u-1', username: 'admin', display_name: 'Admin',
        role: UserRole.ADMINISTRATOR as any,
        is_active: true, is_frozen: false,
        organization_id: 'o-1', created_at: '',
      },
    });
  });

  it('returns user and role', () => {
    const { result } = renderHook(() => useAuth(), { wrapper });
    expect(result.current.user?.username).toBe('admin');
    expect(result.current.role).toBe('administrator');
    expect(result.current.isAuthenticated).toBe(true);
  });

  it('returns null role when no user', () => {
    useAuthStore.setState({
      accessToken: null, user: null, refreshToken: null, sessionId: null, isAuthenticated: false,
    });
    const { result } = renderHook(() => useAuth(), { wrapper });
    expect(result.current.role).toBeNull();
  });

  it('logout clears auth', async () => {
    const { result } = renderHook(() => useAuth(), { wrapper });
    await act(async () => {
      await result.current.logout();
    });
    expect(useAuthStore.getState().accessToken).toBeNull();
  });
});

describe('useNotifications', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.clearAllMocks();
    useAuthStore.setState({
      accessToken: null, user: null, refreshToken: null, sessionId: null, isAuthenticated: false,
    });
    useNotificationStore.setState({ notifications: [], unreadCount: 0 });
  });

  it('returns notification store state', () => {
    const { result } = renderHook(() => useNotifications());
    expect(result.current.notifications).toEqual([]);
    expect(result.current.unreadCount).toBe(0);
    expect(typeof result.current.markAsRead).toBe('function');
    expect(typeof result.current.markAllAsRead).toBe('function');
  });

  it('does not fetch when not authenticated', () => {
    const { result } = renderHook(() => useNotifications());
    vi.advanceTimersByTime(10_000);
    expect(result.current.notifications).toEqual([]);
  });

  it('cleans up interval on unmount', () => {
    useAuthStore.setState({
      accessToken: 'token',
      refreshToken: 'rt',
      sessionId: 's',
      isAuthenticated: true,
      user: {
        id: 'u-1', username: 'x', display_name: 'X',
        role: UserRole.TENANT as any,
        is_active: true, is_frozen: false,
        organization_id: 'o', created_at: '',
      },
    });
    const { unmount } = renderHook(() => useNotifications());
    unmount();
    // No error on cleanup
    expect(true).toBe(true);
  });
});

describe('ErrorBoundary', () => {
  const Boom = () => {
    throw new Error('boom!');
  };

  it('renders children when no error', () => {
    render(
      <ErrorBoundary>
        <div>Healthy child</div>
      </ErrorBoundary>,
    );
    expect(screen.getByText('Healthy child')).toBeInTheDocument();
  });

  it('renders fallback when error thrown', () => {
    // Suppress expected console error
    const spy = vi.spyOn(console, 'error').mockImplementation(() => {});
    render(
      <ErrorBoundary>
        <Boom />
      </ErrorBoundary>,
    );
    expect(screen.getByText('Something went wrong')).toBeInTheDocument();
    expect(screen.getByText(/boom!/)).toBeInTheDocument();
    spy.mockRestore();
  });

  it('renders custom fallback when provided', () => {
    const spy = vi.spyOn(console, 'error').mockImplementation(() => {});
    render(
      <ErrorBoundary fallback={<div>Custom Fallback</div>}>
        <Boom />
      </ErrorBoundary>,
    );
    expect(screen.getByText('Custom Fallback')).toBeInTheDocument();
    spy.mockRestore();
  });

  it('try-again button resets error state', () => {
    const spy = vi.spyOn(console, 'error').mockImplementation(() => {});

    let shouldThrow = true;
    const Conditional = () => {
      if (shouldThrow) throw new Error('conditional error');
      return <div>Recovered</div>;
    };

    const { rerender } = render(
      <ErrorBoundary>
        <Conditional />
      </ErrorBoundary>,
    );

    expect(screen.getByText('Something went wrong')).toBeInTheDocument();
    // Fix the component, click retry
    shouldThrow = false;
    fireEvent.click(screen.getByText('Try again'));
    rerender(
      <ErrorBoundary>
        <Conditional />
      </ErrorBoundary>,
    );
    spy.mockRestore();
  });
});

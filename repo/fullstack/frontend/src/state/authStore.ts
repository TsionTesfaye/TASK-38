import { create } from 'zustand';
import type { User, AuthTokenResponse } from '@/types';
import type { UserRole } from '@/types/enums';

interface AuthState {
  user: User | null;
  accessToken: string | null;
  refreshToken: string | null;
  sessionId: string | null;
  isAuthenticated: boolean;
  setAuth: (response: AuthTokenResponse) => void;
  clearAuth: () => void;
  updateUser: (user: User) => void;
}

export const useAuthStore = create<AuthState>((set) => {
  const storedUser = localStorage.getItem('user');
  const storedAccessToken = localStorage.getItem('access_token');
  const storedRefreshToken = localStorage.getItem('refresh_token');
  const storedSessionId = localStorage.getItem('session_id');

  return {
    user: storedUser ? (JSON.parse(storedUser) as User) : null,
    accessToken: storedAccessToken,
    refreshToken: storedRefreshToken,
    sessionId: storedSessionId,
    isAuthenticated: !!storedAccessToken,

    setAuth: (response: AuthTokenResponse) => {
      localStorage.setItem('user', JSON.stringify(response.user));
      localStorage.setItem('access_token', response.access_token);
      localStorage.setItem('refresh_token', response.refresh_token);
      localStorage.setItem('session_id', response.session_id);
      set({
        user: response.user,
        accessToken: response.access_token,
        refreshToken: response.refresh_token,
        sessionId: response.session_id,
        isAuthenticated: true,
      });
    },

    clearAuth: () => {
      localStorage.removeItem('user');
      localStorage.removeItem('access_token');
      localStorage.removeItem('refresh_token');
      localStorage.removeItem('session_id');
      set({
        user: null,
        accessToken: null,
        refreshToken: null,
        sessionId: null,
        isAuthenticated: false,
      });
    },

    updateUser: (user: User) => {
      localStorage.setItem('user', JSON.stringify(user));
      set({ user });
    },
  };
});

export function getUserRole(): UserRole | null {
  const state = useAuthStore.getState();
  return state.user?.role ?? null;
}

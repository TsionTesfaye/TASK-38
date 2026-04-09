import { useAuthStore } from '@/state/authStore';
import { logout as apiLogout } from '@/api/auth';
import { useNavigate } from 'react-router-dom';
import { useCallback } from 'react';

export function useAuth() {
  const { user, isAuthenticated, sessionId, setAuth, clearAuth } = useAuthStore();
  const navigate = useNavigate();

  const logout = useCallback(async () => {
    try {
      await apiLogout(sessionId);
    } catch {
      // proceed even if API fails
    }
    clearAuth();
    navigate('/login');
  }, [sessionId, clearAuth, navigate]);

  return {
    user,
    isAuthenticated,
    role: user?.role ?? null,
    setAuth,
    clearAuth,
    logout,
  };
}

import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { describe, it, expect, beforeEach } from 'vitest';
import { ProtectedRoute } from '../ProtectedRoute';
import { useAuthStore } from '../../state/authStore';
import { UserRole } from '../../types/enums';

function setAuth(role: string) {
  useAuthStore.setState({
    accessToken: 'test-token',
    user: {
      id: 'user-1',
      username: 'test',
      display_name: 'Test',
      role: role as any,
      is_active: true,
      is_frozen: false,
      organization_id: 'org-1',
      created_at: '',
    },
    refreshToken: 'rt',
    sessionId: 's1',
    isAuthenticated: true,
  });
}

function clearAuth() {
  useAuthStore.setState({
    accessToken: null,
    user: null,
    refreshToken: null,
    sessionId: null,
    isAuthenticated: false,
  });
}

function renderProtected(allowedRoles?: string[], currentPath = '/protected') {
  return render(
    <MemoryRouter initialEntries={[currentPath]}>
      <Routes>
        <Route path="/login" element={<div>Login Page</div>} />
        <Route
          path="/protected"
          element={
            <ProtectedRoute allowedRoles={allowedRoles as any}>
              <div>Protected Content</div>
            </ProtectedRoute>
          }
        />
      </Routes>
    </MemoryRouter>,
  );
}

describe('ProtectedRoute', () => {
  beforeEach(() => {
    localStorage.clear();
    clearAuth();
  });

  // ═══════════════════════════════════════════════════════════════
  // 1. Unauthenticated users redirected to login
  // ═══════════════════════════════════════════════════════════════

  it('redirects unauthenticated users to /login', () => {
    renderProtected();

    expect(screen.getByText('Login Page')).toBeInTheDocument();
    expect(screen.queryByText('Protected Content')).not.toBeInTheDocument();
  });

  // ═══════════════════════════════════════════════════════════════
  // 2. Authenticated user with correct role sees content
  // ═══════════════════════════════════════════════════════════════

  it('shows content for authenticated user with allowed role', () => {
    setAuth(UserRole.ADMINISTRATOR);
    renderProtected([UserRole.ADMINISTRATOR]);

    expect(screen.getByText('Protected Content')).toBeInTheDocument();
  });

  it('shows content when no role restriction is set', () => {
    setAuth(UserRole.TENANT);
    renderProtected(); // no allowedRoles → any authenticated user

    expect(screen.getByText('Protected Content')).toBeInTheDocument();
  });

  // ═══════════════════════════════════════════════════════════════
  // 3. Authenticated user with wrong role sees permission denied
  // ═══════════════════════════════════════════════════════════════

  it('shows PermissionDenied for unauthorized role', () => {
    setAuth(UserRole.TENANT);
    renderProtected([UserRole.ADMINISTRATOR]);

    expect(screen.getByText('Permission Denied')).toBeInTheDocument();
    expect(screen.queryByText('Protected Content')).not.toBeInTheDocument();
  });

  it('shows PermissionDenied for finance clerk on admin route', () => {
    setAuth(UserRole.FINANCE_CLERK);
    renderProtected([UserRole.ADMINISTRATOR]);

    expect(screen.getByText('Permission Denied')).toBeInTheDocument();
  });

  // ═══════════════════════════════════════════════════════════════
  // 4. Multiple allowed roles
  // ═══════════════════════════════════════════════════════════════

  it('allows property manager on manager+admin routes', () => {
    setAuth(UserRole.PROPERTY_MANAGER);
    renderProtected([UserRole.PROPERTY_MANAGER, UserRole.ADMINISTRATOR]);

    expect(screen.getByText('Protected Content')).toBeInTheDocument();
  });

  it('allows administrator on manager+admin routes', () => {
    setAuth(UserRole.ADMINISTRATOR);
    renderProtected([UserRole.PROPERTY_MANAGER, UserRole.ADMINISTRATOR]);

    expect(screen.getByText('Protected Content')).toBeInTheDocument();
  });

  it('denies tenant on manager+admin routes', () => {
    setAuth(UserRole.TENANT);
    renderProtected([UserRole.PROPERTY_MANAGER, UserRole.ADMINISTRATOR]);

    expect(screen.getByText('Permission Denied')).toBeInTheDocument();
  });

  // ═══════════════════════════════════════════════════════════════
  // 5. Each role gets correct access on their section
  // ═══════════════════════════════════════════════════════════════

  it('tenant can access tenant routes', () => {
    setAuth(UserRole.TENANT);
    renderProtected([UserRole.TENANT]);

    expect(screen.getByText('Protected Content')).toBeInTheDocument();
  });

  it('finance clerk can access finance routes', () => {
    setAuth(UserRole.FINANCE_CLERK);
    renderProtected([UserRole.FINANCE_CLERK, UserRole.ADMINISTRATOR]);

    expect(screen.getByText('Protected Content')).toBeInTheDocument();
  });

  it('property manager cannot access admin-only routes', () => {
    setAuth(UserRole.PROPERTY_MANAGER);
    renderProtected([UserRole.ADMINISTRATOR]);

    expect(screen.getByText('Permission Denied')).toBeInTheDocument();
  });
});

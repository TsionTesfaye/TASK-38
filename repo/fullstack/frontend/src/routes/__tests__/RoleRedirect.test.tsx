import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { describe, it, expect, beforeEach } from 'vitest';
import { RoleRedirect } from '../RoleRedirect';
import { useAuthStore } from '../../state/authStore';
import { UserRole } from '../../types/enums';

function setUser(role: string | null) {
  useAuthStore.setState({
    accessToken: role ? 'token' : null,
    refreshToken: role ? 'rt' : null,
    sessionId: role ? 's1' : null,
    isAuthenticated: !!role,
    user: role
      ? {
          id: 'u',
          username: 'test',
          display_name: 'Test',
          role: role as any,
          is_active: true,
          is_frozen: false,
          organization_id: 'o',
          created_at: '',
        }
      : null,
  });
}

function renderAtRoot() {
  return render(
    <MemoryRouter initialEntries={['/']}>
      <Routes>
        <Route path="/" element={<RoleRedirect />} />
        <Route path="/login" element={<div>Login</div>} />
        <Route path="/tenant/bookings" element={<div>Tenant</div>} />
        <Route path="/manager/inventory" element={<div>Manager</div>} />
        <Route path="/finance/bills" element={<div>Finance</div>} />
        <Route path="/admin/users" element={<div>Admin</div>} />
      </Routes>
    </MemoryRouter>,
  );
}

describe('RoleRedirect', () => {
  beforeEach(() => {
    useAuthStore.setState({ user: null, accessToken: null, refreshToken: null, sessionId: null, isAuthenticated: false });
  });

  it('redirects to /login when no user', () => {
    setUser(null);
    renderAtRoot();
    expect(screen.getByText('Login')).toBeInTheDocument();
  });

  it('redirects tenant to /tenant/bookings', () => {
    setUser(UserRole.TENANT);
    renderAtRoot();
    expect(screen.getByText('Tenant')).toBeInTheDocument();
  });

  it('redirects property_manager to /manager/inventory', () => {
    setUser(UserRole.PROPERTY_MANAGER);
    renderAtRoot();
    expect(screen.getByText('Manager')).toBeInTheDocument();
  });

  it('redirects finance_clerk to /finance/bills', () => {
    setUser(UserRole.FINANCE_CLERK);
    renderAtRoot();
    expect(screen.getByText('Finance')).toBeInTheDocument();
  });

  it('redirects administrator to /admin/users', () => {
    setUser(UserRole.ADMINISTRATOR);
    renderAtRoot();
    expect(screen.getByText('Admin')).toBeInTheDocument();
  });
});

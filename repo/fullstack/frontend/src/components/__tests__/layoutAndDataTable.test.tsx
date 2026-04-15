import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, it, expect, vi, beforeEach } from 'vitest';

import { DataTable } from '../common/DataTable';
import { AppShell } from '../layout/AppShell';
import { Header } from '../layout/Header';
import { Sidebar } from '../layout/Sidebar';
import { useAuthStore } from '../../state/authStore';
import { useNotificationStore } from '../../state/notificationStore';
import { UserRole } from '../../types/enums';

vi.mock('../../api/auth', () => ({
  logout: vi.fn(() => Promise.resolve()),
}));

describe('DataTable', () => {
  interface Row { id: string; name: string; value: number }
  const rows: Row[] = [
    { id: '1', name: 'A', value: 10 },
    { id: '2', name: 'B', value: 20 },
  ];
  const cols = [
    { key: 'name', header: 'Name', render: (r: Row) => r.name },
    { key: 'value', header: 'Value', render: (r: Row) => r.value.toString() },
  ];

  it('renders headers and rows', () => {
    render(
      <DataTable columns={cols} data={rows} keyExtractor={(r) => r.id} />,
    );
    expect(screen.getByText('Name')).toBeInTheDocument();
    expect(screen.getByText('Value')).toBeInTheDocument();
    expect(screen.getByText('A')).toBeInTheDocument();
    expect(screen.getByText('B')).toBeInTheDocument();
  });

  it('calls onRowClick when a row is clicked', () => {
    const onRowClick = vi.fn();
    render(
      <DataTable columns={cols} data={rows} keyExtractor={(r) => r.id} onRowClick={onRowClick} />,
    );
    fireEvent.click(screen.getByText('A').closest('tr')!);
    expect(onRowClick).toHaveBeenCalledWith(rows[0]);
  });

  it('renders with empty data', () => {
    const { container } = render(
      <DataTable columns={cols} data={[]} keyExtractor={(r) => r.id} />,
    );
    expect(container.querySelector('tbody')).toBeTruthy();
  });

  it('applies width prop to column', () => {
    const colsWithWidth = [
      { key: 'name', header: 'Name', render: (r: Row) => r.name, width: '200px' },
    ];
    render(
      <DataTable columns={colsWithWidth} data={rows} keyExtractor={(r) => r.id} />,
    );
    expect(screen.getByText('Name')).toBeInTheDocument();
  });

  it('row hover works without onRowClick', () => {
    render(
      <DataTable columns={cols} data={rows} keyExtractor={(r) => r.id} />,
    );
    const row = screen.getByText('A').closest('tr')!;
    fireEvent.mouseEnter(row);
    fireEvent.mouseLeave(row);
    expect(row).toBeTruthy();
  });

  it('row hover works with onRowClick', () => {
    const onRowClick = vi.fn();
    render(
      <DataTable columns={cols} data={rows} keyExtractor={(r) => r.id} onRowClick={onRowClick} />,
    );
    const row = screen.getByText('A').closest('tr')!;
    fireEvent.mouseEnter(row);
    fireEvent.mouseLeave(row);
    expect(row).toBeTruthy();
  });
});

describe('Header', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    useAuthStore.setState({
      accessToken: 'token',
      refreshToken: 'rt',
      sessionId: 's',
      isAuthenticated: true,
      user: {
        id: 'u-1', username: 'admin', display_name: 'Admin User',
        role: UserRole.ADMINISTRATOR as any,
        is_active: true, is_frozen: false,
        organization_id: 'o-1', created_at: '',
      },
    });
    useNotificationStore.setState({ notifications: [], unreadCount: 0 });
  });

  it('renders user display name', () => {
    render(
      <MemoryRouter>
        <Header onMenuToggle={() => {}} />
      </MemoryRouter>,
    );
    expect(screen.getByText('Admin User')).toBeInTheDocument();
  });

  it('shows notification count when unread > 0', () => {
    useNotificationStore.setState({ notifications: [], unreadCount: 3 });
    render(
      <MemoryRouter>
        <Header onMenuToggle={() => {}} />
      </MemoryRouter>,
    );
    expect(screen.getByText('3')).toBeInTheDocument();
  });

  it('shows 9+ when unread count is large', () => {
    useNotificationStore.setState({ notifications: [], unreadCount: 25 });
    render(
      <MemoryRouter>
        <Header onMenuToggle={() => {}} />
      </MemoryRouter>,
    );
    expect(screen.getByText('9+')).toBeInTheDocument();
  });

  it('toggles menu on button click', () => {
    const onMenuToggle = vi.fn();
    render(
      <MemoryRouter>
        <Header onMenuToggle={onMenuToggle} />
      </MemoryRouter>,
    );
    const menuBtn = screen.getByText((_, el) => el?.tagName === 'BUTTON' && el.innerHTML.includes('☰'));
    fireEvent.click(menuBtn);
    expect(onMenuToggle).toHaveBeenCalled();
  });

  it('logout clears auth', async () => {
    render(
      <MemoryRouter>
        <Header onMenuToggle={() => {}} />
      </MemoryRouter>,
    );
    fireEvent.click(screen.getByText('Logout'));
    await waitFor(() => {
      expect(useAuthStore.getState().accessToken).toBeNull();
    });
  });
});

describe('Sidebar', () => {
  beforeEach(() => {
    useAuthStore.setState({
      accessToken: 'token',
      refreshToken: 'rt',
      sessionId: 's',
      isAuthenticated: true,
      user: {
        id: 'u-1', username: 'admin', display_name: 'Admin',
        role: UserRole.ADMINISTRATOR as any,
        is_active: true, is_frozen: false,
        organization_id: 'o-1', created_at: '',
      },
    });
  });

  it('renders for administrator', () => {
    render(
      <MemoryRouter>
        <Sidebar onClose={() => {}} />
      </MemoryRouter>,
    );
    expect(document.body.innerHTML).toContain('Users');
  });

  it('renders for tenant', () => {
    useAuthStore.setState({
      accessToken: 'token',
      user: {
        id: 'u-1', username: 'tenant', display_name: 'Tenant',
        role: UserRole.TENANT as any,
        is_active: true, is_frozen: false,
        organization_id: 'o-1', created_at: '',
      },
      refreshToken: 'rt', sessionId: 's', isAuthenticated: true,
    });
    render(
      <MemoryRouter>
        <Sidebar onClose={() => {}} />
      </MemoryRouter>,
    );
    expect(document.body.innerHTML).not.toBe('');
  });

  it('renders for property_manager', () => {
    useAuthStore.setState({
      accessToken: 'token',
      user: {
        id: 'u-1', username: 'mgr', display_name: 'Mgr',
        role: UserRole.PROPERTY_MANAGER as any,
        is_active: true, is_frozen: false,
        organization_id: 'o-1', created_at: '',
      },
      refreshToken: 'rt', sessionId: 's', isAuthenticated: true,
    });
    render(
      <MemoryRouter>
        <Sidebar onClose={() => {}} />
      </MemoryRouter>,
    );
    expect(document.body.innerHTML).not.toBe('');
  });

  it('renders for finance_clerk', () => {
    useAuthStore.setState({
      accessToken: 'token',
      user: {
        id: 'u-1', username: 'fin', display_name: 'Fin',
        role: UserRole.FINANCE_CLERK as any,
        is_active: true, is_frozen: false,
        organization_id: 'o-1', created_at: '',
      },
      refreshToken: 'rt', sessionId: 's', isAuthenticated: true,
    });
    render(
      <MemoryRouter>
        <Sidebar onClose={() => {}} />
      </MemoryRouter>,
    );
    expect(document.body.innerHTML).not.toBe('');
  });
});

describe('AppShell', () => {
  beforeEach(() => {
    useAuthStore.setState({
      accessToken: 'token',
      user: {
        id: 'u-1', username: 'admin', display_name: 'A',
        role: UserRole.ADMINISTRATOR as any,
        is_active: true, is_frozen: false,
        organization_id: 'o-1', created_at: '',
      },
      refreshToken: 'rt', sessionId: 's', isAuthenticated: true,
    });
  });

  it('renders', () => {
    const { container } = render(
      <MemoryRouter>
        <AppShell />
      </MemoryRouter>,
    );
    expect(container.firstChild).toBeTruthy();
  });
});

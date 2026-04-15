import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { LoginPage } from '../LoginPage';

vi.mock('../../../api/auth', () => ({
  login: vi.fn(),
}));

import * as authApi from '../../../api/auth';

describe('LoginPage', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  const renderPage = () =>
    render(
      <MemoryRouter>
        <LoginPage />
      </MemoryRouter>,
    );

  it('renders username and password fields', () => {
    renderPage();
    expect(screen.getByText('Username')).toBeInTheDocument();
    expect(screen.getByText('Password')).toBeInTheDocument();
    expect(screen.getByText('Sign In')).toBeInTheDocument();
  });

  it('calls login API with credentials on submit', async () => {
    (authApi.login as any).mockResolvedValue({
      access_token: 'at',
      refresh_token: 'rt',
      expires_in: 900,
      session_id: 's',
      user: { id: 'u', username: 'test', display_name: 'T', role: 'tenant', is_active: true, is_frozen: false, organization_id: 'o', created_at: '' },
    });

    renderPage();
    const [usernameInput, passwordInput] = screen.getAllByRole('textbox').concat(document.querySelectorAll('input[type=password]') as any);
    fireEvent.change(screen.getByText('Username').parentElement!.querySelector('input')!, {
      target: { value: 'admin' },
    });
    fireEvent.change(screen.getByText('Password').parentElement!.querySelector('input')!, {
      target: { value: 'password123' },
    });
    fireEvent.click(screen.getByText('Sign In'));

    await waitFor(() => {
      expect(authApi.login).toHaveBeenCalledWith('admin', 'password123', expect.any(String), expect.any(String));
    });
  });

  it('shows error message on login failure', async () => {
    (authApi.login as any).mockRejectedValue({
      response: { data: { message: 'Invalid credentials' } },
    });

    renderPage();
    fireEvent.change(screen.getByText('Username').parentElement!.querySelector('input')!, {
      target: { value: 'bad' },
    });
    fireEvent.change(screen.getByText('Password').parentElement!.querySelector('input')!, {
      target: { value: 'wrong' },
    });
    fireEvent.click(screen.getByText('Sign In'));

    await waitFor(() => {
      expect(screen.getByText('Invalid credentials')).toBeInTheDocument();
    });
  });

  it('shows generic error when no response body', async () => {
    (authApi.login as any).mockRejectedValue(new Error('Network'));

    renderPage();
    fireEvent.change(screen.getByText('Username').parentElement!.querySelector('input')!, {
      target: { value: 'x' },
    });
    fireEvent.change(screen.getByText('Password').parentElement!.querySelector('input')!, {
      target: { value: 'y' },
    });
    fireEvent.click(screen.getByText('Sign In'));

    await waitFor(() => {
      expect(screen.getByText('Login failed')).toBeInTheDocument();
    });
  });
});

import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { BootstrapPage } from '../BootstrapPage';
import { ChangePasswordPage } from '../ChangePasswordPage';

vi.mock('../../../api/auth', () => ({
  bootstrap: vi.fn(),
  changePassword: vi.fn(),
}));

import * as authApi from '../../../api/auth';

describe('BootstrapPage', () => {
  beforeEach(() => vi.clearAllMocks());

  const render_ = () =>
    render(
      <MemoryRouter>
        <BootstrapPage />
      </MemoryRouter>,
    );

  it('renders all form fields', () => {
    render_();
    expect(screen.getByText('System Bootstrap')).toBeInTheDocument();
    expect(screen.getByText('Organization Name')).toBeInTheDocument();
    expect(screen.getByText('Admin Username')).toBeInTheDocument();
  });

  it('submits to bootstrap API', async () => {
    (authApi.bootstrap as any).mockResolvedValue({});
    render_();

    const labels = ['Organization Name', 'Organization Code', 'Admin Username', 'Admin Password', 'Admin Display Name', 'Default Currency'];
    const values = ['Org', 'ORG', 'admin', 'password123', 'Admin', 'USD'];

    for (let i = 0; i < labels.length; i++) {
      const input = screen.getByText(labels[i]).parentElement!.querySelector('input')!;
      fireEvent.change(input, { target: { value: values[i] } });
    }

    fireEvent.click(screen.getByText('Initialize System'));

    await waitFor(() => {
      expect(authApi.bootstrap).toHaveBeenCalled();
    });
  });

  it('shows error on bootstrap failure', async () => {
    (authApi.bootstrap as any).mockRejectedValue({
      response: { data: { message: 'Already bootstrapped' } },
    });
    render_();

    // Fill minimum fields then submit
    const labels = ['Organization Name', 'Organization Code', 'Admin Username', 'Admin Password', 'Admin Display Name', 'Default Currency'];
    for (const label of labels) {
      const input = screen.getByText(label).parentElement!.querySelector('input')!;
      fireEvent.change(input, { target: { value: 'test_value_123' } });
    }
    fireEvent.click(screen.getByText('Initialize System'));

    await waitFor(() => {
      expect(screen.getByText('Already bootstrapped')).toBeInTheDocument();
    });
  });
});

describe('ChangePasswordPage', () => {
  beforeEach(() => vi.clearAllMocks());

  const render_ = () =>
    render(
      <MemoryRouter>
        <ChangePasswordPage />
      </MemoryRouter>,
    );

  it('renders form', () => {
    render_();
    expect(screen.getByRole('heading', { name: 'Change Password' })).toBeInTheDocument();
    expect(screen.getByText('Current Password')).toBeInTheDocument();
    expect(screen.getByText('New Password')).toBeInTheDocument();
  });

  it('submits to changePassword API', async () => {
    (authApi.changePassword as any).mockResolvedValue({});
    render_();

    fireEvent.change(screen.getByText('Current Password').parentElement!.querySelector('input')!, {
      target: { value: 'oldpass123' },
    });
    fireEvent.change(screen.getByText('New Password').parentElement!.querySelector('input')!, {
      target: { value: 'newpass123' },
    });
    fireEvent.click(screen.getByText('Change Password', { selector: 'button' }));

    await waitFor(() => {
      expect(authApi.changePassword).toHaveBeenCalledWith('oldpass123', 'newpass123');
    });
  });

  it('shows error on failure', async () => {
    (authApi.changePassword as any).mockRejectedValue({
      response: { data: { message: 'Wrong password' } },
    });
    render_();

    fireEvent.change(screen.getByText('Current Password').parentElement!.querySelector('input')!, {
      target: { value: 'wrongpass' },
    });
    fireEvent.change(screen.getByText('New Password').parentElement!.querySelector('input')!, {
      target: { value: 'newpass123' },
    });
    fireEvent.click(screen.getByText('Change Password', { selector: 'button' }));

    await waitFor(() => {
      expect(screen.getByText('Wrong password')).toBeInTheDocument();
    });
  });

  it('shows success after password change', async () => {
    (authApi.changePassword as any).mockResolvedValue({});
    render_();

    fireEvent.change(screen.getByText('Current Password').parentElement!.querySelector('input')!, {
      target: { value: 'oldpass123' },
    });
    fireEvent.change(screen.getByText('New Password').parentElement!.querySelector('input')!, {
      target: { value: 'newpass123' },
    });
    fireEvent.click(screen.getByText('Change Password', { selector: 'button' }));

    await waitFor(() => {
      expect(screen.getByText('Password Changed')).toBeInTheDocument();
    });
  });
});

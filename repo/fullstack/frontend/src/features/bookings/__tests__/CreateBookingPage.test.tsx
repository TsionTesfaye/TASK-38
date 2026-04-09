import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { CreateBookingPage } from '../CreateBookingPage';

// Mock APIs
vi.mock('../../../api/inventory', () => ({
  checkAvailability: vi.fn(),
}));
vi.mock('../../../api/bookings', () => ({
  createHold: vi.fn(),
  confirmHold: vi.fn(),
  reschedule: vi.fn(),
}));

import * as inventoryApi from '../../../api/inventory';
import * as bookingsApi from '../../../api/bookings';

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/tenant/bookings/new']}>
      <CreateBookingPage />
    </MemoryRouter>,
  );
}

/**
 * Helper: find an input by its preceding label text.
 * The component uses <label> + <input> siblings without htmlFor.
 */
function getInputByLabel(labelText: string): HTMLInputElement {
  const label = screen.getByText(labelText);
  const parent = label.parentElement!;
  const input = parent.querySelector('input');
  if (!input) throw new Error(`No input found for label "${labelText}"`);
  return input;
}

async function navigateToHoldStep(expiry: string) {
  (inventoryApi.checkAvailability as any).mockResolvedValue({
    available_units: 5,
    requested_units: 1,
    total_capacity: 10,
    can_reserve: true,
  });

  (bookingsApi.createHold as any).mockResolvedValue({
    id: 'hold-1',
    expires_at: expiry,
    status: 'active',
  });

  renderPage();

  // Fill form using sibling input selectors
  fireEvent.change(getInputByLabel('Inventory Item ID'), { target: { value: 'item-1' } });
  fireEvent.change(getInputByLabel('Start'), { target: { value: '2026-04-15T10:00' } });
  fireEvent.change(getInputByLabel('End'), { target: { value: '2026-04-16T10:00' } });

  // Wait for availability check (debounced 500ms)
  await waitFor(() => {
    expect(screen.getByText(/units available/i)).toBeInTheDocument();
  }, { timeout: 3000 });

  // Click reserve
  fireEvent.click(screen.getByText(/Reserve 1 unit/));

  // Wait for hold step
  await waitFor(() => {
    expect(screen.getByText(/Hold Timer/i)).toBeInTheDocument();
  }, { timeout: 3000 });
}

describe('CreateBookingPage', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  // ═══════════════════════════════════════════════════════════════
  // 1. Hold timer countdown display
  // ═══════════════════════════════════════════════════════════════

  it('displays hold timer after creating hold', async () => {
    const futureExpiry = new Date(Date.now() + 10 * 60 * 1000).toISOString();
    await navigateToHoldStep(futureExpiry);

    // Timer should show a time value (MM:SS format)
    expect(screen.getByText(/\d+:\d{2}/)).toBeInTheDocument();
  });

  // ═══════════════════════════════════════════════════════════════
  // 2. Booking policy / cancellation rules display
  // ═══════════════════════════════════════════════════════════════

  it('displays booking policy with cancellation rules in hold step', async () => {
    const futureExpiry = new Date(Date.now() + 10 * 60 * 1000).toISOString();
    await navigateToHoldStep(futureExpiry);

    expect(screen.getByText('Booking Policy:')).toBeInTheDocument();
    expect(screen.getByText(/Free cancellation up to 24 hours/i)).toBeInTheDocument();
    expect(screen.getByText(/Late cancellation: 20% fee applies/i)).toBeInTheDocument();
    expect(screen.getByText(/No-show penalty: 50% \+ first day rent/i)).toBeInTheDocument();
    expect(screen.getByText(/By confirming, you acknowledge these terms/i)).toBeInTheDocument();
  });

  // ═══════════════════════════════════════════════════════════════
  // 3. Hold expiry disables confirmation
  // ═══════════════════════════════════════════════════════════════

  it('disables confirm button when hold is expired', async () => {
    const pastExpiry = new Date(Date.now() - 1000).toISOString();
    await navigateToHoldStep(pastExpiry);

    const confirmBtn = screen.getByText('Hold Expired');
    expect(confirmBtn).toBeDisabled();
  });
});

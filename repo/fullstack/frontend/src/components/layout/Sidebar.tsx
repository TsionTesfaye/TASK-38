import React from 'react';
import { NavLink } from 'react-router-dom';
import { useAuthStore } from '../../state/authStore';
import { UserRole } from '../../types/enums';

interface SidebarProps {
  onClose: () => void;
}

const navItems: { path: string; label: string; roles: string[] }[] = [
  { path: '/tenant/bookings', label: 'My Bookings', roles: [UserRole.TENANT] },
  { path: '/tenant/bookings/new', label: 'New Booking', roles: [UserRole.TENANT] },
  { path: '/tenant/bills', label: 'My Bills', roles: [UserRole.TENANT] },
  { path: '/tenant/payments', label: 'My Payments', roles: [UserRole.TENANT] },
  { path: '/manager/inventory', label: 'Inventory', roles: [UserRole.PROPERTY_MANAGER, UserRole.ADMINISTRATOR] },
  { path: '/manager/bookings', label: 'Bookings', roles: [UserRole.PROPERTY_MANAGER, UserRole.ADMINISTRATOR] },
  { path: '/manager/terminals', label: 'Terminals', roles: [UserRole.PROPERTY_MANAGER, UserRole.ADMINISTRATOR] },
  { path: '/finance/bills', label: 'Bills', roles: [UserRole.FINANCE_CLERK, UserRole.PROPERTY_MANAGER, UserRole.ADMINISTRATOR] },
  { path: '/finance/payments', label: 'Payments', roles: [UserRole.FINANCE_CLERK, UserRole.ADMINISTRATOR] },
  { path: '/finance/refunds', label: 'Refunds', roles: [UserRole.FINANCE_CLERK, UserRole.PROPERTY_MANAGER, UserRole.ADMINISTRATOR] },
  { path: '/finance/reconciliation', label: 'Reconciliation', roles: [UserRole.FINANCE_CLERK, UserRole.ADMINISTRATOR] },
  { path: '/admin/users', label: 'Users', roles: [UserRole.ADMINISTRATOR] },
  { path: '/admin/settings', label: 'Settings', roles: [UserRole.ADMINISTRATOR] },
  { path: '/admin/audit', label: 'Audit Logs', roles: [UserRole.ADMINISTRATOR] },
  { path: '/admin/backups', label: 'Backups', roles: [UserRole.ADMINISTRATOR] },
  { path: '/notifications', label: 'Notifications', roles: [UserRole.TENANT, UserRole.PROPERTY_MANAGER, UserRole.FINANCE_CLERK, UserRole.ADMINISTRATOR] },
  { path: '/notifications/preferences', label: 'Notification Settings', roles: [UserRole.TENANT, UserRole.PROPERTY_MANAGER, UserRole.FINANCE_CLERK, UserRole.ADMINISTRATOR] },
  { path: '/change-password', label: 'Change Password', roles: [UserRole.TENANT, UserRole.PROPERTY_MANAGER, UserRole.FINANCE_CLERK, UserRole.ADMINISTRATOR] },
];

export const Sidebar: React.FC<SidebarProps> = ({ onClose }) => {
  const { user } = useAuthStore();

  const filtered = navItems.filter(item => user && item.roles.includes(user.role));

  return (
    <nav style={{ padding: '16px', width: '240px' }}>
      <h3 style={{ marginBottom: '16px' }}>RentOps</h3>
      {filtered.map(item => (
        <NavLink
          key={item.path}
          to={item.path}
          onClick={onClose}
          style={({ isActive }) => ({
            display: 'block',
            padding: '8px 12px',
            marginBottom: '4px',
            borderRadius: '4px',
            textDecoration: 'none',
            color: isActive ? '#1976d2' : '#333',
            backgroundColor: isActive ? '#e3f2fd' : 'transparent',
            fontWeight: isActive ? 600 : 400,
          })}
        >
          {item.label}
        </NavLink>
      ))}
    </nav>
  );
};

import React from 'react';
import { Navigate } from 'react-router-dom';
import { useAuthStore } from '../state/authStore';
import { UserRole } from '../types/enums';

export const RoleRedirect: React.FC = () => {
  const { user } = useAuthStore();

  if (!user) return <Navigate to="/login" replace />;

  switch (user.role) {
    case UserRole.TENANT:
      return <Navigate to="/tenant/bookings" replace />;
    case UserRole.PROPERTY_MANAGER:
      return <Navigate to="/manager/inventory" replace />;
    case UserRole.FINANCE_CLERK:
      return <Navigate to="/finance/bills" replace />;
    case UserRole.ADMINISTRATOR:
      return <Navigate to="/admin/users" replace />;
    default:
      return <Navigate to="/login" replace />;
  }
};

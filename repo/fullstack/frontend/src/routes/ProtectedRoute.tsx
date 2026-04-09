import React from 'react';
import { Navigate } from 'react-router-dom';
import { useAuthStore } from '../state/authStore';
import { PermissionDenied } from '../components/common/PermissionDenied';
import type { UserRole } from '../types/enums';

interface ProtectedRouteProps {
  children: React.ReactNode;
  allowedRoles?: UserRole[];
}

export const ProtectedRoute: React.FC<ProtectedRouteProps> = ({ children, allowedRoles }) => {
  const { accessToken, user } = useAuthStore();

  if (!accessToken || !user) {
    return <Navigate to="/login" replace />;
  }

  if (allowedRoles && !allowedRoles.includes(user.role)) {
    return <PermissionDenied />;
  }

  return <>{children}</>;
};

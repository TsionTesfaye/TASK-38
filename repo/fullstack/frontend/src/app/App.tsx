import React from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import { ErrorBoundary } from '../components/common/ErrorBoundary';
import { AppShell } from '../components/layout/AppShell';
import { ProtectedRoute } from '../routes/ProtectedRoute';
import { RoleRedirect } from '../routes/RoleRedirect';
import { LoginPage } from '../features/auth/LoginPage';
import { BootstrapPage } from '../features/auth/BootstrapPage';
import { BookingListPage } from '../features/bookings/BookingListPage';
import { BookingDetailPage } from '../features/bookings/BookingDetailPage';
import { CreateBookingPage } from '../features/bookings/CreateBookingPage';
import { InventoryListPage } from '../features/inventory/InventoryListPage';
import { InventoryDetailPage } from '../features/inventory/InventoryDetailPage';
import { BillListPage } from '../features/billing/BillListPage';
import { BillDetailPage } from '../features/billing/BillDetailPage';
import { PaymentListPage } from '../features/payments/PaymentListPage';
import { RefundListPage } from '../features/refunds/RefundListPage';
import { NotificationCenterPage } from '../features/notifications/NotificationCenterPage';
import { TerminalListPage } from '../features/terminals/TerminalListPage';
import { ReconciliationPage } from '../features/reports/ReconciliationPage';
import { UserManagementPage } from '../features/admin/UserManagementPage';
import { SettingsPage } from '../features/admin/SettingsPage';
import { AuditLogPage } from '../features/admin/AuditLogPage';
import { BackupPage } from '../features/admin/BackupPage';
import { InventoryFormPage } from '../features/inventory/InventoryFormPage';
import { PaymentInitiatePage } from '../features/payments/PaymentInitiatePage';
import { RefundFormPage } from '../features/refunds/RefundFormPage';
import { NotificationPreferencesPage } from '../features/notifications/NotificationPreferencesPage';
import { ChangePasswordPage } from '../features/auth/ChangePasswordPage';
import { SupplementalBillPage } from '../features/billing/SupplementalBillPage';
import { UserRole } from '../types/enums';

export const App: React.FC = () => {
  return (
    <ErrorBoundary>
      <Routes>
        <Route path="/login" element={<LoginPage />} />
        <Route path="/bootstrap" element={<BootstrapPage />} />
        <Route element={<ProtectedRoute allowedRoles={[UserRole.ADMINISTRATOR, UserRole.PROPERTY_MANAGER, UserRole.TENANT, UserRole.FINANCE_CLERK]}><AppShell /></ProtectedRoute>}>
          <Route index element={<RoleRedirect />} />
          <Route path="tenant/bookings" element={<ProtectedRoute allowedRoles={[UserRole.TENANT]}><BookingListPage /></ProtectedRoute>} />
          <Route path="tenant/bookings/new" element={<ProtectedRoute allowedRoles={[UserRole.TENANT]}><CreateBookingPage /></ProtectedRoute>} />
          <Route path="tenant/bookings/:id" element={<ProtectedRoute allowedRoles={[UserRole.TENANT]}><BookingDetailPage /></ProtectedRoute>} />
          <Route path="tenant/bills" element={<ProtectedRoute allowedRoles={[UserRole.TENANT]}><BillListPage /></ProtectedRoute>} />
          <Route path="tenant/bills/:id" element={<ProtectedRoute allowedRoles={[UserRole.TENANT]}><BillDetailPage /></ProtectedRoute>} />
          <Route path="tenant/bills/:id/pay" element={<ProtectedRoute allowedRoles={[UserRole.TENANT]}><PaymentInitiatePage /></ProtectedRoute>} />
          <Route path="tenant/payments" element={<ProtectedRoute allowedRoles={[UserRole.TENANT]}><PaymentListPage /></ProtectedRoute>} />
          <Route path="manager/inventory" element={<ProtectedRoute allowedRoles={[UserRole.PROPERTY_MANAGER, UserRole.ADMINISTRATOR]}><InventoryListPage /></ProtectedRoute>} />
          <Route path="manager/inventory/new" element={<ProtectedRoute allowedRoles={[UserRole.PROPERTY_MANAGER, UserRole.ADMINISTRATOR]}><InventoryFormPage /></ProtectedRoute>} />
          <Route path="manager/inventory/:id" element={<ProtectedRoute allowedRoles={[UserRole.PROPERTY_MANAGER, UserRole.ADMINISTRATOR]}><InventoryDetailPage /></ProtectedRoute>} />
          <Route path="manager/bookings" element={<ProtectedRoute allowedRoles={[UserRole.PROPERTY_MANAGER, UserRole.ADMINISTRATOR]}><BookingListPage /></ProtectedRoute>} />
          <Route path="manager/bookings/:id" element={<ProtectedRoute allowedRoles={[UserRole.PROPERTY_MANAGER, UserRole.ADMINISTRATOR]}><BookingDetailPage /></ProtectedRoute>} />
          <Route path="manager/terminals" element={<ProtectedRoute allowedRoles={[UserRole.PROPERTY_MANAGER, UserRole.ADMINISTRATOR]}><TerminalListPage /></ProtectedRoute>} />
          <Route path="finance/bills" element={<ProtectedRoute allowedRoles={[UserRole.FINANCE_CLERK, UserRole.PROPERTY_MANAGER, UserRole.ADMINISTRATOR]}><BillListPage /></ProtectedRoute>} />
          <Route path="finance/bills/:id" element={<ProtectedRoute allowedRoles={[UserRole.FINANCE_CLERK, UserRole.PROPERTY_MANAGER, UserRole.ADMINISTRATOR]}><BillDetailPage /></ProtectedRoute>} />
          <Route path="finance/bills/new" element={<ProtectedRoute allowedRoles={[UserRole.PROPERTY_MANAGER, UserRole.ADMINISTRATOR]}><SupplementalBillPage /></ProtectedRoute>} />
          <Route path="finance/payments" element={<ProtectedRoute allowedRoles={[UserRole.FINANCE_CLERK, UserRole.ADMINISTRATOR]}><PaymentListPage /></ProtectedRoute>} />
          <Route path="finance/refunds" element={<ProtectedRoute allowedRoles={[UserRole.FINANCE_CLERK, UserRole.PROPERTY_MANAGER, UserRole.ADMINISTRATOR]}><RefundListPage /></ProtectedRoute>} />
          <Route path="finance/refunds/new" element={<ProtectedRoute allowedRoles={[UserRole.FINANCE_CLERK, UserRole.PROPERTY_MANAGER, UserRole.ADMINISTRATOR]}><RefundFormPage /></ProtectedRoute>} />
          <Route path="finance/reconciliation" element={<ProtectedRoute allowedRoles={[UserRole.FINANCE_CLERK, UserRole.ADMINISTRATOR]}><ReconciliationPage /></ProtectedRoute>} />
          <Route path="admin/users" element={<ProtectedRoute allowedRoles={[UserRole.ADMINISTRATOR]}><UserManagementPage /></ProtectedRoute>} />
          <Route path="admin/settings" element={<ProtectedRoute allowedRoles={[UserRole.ADMINISTRATOR]}><SettingsPage /></ProtectedRoute>} />
          <Route path="admin/audit" element={<ProtectedRoute allowedRoles={[UserRole.ADMINISTRATOR]}><AuditLogPage /></ProtectedRoute>} />
          <Route path="admin/backups" element={<ProtectedRoute allowedRoles={[UserRole.ADMINISTRATOR]}><BackupPage /></ProtectedRoute>} />
          <Route path="notifications" element={<NotificationCenterPage />} />
          <Route path="notifications/preferences" element={<NotificationPreferencesPage />} />
          <Route path="change-password" element={<ChangePasswordPage />} />
        </Route>
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </ErrorBoundary>
  );
};

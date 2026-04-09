import React, { useState } from 'react';
import { Outlet } from 'react-router-dom';
import { Sidebar } from './Sidebar';
import { Header } from './Header';

export const AppShell: React.FC = () => {
  const [sidebarOpen, setSidebarOpen] = useState(false);

  return (
    <div style={{ display: 'flex', minHeight: '100vh' }}>
      <aside style={{
        width: sidebarOpen ? '240px' : '0',
        overflow: 'hidden',
        transition: 'width 0.2s',
        borderRight: '1px solid #e0e0e0',
        backgroundColor: '#f8f9fa',
      }}>
        <Sidebar onClose={() => setSidebarOpen(false)} />
      </aside>
      <div style={{ flex: 1, display: 'flex', flexDirection: 'column' }}>
        <Header onMenuToggle={() => setSidebarOpen(!sidebarOpen)} />
        <main style={{ flex: 1, padding: '20px', overflow: 'auto' }}>
          <Outlet />
        </main>
      </div>
    </div>
  );
};

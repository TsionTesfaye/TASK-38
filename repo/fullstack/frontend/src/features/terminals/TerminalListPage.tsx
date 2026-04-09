import React, { useEffect, useState, useRef, useCallback } from 'react';
import * as terminalsApi from '../../api/terminals';
import type { Terminal, TerminalPlaylist, TerminalPackageTransfer } from '../../types';
import { LoadingSpinner } from '../../components/common/LoadingSpinner';
import { ErrorMessage } from '../../components/common/ErrorMessage';
import { EmptyState } from '../../components/common/EmptyState';
import { Pagination } from '../../components/common/Pagination';
import { usePagination } from '../../hooks/usePagination';

type Tab = 'terminals' | 'playlists' | 'transfers';

export const TerminalListPage: React.FC = () => {
  const [tab, setTab] = useState<Tab>('terminals');
  const [terminals, setTerminals] = useState<Terminal[]>([]);
  const [playlists, setPlaylists] = useState<TerminalPlaylist[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const { page, perPage, setPage } = usePagination();

  // Register terminal form
  const [showRegister, setShowRegister] = useState(false);
  const [regForm, setRegForm] = useState({ terminal_code: '', display_name: '', location_group: '', language_code: 'en' });

  // Playlist form
  const [showPlaylist, setShowPlaylist] = useState(false);
  const [plForm, setPlForm] = useState({ name: '', location_group: '', schedule_rule: '' });

  // Transfer state
  const [showTransfer, setShowTransfer] = useState(false);
  const [transferTerminalId, setTransferTerminalId] = useState('');
  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const [fileChecksum, setFileChecksum] = useState('');
  const [activeTransfer, setActiveTransfer] = useState<TerminalPackageTransfer | null>(null);
  const [uploadProgress, setUploadProgress] = useState(0);
  const [uploading, setUploading] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const pausedRef = useRef(false);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const CHUNK_SIZE = 64 * 1024; // 64KB chunks

  const loadTerminals = () => {
    setLoading(true);
    terminalsApi.listTerminals({ page, per_page: perPage })
      .then((res) => { setTerminals(res.data); setTotal(res.meta.total); })
      .catch((err: any) => setError(err?.response?.data?.message || 'Failed'))
      .finally(() => setLoading(false));
  };

  const loadPlaylists = () => {
    setLoading(true);
    terminalsApi.listPlaylists({ page, per_page: perPage, location_group: '' })
      .then((res) => { setPlaylists(res.data); setTotal(res.meta.total); })
      .catch((err: any) => setError(err?.response?.data?.message || 'Failed'))
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    if (tab === 'terminals') loadTerminals();
    else if (tab === 'playlists') loadPlaylists();
  }, [page, perPage, tab]);

  const handleRegister = async () => {
    if (!regForm.terminal_code || !regForm.display_name || !regForm.location_group) return;
    setSubmitting(true); setError(null);
    try {
      await terminalsApi.registerTerminal(regForm);
      setShowRegister(false);
      setRegForm({ terminal_code: '', display_name: '', location_group: '', language_code: 'en' });
      loadTerminals();
    } catch (err: any) { setError(err?.response?.data?.message || 'Register failed'); }
    finally { setSubmitting(false); }
  };

  const handleCreatePlaylist = async () => {
    if (!plForm.name || !plForm.location_group || !plForm.schedule_rule) return;
    setSubmitting(true); setError(null);
    try {
      await terminalsApi.createPlaylist(plForm);
      setShowPlaylist(false);
      setPlForm({ name: '', location_group: '', schedule_rule: '' });
      loadPlaylists();
    } catch (err: any) { setError(err?.response?.data?.message || 'Create failed'); }
    finally { setSubmitting(false); }
  };

  // Compute SHA-256 of the selected file
  const computeChecksum = useCallback(async (file: File): Promise<string> => {
    const buffer = await file.arrayBuffer();
    const hashBuffer = await crypto.subtle.digest('SHA-256', buffer);
    return Array.from(new Uint8Array(hashBuffer)).map(b => b.toString(16).padStart(2, '0')).join('');
  }, []);

  const handleFileSelect = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setSelectedFile(file);
    setError(null);
    try {
      const checksum = await computeChecksum(file);
      setFileChecksum(checksum);
    } catch { setError('Failed to compute file checksum'); }
  };

  const handleInitTransfer = async () => {
    if (!transferTerminalId || !selectedFile || !fileChecksum) { setError('Select a terminal and file'); return; }
    const totalChunks = Math.ceil(selectedFile.size / CHUNK_SIZE);
    setSubmitting(true); setError(null);
    try {
      const transfer = await terminalsApi.initiateTransfer({
        terminal_id: transferTerminalId,
        package_name: selectedFile.name,
        checksum: fileChecksum,
        total_chunks: totalChunks,
      });
      setActiveTransfer(transfer);
      setShowTransfer(false);
      // Start uploading chunks
      uploadChunks(transfer, selectedFile, totalChunks);
    } catch (err: any) { setError(err?.response?.data?.message || 'Transfer init failed'); }
    finally { setSubmitting(false); }
  };

  const uploadChunks = async (transfer: TerminalPackageTransfer, file: File, totalChunks: number) => {
    setUploading(true);
    pausedRef.current = false;
    let currentChunk = transfer.transferred_chunks;

    while (currentChunk < totalChunks) {
      if (pausedRef.current) break;
      const start = currentChunk * CHUNK_SIZE;
      const end = Math.min(start + CHUNK_SIZE, file.size);
      const blob = file.slice(start, end);
      const base64 = await new Promise<string>((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => {
          // readAsDataURL returns "data:<mime>;base64,<data>" — extract the base64 part.
          const dataUrl = reader.result as string;
          resolve(dataUrl.split(',', 2)[1] ?? '');
        };
        reader.onerror = () => reject(reader.error);
        reader.readAsDataURL(blob);
      });

      try {
        const updated = await terminalsApi.recordChunk(transfer.id, currentChunk, base64);
        setActiveTransfer(updated);
        currentChunk++;
        setUploadProgress(Math.round((currentChunk / totalChunks) * 100));
      } catch (err: any) {
        setError(err?.response?.data?.message || `Chunk ${currentChunk} failed`);
        break;
      }
    }
    setUploading(false);
  };

  const handlePause = async () => {
    if (!activeTransfer) return;
    pausedRef.current = true;
    try {
      const updated = await terminalsApi.pauseTransfer(activeTransfer.id);
      setActiveTransfer(updated);
    } catch (err: any) { setError(err?.response?.data?.message || 'Pause failed'); }
  };

  const handleResume = async () => {
    if (!activeTransfer || !selectedFile) return;
    try {
      const updated = await terminalsApi.resumeTransfer(activeTransfer.id);
      setActiveTransfer(updated);
      const totalChunks = Math.ceil(selectedFile.size / CHUNK_SIZE);
      uploadChunks(updated, selectedFile, totalChunks);
    } catch (err: any) { setError(err?.response?.data?.message || 'Resume failed'); }
  };

  const btnStyle = (active: boolean) => ({
    padding: '8px 16px', border: 'none', borderRadius: '4px', cursor: 'pointer',
    backgroundColor: active ? '#1976d2' : '#e0e0e0', color: active ? '#fff' : '#333', marginRight: '4px',
  });

  const inputStyle = { padding: '8px', border: '1px solid #ccc', borderRadius: '4px', width: '100%', boxSizing: 'border-box' as const };

  if (loading && terminals.length === 0 && playlists.length === 0) return <LoadingSpinner />;

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px' }}>
        <h1>Terminal Management</h1>
        <div>
          <button onClick={() => setTab('terminals')} style={btnStyle(tab === 'terminals')}>Terminals</button>
          <button onClick={() => setTab('playlists')} style={btnStyle(tab === 'playlists')}>Playlists</button>
          <button onClick={() => setTab('transfers')} style={btnStyle(tab === 'transfers')}>Transfers</button>
        </div>
      </div>
      {error && <ErrorMessage message={error} />}

      {/* ACTIVE TRANSFER STATUS */}
      {activeTransfer && (
        <div style={{ marginBottom: '16px', padding: '16px', border: '1px solid #e0e0e0', borderRadius: '8px', backgroundColor: '#f5f5f5' }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <strong>{activeTransfer.package_name}</strong>
            <span style={{ fontSize: '13px', fontWeight: 600, color: activeTransfer.status === 'completed' ? '#2e7d32' : activeTransfer.status === 'failed' ? '#d32f2f' : '#1976d2' }}>
              {activeTransfer.status.toUpperCase()}
            </span>
          </div>
          <div style={{ marginTop: '8px', background: '#e0e0e0', borderRadius: '4px', height: '8px', overflow: 'hidden' }}>
            <div style={{ width: `${uploadProgress}%`, height: '100%', backgroundColor: activeTransfer.status === 'failed' ? '#d32f2f' : '#2e7d32', transition: 'width 0.3s' }} />
          </div>
          <div style={{ marginTop: '4px', fontSize: '12px', color: '#666' }}>
            {activeTransfer.transferred_chunks}/{activeTransfer.total_chunks} chunks ({uploadProgress}%)
          </div>
          <div style={{ marginTop: '8px', display: 'flex', gap: '8px' }}>
            {(activeTransfer.status === 'in_progress' || uploading) && <button onClick={handlePause} style={{ padding: '4px 12px', cursor: 'pointer', border: '1px solid #ccc', borderRadius: '4px' }}>Pause</button>}
            {activeTransfer.status === 'paused' && <button onClick={handleResume} style={{ padding: '4px 12px', cursor: 'pointer', border: '1px solid #ccc', borderRadius: '4px' }}>Resume</button>}
            {(activeTransfer.status === 'completed' || activeTransfer.status === 'failed') && (
              <button onClick={() => { setActiveTransfer(null); setSelectedFile(null); setUploadProgress(0); }} style={{ padding: '4px 12px', cursor: 'pointer', border: '1px solid #ccc', borderRadius: '4px' }}>Dismiss</button>
            )}
          </div>
        </div>
      )}

      {/* TERMINALS TAB */}
      {tab === 'terminals' && (
        <>
          <button onClick={() => setShowRegister(v => !v)} style={{ marginBottom: '12px', padding: '8px 16px', backgroundColor: '#2e7d32', color: '#fff', border: 'none', borderRadius: '4px', cursor: 'pointer' }}>
            {showRegister ? 'Cancel' : 'Register Terminal'}
          </button>
          {showRegister && (
            <div style={{ marginBottom: '16px', padding: '16px', border: '1px solid #e0e0e0', borderRadius: '8px' }}>
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '8px', marginBottom: '8px' }}>
                <input placeholder="Terminal Code" value={regForm.terminal_code} onChange={e => setRegForm({ ...regForm, terminal_code: e.target.value })} style={inputStyle} />
                <input placeholder="Display Name" value={regForm.display_name} onChange={e => setRegForm({ ...regForm, display_name: e.target.value })} style={inputStyle} />
                <input placeholder="Location Group" value={regForm.location_group} onChange={e => setRegForm({ ...regForm, location_group: e.target.value })} style={inputStyle} />
                <input placeholder="Language (en)" value={regForm.language_code} onChange={e => setRegForm({ ...regForm, language_code: e.target.value })} style={inputStyle} />
              </div>
              <button onClick={handleRegister} disabled={submitting} style={{ padding: '8px 16px', backgroundColor: '#1976d2', color: '#fff', border: 'none', borderRadius: '4px', cursor: 'pointer' }}>
                {submitting ? 'Registering...' : 'Register'}
              </button>
            </div>
          )}
          {terminals.length === 0 ? <EmptyState message="No terminals registered" /> : (
            <table style={{ width: '100%', borderCollapse: 'collapse' }}>
              <thead><tr>
                {['Code', 'Name', 'Location', 'Language', 'Active'].map(h => <th key={h} style={{ textAlign: 'left', padding: '8px', borderBottom: '2px solid #e0e0e0' }}>{h}</th>)}
              </tr></thead>
              <tbody>
                {terminals.map(t => (
                  <tr key={t.id}><td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{t.terminal_code}</td>
                    <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{t.display_name}</td>
                    <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{t.location_group}</td>
                    <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{t.language_code}</td>
                    <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{t.is_active ? 'Yes' : 'No'}</td></tr>
                ))}
              </tbody>
            </table>
          )}
          <Pagination page={page} perPage={perPage} total={total} onPageChange={setPage} />
        </>
      )}

      {/* PLAYLISTS TAB */}
      {tab === 'playlists' && (
        <>
          <button onClick={() => setShowPlaylist(v => !v)} style={{ marginBottom: '12px', padding: '8px 16px', backgroundColor: '#2e7d32', color: '#fff', border: 'none', borderRadius: '4px', cursor: 'pointer' }}>
            {showPlaylist ? 'Cancel' : 'Create Playlist'}
          </button>
          {showPlaylist && (
            <div style={{ marginBottom: '16px', padding: '16px', border: '1px solid #e0e0e0', borderRadius: '8px' }}>
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: '8px', marginBottom: '8px' }}>
                <input placeholder="Name" value={plForm.name} onChange={e => setPlForm({ ...plForm, name: e.target.value })} style={inputStyle} />
                <input placeholder="Location Group" value={plForm.location_group} onChange={e => setPlForm({ ...plForm, location_group: e.target.value })} style={inputStyle} />
                <input placeholder="Schedule Rule" value={plForm.schedule_rule} onChange={e => setPlForm({ ...plForm, schedule_rule: e.target.value })} style={inputStyle} />
              </div>
              <button onClick={handleCreatePlaylist} disabled={submitting} style={{ padding: '8px 16px', backgroundColor: '#1976d2', color: '#fff', border: 'none', borderRadius: '4px', cursor: 'pointer' }}>
                {submitting ? 'Creating...' : 'Create'}
              </button>
            </div>
          )}
          {playlists.length === 0 ? <EmptyState message="No playlists" /> : (
            <table style={{ width: '100%', borderCollapse: 'collapse' }}>
              <thead><tr>
                {['Name', 'Location', 'Schedule', 'Active'].map(h => <th key={h} style={{ textAlign: 'left', padding: '8px', borderBottom: '2px solid #e0e0e0' }}>{h}</th>)}
              </tr></thead>
              <tbody>
                {playlists.map(p => (
                  <tr key={p.id}><td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{p.name}</td>
                    <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{p.location_group}</td>
                    <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{p.schedule_rule}</td>
                    <td style={{ padding: '8px', borderBottom: '1px solid #eee' }}>{p.is_active ? 'Yes' : 'No'}</td></tr>
                ))}
              </tbody>
            </table>
          )}
          <Pagination page={page} perPage={perPage} total={total} onPageChange={setPage} />
        </>
      )}

      {/* TRANSFERS TAB */}
      {tab === 'transfers' && (
        <>
          <button onClick={() => setShowTransfer(v => !v)} style={{ marginBottom: '12px', padding: '8px 16px', backgroundColor: '#2e7d32', color: '#fff', border: 'none', borderRadius: '4px', cursor: 'pointer' }}>
            {showTransfer ? 'Cancel' : 'Upload Package'}
          </button>
          {showTransfer && (
            <div style={{ marginBottom: '16px', padding: '16px', border: '1px solid #e0e0e0', borderRadius: '8px' }}>
              <div style={{ marginBottom: '8px' }}>
                <label style={{ display: 'block', marginBottom: '4px', fontWeight: 500 }}>Terminal ID</label>
                <input placeholder="Terminal ID" value={transferTerminalId} onChange={e => setTransferTerminalId(e.target.value)} style={inputStyle} />
              </div>
              <div style={{ marginBottom: '8px' }}>
                <label style={{ display: 'block', marginBottom: '4px', fontWeight: 500 }}>Package File</label>
                <input ref={fileInputRef} type="file" onChange={handleFileSelect} style={{ display: 'block', width: '100%' }} />
              </div>
              {selectedFile && (
                <div style={{ marginBottom: '8px', fontSize: '13px', color: '#666' }}>
                  <div>File: {selectedFile.name} ({(selectedFile.size / 1024).toFixed(1)} KB)</div>
                  <div>Chunks: {Math.ceil(selectedFile.size / CHUNK_SIZE)}</div>
                  {fileChecksum && <div>SHA-256: {fileChecksum.slice(0, 16)}...</div>}
                </div>
              )}
              <button onClick={handleInitTransfer} disabled={submitting || !selectedFile || !fileChecksum || !transferTerminalId}
                style={{ padding: '8px 16px', backgroundColor: '#1976d2', color: '#fff', border: 'none', borderRadius: '4px', cursor: submitting ? 'not-allowed' : 'pointer' }}>
                {submitting ? 'Starting...' : 'Upload & Transfer'}
              </button>
            </div>
          )}
          {!activeTransfer && !showTransfer && (
            <p style={{ color: '#666' }}>Select a file to transfer to a terminal. The file will be split into chunks, uploaded sequentially, and verified by SHA-256 checksum.</p>
          )}
        </>
      )}
    </div>
  );
};

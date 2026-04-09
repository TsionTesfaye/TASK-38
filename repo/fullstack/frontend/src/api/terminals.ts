import client from './client';
import type {
  Terminal,
  TerminalPlaylist,
  TerminalPackageTransfer,
  PaginatedResponse,
} from '@/types';

export async function listTerminals(params?: {
  page?: number;
  per_page?: number;
  location_group?: string;
  is_active?: boolean;
}): Promise<PaginatedResponse<Terminal>> {
  const { data } = await client.get<PaginatedResponse<Terminal>>('/terminals', { params });
  return data;
}

export async function registerTerminal(terminal: {
  terminal_code: string;
  display_name: string;
  location_group: string;
  language_code: string;
  accessibility_mode?: boolean;
}): Promise<Terminal> {
  const { data } = await client.post<Terminal>('/terminals', terminal);
  return data;
}

export async function updateTerminal(
  terminalId: string,
  updates: Partial<{
    display_name: string;
    location_group: string;
    language_code: string;
    accessibility_mode: boolean;
    is_active: boolean;
  }>,
): Promise<Terminal> {
  const { data } = await client.put<Terminal>(`/terminals/${terminalId}`, updates);
  return data;
}

export async function getTerminal(terminalId: string): Promise<Terminal> {
  const { data } = await client.get<Terminal>(`/terminals/${terminalId}`);
  return data;
}

export async function listPlaylists(params?: {
  page?: number;
  per_page?: number;
  location_group?: string;
  is_active?: boolean;
}): Promise<PaginatedResponse<TerminalPlaylist>> {
  const { data } = await client.get<PaginatedResponse<TerminalPlaylist>>(
    '/terminal-playlists',
    { params },
  );
  return data;
}

export async function createPlaylist(playlist: {
  name: string;
  location_group: string;
  schedule_rule: string;
}): Promise<TerminalPlaylist> {
  const { data } = await client.post<TerminalPlaylist>('/terminal-playlists', playlist);
  return data;
}

export async function initiateTransfer(transfer: {
  terminal_id: string;
  package_name: string;
  checksum: string;
  total_chunks: number;
}): Promise<TerminalPackageTransfer> {
  const { data } = await client.post<TerminalPackageTransfer>(
    '/terminal-transfers',
    transfer,
  );
  return data;
}

export async function recordChunk(
  transferId: string,
  chunkIndex: number,
  chunkData: string,
): Promise<TerminalPackageTransfer> {
  const { data } = await client.post<TerminalPackageTransfer>(
    `/terminal-transfers/${transferId}/chunk`,
    { chunk_index: chunkIndex, chunk_data: chunkData },
  );
  return data;
}

export async function pauseTransfer(transferId: string): Promise<TerminalPackageTransfer> {
  const { data } = await client.post<TerminalPackageTransfer>(
    `/terminal-transfers/${transferId}/pause`,
  );
  return data;
}

export async function resumeTransfer(transferId: string): Promise<TerminalPackageTransfer> {
  const { data } = await client.post<TerminalPackageTransfer>(
    `/terminal-transfers/${transferId}/resume`,
  );
  return data;
}

export async function getTransfer(transferId: string): Promise<TerminalPackageTransfer> {
  const { data } = await client.get<TerminalPackageTransfer>(
    `/terminal-transfers/${transferId}`,
  );
  return data;
}

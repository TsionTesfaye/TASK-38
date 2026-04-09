import client from './client';
import type { ReconciliationRun, PaginatedResponse } from '@/types';

export async function runReconciliation(): Promise<ReconciliationRun> {
  const { data } = await client.post<ReconciliationRun>('/reconciliation/run');
  return data;
}

export async function listRuns(params?: {
  page?: number;
  per_page?: number;
  status?: string;
}): Promise<PaginatedResponse<ReconciliationRun>> {
  const { data } = await client.get<PaginatedResponse<ReconciliationRun>>(
    '/reconciliation/runs',
    { params },
  );
  return data;
}

export async function getRun(runId: string): Promise<ReconciliationRun> {
  const { data } = await client.get<ReconciliationRun>(`/reconciliation/runs/${runId}`);
  return data;
}

export async function downloadCsv(runId: string): Promise<Blob> {
  const { data } = await client.get<Blob>(`/reconciliation/runs/${runId}/csv`, {
    responseType: 'blob',
  });
  return data;
}

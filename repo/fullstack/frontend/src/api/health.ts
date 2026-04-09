import client from './client';

export async function checkHealth(): Promise<{
  status: string;
  version: string;
  uptime_seconds: number;
}> {
  const { data } = await client.get<{
    status: string;
    version: string;
    uptime_seconds: number;
  }>('/health');
  return data;
}

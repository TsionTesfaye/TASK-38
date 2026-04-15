import axios from 'axios';
import { sanitizeErrorMessage } from '../utils/formatters';

const client = axios.create({
  baseURL: '/api/v1',
  headers: {
    'Content-Type': 'application/json',
  },
});

let isRefreshing = false;
let failedQueue: Array<{
  resolve: (token: string) => void;
  reject: (error: unknown) => void;
}> = [];

function processQueue(error: unknown, token: string | null) {
  failedQueue.forEach((promise) => {
    if (error) {
      promise.reject(error);
    } else {
      promise.resolve(token!);
    }
  });
  failedQueue = [];
}

client.interceptors.request.use((config) => {
  const token = localStorage.getItem('access_token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// Unwrap backend {data: ...} wrapper so API functions receive the payload directly
client.interceptors.response.use(
  (response) => {
    if (response.data && typeof response.data === 'object' && 'data' in response.data && !('meta' in response.data)) {
      response.data = response.data.data;
    }
    return response;
  },
  async (error) => {
    // Sanitize error messages to strip any raw UUIDs before they reach UI components.
    if (error.response?.data?.message && typeof error.response.data.message === 'string') {
      error.response.data.message = sanitizeErrorMessage(error.response.data.message);
    }

    const originalRequest = error.config;

    if (error.response?.status !== 401 || originalRequest._retry) {
      return Promise.reject(error);
    }

    if (isRefreshing) {
      return new Promise<string>((resolve, reject) => {
        failedQueue.push({ resolve, reject });
      }).then((token) => {
        originalRequest.headers.Authorization = `Bearer ${token}`;
        return client(originalRequest);
      });
    }

    originalRequest._retry = true;
    isRefreshing = true;

    const refreshToken = localStorage.getItem('refresh_token');

    if (!refreshToken) {
      isRefreshing = false;
      localStorage.removeItem('access_token');
      localStorage.removeItem('refresh_token');
      window.location.href = '/login';
      return Promise.reject(error);
    }

    try {
      // Use a bare axios.post (no interceptors, no auth header) so we don't
      // recursively retry through this same interceptor. Construct the URL
      // from the configured baseURL so tests and production both work.
      const refreshUrl = `${client.defaults.baseURL ?? '/api/v1'}/auth/refresh`;
      const { data: responseBody } = await axios.post(refreshUrl, {
        refresh_token: refreshToken,
      });

      const newAccessToken: string = responseBody.data?.access_token ?? responseBody.access_token;
      localStorage.setItem('access_token', newAccessToken);

      processQueue(null, newAccessToken);

      originalRequest.headers.Authorization = `Bearer ${newAccessToken}`;
      return client(originalRequest);
    } catch (refreshError) {
      processQueue(refreshError, null);
      localStorage.removeItem('access_token');
      localStorage.removeItem('refresh_token');
      window.location.href = '/login';
      return Promise.reject(refreshError);
    } finally {
      isRefreshing = false;
    }
  },
);

export default client;

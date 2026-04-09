import { useState, useEffect, useRef, useMemo } from 'react';

interface HoldTimerResult {
  remainingSeconds: number;
  isExpired: boolean;
  formatted: string;
}

export function useHoldTimer(expiresAt: string | null | undefined): HoldTimerResult {
  const calcRemaining = (): number => {
    if (!expiresAt) return 0;
    const diff = Math.floor(
      (new Date(expiresAt).getTime() - Date.now()) / 1000,
    );
    return Math.max(0, diff);
  };

  const [remainingSeconds, setRemainingSeconds] = useState(calcRemaining);
  const intervalRef = useRef<ReturnType<typeof setInterval> | null>(null);

  useEffect(() => {
    setRemainingSeconds(calcRemaining());

    if (intervalRef.current) {
      clearInterval(intervalRef.current);
    }

    if (!expiresAt) return;

    intervalRef.current = setInterval(() => {
      const r = calcRemaining();
      setRemainingSeconds(r);
      if (r <= 0 && intervalRef.current) {
        clearInterval(intervalRef.current);
        intervalRef.current = null;
      }
    }, 1000);

    return () => {
      if (intervalRef.current) {
        clearInterval(intervalRef.current);
        intervalRef.current = null;
      }
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [expiresAt]);

  const isExpired = remainingSeconds <= 0;

  const formatted = useMemo(() => {
    const m = Math.floor(remainingSeconds / 60);
    const s = remainingSeconds % 60;
    return `${m}:${s.toString().padStart(2, '0')}`;
  }, [remainingSeconds]);

  return { remainingSeconds, isExpired, formatted };
}

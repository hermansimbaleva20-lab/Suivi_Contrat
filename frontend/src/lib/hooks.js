import { useCallback, useEffect, useRef, useState } from 'react';
import { api, apiError } from '../api.js';

/** Charge une ressource GET et expose { data, loading, error, reload }. Les paramètres sont relus à chaque changement. */
export function useLoad(url, params = {}, enabled = true) {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(enabled);
  const [error, setError] = useState('');
  const key = JSON.stringify(params);
  const seq = useRef(0);

  const run = useCallback(async () => {
    if (!enabled) return;
    const id = ++seq.current;
    setLoading(true);
    try {
      const res = await api.get(url, { params: JSON.parse(key) });
      if (id === seq.current) { setData(res.data); setError(''); }
    } catch (e) {
      if (id === seq.current) setError(apiError(e));
    } finally {
      if (id === seq.current) setLoading(false);
    }
  }, [url, key, enabled]);

  useEffect(() => { run(); }, [run]);
  return { data, loading, error, reload: run, setData, setError };
}

/** Action d'écriture avec état « en cours » et message d'erreur. */
export function useAction() {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const run = async (fn) => {
    setBusy(true);
    setError('');
    try { return await fn(); } catch (e) { setError(apiError(e)); throw e; } finally { setBusy(false); }
  };
  return { busy, error, setError, run };
}

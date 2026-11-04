import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { apiError, fieldErrors } from '../../api.js';

/** Paramètre d'URL (filtre partageable et conservé au rechargement) ; une valeur vide retire le paramètre. */
export function useParam(name) {
  const [params, setParams] = useSearchParams();
  const set = (v) => {
    const next = new URLSearchParams(params);
    if (v) next.set(name, v); else next.delete(name);
    setParams(next, { replace: true });
  };
  return [params.get(name) || '', set];
}

/** Envoi d'une écriture : message de succès, erreurs par champ (422) ou message général, puis rechargement. */
export function useMutation(reload) {
  const [flash, setFlash] = useState({ type: 'success', text: '' });
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);
  const call = async (fn, onOk) => {
    setBusy(true);
    setErrors({});
    try {
      const r = await fn();
      setFlash({ type: 'success', text: r.data.message });
      onOk?.(r.data);
      await reload();
      return true;
    } catch (e) {
      const fe = fieldErrors(e);
      setErrors(fe);
      if (!Object.keys(fe).length) setFlash({ type: 'danger', text: apiError(e) });
      return false;
    } finally {
      setBusy(false);
    }
  };
  return { flash, setFlash, errors, setErrors, busy, call };
}

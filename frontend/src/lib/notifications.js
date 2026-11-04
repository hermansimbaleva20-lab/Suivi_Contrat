import { useCallback, useEffect, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { api } from '../api.js';

/** Notifications du compte (décompte à valider, saisie du carnet à autoriser, décision) : rechargées à chaque page et chaque minute. */
export function useNotifications() {
  const loc = useLocation();
  const [state, setState] = useState({ notifications: [], unread: 0 });
  // silent : une notification indisponible ne doit jamais renvoyer vers la page d'erreur.
  const load = useCallback(() => api.get('/notifications', { silent: true }).then((r) => setState(r.data)).catch(() => {}), []);
  useEffect(() => { load(); }, [load, loc.pathname]);
  useEffect(() => {
    const t = setInterval(load, 60000);
    return () => clearInterval(t);
  }, [load]);
  return { ...state, reload: load };
}

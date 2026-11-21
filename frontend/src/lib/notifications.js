import { useCallback, useEffect, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { api, DATA_CHANGED } from '../api.js';

/**
 * Notifications du compte (décompte à valider, saisie du carnet à autoriser, demande d'accès, décision).
 * Rechargées à chaque page, chaque minute, au retour sur l'onglet du navigateur, et aussitôt après toute action enregistrée
 * (DATA_CHANGED, api.js) : une notification traitée disparaît du compteur sans actualiser la page.
 */
export function useNotifications() {
  const loc = useLocation();
  const [state, setState] = useState({ notifications: [], unread: 0 });
  // silent : une notification indisponible ne doit jamais renvoyer vers la page d'erreur.
  const load = useCallback(() => api.get('/notifications', { silent: true }).then((r) => setState(r.data)).catch(() => {}), []);
  useEffect(() => { load(); }, [load, loc.pathname]);
  useEffect(() => {
    const t = setInterval(load, 60000);
    // Plusieurs actions coup sur coup (enregistrement en lot) : un seul rechargement, juste après la dernière.
    let pending = null;
    const soon = () => { clearTimeout(pending); pending = setTimeout(load, 60); };
    const visible = () => { if (document.visibilityState === 'visible') soon(); };
    window.addEventListener(DATA_CHANGED, soon);
    document.addEventListener('visibilitychange', visible);
    return () => { clearInterval(t); clearTimeout(pending); window.removeEventListener(DATA_CHANGED, soon); document.removeEventListener('visibilitychange', visible); };
  }, [load]);
  return { ...state, reload: load };
}

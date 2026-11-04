import axios from 'axios';

export const api = axios.create({ baseURL: import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api', headers: { Accept: 'application/json' } });

// Jeton Sanctum, projet (PRISE | PREDIRE) et phase active transmis à chaque requête.
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('prise_token');
  if (token) config.headers.Authorization = `Bearer ${token}`;
  if (!config.headers['X-Project']) config.headers['X-Project'] = localStorage.getItem('prise_project') || 'PRISE';
  const phase = localStorage.getItem('prise_phase');
  if (phase) config.headers['X-Phase'] = phase;
  return config;
});

api.interceptors.response.use(
  (r) => r,
  (error) => {
    if (error?.response?.status === 401 && localStorage.getItem('prise_token')) {
      localStorage.removeItem('prise_token');
      if (!location.pathname.startsWith('/login')) location.href = '/login';
    } else if (!error?.config?.silent && !location.pathname.startsWith('/error') && !['/login', '/register', '/forgot-password', '/reset-password'].includes(location.pathname) && (!error?.response || error.response.status >= 500)) {
      const type = error?.response ? 'server' : 'connection';
      try { sessionStorage.setItem('prise_last_error', error?.message || 'Erreur réseau sans détail supplémentaire.'); sessionStorage.setItem('prise_return_path', location.pathname + location.search); } catch (_) {}
      location.href = `/error?type=${type}`;
    }
    return Promise.reject(error);
  },
);

/** Message lisible à partir d'une erreur axios (validation Laravel incluse). */
export function apiError(error) {
  const d = error?.response?.data;
  if (d?.errors) {
    const first = Object.values(d.errors)[0];
    return Array.isArray(first) ? first[0] : String(first);
  }
  if (d?.message) return d.message;
  if (error?.code === 'ERR_NETWORK') return "Le serveur est injoignable. Vérifiez que l'API Laravel est démarrée.";
  return error?.message || 'Une erreur est survenue.';
}

/** Erreurs de validation par champ : { champ: 'message' } */
export function fieldErrors(error) {
  const e = error?.response?.data?.errors || {};
  const out = {};
  Object.keys(e).forEach((k) => { out[k] = Array.isArray(e[k]) ? e[k][0] : e[k]; });
  return out;
}

export async function download(url, params, fallbackName) {
  const res = await api.get(url, { params, responseType: 'blob' });
  const cd = res.headers['content-disposition'] || '';
  const m = /filename="?([^";]+)"?/i.exec(cd);
  const a = document.createElement('a');
  a.href = URL.createObjectURL(res.data);
  a.download = m ? m[1] : fallbackName;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 4000);
}

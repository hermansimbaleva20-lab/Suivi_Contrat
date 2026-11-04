import React, { createContext, useCallback, useContext, useEffect, useState } from 'react';
import { api } from '../api.js';

const AuthCtx = createContext(null);
export const useAuth = () => useContext(AuthCtx);

export function AuthProvider({ children }) {
  const [state, setState] = useState({ loading: !!localStorage.getItem('prise_token'), me: null });

  const load = useCallback(async ({ throwOnError = false } = {}) => {
    try {
      const { data } = await api.get('/auth/me');
      localStorage.setItem('prise_phase', String(data.phaseId || ''));
      setState({ loading: false, me: data });
      return data;
    } catch (error) {
      localStorage.removeItem('prise_token');
      setState({ loading: false, me: null });
      if (throwOnError) throw error;
      return null;
    }
  }, []);

  useEffect(() => {
    if (localStorage.getItem('prise_token')) load();
    else setState((s) => ({ ...s, loading: false }));
  }, [load]);

  const login = async (email, password, project) => {
    const { data } = await api.post('/auth/login', { email, password, project }, { headers: { 'X-Project': project } });
    localStorage.setItem('prise_token', data.token);
    localStorage.setItem('prise_project', project);
    localStorage.removeItem('prise_phase');
    return load({ throwOnError: true });
  };

  const logout = async () => {
    try { await api.post('/auth/logout'); } catch { /* session déjà expirée */ }
    localStorage.removeItem('prise_token');
    localStorage.removeItem('prise_phase');
    setState({ loading: false, me: null });
  };

  /** Change la phase consultée : toutes les pages rechargent leurs données avec la nouvelle phase. */
  const selectPhase = (id) => {
    localStorage.setItem('prise_phase', String(id));
    window.location.reload();
  };

  const can = (perm) => !!state.me && (state.me.user.isAdmin || !!state.me.user.permissions[perm]);

  return <AuthCtx.Provider value={{ ...state, login, logout, reload: load, selectPhase, can }}>{children}</AuthCtx.Provider>;
}

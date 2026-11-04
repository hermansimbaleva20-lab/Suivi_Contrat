import React, { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '../api.js';
import { frDateTime } from '../lib/format.js';

const ICONS = {
  DECOMPTE_A_VALIDER: 'receipt-cutoff',
  DECOMPTE_DECISION: 'check2-square',
  DECOMPTE_A_PAYER: 'cash-coin',
  CARNET_A_AUTORISER: 'journal-check',
  CARNET_DECISION: 'check2-square',
};
const SEEN_KEY = 'prise_notif_seen';

/** Ouvre une notification : marquée lue, puis la page concernée. */
function useOpenNotification(reload) {
  const nav = useNavigate();
  return async (n) => {
    if (!n.isRead) await api.put(`/notifications/${n.id}/read`, null, { silent: true }).catch(() => {});
    reload();
    if (n.link) nav(n.link);
  };
}

/**
 * Cloche de l'en-tête ; les données viennent de useNotifications (lib/notifications.js), partagées entre bureau et mobile.
 * Quand une notification arrive, la cloche se balance ; tant qu'il en reste à lire, un halo pulse autour du compteur.
 */
export default function NotificationBell({ data, align = 'right' }) {
  const [open, setOpen] = useState(false);
  const [ringing, setRinging] = useState(false);
  const ref = useRef(null);
  const previous = useRef(null);
  const { notifications, unread, reload } = data;
  const openNotification = useOpenNotification(reload);

  useEffect(() => {
    if (previous.current !== null && unread > previous.current) {
      setRinging(true);
      const t = setTimeout(() => setRinging(false), 1200);
      previous.current = unread;
      return () => clearTimeout(t);
    }
    previous.current = unread;
    return undefined;
  }, [unread]);

  useEffect(() => {
    if (!open) return undefined;
    const close = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
    const esc = (e) => { if (e.key === 'Escape') setOpen(false); };
    document.addEventListener('mousedown', close);
    document.addEventListener('keydown', esc);
    return () => { document.removeEventListener('mousedown', close); document.removeEventListener('keydown', esc); };
  }, [open]);

  const go = async (n) => { setOpen(false); await openNotification(n); };
  const readAll = async () => { await api.put('/notifications/read-all', null, { silent: true }).catch(() => {}); reload(); };
  const label = unread ? `Notifications : ${unread} non lue(s)` : 'Notifications';

  return (
    <div className="tw:relative" ref={ref}>
      <button type="button" className={`alert-chip alert-chip-notif ${unread ? 'has-alert' : ''} ${ringing ? 'is-ringing' : ''}`} title={label} aria-label={label} aria-haspopup="true" aria-expanded={open} onClick={() => setOpen((o) => !o)}>
        <i className="bi bi-inbox-fill" />
        {unread > 0 && <span className="chip-badge">{unread > 99 ? '99+' : unread}</span>}
      </button>
      {open && (
        <div className={`notif-panel tw:absolute tw:top-full tw:mt-2 tw:z-50 tw:w-[360px] tw:max-sm:fixed tw:max-sm:inset-x-4 tw:max-sm:top-28 tw:max-sm:w-auto tw:rounded-card tw:border tw:border-prise-border tw:bg-white tw:shadow-card tw:overflow-hidden ${align === 'left' ? 'tw:left-0' : 'tw:right-0'}`} role="dialog" aria-label="Notifications">
          <div className="tw:flex tw:items-center tw:justify-between tw:gap-2 tw:px-4 tw:py-3 tw:border-0 tw:border-b tw:border-prise-border">
            <strong className="tw:font-display tw:text-sm">Notifications{unread > 0 && <span className="tw:ml-2 tw:rounded-full tw:bg-prise-green-soft tw:px-2 tw:py-0.5 tw:font-tech tw:text-[11px] tw:text-prise-green-2">{unread} à lire</span>}</strong>
            {unread > 0 && <button type="button" className="tw:border-0 tw:bg-transparent tw:p-0 tw:text-xs tw:font-semibold tw:text-prise-green tw:cursor-pointer tw:hover:underline" onClick={readAll}>Tout marquer comme lu</button>}
          </div>
          <ul className="tw:m-0 tw:p-0 tw:list-none tw:max-h-[420px] tw:overflow-y-auto">
            {notifications.map((n, i) => (
              <li key={n.id} className="notif-item tw:border-0 tw:border-b tw:border-prise-border tw:last:border-b-0" style={{ '--i': Math.min(i, 10) }}>
                <button type="button" onClick={() => go(n)}
                  className={`tw:flex tw:w-full tw:gap-3 tw:border-0 tw:px-4 tw:py-3 tw:text-left tw:cursor-pointer tw:hover:bg-prise-green-soft ${n.isRead ? 'tw:bg-transparent' : 'tw:bg-[#f3faf6]'}`}>
                  <span className={`notif-icon ${n.isRead ? '' : 'is-unread'}`} aria-hidden="true"><i className={`bi bi-${ICONS[n.kind] || 'bell'}`} /></span>
                  <span className="tw:min-w-0 tw:flex-1">
                    <span className={`tw:block tw:text-sm ${n.isRead ? 'tw:text-prise-muted' : 'tw:font-semibold tw:text-prise-text'}`}>{n.title}</span>
                    {n.body && <span className="tw:block tw:text-xs tw:text-prise-muted tw:mt-0.5 tw:line-clamp-3">{n.body}</span>}
                    <span className="tw:block tw:text-[11px] tw:text-prise-muted tw:mt-1 tw:font-tech">{frDateTime(n.createdAt)}</span>
                  </span>
                  {!n.isRead && <span className="notif-dot" aria-label="Non lue" />}
                </button>
              </li>
            ))}
            {!notifications.length && <li className="tw:px-4 tw:py-6 tw:text-center tw:text-sm tw:text-prise-muted">Aucune notification.</li>}
          </ul>
        </div>
      )}
    </div>
  );
}

/**
 * Bandeau d'arrivée : la plus récente notification non lue que le compte n'a pas encore vue s'annonce en haut à droite,
 * 8 secondes, avec un minuteur ; un clic l'ouvre. Monté une seule fois (Layout), quel que soit l'écran.
 */
export function NotificationToast({ data }) {
  const [toast, setToast] = useState(null);
  const openNotification = useOpenNotification(data.reload);

  useEffect(() => {
    const newest = data.notifications.find((n) => !n.isRead);
    if (!newest) return;
    let seen = 0;
    try { seen = Number(sessionStorage.getItem(SEEN_KEY)) || 0; } catch { /* stockage indisponible */ }
    if (newest.id <= seen) return;
    try { sessionStorage.setItem(SEEN_KEY, String(newest.id)); } catch { /* stockage indisponible */ }
    setToast({ ...newest, others: Math.max(0, data.unread - 1) });
  }, [data.notifications, data.unread]);

  useEffect(() => {
    if (!toast) return undefined;
    const t = setTimeout(() => setToast(null), 8000);
    return () => clearTimeout(t);
  }, [toast]);

  if (!toast) return null;
  return (
    <div className="notif-toast" role="status" aria-live="polite">
      <span className="notif-toast-icon" aria-hidden="true"><i className={`bi bi-${ICONS[toast.kind] || 'bell'}`} /></span>
      <div className="notif-toast-body">
        <strong>{toast.title}</strong>
        {toast.body && <span className="notif-toast-text">{toast.body}</span>}
        {toast.others > 0 && <span className="notif-toast-meta">Et {toast.others} autre(s) notification(s) à lire.</span>}
        <div className="notif-toast-actions">
          <button type="button" className="btn btn-sm btn-primary" onClick={() => { setToast(null); openNotification(toast); }}>Ouvrir</button>
          <button type="button" className="btn btn-sm btn-light" onClick={() => setToast(null)}>Plus tard</button>
        </div>
      </div>
      <button type="button" className="notif-toast-close" aria-label="Fermer" onClick={() => setToast(null)}><i className="bi bi-x-lg" /></button>
      <span className="notif-toast-timer" aria-hidden="true" />
    </div>
  );
}

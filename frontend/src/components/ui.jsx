import React, { useEffect, useState } from 'react';
import { statusClass } from '../lib/format.js';

// « kicker » est conservé dans la signature pour les écrans existants, mais n'est plus affiché : le titre porte seul la hiérarchie.
// eslint-disable-next-line no-unused-vars
export function PageHead({ kicker, title, desc, children }) {
  return (
    <div className="page-head">
      <div>
        <h1>{title}</h1>
        {desc && <div className="page-desc">{desc}</div>}
      </div>
      {children && <div className="d-flex gap-2 flex-wrap align-items-start">{children}</div>}
    </div>
  );
}

export function Notice({ type = 'danger', children, onClose }) {
  if (!children) return null;
  return (
    <div className={`alert alert-${type} d-flex justify-content-between align-items-start gap-2`} role="alert">
      <div>{children}</div>
      {onClose && <button type="button" className="btn-close" aria-label="Fermer" onClick={onClose} />}
    </div>
  );
}

export function Loading({ text = 'Chargement des données…' }) {
  return (
    <div className="cardx p-4 text-center text-secondary">
      <span className="spinner-border spinner-border-sm me-2" role="status" />
      {text}
    </div>
  );
}

export function Empty({ text = 'Aucune donnée à afficher pour le moment.' }) {
  return <div className="empty-state">{text}</div>;
}

function useAnimatedValue(value) {
  const [display, setDisplay] = useState(value);
  useEffect(() => {
    const raw = String(value ?? '');
    const match = raw.match(/^([^\d-]*)(-?\d[\d.,]*)(.*)$/);
    if (!match) { setDisplay(value); return undefined; }
    const [, prefix, numberText, suffix] = match;
    const normalized = numberText.replace(/\s/g, '').replace(/\.(?=.*\.)/g, '').replace(',', '.');
    const target = Number(normalized);
    if (!Number.isFinite(target)) { setDisplay(value); return undefined; }
    const decimals = (numberText.split(/[.,]/)[1] || '').length;
    let frame = 0;
    let start;
    const duration = 850;
    const tick = (now) => {
      if (start === undefined) start = now;
      const progress = Math.min(1, (now - start) / duration);
      const eased = 1 - Math.pow(1 - progress, 3);
      const current = target * eased;
      const formatted = current.toLocaleString('fr-FR', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
      setDisplay(`${prefix}${formatted}${suffix}`);
      if (progress < 1) frame = requestAnimationFrame(tick);
    };
    frame = requestAnimationFrame(tick);
    return () => cancelAnimationFrame(frame);
  }, [value]);
  return display;
}

export function Kpi({ label, value, sub, tone = '' }) {
  const animatedValue = useAnimatedValue(value);
  return (
    <div className={`cardx kpi ${tone}`}>
      <div className="label">{label}</div>
      <div className="value">{animatedValue}</div>
      {sub && <div className="small text-secondary">{sub}</div>}
    </div>
  );
}

export function StatusPill({ status }) {
  return <span className={`status ${statusClass(status)}`}>{status}</span>;
}

export function Progress({ value, danger }) {
  const v = Math.max(0, Math.min(100, Number(value) || 0));
  return (
    <div className="progress-bar-prise">
      <span className={danger ? 'low' : ''} style={{ width: `${v}%` }} />
    </div>
  );
}

export function Pager({ meta, onPage }) {
  if (!meta || meta.pages <= 1) return null;
  return (
    <div className="pager d-flex align-items-center justify-content-between gap-2 flex-wrap mt-3">
      <button className="btn btn-sm btn-light" disabled={meta.page <= 1} onClick={() => onPage(meta.page - 1)}>
        <i className="bi bi-chevron-left" /> Précédent
      </button>
      <span className="small text-secondary">Page {meta.page} sur {meta.pages} · {meta.total} ligne(s)</span>
      <button className="btn btn-sm btn-light" disabled={meta.page >= meta.pages} onClick={() => onPage(meta.page + 1)}>
        Suivant <i className="bi bi-chevron-right" />
      </button>
    </div>
  );
}

export function Modal({ title, subtitle, onClose, children, footer, size = 'lg' }) {
  useEffect(() => {
    document.body.classList.add('modal-open');
    const onKey = (e) => e.key === 'Escape' && onClose?.();
    window.addEventListener('keydown', onKey);
    return () => { document.body.classList.remove('modal-open'); window.removeEventListener('keydown', onKey); };
  }, [onClose]);
  return (
    <>
      <div className="modal fade show d-block" tabIndex="-1" role="dialog" aria-modal="true" onMouseDown={(e) => e.target === e.currentTarget && onClose?.()}>
        <div className={`modal-dialog modal-${size} modal-dialog-centered modal-dialog-scrollable`}>
          <div className="modal-content prise-modal">
            <div className="modal-header">
              <div>
                <h5 className="modal-title">{title}</h5>
                {subtitle && <div className="small text-secondary">{subtitle}</div>}
              </div>
              <button type="button" className="btn-close" aria-label="Fermer" onClick={onClose} />
            </div>
            <div className="modal-body">{children}</div>
            {footer && <div className="modal-footer">{footer}</div>}
          </div>
        </div>
      </div>
      <div className="modal-backdrop fade show" />
    </>
  );
}

/** Saisie décimale : accepte la virgule ou le point ; la valeur reste une chaîne (le serveur la lit de façon tolérante). */
export function DecimalInput({ value, onChange, ...props }) {
  return (
    <input
      type="text"
      inputMode="decimal"
      autoComplete="off"
      className={`form-control ${props.className || ''}`}
      value={value ?? ''}
      onChange={(e) => onChange(e.target.value.replace(/[^\d\s.,-]/g, ''))}
      {...props}
    />
  );
}

export function Field({ label, error, children, className = '' }) {
  return (
    <div className={className}>
      {label && <label className="form-label">{label}</label>}
      {children}
      {error && <div className="text-danger small mt-1">{error}</div>}
    </div>
  );
}

/** Confirmation avec motif obligatoire (suppression, résiliation…). */
export function ReasonDialog({ title, text, confirmLabel = 'Confirmer', danger = true, minLength = 5, onConfirm, onClose, extra }) {
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const submit = async (e) => {
    e.preventDefault();
    if (reason.trim().length < minLength) { setError(`Une raison valable (minimum ${minLength} caractères) est obligatoire.`); return; }
    setBusy(true);
    try { await onConfirm(reason.trim()); } catch (err) { setError(err?.message || 'Opération impossible.'); setBusy(false); }
  };
  return (
    <Modal
      title={title}
      size="md"
      onClose={onClose}
      footer={
        <>
          <button type="button" className="btn btn-light" onClick={onClose}>Annuler</button>
          <button type="submit" form="reason-form" className={`btn ${danger ? 'btn-danger' : 'btn-primary'}`} disabled={busy}>{confirmLabel}</button>
        </>
      }
    >
      <form id="reason-form" onSubmit={submit}>
        {text && <p className="text-secondary">{text}</p>}
        {extra}
        <Field label="Motif" error={error}>
          <textarea className="form-control" rows="3" value={reason} onChange={(e) => setReason(e.target.value)} autoFocus placeholder="Expliquez brièvement la raison de cette opération…" />
        </Field>
      </form>
    </Modal>
  );
}

export const SELECT_NONE = '';

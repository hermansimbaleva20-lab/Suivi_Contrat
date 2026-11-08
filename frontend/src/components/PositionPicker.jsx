import React, { useEffect, useId, useRef, useState } from 'react';

/*
 * Choix du poste dans la demande d'accès : la description du poste s'affiche quand le curseur passe sur son intitulé
 * (ou quand on le parcourt au clavier). Une liste <select> native ne le permet pas : Chrome et Edge n'affichent pas
 * d'info-bulle sur ses options. Modèle « combobox en lecture seule » du WAI-ARIA : le focus reste sur le bouton,
 * l'option active est annoncée par aria-activedescendant.
 */
const NO_DESCRIPTION = 'Aucune description n’a été renseignée pour ce poste.';

export function PositionPicker({ positions, value, onChange, disabled, placeholder, invalid }) {
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(-1);
  const root = useRef(null);
  const list = useRef(null);
  const id = useId();
  const selected = positions.find((p) => String(p.id) === String(value)) || null;
  const current = active >= 0 ? positions[active] : null;

  // Fermeture au clic en dehors.
  useEffect(() => {
    if (!open) return undefined;
    const away = (e) => { if (!root.current?.contains(e.target)) setOpen(false); };
    document.addEventListener('mousedown', away);
    return () => document.removeEventListener('mousedown', away);
  }, [open]);

  // L'option active reste visible quand on la parcourt au clavier.
  useEffect(() => {
    if (open && active >= 0) list.current?.querySelector(`[data-index="${active}"]`)?.scrollIntoView({ block: 'nearest' });
  }, [open, active]);

  const show = () => {
    if (disabled || !positions.length) return;
    setActive(Math.max(0, positions.findIndex((p) => String(p.id) === String(value))));
    setOpen(true);
  };
  const choose = (i) => { onChange(String(positions[i].id)); setOpen(false); };
  const onKeyDown = (e) => {
    if (!open) {
      if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(e.key)) { e.preventDefault(); show(); }
      return;
    }
    const last = positions.length - 1;
    const moves = { ArrowDown: Math.min(last, active + 1), ArrowUp: Math.max(0, active - 1), Home: 0, End: last, PageDown: Math.min(last, active + 5), PageUp: Math.max(0, active - 5) };
    if (e.key in moves) { e.preventDefault(); setActive(moves[e.key]); } else if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); if (active >= 0) choose(active); } else if (e.key === 'Escape') { e.preventDefault(); setOpen(false); } else if (e.key === 'Tab') setOpen(false);
  };

  return (
    <div className="pos-picker" ref={root}>
      <button type="button" id={`${id}-button`} className={`form-select pos-picker-button${invalid ? ' is-invalid' : ''}`} disabled={disabled}
        role="combobox" aria-haspopup="listbox" aria-expanded={open} aria-controls={`${id}-list`} aria-labelledby={`reg-position-label ${id}-button`}
        aria-activedescendant={open && active >= 0 ? `${id}-opt-${active}` : undefined} aria-describedby={selected?.description && !open ? `${id}-chosen` : undefined}
        title={selected ? selected.description || undefined : undefined}
        onClick={() => (open ? setOpen(false) : show())} onKeyDown={onKeyDown}>
        <span className={selected ? '' : 'pos-picker-placeholder'}>{selected ? selected.name : placeholder}</span>
      </button>
      {open && (
        <div className="pos-picker-panel">
          <ul id={`${id}-list`} ref={list} role="listbox" aria-labelledby="reg-position-label" className="pos-picker-list" onMouseLeave={() => setActive(Math.max(0, positions.findIndex((p) => String(p.id) === String(value))))}>
            {positions.map((p, i) => (
              <li key={p.id} id={`${id}-opt-${i}`} data-index={i} role="option" aria-selected={String(p.id) === String(value)}
                className={`pos-picker-option${i === active ? ' is-active' : ''}`} title={p.description || undefined}
                onMouseEnter={() => setActive(i)} onMouseDown={(e) => e.preventDefault()} onClick={() => choose(i)}>
                <span>{p.name}</span>
                {p.description && <i className="bi bi-info-circle" aria-hidden="true" />}
                {String(p.id) === String(value) && <i className="bi bi-check2 pos-picker-check" aria-hidden="true" />}
              </li>
            ))}
          </ul>
          {/* Description du poste survolé ; lue par les lecteurs d'écran quand elle change. */}
          <div className="pos-picker-desc" aria-live="polite">
            {current ? (
              <>
                <strong>{current.name}</strong>
                <span className={current.description ? '' : 'pos-picker-empty'}>{current.description || NO_DESCRIPTION}</span>
              </>
            ) : <span className="pos-picker-empty">Survolez un poste pour lire sa description.</span>}
          </div>
        </div>
      )}
      {selected?.description && !open && <p id={`${id}-chosen`} className="pos-picker-chosen">{selected.description}</p>}
    </div>
  );
}

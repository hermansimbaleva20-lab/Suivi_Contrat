import React, { useEffect, useRef, useState } from 'react';
import { THEME_CHOICES, setThemeChoice, themeChoice, useResolvedTheme } from '../lib/theme.js';

/** Choix du thème dans l'en-tête : Clair, Sombre ou Automatique (réglage de l'ordinateur). */
export default function ThemeSwitch({ align = 'right' }) {
  const [open, setOpen] = useState(false);
  const [choice, setChoice] = useState(themeChoice);
  const theme = useResolvedTheme();
  const ref = useRef(null);

  useEffect(() => {
    if (!open) return undefined;
    const close = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
    const esc = (e) => { if (e.key === 'Escape') setOpen(false); };
    document.addEventListener('mousedown', close);
    document.addEventListener('keydown', esc);
    return () => { document.removeEventListener('mousedown', close); document.removeEventListener('keydown', esc); };
  }, [open]);

  const pick = (value) => { setThemeChoice(value); setChoice(value); setOpen(false); };
  const label = `Thème : ${THEME_CHOICES.find((c) => c.value === choice)?.label.toLowerCase()}`;

  return (
    <div className="tw:relative" ref={ref}>
      <button type="button" className="alert-chip theme-chip" title={label} aria-label={label} aria-haspopup="menu" aria-expanded={open} onClick={() => setOpen((o) => !o)}>
        <i className={`bi ${theme === 'dark' ? 'bi-moon-stars' : 'bi-sun'}`} aria-hidden="true" />
      </button>
      {open && (
        <div className={`theme-menu tw:absolute tw:top-full tw:mt-2 tw:z-50 tw:w-56 tw:rounded-card tw:border tw:border-prise-border tw:bg-white tw:shadow-card tw:p-1.5 ${align === 'left' ? 'tw:left-0' : 'tw:right-0'}`} role="menu" aria-label="Thème de l’interface">
          {THEME_CHOICES.map((c) => (
            <button key={c.value} type="button" role="menuitemradio" aria-checked={choice === c.value} onClick={() => pick(c.value)}
              className={`theme-option tw:flex tw:w-full tw:items-center tw:gap-3 tw:rounded-lg tw:border-0 tw:px-3 tw:py-2 tw:text-left tw:text-sm tw:cursor-pointer tw:hover:bg-prise-green-soft ${choice === c.value ? 'is-active tw:bg-prise-green-soft tw:font-semibold tw:text-prise-green-2' : 'tw:bg-transparent tw:text-prise-text'}`}>
              <i className={`bi ${c.icon} tw:text-base`} aria-hidden="true" />
              <span className="tw:flex-1">{c.label}{c.hint && <span className="tw:block tw:text-[11px] tw:font-normal tw:text-prise-muted">{c.hint}</span>}</span>
              {choice === c.value && <i className="bi bi-check2" aria-hidden="true" />}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}

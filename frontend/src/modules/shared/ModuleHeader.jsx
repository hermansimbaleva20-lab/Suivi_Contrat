import React from 'react';
import { NavLink } from 'react-router-dom';

/*
 * En-tête des modules (Logistique : stock, charroi, groupes électrogènes) : icône et titre du module, unité de travail,
 * actions principales, puis sous-navigation par onglets (liens d'URL : retour arrière et liens partageables conservés).
 */
export function ModuleHeader({ icon, title, desc, unit, actions, tabs }) {
  return (
    <header className="tw:mb-5 tw:print:hidden">
      <div className="tw:flex tw:flex-wrap tw:items-start tw:justify-between tw:gap-x-6 tw:gap-y-3">
        <div className="tw:flex tw:min-w-0 tw:items-start tw:gap-3">
          {icon && (
            <span className="tw:mt-1 tw:grid tw:size-10 tw:shrink-0 tw:place-items-center tw:rounded-xl tw:bg-prise-green tw:text-lg tw:text-white" aria-hidden="true">
              <i className={`bi bi-${icon}`} />
            </span>
          )}
          <div className="tw:min-w-0">
            <div className="tw:flex tw:flex-wrap tw:items-center tw:gap-x-3 tw:gap-y-1">
              <h1 className="tw:m-0 tw:font-display tw:text-[26px] tw:font-bold tw:leading-tight tw:text-prise-blue tw:text-balance">{title}</h1>
              {unit && (
                <span className="tw:inline-flex tw:items-center tw:gap-1.5 tw:rounded-full tw:border tw:border-prise-border tw:bg-white tw:px-2.5 tw:py-0.5 tw:text-xs tw:font-semibold tw:text-prise-text">
                  <i className="bi bi-geo-alt-fill tw:text-prise-green-2" aria-hidden="true" />{unit}
                </span>
              )}
            </div>
            {desc && <p className="tw:m-0 tw:mt-1 tw:max-w-3xl tw:text-sm tw:text-prise-muted">{desc}</p>}
          </div>
        </div>
        {actions && <div className="tw:flex tw:flex-wrap tw:items-center tw:gap-2">{actions}</div>}
      </div>
      {tabs && (
        <nav aria-label={`Rubriques — ${title}`} className="tw:mt-4 tw:overflow-x-auto tw:overflow-y-hidden tw:border-b tw:border-prise-border">
          <ul className="tw:m-0 tw:flex tw:min-w-max tw:list-none tw:gap-1 tw:p-0">
            {tabs.map((t) => (
              <li key={t.to}>
                <NavLink
                  to={t.to}
                  end={!!t.end}
                  className={({ isActive }) => `tw:relative tw:flex tw:items-center tw:gap-2 tw:px-3.5 tw:py-2.5 tw:text-sm tw:font-semibold tw:no-underline tw:transition-colors tw:duration-150 tw:focus-visible:outline-2 tw:focus-visible:-outline-offset-2 tw:focus-visible:outline-prise-green-2 ${
                    isActive
                      ? 'tw:text-prise-green tw:after:absolute tw:after:inset-x-2 tw:after:bottom-0 tw:after:h-0.5 tw:after:rounded-full tw:after:bg-prise-green-2'
                      : 'tw:text-prise-muted tw:hover:text-prise-text'}`}
                >
                  {t.icon && <i className={`bi bi-${t.icon}`} aria-hidden="true" />}
                  {t.label}
                  {t.badge > 0 && <span className="tw:rounded-full tw:bg-prise-danger tw:px-1.5 tw:text-[11px] tw:font-bold tw:text-white tw:tabular-nums">{t.badge}</span>}
                </NavLink>
              </li>
            ))}
          </ul>
        </nav>
      )}
    </header>
  );
}

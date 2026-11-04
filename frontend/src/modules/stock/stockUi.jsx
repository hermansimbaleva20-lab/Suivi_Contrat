import React from 'react';

const LEVELS = {
  OUT: ['Rupture', 'tw:bg-red-50 tw:text-prise-danger'],
  LOW: ['À réapprovisionner', 'tw:bg-amber-50 tw:text-amber-800'],
  EMPTY: ['Jamais approvisionné', 'tw:bg-slate-100 tw:text-slate-600'],
};

export function LevelPill({ level }) {
  const l = LEVELS[level];
  if (!l) return null;
  return <span className={`tw:inline-block tw:whitespace-nowrap tw:rounded-full tw:px-2 tw:py-0.5 tw:text-[11px] tw:font-bold ${l[1]}`}>{l[0]}</span>;
}

/** En-tête de colonne de tableau, au style des documents du projet (majuscules discrètes). */
export const th = 'tw:border-b tw:border-prise-border tw:bg-prise-bg tw:px-3 tw:py-2.5 tw:text-left tw:font-tech tw:text-[11px] tw:font-semibold tw:uppercase tw:tracking-wide tw:text-prise-muted tw:whitespace-nowrap';
export const td = 'tw:border-b tw:border-prise-border tw:px-3 tw:py-2.5 tw:align-middle tw:text-sm tw:text-prise-text';
export const tdNum = `${td} tw:text-right tw:font-tech tw:tabular-nums tw:whitespace-nowrap`;

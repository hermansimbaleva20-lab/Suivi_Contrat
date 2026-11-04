import React from 'react';

/*
 * Classes Tailwind partagées par les écrans des modules (préfixe tw:, sans preflight).
 * Pas de preflight : la bordure et le fond par défaut des boutons du navigateur sont retirés explicitement.
 */
export const btn = 'tw:inline-flex tw:cursor-pointer tw:items-center tw:border-0 tw:whitespace-nowrap tw:gap-1.5 tw:rounded-lg tw:px-3 tw:py-1.5 tw:text-[13px] tw:font-semibold tw:font-tech tw:no-underline tw:transition-colors tw:duration-150 tw:focus-visible:outline-2 tw:focus-visible:outline-offset-2 tw:focus-visible:outline-prise-green-2 tw:disabled:opacity-50 tw:disabled:cursor-not-allowed tw:print:hidden';
export const btnPrimary = `${btn} tw:bg-prise-green-2 tw:text-white tw:hover:bg-prise-green tw:hover:text-white`;
export const btnGhost = `${btn} tw:bg-transparent tw:text-prise-text tw:hover:bg-prise-green-soft`;
export const btnDanger = `${btn} tw:bg-transparent tw:text-prise-danger tw:hover:bg-red-50`;
export const input = 'tw:w-full tw:rounded-lg tw:border tw:border-prise-border tw:bg-white tw:px-3 tw:py-2 tw:text-sm tw:text-prise-text tw:placeholder:text-prise-muted tw:focus:border-prise-green-2 tw:focus:outline-none tw:focus:ring-3 tw:focus:ring-prise-green-2/15';
export const label = 'tw:mb-1 tw:block tw:text-xs tw:font-semibold tw:text-prise-muted';
export const panel = 'tw:rounded-card tw:border tw:border-prise-border tw:bg-white tw:shadow-card';

export function FieldError({ text }) {
  return text ? <p className="tw:mt-1 tw:mb-0 tw:text-xs tw:text-prise-danger">{text}</p> : null;
}

import React from 'react';

/*
 * Mention de consultation : un compte à vue nationale (désigné par le super-admin) voit la logistique d'une autre unité
 * sans pouvoir y ajouter ni modifier quoi que ce soit ; ses opérations se font dans sa propre unité.
 */
export function ReadOnlyNote({ unitName, children }) {
  return (
    <p className="tw:m-0 tw:mb-4 tw:flex tw:items-start tw:gap-2 tw:rounded-lg tw:border tw:border-prise-border tw:bg-white tw:px-4 tw:py-2.5 tw:text-sm tw:text-prise-muted tw:print:hidden" role="note">
      <i className="bi bi-eye tw:mt-0.5 tw:text-prise-green-2" aria-hidden="true" />
      <span>
        <strong className="tw:text-prise-text">Lecture seule{unitName ? ` — ${unitName}` : ''}.</strong>{' '}
        {children || 'Vous consultez la logistique d’une autre unité : aucun ajout ni aucune modification n’y est possible. Vos opérations se font dans votre propre unité.'}
      </span>
    </p>
  );
}

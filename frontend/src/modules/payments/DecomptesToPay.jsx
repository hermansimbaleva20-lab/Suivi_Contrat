import React from 'react';
import { fr, money } from '../../lib/format.js';
import { btnPrimary, panel } from '../shared/tw.jsx';

/*
 * Message au gestionnaire des paiements : chaque décompte validé mérite le paiement de la facture, au montant de la colonne
 * MONTANT PAYÉ de sa ligne. « Renseigner le paiement » ouvre le formulaire de la page, montant déjà repris. Le message
 * disparaît une fois le paiement enregistré.
 */
export default function DecomptesToPay({ items, activeId, onPay }) {
  if (!items.length) return null;
  const total = items.reduce((a, x) => a + Number(x.amount), 0);
  return (
    <section className={`${panel} tw:mb-4 tw:print:hidden`} aria-labelledby="dp-title">
      <header className="tw:flex tw:flex-wrap tw:items-baseline tw:justify-between tw:gap-x-4 tw:gap-y-1 tw:border-b tw:border-prise-border tw:px-5 tw:py-3.5">
        <h2 id="dp-title" className="tw:m-0 tw:font-display tw:text-[17px] tw:font-bold tw:text-prise-blue">
          {items.length > 1 ? `${items.length} décomptes validés à payer` : 'Un décompte validé à payer'}
        </h2>
        {items.length > 1 && <p className="tw:m-0 tw:text-sm tw:text-prise-muted">Total : <strong className="tw:font-tech tw:font-semibold tw:tabular-nums tw:text-prise-text">{money(total)} USD</strong></p>}
      </header>
      <ul className="tw:m-0 tw:list-none tw:p-0">
        {items.map((x) => (
          <li key={x.id} className={`tw:flex tw:flex-wrap tw:items-center tw:gap-x-4 tw:gap-y-2 tw:border-b tw:border-prise-border tw:px-5 tw:py-3 tw:last:border-b-0 ${activeId === x.id ? 'tw:bg-prise-green-soft' : ''}`}>
            <span className="tw:grid tw:size-9 tw:shrink-0 tw:place-items-center tw:rounded-full tw:bg-prise-green-soft tw:text-prise-green-2" aria-hidden="true"><i className="bi bi-cash-coin" /></span>
            <div className="tw:min-w-60 tw:flex-1">
              <p className="tw:m-0 tw:text-[15px] tw:leading-snug tw:text-prise-text">
                {x.kind === 'LIBERATION' ? 'La ligne RETENUE' : <>Le <strong className="tw:font-tech tw:font-semibold">{x.number}</strong></>} du contrat <strong className="tw:font-tech tw:font-semibold">{x.contractNumber}</strong> ({x.company}) est validé : il mérite le paiement de la facture de{' '}
                <strong className="tw:font-tech tw:font-semibold tw:tabular-nums tw:whitespace-nowrap">{money(x.amount)} USD</strong>.
              </p>
              <p className="tw:m-0 tw:mt-0.5 tw:text-xs tw:text-prise-muted">Validé{x.decisionDate ? ` le ${fr(x.decisionDate)}` : ''}{x.decidedBy ? ` par ${x.decidedBy}` : ''}. Montant repris de la colonne MONTANT PAYÉ du décompte.</p>
            </div>
            <button type="button" className={btnPrimary} aria-pressed={activeId === x.id} onClick={() => onPay(x)}><i className="bi bi-pencil-square" aria-hidden="true" />Renseigner le paiement</button>
          </li>
        ))}
      </ul>
    </section>
  );
}

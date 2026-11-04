import { fr, money, num } from '../../lib/format.js';

/** Situation de l'avance sur demande en une phrase : page Paiements et fiche du contrat. */
export function advanceSituation(c) {
  const a = c.advanceRequest;
  if (!a && !c.initialFinancingRate) return 'Aucune avance de démarrage prévue pour ce contrat.';
  if (!a) return `Non demandée : ${num(c.initialFinancingRate, 2)} % du marché, soit ${money(c.advanceDue)} USD, sur demande de l’entreprise.`;
  if (a.status === 'PAYEE') return `Payée le ${fr(a.paidDate)} : ${money(a.amount)} USD (réf. ${a.paymentReference}).`;
  return `Demandée le ${fr(a.requestDate)} (${a.requestReference}) : ${money(a.amount)} USD à payer.`;
}

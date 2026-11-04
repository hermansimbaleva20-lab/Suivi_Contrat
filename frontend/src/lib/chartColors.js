/** Couleurs des indicateurs dans les histogrammes ; « overpaid » signale un paiement en avance sur l'avancement physique. */
export const COLORS = { progress: '#087a3f', days: '#d98900', payment: '#2f6db3', overpaid: '#c62828', guarantee: '#7a5bb5', before: '#9bb8aa', after: '#087a3f' };

/** Taux de paiement en rouge lorsqu'il dépasse l'avancement physique, dans sa couleur sinon. */
export const paymentColor = (payment, progress) => (Number(payment) > Number(progress) ? COLORS.overpaid : COLORS.payment);

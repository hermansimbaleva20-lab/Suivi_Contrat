/*
 * Sous-options de « Finance » : [libellé, chemin, icône, visibilité(can)]. L'option principale (/finance) ouvre la première
 * sous-option accessible ; le menu et les routes partagent cette liste.
 */
export const FINANCE = [
  ['Paiements', '/payments', 'cash-stack', (c) => c('canManagePayments') || c('canViewSituation')],
  ['Décomptes', '/decomptes', 'receipt-cutoff', (c) => c('canManagePayments') || c('canValidateDecomptes') || c('canViewSituation')],
  ['Garanties', '/guarantees', 'shield-check', (c) => c('canManageGuarantees')],
];

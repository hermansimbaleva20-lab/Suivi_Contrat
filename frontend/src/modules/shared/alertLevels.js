/** Niveau d'alerte d'un article d'après son état de stock. */
export const stockAlertLevel = (level) => ({ OUT: 'danger', LOW: 'warning' }[level] || null);

/** Niveau d'alerte le plus grave d'un engin d'après la liste des alertes du parc. */
export const assetAlertLevel = (alerts, assetId) => {
  const own = alerts.filter((a) => a.assetId === assetId);
  if (!own.length) return null;
  return own.some((a) => a.level === 'danger') ? 'danger' : 'warning';
};

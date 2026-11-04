const nf = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 2 });

/** Nombre sans zéros inutiles : 120 / 5,4 / 31,25. */
export const q = (n) => (n === null || n === undefined || n === '' ? '—' : nf.format(Number(n)));

/** Engins actifs des unités où l'utilisateur peut saisir (proposés dans les formulaires). */
export const writableAssets = (data) => data.assets.filter((a) => a.isActive && data.writableUnitIds.includes(a.unitId));

export const meterUnit = (kind) => (kind === 'GROUPE' ? 'h' : 'km');
export const kindLabel = (kind) => (kind === 'GROUPE' ? 'Groupe électrogène' : 'Véhicule');

/** Repères du réservoir proposés à la saisie (« quantité avant départ »), comme sur le carnet papier. */
export const FUEL_PRESETS = [['Plein', 1], ['¾', 0.75], ['½', 0.5], ['¼', 0.25], ['⅕', 0.2]];

/** « ½ · 60 L » si la quantité correspond à un repère du réservoir, sinon « 42 L ». */
export function levelLabel(litres, capacity) {
  if (litres === null || litres === undefined) return '—';
  const p = FUEL_PRESETS.find(([, f]) => Math.abs(f * capacity - litres) < 0.01);
  return p ? `${p[0]} · ${q(litres)} L` : `${q(litres)} L`;
}

const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
export const monthLabel = (ym) => (ym ? `${MONTHS[Number(ym.slice(5, 7)) - 1]} ${ym.slice(0, 4)}` : '');

/** Lecture tolérante d'une saisie décimale (virgule ou point) pour les aperçus de calcul ; NaN si vide. */
export const dec = (v) => (v === null || v === undefined || String(v).trim() === '' ? NaN : Number(String(v).replace(/\s/g, '').replace(',', '.')));

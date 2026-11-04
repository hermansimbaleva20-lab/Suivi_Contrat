export const money = (n) => new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(n || 0));
export const num = (n, d = 0) => new Intl.NumberFormat('fr-FR', { minimumFractionDigits: d, maximumFractionDigits: d }).format(Number(n || 0));
export const pct = (n, d = 0) => `${num(n, d)} %`;

export function fr(date) {
  if (!date) return '—';
  const s = String(date).slice(0, 10);
  return /^\d{4}-\d{2}-\d{2}$/.test(s) ? `${s.slice(8, 10)}/${s.slice(5, 7)}/${s.slice(0, 4)}` : s;
}
export const frDateTime = (v) => (v ? `${fr(v)} ${String(v).slice(11, 16)}` : '—');

/** Comme Dates::addMonths côté serveur : le jour est ramené à la fin du mois si nécessaire (31 janv. + 1 mois = 28/29 févr.). */
export function addMonths(ymd, n) {
  const [y, m, d] = String(ymd).slice(0, 10).split('-').map(Number);
  const total = y * 12 + (m - 1) + n;
  const ny = Math.floor(total / 12);
  const nm = total - ny * 12 + 1;
  const dim = new Date(Date.UTC(ny, nm, 0)).getUTCDate();
  return `${String(ny).padStart(4, '0')}-${String(nm).padStart(2, '0')}-${String(Math.min(d, dim)).padStart(2, '0')}`;
}

export const todayISO = () => new Date().toISOString().slice(0, 10);

/** Lecture tolérante d'un nombre saisi avec virgule ou point (1500,75 / 1 500,75 / 1.500,75 / 1,500.75). */
export function parseDecimal(raw) {
  if (raw === null || raw === undefined) return NaN;
  let t = String(raw).replace(/[\s\u00A0\u202F'’]/g, '');
  if (!t) return NaN;
  const c = t.lastIndexOf(','), d = t.lastIndexOf('.');
  if (c >= 0 && d >= 0) t = c > d ? t.replace(/\./g, '').replace(',', '.') : t.replace(/,/g, '');
  else if (c >= 0) t = t.indexOf(',') !== c ? t.replace(/,/g, '') : t.replace(',', '.');
  else if (d >= 0 && t.indexOf('.') !== d) t = t.replace(/\./g, '');
  return /^-?\d*\.?\d+$|^-?\d+\.$/.test(t) ? parseFloat(t) : NaN;
}

export const statusClass = (s) => ({ Normal: 'status-normal', Vigilance: 'status-vigilance', Retard: 'status-retard', 'Terminé': 'status-termine', 'Résilié': 'status-termine' }[s] || 'status-normal');

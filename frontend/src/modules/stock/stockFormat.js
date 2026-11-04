import { num } from '../../lib/format.js';

/** Quantité : entière sans décimales (16), sinon deux décimales (5,50), comme sur les fiches papier. */
export const qty = (n) => num(n, Number.isInteger(Number(n)) ? 0 : 2);

/** Équivalence du colisage : 41 cartons de 12 pièces → « = 492 pièces ». */
export const packLabel = (stock, a) => (a.packSize ? `= ${qty(Math.round(stock * a.packSize * 100) / 100)} ${a.packUnit}` : '');

/** N° de fiche de stock sur trois chiffres : 1 → 001. */
export const cardNo = (n) => String(n).padStart(3, '0');

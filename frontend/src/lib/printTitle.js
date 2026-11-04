const ROMAN = [[10, 'X'], [9, 'IX'], [5, 'V'], [4, 'IV'], [1, 'I']];

const roman = (n) => {
  let out = '';
  let rest = n;
  for (const [v, s] of ROMAN) while (rest >= v) { out += s; rest -= v; }
  return out;
};

/**
 * Intitulé imprimé du projet : son code, suivi du numéro de la phase consultée en chiffres romains à partir de la
 * 2e phase (PRISE, phase 2 → « PRISE II » ; PREDIRE, phase 1 → « PREDIRE »).
 */
export function printTitle(me) {
  const code = me?.project?.code || '';
  const phase = (me?.phases || []).find((p) => p.id === me?.phaseId);
  const n = Number(phase?.number) || 1;
  return n > 1 ? `${code} ${roman(n)}` : code;
}

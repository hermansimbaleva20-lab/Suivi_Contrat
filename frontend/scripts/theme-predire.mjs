// Génère src/styles/theme-predire.css : l'espace PREDIRE prend un turquoise professionnel (décision du 20/11/2026, auparavant le
// bleu de son logo #249bcf) à la place du vert de PRISE.
//   node scripts/theme-predire.mjs     (à relancer quand les feuilles de style changent)
//
// Méthode : chaque déclaration de couleur verte des feuilles de style (vert de marque, mais aussi les gris teintés de vert des
// fonds et filets) est reprise pour PREDIRE avec la même luminosité et la teinte du bleu du logo ; les contrastes restent ceux
// du vert d'origine. Les couleurs qui portent un sens (statuts « Normal », succès, terminé…), les documents imprimés et la page
// de connexion ne changent pas. Le thème clair et le thème sombre sont repris chacun dans leur cadre, sans déborder l'un sur
// l'autre ; la spécificité est celle de la règle d'origine (:where) : theme-predire.css, chargé en dernier, l'emporte.
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import postcss from 'postcss';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', 'src', 'styles');
const LIGHT = ['prise-ui.css', 'prise-v19.css', 'prise-react.css', 'prise-design.css'];
const DARK = ['theme-sombre.css'];
const P = '[data-project="predire"]';

// Teinte turquoise (≈ 182°, décision du 20/11/2026 ; 199° pour l'ancien bleu du logo).
const TARGET_HUE = 182;
// Sens conservé : statuts, succès, impressions, connexion, synthèse imprimable.
const KEEP_SELECTOR = /status-|sev-|kind-|gm-|success|is-done|\.done|normal|termine|satisf|\.ok\b|valid|\.syn|syn-|pdoc|exe-print|print|login|auth-|alert-chip-notif|notif|province-legend|prise-map|leaflet|chart|pie3d|temp-password/i;
const KEEP_PROP = /^--(tint-green|tint-green-strong|text-green|bs-success|chart-|pie-|prise-success|color-prise-success|tw-color-prise-success)/;

const toHsl = (r, g, b) => {
  r /= 255; g /= 255; b /= 255;
  const max = Math.max(r, g, b), min = Math.min(r, g, b), l = (max + min) / 2;
  if (max === min) return [0, 0, l];
  const d = max - min, s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
  const h = max === r ? (g - b) / d + (g < b ? 6 : 0) : max === g ? (b - r) / d + 2 : (r - g) / d + 4;
  return [h * 60, s, l];
};
const toRgb = (h, s, l) => {
  const k = (n) => (n + h / 30) % 12, a = s * Math.min(l, 1 - l);
  const f = (n) => l - a * Math.max(-1, Math.min(k(n) - 3, Math.min(9 - k(n), 1)));
  return [f(0), f(8), f(4)].map((v) => Math.round(v * 255));
};
// Vert (y compris les gris teintés de vert) → bleu du logo, même luminosité.
function mapRgb(r, g, b) {
  const [h, s, l] = toHsl(r, g, b);
  if (h < 80 || h > 182 || s < 0.06) return null;
  return toRgb(TARGET_HUE + (h - 150) * 0.15, Math.min(1, s * 1.04), l);
}
const hex = ([r, g, b]) => `#${[r, g, b].map((v) => v.toString(16).padStart(2, '0')).join('')}`;

function mapValue(value, prop) {
  let changed = false;
  let out = value.replace(/#([0-9a-f]{6}|[0-9a-f]{3})\b/gi, (m, h) => {
    const full = h.length === 3 ? h.split('').map((x) => x + x).join('') : h;
    const v = mapRgb(...[0, 2, 4].map((i) => parseInt(full.slice(i, i + 2), 16)));
    if (!v) return m;
    changed = true;
    return hex(v);
  });
  out = out.replace(/%23([0-9a-f]{6})\b/gi, (m, h) => {
    const v = mapRgb(...[0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16)));
    if (!v) return m;
    changed = true;
    return `%23${hex(v).slice(1)}`;
  });
  out = out.replace(/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*(,\s*[\d.]+\s*)?\)/gi, (m, r, g, b, a) => {
    const v = mapRgb(+r, +g, +b);
    if (!v) return m;
    changed = true;
    return a ? `rgba(${v.join(', ')}${a})` : `rgb(${v.join(', ')})`;
  });
  // Valeurs Bootstrap « r, g, b » des propriétés --bs-*-rgb.
  if (/-rgb$/.test(prop) && /^\s*\d+\s*,\s*\d+\s*,\s*\d+\s*$/.test(out)) {
    const v = mapRgb(...out.split(',').map((x) => +x.trim()));
    if (v) { changed = true; out = v.join(', '); }
  }
  return changed ? out : null;
}

function scope(selector, dark) {
  return selector.split(',').map((raw) => {
    const s = raw.trim();
    if (dark) return s.replace(/^html\[data-theme="dark"\]/, `html[data-theme="dark"]:where(${P})`);
    if (s.startsWith(':root')) return s.replace(':root', `html${P}:not([data-theme="dark"])`);
    if (s.startsWith('html')) return s.replace(/^html/, `html:where(${P})`);
    return `:where(html${P}) ${s}`;
  }).join(',\n');
}

const out = postcss.root();
let count = 0;
for (const [files, dark] of [[LIGHT, false], [DARK, true]]) {
  for (const file of files) {
    const css = postcss.parse(readFileSync(join(root, file), 'utf8'), { from: file });
    css.walkRules((rule) => {
      if (rule.parent.type === 'atrule' && /print|keyframes/i.test(`${rule.parent.name} ${rule.parent.params}`)) return;
      if (/keyframes/i.test(rule.parent?.parent?.name || '')) return;
      const selectors = rule.selectors.filter((s) => !KEEP_SELECTOR.test(s) && (!dark || s.trim().startsWith('html[data-theme="dark"]')));
      if (!selectors.length) return;
      const decls = [];
      rule.walkDecls((d) => {
        if (d.parent !== rule || KEEP_PROP.test(d.prop)) return;
        const v = mapValue(d.value, d.prop);
        if (v) decls.push(postcss.decl({ prop: d.prop, value: v, important: d.important }));
      });
      if (!decls.length) return;
      const copy = postcss.rule({ selector: scope(selectors.join(','), dark) });
      decls.forEach((d) => copy.append(d));
      // Même cadre @media que la règle d'origine.
      let target = copy;
      for (let p = rule.parent; p && p.type === 'atrule'; p = p.parent) target = postcss.atRule({ name: p.name, params: p.params }).append(target);
      out.append(target);
      count += decls.length;
    });
  }
}

// Jetons Tailwind (@theme de tailwind.css, publiés en --tw-color-* sur :root) : thème clair seulement, le sombre les redéfinit.
const theme = readFileSync(join(root, 'tailwind.css'), 'utf8').match(/@theme\s*\{([\s\S]*?)\}/);
if (theme) {
  const tokens = postcss.rule({ selector: `html${P}:not([data-theme="dark"])` });
  for (const m of theme[1].matchAll(/--color-([\w-]+)\s*:\s*([^;]+);/g)) {
    const prop = `--tw-color-${m[1]}`;
    if (KEEP_PROP.test(prop)) continue;
    const v = mapValue(m[2].trim(), prop);
    if (v) { tokens.append(postcss.decl({ prop, value: v })); count += 1; }
  }
  if (tokens.nodes.length) out.prepend(tokens);
}

const header = `/*
 * PREDIRE — thème turquoise (décision du 20/11/2026, bleu du logo du 18/11) : un turquoise remplace le vert de PRISE dans tout l'espace
 * PREDIRE (html[data-project="predire"], posé par Layout.jsx et public/theme-init.js).
 * FICHIER GÉNÉRÉ par scripts/theme-predire.mjs à partir des feuilles de style : ne pas modifier à la main, relancer le script.
 * Les réglages faits à la main sont dans theme-predire-signature.css.
 */
`;
writeFileSync(join(root, 'theme-predire.css'), header + out.toString() + '\n');
console.log(`theme-predire.css : ${count} déclarations reprises en turquoise PREDIRE.`);

/*
 * Génère les élévations « ouvrages » du fond de l'application (décision du 22/11/2026) : forage, château d'eau, réseau
 * d'AEP enterré avec vannes, borne-fontaine, centre de santé, école à véranda et bloc de latrines VIP, sur une ligne de
 * terrain hachurée. Une seule source, quatre teintes (PRISE / PREDIRE, clair / sombre), écrites dans public/images.
 *
 *   node scripts/ouvrages.mjs
 *
 * Le dessin reste un filigrane : opacité de trait 0,13 à 0,2 en clair, 0,08 à 0,1 en sombre, derrière les cartes.
 */
import { writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const W = 1400;
const H = 300;
const SOL = 250; // ligne de terrain
const RESEAU = 270; // conduite enterrée

const p = [];
const trait = (d) => p.push(`<path d="${d}"/>`);
const fin = (d) => p.push(`<path class="f" d="${d}"/>`);
const tirets = (d) => p.push(`<path class="t" d="${d}"/>`);

// ---------------------------------------------------------------- Terrain : ligne de sol, hachures de coupe, collines
trait(`M0 ${SOL}H${W}`);
let h = '';
for (let x = 6; x < W; x += 22) h += `M${x} ${SOL + 2}l-9 9`;
fin(h);

// ---------------------------------------------------------------- Forage tubé, pompe à motricité humaine
trait(`M30 ${SOL}v44M44 ${SOL}v44`); // tubage
tirets(`M30 ${SOL + 30}h14`); // niveau de la nappe
fin(`M33 ${SOL + 34}h8M35 ${SOL + 38}h4`);
trait(`M28 ${SOL}v-30h18v30M37 220v-26M37 202l22-10`); // tête de forage et levier
trait(`M44 236h12v6`); // bec

// ---------------------------------------------------------------- Château d'eau
trait('M95 66L140 42l45 24'); // toit
trait('M95 66v46h90V66'); // cuve
fin('M95 82h90M95 98h90');
trait('M95 112l45 14 45-14'); // fond conique
trait(`M106 118L94 ${SOL}M174 118l12 ${SOL - 118}M128 124l-4 ${SOL - 124}M152 124l4 ${SOL - 124}`); // pieds
fin(`M101 160l57 40M158 160l-57 40M98 200l60 42M158 200l-60 42`); // contreventements
fin('M101 160h57M98 200h60');
trait(`M140 126V${RESEAU}`); // colonne montante
fin('M188 112v60M196 112v60M188 124h8M188 136h8M188 148h8M188 160h8'); // échelle

// ---------------------------------------------------------------- Réseau d'AEP enterré et vannes
tirets(`M37 ${RESEAU}H1330`);
tirets(`M37 ${SOL + 2}V${RESEAU}`);
const vanne = (x) => trait(`M${x - 7} ${RESEAU - 5}l14 10v-10l-14 10z`);
[225, 395, 690, 1080].forEach(vanne);

// ---------------------------------------------------------------- Borne-fontaine
tirets(`M305 ${RESEAU}V${SOL}`);
trait(`M292 ${SOL}v-36h26v36`); // fût
trait('M300 214v-12h18v7'); // robinet
trait('M318 213c-3 5-5 8-5 10a5 5 0 0 0 10 0c0-2-2-5-5-10z'); // goutte
trait(`M286 ${SOL}v-6h40v6`); // dalle et rigole

// ---------------------------------------------------------------- Arbres
// Couronne festonnée (cinq lobes) sur un tronc qui se divise ; r = demi-largeur de la couronne.
const arbre = (x, r) => {
  const b = SOL - 14 - r * 0.7; // base de la couronne, au-dessus d'un tronc visible
  const l = r * 0.62;
  trait(`M${x} ${SOL}V${b + 2}M${x} ${b + 6}l-${r * 0.3} -${r * 0.35}M${x} ${b + 4}l${r * 0.28} -${r * 0.4}`);
  trait(`M${x - r} ${b}a${l} ${l} 0 0 1 ${r * 0.25} -${r * 1.05}a${l} ${l} 0 0 1 ${r * 0.75} -${r * 0.7}a${l} ${l} 0 0 1 ${r * 0.85} ${r * 0.25}a${l} ${l} 0 0 1 ${r * 0.4} ${r * 1.05}a${l * 0.9} ${l * 0.9} 0 0 1 -${r * 0.5} ${r * 0.45}h-${r * 1.1}a${l * 0.9} ${l * 0.9} 0 0 1 -${r * 0.65} -${r * 0.0}z`);
};
arbre(368, 20);
arbre(668, 15);
arbre(1306, 22);

// ---------------------------------------------------------------- Centre de santé
tirets(`M560 ${RESEAU}V${SOL}`);
trait('M418 168L530 124l112 44'); // toit à deux pans
trait(`M430 164V${SOL}h200V164`);
trait(`M515 ${SOL}v-46h30v46`); // porte
fin(`M530 ${SOL - 46}v46`);
trait('M448 182h40v28h-40zM572 182h40v28h-40z'); // fenêtres
fin('M468 182v28M592 182v28');
trait('M518 140h24v22h-24z'); // plaque
trait('M530 144v14M523 151h14'); // croix (trait, sans couleur d'emblème)
fin(`M500 ${SOL - 50}h60l6-8h-72z`); // auvent

// ---------------------------------------------------------------- École : véranda, salles de classe, cote
tirets(`M884 ${RESEAU}V${SOL}`);
trait('M712 178l173-36 173 36'); // toit
trait(`M730 174V${SOL}h310V174`);
fin('M730 186h310'); // bord de véranda
for (const x of [760, 822, 884, 946, 1008]) trait(`M${x} 186V${SOL}`); // poteaux
for (const x of [736, 798, 860, 922, 984]) { fin(`M${x + 6} ${SOL}v-38h12v38`); fin(`M${x + 24} 202h14v16h-14z`); } // portes et fenêtres
trait(`M696 ${SOL}v-46M714 ${SOL}v-46M690 190h30v16h-30z`); // panneau sur deux poteaux
fin('M730 120h310M730 114v12M1040 114v12'); // cote

// ---------------------------------------------------------------- Bloc de latrines VIP et fosse en coupe
tirets(`M1185 ${RESEAU}V${SOL}`);
trait(`M1108 196V${SOL}h154V196`);
trait('M1102 196l166-8'); // toit monopente
for (const x of [1146.5, 1185, 1223.5]) trait(`M${x} ${196 - ((x - 1108) * 8) / 160}V${SOL}`);
for (const x of [1114, 1152.5, 1191, 1229.5]) fin(`M${x} ${SOL}v-40h26v40`); // portes
for (const x of [1127, 1165.5, 1204, 1242.5]) { trait(`M${x} ${194 - ((x - 1108) * 8) / 160}v-30`); trait(`M${x - 4} ${163 - ((x - 1108) * 8) / 160}h8`); } // tuyaux d'aération
tirets(`M1110 ${SOL + 2}v38h150v-38`); // fosse
fin(`M1110 ${SOL + 26}h150`);

const css = (couleur, op, opFin) => `<style>path{fill:none;stroke:${couleur};stroke-opacity:${op};stroke-width:1.4;stroke-linecap:round;stroke-linejoin:round}.f{stroke-opacity:${opFin};stroke-width:1.1}.t{stroke-dasharray:6 5}</style>`;
const svg = (couleur, op, opFin) => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${W} ${H}" width="${W}" height="${H}">${css(couleur, op, opFin)}${p.join('')}</svg>\n`;

const ici = dirname(fileURLToPath(import.meta.url));
const sortie = join(ici, '..', 'public', 'images');
const variantes = {
  'ouvrages-prise.svg': ['#0a5a33', 0.2, 0.13],
  'ouvrages-prise-sombre.svg': ['#7be0ac', 0.1, 0.065],
  'ouvrages-predire.svg': ['#0a6a6e', 0.2, 0.13],
  'ouvrages-predire-sombre.svg': ['#7adfd5', 0.1, 0.065],
};
for (const [nom, [c, o, f]] of Object.entries(variantes)) writeFileSync(join(sortie, nom), svg(c, o, f));
console.log(`${Object.keys(variantes).length} élévations écrites dans public/images`);

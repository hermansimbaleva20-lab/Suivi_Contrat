/*
 * Retour au clic de tous les boutons de l'application, en un seul endroit : le bouton s'enfonce et une onde part du point
 * touché, comme à la surface de l'eau (le même motif que le chargeur d'ouverture).
 * L'onde est posée sur <body>, au-dessus du bouton, et non dans le bouton : elle ne touche ni à son contenu ni à ses pastilles,
 * et elle se termine même si le clic ferme la boîte qui le contient.
 */
const BUTTONS = 'button, .btn, [role="button"]';
// Survol (décision du 15/11/2026) : boutons, options du menu et onglets des modules grossissent légèrement.
const HOVERABLE = `${BUTTONS}, .side-nav .nav-link, .module-tab`;
const EASE_OUT = 'cubic-bezier(.16, 1, .3, 1)';
const reduced = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const buttonAt = (node, selector = BUTTONS) => {
  const el = node instanceof Element ? node.closest(selector) : null;
  if (!el || el.closest('.leaflet-container, .print-doc') || el.matches(':disabled, .disabled, [aria-disabled="true"]')) return null;
  return el;
};

/*
 * Zoom au survol, proportionnel à la taille : environ 3 px de plus de chaque côté, entre 1,5 % (grand bouton pleine largeur)
 * et 4 % (petite icône). Animé par la Web Animations API sur « scale » : les transitions CSS des boutons restent intactes.
 */
const hoverScale = new WeakMap();
const zoomOf = (el) => { const w = el.offsetWidth || el.getBoundingClientRect().width || 1; return Math.min(1.04, Math.max(1.015, 1 + 6 / w)); };
function hover(el, on) {
  const anim = hoverScale.get(el);
  const from = anim ? parseFloat(getComputedStyle(el).scale) || 1 : 1;
  anim?.cancel();
  const to = on ? zoomOf(el) : 1;
  if (!on && from === 1) { hoverScale.delete(el); return; }
  const next = el.animate([{ scale: from }, { scale: to }], { duration: on ? 180 : 140, easing: EASE_OUT, fill: 'forwards' });
  hoverScale.set(el, next);
  if (!on) next.onfinish = () => { next.cancel(); if (hoverScale.get(el) === next) hoverScale.delete(el); };
}

function play(el, x, y) {
  if (reduced()) return;
  // « scale » seul : les boutons qui ont déjà une transformation ou une animation la gardent. Le bouton s'enfonce depuis
  // sa taille du moment (agrandie s'il est survolé) et y revient.
  const base = parseFloat(getComputedStyle(el).scale) || 1;
  el.animate([{ scale: base }, { scale: base * 0.96, offset: 0.3 }, { scale: base }], { duration: 240, easing: EASE_OUT, composite: 'replace' });

  const box = el.getBoundingClientRect();
  const style = getComputedStyle(el);
  const radius = Math.hypot(Math.max(x - box.left, box.right - x), Math.max(y - box.top, box.bottom - y));
  const host = document.createElement('span');
  host.className = 'press-ripple';
  host.setAttribute('aria-hidden', 'true');
  Object.assign(host.style, { left: `${box.left}px`, top: `${box.top}px`, width: `${box.width}px`, height: `${box.height}px`, borderRadius: style.borderRadius, color: style.color });
  const wave = host.appendChild(document.createElement('span'));
  Object.assign(wave.style, { left: `${x - box.left - radius}px`, top: `${y - box.top - radius}px`, width: `${radius * 2}px`, height: `${radius * 2}px` });
  document.body.appendChild(host);
  // L'onde arrive vite mais s'efface lentement : avec une seule courbe, elle disparaîtrait avant d'avoir couvert le bouton.
  wave.animate({ transform: ['scale(.12)', 'scale(1)'] }, { duration: 460, easing: EASE_OUT });
  const run = wave.animate({ opacity: [1, 1, 0], offset: [0, 0.35, 1] }, { duration: 460, easing: 'linear' });
  run.onfinish = () => host.remove();
  run.oncancel = () => host.remove();
}

export function installPressFeedback() {
  let lastPointer = 'mouse';
  // Survol à la souris seulement (pas au doigt), et jamais quand l'utilisateur a demandé moins d'animations.
  const finePointer = window.matchMedia('(hover: hover) and (pointer: fine)');
  let hovered = null;
  const leave = () => { if (hovered) { hover(hovered, false); hovered = null; } };
  document.addEventListener('pointerover', (e) => {
    if (e.pointerType !== 'mouse' || !finePointer.matches || reduced()) return;
    let el = buttonAt(e.target, HOVERABLE);
    // L'onglet déjà choisi ne bouge pas : il reste calé sur son repère.
    if (el?.matches('.side-nav .nav-link.active, .module-tab.is-active')) el = null;
    if (el === hovered) return;
    leave();
    if (el) { hovered = el; hover(el, true); }
  }, { passive: true });
  document.addEventListener('pointerout', (e) => {
    if (!hovered || (e.relatedTarget instanceof Node && hovered.contains(e.relatedTarget))) return;
    leave();
  }, { passive: true });
  // Un bouton qui disparaît (boîte fermée, page changée) ou devient inactif sous la souris ne doit pas rester agrandi.
  document.addEventListener('click', () => {
    // L'onglet que l'on vient de choisir reprend sa taille : son repère vient se poser dessus.
    if (hovered?.matches('.side-nav .nav-link, .module-tab')) leave();
  }, { capture: true, passive: true });
  document.addEventListener('click', () => requestAnimationFrame(() => {
    if (hovered && (!hovered.isConnected || hovered.matches(':disabled, .disabled'))) leave();
  }), { passive: true });
  document.addEventListener('pointerdown', (e) => {
    lastPointer = e.pointerType;
    if (e.pointerType === 'touch' || e.button !== 0) return;
    const el = buttonAt(e.target);
    if (el) play(el, e.clientX, e.clientY);
  }, { capture: true, passive: true });
  // Au doigt, on attend le clic pour ne pas réagir au simple défilement ; au clavier, l'onde part du centre du bouton.
  document.addEventListener('click', (e) => {
    const keyboard = e.detail === 0;
    if (!keyboard && lastPointer !== 'touch') return;
    const el = buttonAt(e.target);
    if (!el) return;
    if (!keyboard) { play(el, e.clientX, e.clientY); return; }
    const box = el.getBoundingClientRect();
    play(el, box.left + box.width / 2, box.top + box.height / 2);
  }, { capture: true, passive: true });
}

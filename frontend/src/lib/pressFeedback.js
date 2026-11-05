/*
 * Retour au clic de tous les boutons de l'application, en un seul endroit : le bouton s'enfonce et une onde part du point
 * touché, comme à la surface de l'eau (le même motif que le chargeur d'ouverture).
 * L'onde est posée sur <body>, au-dessus du bouton, et non dans le bouton : elle ne touche ni à son contenu ni à ses pastilles,
 * et elle se termine même si le clic ferme la boîte qui le contient.
 */
const BUTTONS = 'button, .btn, [role="button"]';
const EASE_OUT = 'cubic-bezier(.16, 1, .3, 1)';

const buttonAt = (node) => {
  const el = node instanceof Element ? node.closest(BUTTONS) : null;
  if (!el || el.closest('.leaflet-container') || el.matches(':disabled, .disabled, [aria-disabled="true"]')) return null;
  return el;
};

function play(el, x, y) {
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  // « scale » seul : les boutons qui ont déjà une transformation ou une animation la gardent.
  el.animate([{ scale: 1 }, { scale: 0.96, offset: 0.3 }, { scale: 1 }], { duration: 240, easing: EASE_OUT });

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

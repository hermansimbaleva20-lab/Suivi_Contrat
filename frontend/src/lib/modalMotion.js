/** Fait disparaître la boîte ouverte avant de la retirer de la page ; la sortie est plus rapide que l'entrée (prise-design.css). */
export function animateModalOut() {
  const dialog = [...document.querySelectorAll('.prise-modal')].pop();
  if (!dialog) return Promise.resolve();
  const moves = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const timing = { duration: 160, easing: 'ease-in', fill: 'forwards' };
  document.querySelectorAll('.modal-backdrop').forEach((veil) => veil.animate({ opacity: 0 }, timing));
  dialog.closest('.modal')?.animate({ backgroundColor: 'transparent' }, timing);
  return dialog.animate({ opacity: 0, ...(moves ? { transform: 'translateY(8px) scale(.985)' } : {}) }, timing).finished.catch(() => {});
}

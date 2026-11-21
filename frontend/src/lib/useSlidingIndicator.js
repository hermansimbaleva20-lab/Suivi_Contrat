import { useLayoutEffect, useRef, useState } from 'react';

/*
 * Repère de l'onglet actif (décision du 15/11/2026) : un seul repère par barre d'onglets, qui glisse de l'ancien onglet
 * vers celui que l'on vient de choisir, au lieu d'apparaître d'un coup.
 * - selector : l'onglet actif dans la barre ; la barre (ref, position relative) sert de repère de mesure, défilement compris.
 * - deps : ce qui change l'onglet actif (en général l'adresse de la page).
 * - memoryKey : quand la barre est redessinée à chaque page (onglets des modules), le repère repart de la position retenue
 *   pour cette barre au lieu de surgir sur le nouvel onglet.
 * Sans onglet actif, le repère est masqué. Animations réduites : il se pose sans glisser (styles CSS).
 */
const memory = new Map();

export function useSlidingIndicator(selector, deps = [], memoryKey = null) {
  const ref = useRef(null);
  const first = useRef(true);
  const [box, setBox] = useState(() => (memoryKey && memory.get(memoryKey)) || null);
  const [glide, setGlide] = useState(false);

  useLayoutEffect(() => {
    const bar = ref.current;
    if (!bar) return undefined;
    const measure = () => {
      const active = bar.querySelector(selector);
      if (!active) { setBox(null); return; }
      // Un onglet encore agrandi par le survol (zoom autour de son centre) est ramené à sa taille réelle : même centre,
      // dimensions divisées par le zoom. Mesure au sous-pixel près, sans arrondi.
      const s = parseFloat(getComputedStyle(active).scale) || 1;
      const b = bar.getBoundingClientRect();
      const a = active.getBoundingClientRect();
      const w = a.width / s;
      const h = a.height / s;
      const next = { x: a.left + a.width / 2 - w / 2 - b.left - bar.clientLeft + bar.scrollLeft, y: a.top + a.height / 2 - h / 2 - b.top - bar.clientTop + bar.scrollTop, w, h };
      setBox((prev) => (prev && prev.x === next.x && prev.y === next.y && prev.w === next.w && prev.h === next.h ? prev : next));
      if (memoryKey) memory.set(memoryKey, next);
    };
    const fromMemory = first.current && !!memoryKey && memory.has(memoryKey);
    first.current = false;
    let frame;
    let frame2;
    if (fromMemory) {
      // Barre redessinée : le repère est d'abord affiché à son ancienne place, puis il glisse vers l'onglet choisi.
      setGlide(true);
      frame = requestAnimationFrame(() => { frame2 = requestAnimationFrame(measure); });
    } else {
      measure();
      // Première pose sans glissement : à l'ouverture de la page, le repère est déjà en place.
      frame = requestAnimationFrame(() => setGlide(true));
    }
    const observer = typeof ResizeObserver === 'function' ? new ResizeObserver(measure) : null;
    observer?.observe(bar);
    window.addEventListener('resize', measure);
    return () => { cancelAnimationFrame(frame); cancelAnimationFrame(frame2); observer?.disconnect(); window.removeEventListener('resize', measure); };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps);

  const style = box ? { width: box.w, height: box.h, translate: `${box.x}px ${box.y}px` } : { opacity: 0 };
  return { ref, style, glide };
}

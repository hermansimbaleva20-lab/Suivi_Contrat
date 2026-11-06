import { useEffect, useState } from 'react';

/**
 * Thème de l'interface, au choix de chaque utilisateur (en-tête) : clair (par défaut), sombre ou automatique (réglage de
 * l'ordinateur). Le choix reste dans ce navigateur. public/theme-init.js applique le même calcul avant le premier affichage,
 * pour éviter un éclair blanc ; ce module prend le relais ensuite. L'impression reste toujours claire : les règles du thème
 * sombre (styles/theme-sombre.css) ne s'appliquent qu'à l'écran.
 */
export const THEME_KEY = 'prise_theme';
export const THEME_CHOICES = [
  { value: 'clair', label: 'Clair', icon: 'bi-sun' },
  { value: 'sombre', label: 'Sombre', icon: 'bi-moon-stars' },
  { value: 'auto', label: 'Automatique', icon: 'bi-circle-half', hint: 'selon l’ordinateur' },
];
export const THEME_EVENT = 'prise-theme-change';
const EVENT = THEME_EVENT;
const media = () => (typeof window !== 'undefined' && window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null);

export function themeChoice() {
  try {
    const v = localStorage.getItem(THEME_KEY);
    return THEME_CHOICES.some((c) => c.value === v) ? v : 'clair';
  } catch {
    return 'clair';
  }
}

const resolve = (choice) => (choice === 'sombre' || (choice === 'auto' && media()?.matches) ? 'dark' : 'light');

function apply(choice) {
  const theme = resolve(choice);
  const root = document.documentElement;
  if (root.dataset.theme !== theme) root.dataset.theme = theme;
  document.querySelector('meta[name="theme-color"]')?.setAttribute('content', theme === 'dark' ? '#0d1512' : '#064d25');
  window.dispatchEvent(new CustomEvent(EVENT, { detail: theme }));
}

export function setThemeChoice(choice) {
  try { localStorage.setItem(THEME_KEY, choice); } catch { /* navigation privée : le choix vaut pour la session */ }
  apply(choice);
}

/** À appeler une fois au démarrage : applique le choix et suit le réglage de l'ordinateur en mode automatique. */
export function installTheme() {
  apply(themeChoice());
  media()?.addEventListener?.('change', () => { if (themeChoice() === 'auto') apply('auto'); });
}

/** Thème affiché ('light' | 'dark'), mis à jour à chaque changement (graphiques). */
export function useResolvedTheme() {
  const [theme, setTheme] = useState(() => (typeof document !== 'undefined' && document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light'));
  useEffect(() => {
    const on = (e) => setTheme(e.detail);
    window.addEventListener(EVENT, on);
    return () => window.removeEventListener(EVENT, on);
  }, []);
  return theme;
}

/** Couleurs des axes et libellés des graphiques, lues dans les jetons du thème affiché ; `print` : celles du papier. */
export function chartInk(print = false) {
  const css = getComputedStyle(document.documentElement);
  const v = (name, fallback) => (print ? fallback : css.getPropertyValue(name).trim() || fallback);
  return {
    grid: v('--chart-grid', '#e6eee9'),
    axis: v('--chart-axis', '#dce7e0'),
    tick: v('--chart-tick', '#5f7468'),
    label: v('--chart-label', '#12261d'),
    legend: v('--chart-legend', '#4b6357'),
    value: v('--chart-value', '#35524a'),
    tooltip: v('--chart-tooltip', '#12261d'),
  };
}

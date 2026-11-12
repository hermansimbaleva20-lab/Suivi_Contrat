/* Thème choisi appliqué avant le premier affichage (pas d'éclair blanc en thème sombre). Fichier externe : la CSP refuse les
   scripts en ligne. Même calcul que src/lib/theme.js. */
(function () {
  var choice = 'clair';
  try { choice = localStorage.getItem('prise_theme') || 'clair'; } catch (e) { /* stockage indisponible */ }
  var dark = choice === 'sombre' || (choice === 'auto' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
  document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
})();

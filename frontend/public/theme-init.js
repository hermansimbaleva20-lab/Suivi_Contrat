/* Thème choisi appliqué avant le premier affichage (pas d'éclair blanc en thème sombre). Fichier externe : la CSP refuse les
   scripts en ligne. Même calcul que src/lib/theme.js.
   Projet (18/11/2026) : une session PREDIRE ouverte reçoit aussitôt son thème bleu (html[data-project], repris par Layout.jsx),
   sans éclair vert au rechargement ; la page de connexion garde son apparence. */
(function () {
  var choice = 'clair';
  var project = '';
  try {
    choice = localStorage.getItem('prise_theme') || 'clair';
    if (localStorage.getItem('prise_token')) project = (localStorage.getItem('prise_project') || '').toLowerCase();
  } catch (e) { /* stockage indisponible */ }
  var dark = choice === 'sombre' || (choice === 'auto' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
  document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
  if (project && !/^\/(login|register|forgot-password|reset-password)/.test(location.pathname)) document.documentElement.setAttribute('data-project', project);
})();

import React from 'react';

/*
 * Pied de page de l'application et des pages de connexion : mention de copyright du projet et contact du développeur.
 * variant="app" sous le contenu des écrans (styles prise-design.css), variant="auth" sur la photo des pages de connexion.
 * Masqué à l'impression : les documents imprimés ont leur propre entête (PrintLetterhead).
 */
const DEVELOPER = 'hermansimbaleva20@gmail.com';

export function AppFooter({ variant = 'app' }) {
  return (
    <footer className={`app-footer app-footer--${variant}`}>
      <p className="app-footer-copy">
        Copyright © 2026 Projet PRISE / PREDIRE. All rights reserved.
      </p>
      <p className="app-footer-credit">
        Powered by <a href={`mailto:${DEVELOPER}`}>{DEVELOPER}</a>
      </p>
    </footer>
  );
}

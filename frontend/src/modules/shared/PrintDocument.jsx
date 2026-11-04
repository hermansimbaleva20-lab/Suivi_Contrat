import { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';

/*
 * Document imprimé seul : rendu hors de l'application (portail sur <body>), masqué à l'écran. Tant qu'il est monté,
 * l'impression ne contient que lui : ni barre latérale, ni en-tête, ni commandes de l'application (voir prise-react.css).
 * orientation : 'portrait' ou 'paysage' (page A4 nommée, marges du document papier).
 */
export function PrintDocument({ orientation = 'portrait', children }) {
  const [host] = useState(() => document.createElement('div'));
  useEffect(() => {
    host.className = 'print-doc';
    host.dataset.orientation = orientation;
    document.body.appendChild(host);
    document.body.classList.add('has-print-doc');
    return () => {
      host.remove();
      document.body.classList.remove('has-print-doc');
    };
  }, [host, orientation]);
  return createPortal(children, host);
}

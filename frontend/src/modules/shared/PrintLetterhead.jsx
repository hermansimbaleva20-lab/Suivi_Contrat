import React, { useEffect } from 'react';
import { useAuth } from '../../context/AuthContext.jsx';
import { printTitle } from '../../lib/printTitle.js';

/*
 * Entête de tout document imprimé, sur le modèle papier du projet : bandeau des logos, intitulé du projet en vert et
 * centré, nom court souligné (« PRISE II », « PREDIRE »), puis le titre du document. Invisible à l'écran ; « always »
 * l'affiche aussi dans un PrintDocument (déjà masqué à l'écran). « orientation="paysage" » imprime la page en A4
 * paysage, pour les tableaux larges (carnet de bord, rapport carburant).
 */
export function PrintLetterhead({ title, subtitle, always = false, orientation = 'portrait' }) {
  const { me } = useAuth();
  const code = me.project.code;
  useEffect(() => {
    if (orientation !== 'paysage') return undefined;
    document.body.dataset.printOrientation = 'paysage';
    return () => { delete document.body.dataset.printOrientation; };
  }, [orientation]);
  return (
    <header className={`print-letterhead${always ? ' is-always' : ''}`}>
      <img src={code === 'PREDIRE' ? '/images/predire_logo.png' : '/images/prise2.png'} alt={`Entête du projet ${code}`} className="pdoc-letterhead" />
      <hr className="pdoc-rule" />
      <p className="pdoc-project">
        {me.project.name}
        <strong>{printTitle(me)}</strong>
      </p>
      <h1 className="pdoc-title">{title}</h1>
      {subtitle && <p className="pdoc-subtitle">{subtitle}</p>}
    </header>
  );
}

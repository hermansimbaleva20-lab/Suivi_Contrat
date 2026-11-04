import React from 'react';
import { useAuth } from '../../context/AuthContext.jsx';

/*
 * Produit visé par une alerte logistique (article, véhicule, groupe) : son nom clignote, en rouge pour une alerte grave, en
 * orange sinon. Réservé aux comptes habilités aux alertes logistiques ; pour les autres, le nom s'affiche normalement.
 * Le rythme reste lent et il n'y a plus d'animation si le poste demande moins de mouvement (voir prise-design.css).
 */
export function AlertBlink({ level, children }) {
  const { can } = useAuth();
  if (!level || !can('canViewAlertsLogistics')) return children;
  const words = level === 'danger' ? 'Alerte grave' : 'Alerte';
  return <span className={`logi-blink is-${level}`} title={words}><span className="tw:sr-only">{words} : </span>{children}</span>;
}

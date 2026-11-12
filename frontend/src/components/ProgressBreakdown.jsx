import React from 'react';
import { Link } from 'react-router-dom';
import { money, num } from '../lib/format.js';
import { Progress as Bar } from './ui.jsx';

/*
 * Détail de l'avancement physique (décision du 10/11/2026), dans l'ordre de la formule du projet : installation de chantier
 * et travaux préparatoires (contrats de TRAVAUX), puis chaque site. Pour chaque ligne : montant, taux d'exécution et
 * résultat (taux × montant ÷ montant du contrat) ; la somme des résultats est l'avancement physique.
 * Sans montant par site, aucun résultat n'est affiché. highlight : ligne en cours de saisie ('INSTALLATION' ou id du site).
 */
const rowKey = (r) => (r.kind === 'INSTALLATION' ? 'INSTALLATION' : String(r.siteId));

export function ProgressBreakdown({ breakdown, progress, editLink, highlight }) {
  if (!breakdown || !breakdown.rows.length) return null;
  const { weighted, base, rows } = breakdown;
  let site = 0;
  return (
    <div className="progress-calc">
      {!weighted && (
        <div className="progress-calc-missing" role="note">
          <i className="bi bi-exclamation-triangle-fill" aria-hidden="true" />
          <span>Montants non renseignés : avancement provisoire.{editLink && <> <Link to={editLink}>Compléter le contrat</Link></>}</span>
        </div>
      )}
      <div className="table-wrap">
        <table className="table align-middle progress-calc-table">
          <thead>
            <tr>
              <th scope="col">Désignation</th>
              <th scope="col" className="text-end">Montant (USD)</th>
              <th scope="col">Taux d’exécution</th>
              <th scope="col" className="text-end">Résultat</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => {
              const isInstallation = r.kind === 'INSTALLATION';
              if (!isInstallation) site += 1;
              return (
                <tr key={rowKey(r)} className={highlight && highlight === rowKey(r) ? 'is-current' : ''}>
                  <th scope="row">
                    {isInstallation ? 'Installation de chantier et travaux préparatoires' : <><span className="progress-calc-site">Site {site}</span> {r.name}</>}
                  </th>
                  <td className="text-end text-money">{r.amount === null ? '—' : money(r.amount)}</td>
                  <td style={{ minWidth: 140 }}><span className="fw-semibold">{num(r.progress, 2)} %</span><Bar value={r.progress} /></td>
                  <td className="text-end text-money"><strong>{weighted ? `${num(r.contribution, 2)} %` : '—'}</strong></td>
                </tr>
              );
            })}
          </tbody>
          <tfoot>
            <tr>
              <th scope="row">Avancement physique</th>
              <td className="text-end text-money fw-semibold">{weighted ? money(base) : ''}</td>
              <td />
              <td className="text-end text-money"><strong className="progress-calc-total">{num(progress, 2)} %</strong></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  );
}

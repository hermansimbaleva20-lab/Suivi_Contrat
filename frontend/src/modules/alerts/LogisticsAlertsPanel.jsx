import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext.jsx';
import { panel } from '../shared/tw.jsx';
import { AlertBlink } from '../shared/AlertBlink.jsx';
import { logbookPath } from '../shared/logisticsNav.js';

/*
 * Alertes logistiques de la page Alertes (stock, charroi, groupes électrogènes), dans le périmètre de lecture du compte :
 * son unité, ou tout le projet pour la vue nationale. Le produit concerné clignote ; « Ouvrir » mène à sa fiche, si le compte
 * a accès au module.
 */

const MODULES = { STOCK: ['Stock', 'box-seam-fill'], CHARROI: ['Charroi', 'truck-front-fill'], GROUPE: ['Groupes électrogènes', 'lightning-charge-fill'] };
const th = 'tw:whitespace-nowrap tw:border-b tw:border-prise-border tw:bg-prise-bg tw:px-3 tw:py-2.5 tw:text-left tw:font-tech tw:text-[11px] tw:font-semibold tw:uppercase tw:tracking-wide tw:text-prise-muted';
const td = 'tw:border-b tw:border-prise-border tw:px-3 tw:py-2.5 tw:text-sm tw:text-prise-text tw:align-top';

/** Fiche du produit : article de stock, ou page de l'engin selon la nature de l'alerte. */
function productLink(a) {
  if (a.category === 'STOCK') return `/stock/articles/${a.itemId}?unit=${a.unitId}`;
  const kind = a.category === 'GROUPE' ? 'GROUPE' : 'VEHICULE';
  if (a.code === 'MAINTENANCE') return `${kind === 'GROUPE' ? '/generators' : '/fleet'}/maintenance?engin=${a.itemId}`;
  if (a.code === 'INSURANCE') return `/fleet/insurance?engin=${a.itemId}`;
  return `${logbookPath(kind)}?engin=${a.itemId}`;
}

export default function LogisticsAlertsPanel({ items }) {
  const { can } = useAuth();
  const [filter, setFilter] = useState('');
  const counts = (k) => items.filter((a) => a.category === k).length;
  const rows = filter ? items.filter((a) => a.category === filter) : items;
  const danger = items.filter((a) => a.level === 'danger').length;
  const severalUnits = new Set(items.map((a) => a.unitId)).size > 1;
  const canOpen = (a) => (a.category === 'STOCK' ? can('canViewStock') || can('canManageStock') : can('canViewFleet') || can('canManageFleet') || can('canAuthorizeFleet'));
  const filters = [['', 'Toutes', items.length], ...Object.entries(MODULES).map(([k, [l]]) => [k, l, counts(k)])];

  return (
    <section id="logistique" className={`${panel} tw:mb-4 tw:scroll-mt-24`} aria-labelledby="logi-title">
      <header className="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-3 tw:border-b tw:border-prise-border tw:px-5 tw:py-4">
        <div className="tw:min-w-0">
          <h2 id="logi-title" className="tw:m-0 tw:font-display tw:text-[17px] tw:font-bold tw:text-prise-blue">Alertes logistiques</h2>
          <p className="tw:m-0 tw:mt-1 tw:text-xs tw:text-prise-muted">
            {items.length ? `${items.length} alerte${items.length > 1 ? 's' : ''}, dont ${danger} grave${danger > 1 ? 's' : ''}. Les produits concernés clignotent, ici et dans leur module.` : 'Stock, charroi et groupes électrogènes de votre périmètre.'}
          </p>
        </div>
        {items.length > 0 && (
          <div role="group" aria-label="Filtrer les alertes logistiques" className="tw:flex tw:flex-wrap tw:rounded-lg tw:border tw:border-prise-border tw:bg-white tw:p-0.5">
            {filters.map(([k, l, n]) => (
              <button key={k} type="button" aria-pressed={filter === k} onClick={() => setFilter(k)} disabled={k !== '' && !n}
                className={`tw:cursor-pointer tw:rounded-md tw:border-0 tw:px-3 tw:py-1.5 tw:text-[13px] tw:font-semibold tw:disabled:cursor-not-allowed tw:disabled:opacity-45 ${filter === k ? 'tw:bg-prise-green-2 tw:text-white' : 'tw:bg-transparent tw:text-prise-text tw:hover:bg-prise-green-soft'}`}>
                {l} <span className="tw:font-tech tw:tabular-nums">{n}</span>
              </button>
            ))}
          </div>
        )}
      </header>
      {items.length ? (
        <div className="tw:overflow-x-auto">
          <table className="tw:w-full tw:border-collapse">
            <thead>
              <tr>
                <th className={th} scope="col">Produit</th>
                <th className={th} scope="col">Module</th>
                <th className={th} scope="col">Détail</th>
                {severalUnits && <th className={th} scope="col">Unité</th>}
                <th className={th} scope="col"><span className="tw:sr-only">Action</span></th>
              </tr>
            </thead>
            <tbody>
              {rows.map((a) => {
                const [module, icon] = MODULES[a.category];
                return (
                  <tr key={a.key}>
                    <td className={`${td} tw:font-semibold`}><AlertBlink level={a.level}>{a.name}</AlertBlink></td>
                    <td className={`${td} tw:whitespace-nowrap`}><i className={`bi bi-${icon} tw:mr-1.5 tw:text-prise-muted`} aria-hidden="true" />{module}</td>
                    <td className={td}>{a.text}</td>
                    {severalUnits && <td className={`${td} tw:whitespace-nowrap`}>{a.unitName}</td>}
                    <td className={`${td} tw:whitespace-nowrap tw:text-right`}>
                      {canOpen(a) && <Link to={productLink(a)} className="tw:font-semibold tw:text-prise-green-2 tw:no-underline tw:hover:underline">Ouvrir</Link>}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      ) : (
        <p className="tw:m-0 tw:flex tw:items-center tw:gap-2 tw:px-5 tw:py-5 tw:text-sm tw:text-prise-green-2"><i className="bi bi-check-circle-fill" aria-hidden="true" />Aucune alerte logistique pour le moment.</p>
      )}
    </section>
  );
}

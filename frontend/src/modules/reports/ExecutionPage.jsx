import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { apiError, download } from '../../api.js';
import { useLoad } from '../../lib/hooks.js';
import { fr, money, num, todayISO } from '../../lib/format.js';
import { Loading, Notice } from '../../components/ui.jsx';
import { btnGhost, btnPrimary, input, label, panel } from '../shared/tw.jsx';
import { ModuleHeader } from '../shared/ModuleHeader.jsx';
import { PrintDocument } from '../shared/PrintDocument.jsx';
import { PrintLetterhead } from '../shared/PrintLetterhead.jsx';
import '../../styles/execution.css';

/*
 * Rapports & suivi › État d'exécution (décision du 10/11/2026) : la matrice du projet « État d'exécution des travaux,
 * activités et prestations », arrêtée à une date. Par catégorie puis par province (avec les ouvrages terminés), chaque
 * contrat sur une ligne, puis chacun de ses sites : situation actuelle et taux de réalisation. Même contenu à l'écran,
 * à l'impression (A4 paysage) et dans l'export Excel. Adaptée aux données de l'application : ni composante, ni budget en
 * UC, ni performance environnementale et sociale.
 */

const pct = (v) => `${num(v, 1)} %`;
const late = (r) => r.progress < 100 && r.elapsed - r.progress >= 0.5 && r.status !== 'Résilié';

function Completed({ list }) {
  if (!list.length) return <span className="exe-done is-none">Aucun ouvrage terminé</span>;
  return (
    <span className="exe-done">
      Ouvrages terminés :
      {list.map((x) => <span key={x.type} className="exe-done-item">{x.type} <strong>{x.count}</strong></span>)}
    </span>
  );
}

/**
 * Un contrat : sa ligne, puis une ligne par site (les colonnes du contrat couvrent ses sites). group : la composante,
 * dont les cellules « Composantes » et « Budget » couvrent tous ses contrats (posées sur le premier).
 */
function ContractRows({ r, printing, group }) {
  const span = 1 + r.sites.length;
  return (
    <>
      <tr className="exe-contract-first">
        {group && (
          <>
            <td rowSpan={group.lines} className="exe-component">{group.name || <span className="exe-muted">—</span>}</td>
            <td rowSpan={group.lines} className="exe-num">{group.budget === null || group.budget === undefined ? <span className="exe-muted">—</span> : money(group.budget).replace(/,00$/, '')}</td>
          </>
        )}
        <td rowSpan={span} className="exe-activity">
          {printing ? <strong>Contrat N° {r.number}</strong> : <Link to={`/contracts/${r.id}`}><strong>Contrat N° {r.number}</strong></Link>}
          {(r.activity || r.title) && <span> : {r.activity || r.title}</span>}
          {r.provinces.length > 1 && <span className="exe-note">Provinces : {r.provinces.join(', ')}</span>}
        </td>
        <td rowSpan={span} className="exe-supplier">
          <strong>{r.company}</strong>
          {r.signatureDate && <span className="exe-note">Signé le {fr(r.signatureDate)}</span>}
          {r.startDate && <span className="exe-note">Démarré le {fr(r.startDate)}</span>}
          <span className="exe-note">Délai : {r.durationMonths} mois{r.amendments ? ` · ${r.amendments} avenant${r.amendments > 1 ? 's' : ''}` : ''}</span>
        </td>
        <td rowSpan={span} className="exe-num">{money(r.amount)}</td>
        <td rowSpan={span} className="exe-num">{pct(r.elapsed)}</td>
        <td className="exe-realization">
          {r.installation && <span className="exe-install">Installation de chantier et travaux préparatoires : <strong>{pct(r.installation.progress)}</strong></span>}
          {r.realization ? <span>{r.realization}{r.realizationDate && <span className="exe-note-inline"> ({fr(r.realizationDate)})</span>}</span> : !r.installation && <span className="exe-muted">—</span>}
        </td>
        <td rowSpan={span} className="exe-es">{r.esPerformance || <span className="exe-muted">—</span>}</td>
        <td className={`exe-num exe-rate ${late(r) ? 'is-late' : ''} ${r.progress >= 100 ? 'is-done' : ''}`}>{pct(r.progress)}</td>
        <td rowSpan={span} className="exe-num">{money(r.engagement)}</td>
        <td rowSpan={span} className="exe-num">{money(r.paid)}</td>
        <td rowSpan={span} className={`exe-num exe-rate ${r.paymentRate > r.progress + 0.05 ? 'is-over' : ''}`}>{pct(r.paymentRate)}</td>
        <td className="exe-observation">{r.observation}</td>
      </tr>
      {r.sites.map((s) => (
        <tr key={s.id} className="exe-site">
          <td className="exe-realization">
            <strong>Site {s.name}</strong>
            {(s.workType || s.description) && <span> : {[s.workType, s.description !== s.workType ? s.description : ''].filter(Boolean).join(' — ')}</span>}
            {s.situation && <span className="exe-situation"><u>Situation actuelle</u> : {s.situation}</span>}
          </td>
          <td className={`exe-num ${s.progress >= 100 ? 'is-done' : ''}`}>{pct(s.progress)}</td>
          <td className="exe-observation">{s.observation || ''}</td>
        </tr>
      ))}
    </>
  );
}

/** Contrats d'une province, groupés par composante comme dans la matrice du projet. */
function ProvinceRows({ p, printing }) {
  const byId = Object.fromEntries(p.rows.map((r) => [r.id, r]));
  return (
    <tbody className="exe-province">
      {p.components.flatMap((g) => g.contractIds.map((id, k) => <ContractRows key={id} r={byId[id]} printing={printing} group={k === 0 ? g : null} />))}
    </tbody>
  );
}

function Matrix({ d, printing }) {
  return (
    <table className="exe-table">
      <colgroup>
        <col className="exe-c-component" /><col className="exe-c-budget" /><col className="exe-c-activity" /><col className="exe-c-supplier" /><col className="exe-c-num" /><col className="exe-c-small" />
        <col className="exe-c-realization" /><col className="exe-c-small" /><col className="exe-c-small" /><col className="exe-c-num" /><col className="exe-c-num" /><col className="exe-c-small" /><col className="exe-c-observation" />
      </colgroup>
      <thead>
        <tr>
          <th scope="col">Composantes</th><th scope="col">Budget en millions d’UC</th><th scope="col">Activités</th><th scope="col">Fournisseurs</th><th scope="col">Coût en USD</th>
          <th scope="col">Conso. du délai contractuel</th><th scope="col">Réalisation physique au {fr(d.asOf)}</th><th scope="col">Performance env. et sociale (Bon, Moyen)</th>
          <th scope="col">Taux de réalisation physique</th><th scope="col">Engagement</th><th scope="col">Paiement</th><th scope="col">Taux de paiement</th><th scope="col">Observations</th>
        </tr>
      </thead>
      {d.categories.map((cat) => (
        <React.Fragment key={cat.category}>
          <tbody className="exe-band-group">
            <tr className="exe-band exe-band-category">
              <th colSpan={13} scope="colgroup">
                {cat.label}
                <span className="exe-band-figures">{cat.totals.contracts} contrat{cat.totals.contracts > 1 ? 's' : ''} · coût {money(cat.totals.amount)} USD · réalisation physique {pct(cat.totals.progress)} · paiement {pct(cat.totals.paymentRate)}</span>
              </th>
            </tr>
          </tbody>
          {cat.provinces.map((p) => (
            <React.Fragment key={p.province}>
              <tbody className="exe-band-group">
                <tr className="exe-band exe-band-province">
                  <th colSpan={13} scope="colgroup">Province {p.province}<Completed list={p.completed} /></th>
                </tr>
              </tbody>
              <ProvinceRows p={p} printing={printing} />
            </React.Fragment>
          ))}
        </React.Fragment>
      ))}
      <tfoot>
        <tr>
          <th scope="row" colSpan={4}>Total — {d.totals.contracts} contrat{d.totals.contracts > 1 ? 's' : ''}</th>
          <td className="exe-num">{money(d.totals.amount)}</td><td />
          <td><Completed list={d.completed} /></td><td />
          <td className="exe-num exe-rate">{pct(d.totals.progress)}</td>
          <td className="exe-num">{money(d.totals.amount)}</td><td className="exe-num">{money(d.totals.paid)}</td>
          <td className="exe-num exe-rate">{pct(d.totals.paymentRate)}</td><td />
        </tr>
      </tfoot>
    </table>
  );
}

export default function ExecutionPage() {
  const today = todayISO();
  const [date, setDate] = useState(today);
  const [msg, setMsg] = useState('');
  const { data: d, loading, error } = useLoad('/reports/execution', { date });
  const hasRows = d && d.categories.length > 0;

  // Nom proposé par le navigateur à l'enregistrement en PDF.
  const docName = d ? `État d’exécution ${d.project} au ${d.longDate}` : null;
  useEffect(() => {
    if (!docName) return undefined;
    const before = document.title;
    document.title = docName;
    return () => { document.title = before; };
  }, [docName]);

  const exportXlsx = async () => {
    try { await download('/reports/execution/export', { date }, `etat_execution_${date}.xlsx`); } catch (e) { setMsg(apiError(e)); }
  };
  const title = d ? `ÉTAT D’EXÉCUTION DES TRAVAUX, ACTIVITÉS ET PRESTATIONS DU ${d.project}` : '';

  return (
    <>
      <ModuleHeader
        icon="table" title="État d’exécution"
        desc="Matrice d’exécution des travaux, activités et prestations à une date : chaque contrat, puis chacun de ses sites, par catégorie et par province."
        actions={(
          <>
            <Link to="/reports" className={btnGhost}><i className="bi bi-arrow-left" aria-hidden="true" />Rapport de suivi</Link>
            <button type="button" className={btnGhost} disabled={!hasRows} onClick={exportXlsx}><i className="bi bi-file-earmark-excel" aria-hidden="true" />Exporter en Excel</button>
            <button type="button" className={btnPrimary} disabled={!hasRows} onClick={() => window.print()}><i className="bi bi-printer" aria-hidden="true" />Imprimer</button>
          </>
        )}
      />
      <Notice onClose={() => setMsg('')}>{msg}</Notice>
      <form className={`${panel} tw:mb-4 tw:flex tw:flex-wrap tw:items-end tw:gap-x-6 tw:gap-y-3 tw:px-5 tw:py-3.5 tw:print:hidden`} onSubmit={(e) => e.preventDefault()} aria-label="Date de la situation">
        <div>
          <label className={label} htmlFor="exe-date">Situation au</label>
          <input id="exe-date" type="date" className={`${input.replace('tw:w-full ', '')} tw:w-auto`} value={date} max={today} onChange={(e) => setDate(e.target.value || today)} />
        </div>
        {d && hasRows && (
          <dl className="exe-figures">
            <div><dt>Contrats</dt><dd>{d.totals.contracts}</dd></div>
            <div><dt>Coût total</dt><dd>{money(d.totals.amount)} <small>USD</small></dd></div>
            <div><dt>Réalisation physique</dt><dd>{pct(d.totals.progress)}</dd></div>
            <div><dt>Paiement</dt><dd>{money(d.totals.paid)} <small>USD · {pct(d.totals.paymentRate)}</small></dd></div>
          </dl>
        )}
      </form>

      {error && <Notice>{error}</Notice>}
      {loading && !d && <Loading />}
      {d && !hasRows && (
        <div className={`${panel} tw:px-5 tw:py-10 tw:text-center`}>
          <p className="tw:m-0 tw:font-display tw:text-lg tw:font-bold tw:text-prise-blue">Aucun contrat signé au {fr(d.asOf)}</p>
          <p className="tw:m-0 tw:mt-1 tw:text-sm tw:text-prise-muted">Choisissez une date plus récente.</p>
        </div>
      )}
      {hasRows && (
        <div className={loading ? 'exe-loading' : ''} aria-busy={loading}>
          <section className={`${panel} exe-screen`}>
            <header className="exe-head">
              <h2>{title} <span>au {d.longDate}</span></h2>
              <p>Taux de réalisation physique pondéré par les montants (installation de chantier et sites). Montants en USD.</p>
            </header>
            <div className="exe-scroll" tabIndex={0} role="region" aria-label="Matrice de l’état d’exécution">
              <Matrix d={d} />
            </div>
          </section>
          <PrintDocument orientation="paysage">
            <article className="exe-print">
              <PrintLetterhead always title={title} subtitle={`au ${d.longDate}`} />
              <Matrix d={d} printing />
              <p className="exe-signoff">Établi le {fr(d.issuedBy.date)} par {d.issuedBy.name}{d.issuedBy.title ? `, ${d.issuedBy.title}` : ''}. Montants en USD.</p>
            </article>
          </PrintDocument>
        </div>
      )}
    </>
  );
}

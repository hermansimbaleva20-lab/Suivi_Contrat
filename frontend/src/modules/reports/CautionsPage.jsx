import React, { useEffect, useState } from 'react';
import { Link, Navigate, useParams } from 'react-router-dom';
import { useLoad } from '../../lib/hooks.js';
import { fr, money, todayISO } from '../../lib/format.js';
import { Loading, Notice } from '../../components/ui.jsx';
import { btnGhost, btnPrimary, input, label, panel } from '../shared/tw.jsx';
import { ModuleHeader } from '../shared/ModuleHeader.jsx';
import { PrintDocument } from '../shared/PrintDocument.jsx';
import { PrintLetterhead } from '../shared/PrintLetterhead.jsx';
import '../../styles/cautions.css';

/*
 * Rapports & suivi › Cautions d'avance et garanties (décision du 22/11/2026) : les deux tableaux de suivi du projet,
 * reproduits colonne pour colonne et arrêtés à une date. TITULAIRE, MONTANT($), MODE D'ACQUISITION et MARCHÉ (début
 * d'exécution, fin contractuelle) viennent du contrat ; la caution ou la garantie a ses propres dates (référence et date,
 * date d'expiration, validité en jours), puis les observations. Une caution ou une garantie expirée est écrite en rouge,
 * comme dans le tableau papier. Même contenu à l'écran et à l'impression (A4 paysage).
 */

const KINDS = {
  avances: {
    api: 'avance', icon: 'cash-coin', tab: 'Avances de démarrage', group: 'CAUTION D’AVANCE DE DÉMARRAGE', ref: 'Référence et date de la caution',
    title: 'SITUATION DES CAUTIONS D’AVANCE DE DÉMARRAGE', empty: 'Aucun contrat avec avance de démarrage', doc: 'Cautions d’avance de démarrage',
  },
  garanties: {
    api: 'garantie', icon: 'shield-check', tab: 'Garanties de bonne exécution', group: 'GARANTIE DE BONNE EXÉCUTION', ref: 'Référence et date de la garantie',
    title: 'SITUATION DES GARANTIES DE BONNE EXÉCUTION', empty: 'Aucune garantie de bonne exécution', doc: 'Garanties de bonne exécution',
  },
};

const days = (n) => (n === null || n === undefined ? '' : `${n} jour${Math.abs(n) > 1 ? 's' : ''}`);

/** Le tableau du projet : en-têtes sur deux lignes, « Référence et date » sur deux colonnes (référence et banque, puis date). */
function Table({ rows, kind }) {
  return (
    <table className="cau-table">
      <colgroup>
        <col className="cau-c-holder" /><col className="cau-c-amount" /><col className="cau-c-mode" />
        <col className="cau-c-date" /><col className="cau-c-date" />
        <col className="cau-c-ref" /><col className="cau-c-date" /><col className="cau-c-date" /><col className="cau-c-validity" /><col className="cau-c-obs" />
      </colgroup>
      <thead>
        <tr>
          <th rowSpan={2} scope="col">TITULAIRE</th>
          <th rowSpan={2} scope="col">MONTANT($)</th>
          <th rowSpan={2} scope="col">MODE D’ACQUISITION</th>
          <th colSpan={2} scope="colgroup">MARCHÉ</th>
          <th colSpan={5} scope="colgroup">{kind.group}</th>
        </tr>
        <tr>
          <th scope="col">Date de début d’exécution</th>
          <th scope="col">Date de fin contractuelle</th>
          <th colSpan={2} scope="colgroup">{kind.ref}</th>
          <th scope="col">Date d’expiration</th>
          <th scope="col">Validité</th>
          <th scope="col">OBSERVATIONS</th>
        </tr>
      </thead>
      <tbody>
        {rows.map((r) => (
          <tr key={r.contractId} className={`${r.expired ? 'is-expired' : ''} ${r.missing ? 'is-missing' : ''}`}>
            <td className="cau-holder">{r.holder}<span className="cau-contract">Contrat N° {r.contractNumber}</span></td>
            <td className="cau-num">{money(r.amount)}</td>
            <td className="cau-mode">{r.mode || <span className="cau-muted">—</span>}</td>
            <td className="cau-date">{fr(r.start)}</td>
            <td className="cau-date">{fr(r.end)}</td>
            {r.missing ? (
              <td colSpan={2} className="cau-muted cau-none">Non renseignée</td>
            ) : (
              <>
                <td className="cau-ref"><span>{r.reference}</span>{r.bank && <span className="cau-bank">{r.bank}</span>}</td>
                <td className="cau-date">{r.date ? fr(r.date) : <span className="cau-muted">—</span>}</td>
              </>
            )}
            <td className="cau-date">{r.expiry ? fr(r.expiry) : <span className="cau-muted">—</span>}</td>
            <td className="cau-num">{days(r.validity)}</td>
            <td className="cau-obs">{r.observation}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

export default function CautionsPage() {
  const { kind: slug } = useParams();
  const kind = KINDS[slug];
  const today = todayISO();
  const [date, setDate] = useState(today);
  const { data: d, loading, error } = useLoad('/reports/cautions', { type: kind?.api || 'avance', date });

  // Nom proposé par le navigateur à l'enregistrement en PDF.
  const docName = d && kind ? `${kind.doc} ${d.project} au ${d.longDate}` : null;
  useEffect(() => {
    if (!docName) return undefined;
    const before = document.title;
    document.title = docName;
    return () => { document.title = before; };
  }, [docName]);

  if (!kind) return <Navigate to="/reports/cautions/avances" replace />;
  const rows = d?.type === kind.api ? d.rows : [];
  const hasRows = rows.length > 0;
  const title = d ? `${kind.title} DU ${d.project}` : kind.title;
  const tabs = Object.entries(KINDS).map(([k, v]) => ({ to: `/reports/cautions/${k}`, label: v.tab, icon: v.icon }));

  return (
    <>
      <ModuleHeader
        icon={kind.icon} title="Cautions et garanties" tabs={tabs}
        actions={(
          <>
            <Link to="/reports" className={btnGhost}><i className="bi bi-arrow-left" aria-hidden="true" />Rapport de suivi</Link>
            <button type="button" className={btnPrimary} disabled={!hasRows} onClick={() => window.print()}><i className="bi bi-printer" aria-hidden="true" />Imprimer</button>
          </>
        )}
      />
      <form className={`${panel} tw:mb-4 tw:flex tw:flex-wrap tw:items-end tw:gap-x-6 tw:gap-y-3 tw:px-5 tw:py-3.5 tw:print:hidden`} onSubmit={(e) => e.preventDefault()} aria-label="Date de la situation">
        <div>
          <label className={label} htmlFor="cau-date">Situation au</label>
          <input id="cau-date" type="date" className={`${input.replace('tw:w-full ', '')} tw:w-auto`} value={date} max={today} onChange={(e) => setDate(e.target.value || today)} />
        </div>
        {d && hasRows && (
          <dl className="cau-figures">
            <div><dt>Contrats</dt><dd>{rows.length}</dd></div>
            <div className={d.expired ? 'is-alert' : ''}><dt>Expirées</dt><dd>{d.expired}</dd></div>
            {kind.api === 'avance' && <div className={d.missing ? 'is-warn' : ''}><dt>Non renseignées</dt><dd>{d.missing}</dd></div>}
          </dl>
        )}
      </form>

      {d && !d.ready && <Notice>La base n’est pas encore à jour (migration du 22/11/2026 ou script V40) : le mode d’acquisition et les cautions d’avance n’y sont pas encore enregistrés.</Notice>}
      {error && <Notice>{error}</Notice>}
      {loading && !d && <Loading />}
      {d && !loading && !hasRows && (
        <div className={`${panel} tw:px-5 tw:py-10 tw:text-center`}>
          <p className="tw:m-0 tw:font-display tw:text-lg tw:font-bold tw:text-prise-blue">{kind.empty} au {fr(d.date)}</p>
        </div>
      )}
      {hasRows && (
        <div className={loading ? 'cau-loading' : ''} aria-busy={loading}>
          <section className={`${panel} cau-screen`}>
            <header className="cau-head">
              <h2>{title} <span>au {d.longDate}</span></h2>
            </header>
            <div className="cau-scroll" tabIndex={0} role="region" aria-label={kind.doc}>
              <Table rows={rows} kind={kind} />
            </div>
          </section>
          <PrintDocument orientation="paysage">
            <article className="cau-print">
              <PrintLetterhead always title={title} subtitle={`au ${d.longDate}`} />
              <Table rows={rows} kind={kind} />
              <p className="cau-signoff">Établi le {fr(d.issuedBy.date)} par {d.issuedBy.name}{d.issuedBy.title ? `, ${d.issuedBy.title}` : ''}. Montants en USD ; validité en jours, de la date {kind.api === 'avance' ? 'de la caution' : 'd’émission'} à la date d’expiration.</p>
            </article>
          </PrintDocument>
        </div>
      )}
    </>
  );
}

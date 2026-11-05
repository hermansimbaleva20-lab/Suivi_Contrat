import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { fr } from '../../lib/format.js';
import { Empty } from '../../components/ui.jsx';
import { PrintDocument } from '../shared/PrintDocument.jsx';
import { PrintLetterhead } from '../shared/PrintLetterhead.jsx';

const HEADERS = ['N°', 'Contrat', 'Entreprise', 'Catégorie', 'Type d’ouvrage', 'Date estimative de démarrage', 'Durée des travaux'];

function Head() {
  return <thead><tr>{HEADERS.map((h) => <th key={h}>{h}</th>)}</tr></thead>;
}

/* Une ligne de titre par province ; la numérotation repart de 01 dans chacune, comme dans le rapport du projet. */
function Rows({ groups, links = false }) {
  return groups.map((g) => (
    <tbody key={g.key}>
      <tr className="province-row"><td colSpan={HEADERS.length}>{g.title}</td></tr>
      {g.contracts.map((c, i) => (
        <tr key={c.id}>
          <td className="pdoc-left">{String(i + 1).padStart(2, '0')}</td>
          <td className="pdoc-left">{links ? <Link className="contract-link" to={`/overview/${c.id}`}>{c.number}</Link> : c.number}</td>
          <td className="pdoc-left">{c.company || '—'}</td>
          <td className="pdoc-left">{c.category || '—'}</td>
          <td className="pdoc-left">{c.workType || '—'}</td>
          <td className="pdoc-left">{fr(c.startDate)}</td>
          <td className="pdoc-left">{c.months} mois</td>
        </tr>
      ))}
    </tbody>
  ));
}

/** Répartition des contrats par province, sur le modèle du tableau du rapport : à l'écran et à l'impression. */
export default function ProvinceContractsTable({ groups }) {
  const [printing, setPrinting] = useState(false);
  const count = new Set(groups.flatMap((g) => g.contracts.map((c) => c.id))).size;

  // Le document n'est monté que pour cette impression : il masquerait sinon tout le tableau de bord à l'impression de la page.
  useEffect(() => {
    if (!printing) return undefined;
    const done = () => setPrinting(false);
    window.addEventListener('afterprint', done);
    const logo = document.querySelector('.print-doc img');
    if (logo && !logo.complete) {
      logo.addEventListener('load', () => window.print(), { once: true });
      logo.addEventListener('error', () => window.print(), { once: true });
    } else {
      window.print();
    }
    return () => window.removeEventListener('afterprint', done);
  }, [printing]);

  return (
    <>
      <div className="cardx p-3 mb-3">
        <div className="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
          <div>
            <div className="section-title">Répartition des contrats par province</div>
            {groups.length > 0 && <div className="section-subtitle">{count} contrat(s) dans {groups.length} province(s). Un contrat présent dans plusieurs provinces figure sous chacune d’elles.</div>}
          </div>
          {groups.length > 0 && <button type="button" className="btn btn-outline-primary btn-sm" onClick={() => setPrinting(true)}><i className="bi bi-printer me-1" />Imprimer</button>}
        </div>
        <div className="table-wrap">
          <table className="table table-sm align-middle province-table">
            <Head />
            {groups.length > 0
              ? <Rows groups={groups} links />
              : <tbody><tr><td colSpan={HEADERS.length}><Empty text="Aucun contrat enregistré pour cette phase." /></td></tr></tbody>}
          </table>
        </div>
      </div>
      {printing && (
        <PrintDocument orientation="portrait">
          <PrintLetterhead always title="Répartition des contrats par province et durée des travaux" />
          <table className="pdoc-table pdoc-provinces">
            <Head />
            <Rows groups={groups} />
          </table>
        </PrintDocument>
      )}
    </>
  );
}

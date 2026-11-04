import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { useLoad } from '../../lib/hooks.js';
import { useAuth } from '../../context/AuthContext.jsx';
import { frDateTime } from '../../lib/format.js';
import { Modal, Notice } from '../../components/ui.jsx';
import { btnGhost, input, label, panel } from '../shared/tw.jsx';

/*
 * Historique des messages envoyés aux entreprises et au personnel du projet (automatiques ou manuels, réussis ou en échec),
 * filtré par contrat ou, pour la garantie de bonne exécution, par n° de garantie. Le texte exact envoyé est consultable.
 */

const th = 'tw:whitespace-nowrap tw:border-b tw:border-prise-border tw:bg-prise-bg tw:px-3 tw:py-2.5 tw:text-left tw:font-tech tw:text-[11px] tw:font-semibold tw:uppercase tw:tracking-wide tw:text-prise-muted';
const td = 'tw:border-b tw:border-prise-border tw:px-3 tw:py-2.5 tw:text-sm tw:text-prise-text tw:align-top';
const pill = 'tw:inline-block tw:whitespace-nowrap tw:rounded-full tw:px-2 tw:py-0.5 tw:text-[11px] tw:font-bold';
// Champ en ligne : la classe commune impose toute la largeur.
const select = `${input.replace('tw:w-full ', '')} tw:w-full tw:sm:w-auto tw:sm:min-w-44`;
const KIND = {
  VIGILANCE: ['Vigilance', 'tw:bg-amber-50 tw:text-amber-800'], RETARD: ['Retard', 'tw:bg-red-50 tw:text-prise-danger'], SATISFACTION: ['Satisfaction', 'tw:bg-prise-green-soft tw:text-prise-green-2'],
  PERSONNEL: ['Personnel du projet', 'tw:bg-sky-50 tw:text-sky-800'], RAPPEL: ['Rappel de décompte', 'tw:bg-sky-50 tw:text-sky-800'],
};
const STATUS = { ENVOYE: ['Envoyé', 'tw:bg-prise-green-soft tw:text-prise-green-2'], ECHEC: ['Échec', 'tw:bg-red-50 tw:text-prise-danger'] };
const SCOPES = [['', 'Tous'], ['GARANTIE', 'Garanties'], ['CONTRAT', 'Contrats']];

export default function AlertHistoryPanel({ rev = 0 }) {
  const { can } = useAuth();
  const seeGuarantees = can('canViewAlertsGuarantees');
  const seeBoth = seeGuarantees && can('canViewAlertsContracts');
  const [f, setF] = useState({ scope: '', contract_id: '', guarantee_id: '', type: '', status: '' });
  const params = Object.fromEntries(Object.entries({ ...f, rev: rev || '' }).filter(([, v]) => v !== ''));
  const { data, loading, error } = useLoad('/alerts/history', params);
  const [shown, setShown] = useState(null);
  const set = (k, v) => setF((s) => {
    const next = { ...s, [k]: v };
    if (k === 'scope' && v === 'CONTRAT') next.guarantee_id = '';
    if (k === 'guarantee_id' && v) next.scope = 'GARANTIE';
    if (k === 'contract_id') next.guarantee_id = '';
    return next;
  });
  const guarantees = (data?.guarantees || []).filter((g) => !f.contract_id || String(g.contractId) === f.contract_id);

  return (
    <section className={panel} aria-labelledby="hist-title">
      <header className="tw:border-b tw:border-prise-border tw:px-5 tw:py-4">
        <h2 id="hist-title" className="tw:m-0 tw:font-display tw:text-[17px] tw:font-bold tw:text-prise-blue">Historique des envois</h2>
        <p className="tw:m-0 tw:mt-1 tw:text-xs tw:text-prise-muted">Messages adressés aux entreprises (relances automatiques des garanties, envois manuels) rappels au personnel du projet et rappels de décomptes à valider. Choisissez un contrat ou un n° de garantie pour suivre ses messages.</p>
      </header>
      <div className="tw:flex tw:flex-wrap tw:items-end tw:gap-3 tw:border-b tw:border-prise-border tw:px-5 tw:py-3">
        {seeBoth && <div role="group" aria-label="Messages" className="tw:flex tw:rounded-lg tw:border tw:border-prise-border tw:bg-white tw:p-0.5">
          {SCOPES.map(([v, l]) => (
            <button key={v} type="button" aria-pressed={f.scope === v} onClick={() => set('scope', v)}
              className={`tw:cursor-pointer tw:rounded-md tw:border-0 tw:px-3 tw:py-1.5 tw:text-[13px] tw:font-semibold ${f.scope === v ? 'tw:bg-prise-green-2 tw:text-white' : 'tw:bg-transparent tw:text-prise-text tw:hover:bg-prise-green-soft'}`}>{l}</button>
          ))}
        </div>}
        <div className="tw:w-full tw:sm:w-auto">
          <label className={label} htmlFor="h-contract">Contrat</label>
          <select id="h-contract" className={select} value={f.contract_id} onChange={(e) => set('contract_id', e.target.value)}>
            <option value="">Tous les contrats</option>
            {(data?.contracts || []).map((c) => <option key={c.id} value={c.id}>{c.number}{c.company ? ` — ${c.company}` : ''}</option>)}
          </select>
        </div>
        {seeGuarantees && <div className="tw:w-full tw:sm:w-auto">
          <label className={label} htmlFor="h-guarantee">N° de garantie</label>
          <select id="h-guarantee" className={select} value={f.guarantee_id} disabled={f.scope === 'CONTRAT'} onChange={(e) => set('guarantee_id', e.target.value)}>
            <option value="">Toutes les garanties</option>
            {guarantees.map((g) => <option key={g.id} value={g.id}>{g.number || 'Sans n°'} — contrat {g.contractNumber}</option>)}
          </select>
        </div>}
        <div className="tw:w-full tw:sm:w-auto">
          <label className={label} htmlFor="h-type">Nature</label>
          <select id="h-type" className={select} value={f.type} onChange={(e) => set('type', e.target.value)}>
            <option value="">Toutes</option>
            {Object.entries(KIND).map(([k, [l]]) => <option key={k} value={k}>{l}</option>)}
          </select>
        </div>
        <div className="tw:w-full tw:sm:w-auto">
          <label className={label} htmlFor="h-status">Résultat</label>
          <select id="h-status" className={select} value={f.status} onChange={(e) => set('status', e.target.value)}>
            <option value="">Tous</option>
            <option value="ENVOYE">Envoyé</option>
            <option value="ECHEC">Échec</option>
          </select>
        </div>
      </div>
      {error && <div className="tw:px-5 tw:pt-3"><Notice>{error}</Notice></div>}
      <div className="tw:overflow-x-auto" aria-busy={loading}>
        <table className="tw:w-full tw:border-collapse">
          <thead>
            <tr>
              <th className={th} scope="col">Date</th>
              <th className={th} scope="col">Message</th>
              <th className={th} scope="col">Contrat</th>
              <th className={th} scope="col">N° garantie</th>
              <th className={th} scope="col">Destinataires</th>
              <th className={th} scope="col">Résultat</th>
              <th className={th} scope="col"><span className="tw:sr-only">Texte</span></th>
            </tr>
          </thead>
          <tbody>
            {(data?.data || []).map((d) => (
              <tr key={d.id}>
                <td className={`${td} tw:whitespace-nowrap tw:font-tech tw:text-xs`}>{frDateTime(d.date)}<span className="tw:block tw:font-body tw:text-[11px] tw:text-prise-muted">{d.trigger === 'AUTO' ? 'envoi planifié' : `manuel${d.sentBy ? ` · ${d.sentBy}` : ''}`}</span></td>
                <td className={td}>
                  <span className="tw:flex tw:flex-wrap tw:gap-1">
                    <span className={`${pill} tw:bg-prise-bg tw:text-prise-text`}>{d.scope === 'GARANTIE' ? 'Garantie' : 'Contrat'}</span>
                    {d.type && KIND[d.type] && <span className={`${pill} ${KIND[d.type][1]}`}>{KIND[d.type][0]}</span>}
                  </span>
                  <span className="tw:mt-1 tw:block tw:text-xs">{d.subject}</span>
                </td>
                <td className={`${td} tw:whitespace-nowrap`}><Link className="contract-link" to={`/overview/${d.contractId}`}>{d.contractNumber}</Link><span className="tw:block tw:text-xs tw:text-prise-muted">{d.company}</span></td>
                <td className={`${td} tw:whitespace-nowrap tw:font-tech tw:text-xs`}>{d.guaranteeNumber || (d.scope === 'GARANTIE' ? 'non renseigné' : '—')}</td>
                <td className={`${td} tw:max-w-64 tw:text-xs tw:break-words`}>{d.recipients}</td>
                <td className={td}>
                  <span className={`${pill} ${STATUS[d.status]?.[1] || ''}`}>{STATUS[d.status]?.[0] || d.status}</span>
                  {d.status === 'ECHEC' && <span className="tw:mt-1 tw:block tw:max-w-56 tw:text-[11px] tw:text-prise-danger">{d.message}</span>}
                </td>
                <td className={`${td} tw:text-right`}>
                  {d.body && <button type="button" className={btnGhost} onClick={() => setShown(d)} title="Voir le message envoyé"><i className="bi bi-envelope-open" aria-hidden="true" /><span className="tw:sr-only">Voir le message du {frDateTime(d.date)}</span></button>}
                </td>
              </tr>
            ))}
            {data && !data.data.length && <tr><td colSpan={7} className="tw:px-5 tw:py-6 tw:text-center tw:text-sm tw:text-prise-muted">Aucun message pour cette sélection.</td></tr>}
          </tbody>
        </table>
      </div>
      {data && data.total > data.data.length && <p className="tw:m-0 tw:px-5 tw:py-2.5 tw:text-xs tw:text-prise-muted">{data.data.length} messages les plus récents sur {data.total} : affinez la sélection pour voir les plus anciens.</p>}
      {data && data.total > 0 && data.total <= data.data.length && <p className="tw:m-0 tw:px-5 tw:py-2.5 tw:text-xs tw:text-prise-muted">{data.total} message(s).</p>}

      {shown && (
        <Modal title="Message envoyé" subtitle={`${frDateTime(shown.date)} · contrat ${shown.contractNumber}${shown.guaranteeNumber ? ` · garantie n° ${shown.guaranteeNumber}` : ''}`} onClose={() => setShown(null)}>
          <p className="tw:mb-1 tw:text-xs tw:text-prise-muted">À : {shown.recipients}</p>
          <p className="tw:mb-3 tw:font-semibold">{shown.subject}</p>
          <div className="tw:whitespace-pre-line tw:rounded-lg tw:border tw:border-prise-border tw:bg-prise-bg tw:p-4 tw:text-sm tw:leading-relaxed">{shown.body}</div>
        </Modal>
      )}
    </section>
  );
}

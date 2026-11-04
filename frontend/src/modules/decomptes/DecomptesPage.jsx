import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { api, apiError, fieldErrors } from '../../api.js';
import { useLoad } from '../../lib/hooks.js';
import { fr, frDateTime, money, num, todayISO } from '../../lib/format.js';
import { Loading, Modal, Notice } from '../../components/ui.jsx';
import { btn, btnGhost, btnPrimary, FieldError, input, label, panel } from '../shared/tw.jsx';
import { ModuleHeader } from '../shared/ModuleHeader.jsx';
import { PrintDocument } from '../shared/PrintDocument.jsx';
import { PrintLetterhead } from '../shared/PrintLetterhead.jsx';

import { useMutation, useParam } from '../fleet/fleetHooks.js';
import { dec } from '../fleet/fleetFormat.js';

/*
 * Décomptes — « Situation du contrat » (modèle du projet) : avance perçue en première ligne, puis chaque décompte apporté
 * par l'entreprise avec ses retenues (avance de démarrage, garantie de bonne exécution), le montant payé et les soldes.
 * Chaque ligne est transmise au chef de cellule infrastructures, qui la valide ou la rejette.
 */

const pct = (r) => `${Math.round(r * 10000) / 100} %`;
const STATUS = {
  EN_ATTENTE: ['En attente', 'tw:bg-amber-50 tw:text-amber-800'],
  VALIDE: ['Validé', 'tw:bg-prise-green-soft tw:text-prise-green-2'],
  REJETE: ['Rejeté', 'tw:bg-red-50 tw:text-prise-danger'],
};
const th = 'tw:border-b tw:border-prise-border tw:bg-prise-bg tw:px-2.5 tw:py-2.5 tw:text-right tw:font-tech tw:text-[11px] tw:font-semibold tw:uppercase tw:tracking-wide tw:text-prise-muted tw:align-bottom';
const td = 'tw:border-b tw:border-prise-border tw:px-2.5 tw:py-2.5 tw:text-right tw:font-tech tw:text-sm tw:tabular-nums tw:whitespace-nowrap';

/** Ce que le chef de cellule a décidé, et quand (date de l'opération saisie). */
function decisionText(r) {
  if (r.status === 'EN_ATTENTE') return r.waitsForEarlier ? 'après la ligne précédente' : '';
  const when = r.decisionDate ? ` le ${fr(r.decisionDate)}` : '';
  return `${r.decidedBy || ''}${when}${r.decisionComment ? ` : ${r.decisionComment}` : ''}`;
}

/** Rejet : le chef de cellule écrit le motif et la date de l'opération. */
function RejectDialog({ row, onClose, onDone }) {
  const [f, setF] = useState({ decision_date: todayISO(), comment: '' });
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);
  const submit = async (e) => {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    try {
      const r = await api.post(`/decomptes/${row.id}/decision`, { decision: 'REJETE', ...f });
      onDone(r.data.message);
    } catch (err) {
      const fe = fieldErrors(err);
      setErrors(Object.keys(fe).length ? fe : { comment: apiError(err) });
      setBusy(false);
    }
  };
  return (
    <Modal title={`Rejeter ${row.number}`} subtitle={`Décompte du ${fr(row.date)} : ${money(row.gross)} USD`} size="md" onClose={onClose}
      footer={<><button type="button" className="btn btn-light" onClick={onClose}>Annuler</button><button type="submit" form="dc-reject" className="btn btn-danger" disabled={busy}>Rejeter le décompte</button></>}>
      <form id="dc-reject" className="tw:grid tw:gap-3" onSubmit={submit}>
        <p className="tw:m-0 tw:text-sm tw:text-prise-muted">Le décompte restera visible, barré, et ne comptera pas dans la situation du contrat. L’auteur de la saisie sera prévenu.</p>
        <div>
          <label className={label} htmlFor="dc-reject-date">Date de l’opération de rejet</label>
          <input id="dc-reject-date" type="date" className={`${input} tw:font-tech tw:sm:w-56`} value={f.decision_date} min={row.date} max={todayISO()}
            onChange={(e) => setF({ ...f, decision_date: e.target.value })} required />
          <FieldError text={errors.decision_date} />
        </div>
        <div>
          <label className={label} htmlFor="dc-reject-comment">Motif du rejet</label>
          <textarea id="dc-reject-comment" className={`${input} tw:min-h-24`} value={f.comment} maxLength={500} onChange={(e) => setF({ ...f, comment: e.target.value })} required autoFocus />
          <FieldError text={errors.comment} />
        </div>
      </form>
    </Modal>
  );
}

/*
 * Impression de la situation du contrat, sur le modèle papier du projet : entête du projet, intitulé du projet en vert
 * (nom court souligné), titre « SITUATION DU CONTRAT », puis le tableau seul (sans total ni notes).
 */
function DecomptePrint({ c, rows, totals }) {
  return (
    <PrintDocument orientation="paysage">
      <PrintLetterhead always title={`Situation du contrat N° ${c.number} — ${c.company}`} />
      <table className="pdoc-table">
        <thead>
          <tr>
            <th>N°</th><th>N° décompte</th><th>Date</th><th>Montant décompte USD</th><th>Avance de démarrage USD</th>
            <th>Retenue garantie B.E. USD</th>{c.usesPenalty && <th>Pénalité de retard USD</th>}<th>Montant payé</th><th>Montant contrat</th><th>Solde contrat</th><th>Avance perçue</th>
            <th>Solde avance</th>
          </tr>
        </thead>
        <tbody>
          <tr className="pdoc-advance">
            <td /><td className="pdoc-left pdoc-nowrap">Avance perçue</td><td /><td /><td /><td />{c.usesPenalty && <td />}
            <td>{money(c.advanceReceived)}</td><td>{money(c.amount)}</td><td>{money(c.amount - c.advanceReceived)}</td>
            <td>{money(c.advanceReceived)}</td><td>{money(c.advanceReceived)}</td>
          </tr>
          {rows.map((r) => {
            const rejected = r.status === 'REJETE';
            const cls = rejected ? 'pdoc-rejected' : r.kind === 'LIBERATION' ? 'pdoc-release' : '';
            const v = (x) => (rejected ? '' : money(x));
            return (
              <tr key={r.id} className={cls}>
                <td>{r.lineNo}</td>
                <td className="pdoc-left pdoc-strong"><span className="pdoc-amount pdoc-nowrap">{r.number}</span>{r.observation && <span className="pdoc-note">{r.observation}</span>}</td>
                <td className="pdoc-left">{fr(r.date)}</td>
                <td className="pdoc-amount">{money(r.gross)}</td>
                <td>{v(r.advance)}</td><td>{v(r.guarantee)}</td>{c.usesPenalty && <td>{v(r.penalty)}</td>}<td className="pdoc-strong">{v(r.net)}</td>
                <td>{v(c.amount)}</td><td>{v(r.contractBalance)}</td><td>{v(c.advanceReceived)}</td><td>{v(r.advanceBalance)}</td>
              </tr>
            );
          })}
        </tbody>
        {/* Total validé, comme à l'écran : le montant payé inclut l'avance perçue. */}
        <tfoot>
          <tr className="pdoc-total">
            <th scope="row" colSpan={3}>Total validé</th>
            <td>{money(totals.gross)}</td><td>{money(totals.advance)}</td><td>{money(totals.guarantee)}</td>{c.usesPenalty && <td>{money(totals.penalty)}</td>}<td>{money(totals.net + c.advanceReceived)}</td>
            <td /><td /><td /><td />
          </tr>
        </tfoot>
      </table>
    </PrintDocument>
  );
}

function DecompteForm({ c, rows, nextNumber, onSubmit, onCancel, busy, errors }) {
  const [f, setF] = useState({ number: nextNumber, operation_date: todayISO(), gross_amount: '', observation: '', is_final: false, reception_date: '' });
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value });
  const live = rows.filter((r) => r.status !== 'REJETE');
  const advanceBalance = live.length ? live[live.length - 1].advanceBalance : c.advanceReceived;
  const gross = dec(f.gross_amount);
  const advance = Number.isFinite(gross) ? Math.min(gross * c.advanceRate, Math.max(0, advanceBalance)) : NaN;
  const guarantee = Number.isFinite(gross) ? gross * c.guaranteeRate : NaN;
  // Procédure nationale : le décompte qui atteint le montant du marché, ou marqué comme dernier, porte la pénalité de retard.
  const reachesAmount = c.usesPenalty && Number.isFinite(gross) && c.grossSoFar + gross >= c.amount - 0.005;
  const isFinal = c.usesPenalty && (f.is_final || reachesAmount);
  const weeks = isFinal && f.reception_date > c.endDate ? Math.floor((Date.parse(f.reception_date) - Date.parse(c.endDate)) / 86400000 / 7) : 0;
  const penalty = isFinal && Number.isFinite(gross) ? Math.min(c.amount * c.penaltyRate / 100 * weeks, c.amount * c.penaltyCap / 100, Math.max(0, gross - advance - guarantee)) : 0;
  return (
    <form className="tw:grid tw:gap-3 tw:border-b tw:border-prise-border tw:bg-prise-bg/60 tw:px-5 tw:py-4 tw:sm:grid-cols-12 tw:print:hidden" onSubmit={(e) => { e.preventDefault(); onSubmit({ ...f, is_final: isFinal }); }}>
      <div className="tw:sm:col-span-3">
        <label className={label} htmlFor="dc-number">N° du décompte</label>
        <input id="dc-number" className={input} value={f.number} onChange={set('number')} maxLength={60} required />
        <FieldError text={errors.number} />
      </div>
      <div className="tw:sm:col-span-3">
        <label className={label} htmlFor="dc-date">Date de l’opération</label>
        <input id="dc-date" type="date" className={`${input} tw:font-tech`} value={f.operation_date} max={todayISO()} onChange={set('operation_date')} required />
        <FieldError text={errors.operation_date} />
      </div>
      <div className="tw:sm:col-span-3">
        <label className={label} htmlFor="dc-gross">Montant du décompte (USD)</label>
        <input id="dc-gross" className={`${input} tw:font-tech tw:text-right`} inputMode="decimal" autoComplete="off" value={f.gross_amount} onChange={set('gross_amount')} required autoFocus />
        <FieldError text={errors.gross_amount} />
      </div>
      <div className="tw:sm:col-span-3">
        <label className={label} htmlFor="dc-obs">Observation (facultatif)</label>
        <input id="dc-obs" className={input} value={f.observation} onChange={set('observation')} maxLength={500} />
      </div>
      {c.usesPenalty && (
        <>
          <label className="tw:flex tw:items-center tw:gap-2 tw:self-end tw:pb-2 tw:text-sm tw:sm:col-span-5">
            <input type="checkbox" checked={isFinal} disabled={reachesAmount} onChange={(e) => setF({ ...f, is_final: e.target.checked })} />
            Dernier décompte (réception des biens){reachesAmount ? ' : le montant du marché est atteint' : ''}
          </label>
          {isFinal && (
            <div className="tw:sm:col-span-3">
              <label className={label} htmlFor="dc-reception">Date de réception des biens</label>
              <input id="dc-reception" type="date" className={`${input} tw:font-tech`} value={f.reception_date} max={todayISO()} onChange={set('reception_date')} required />
              <FieldError text={errors.reception_date} />
            </div>
          )}
        </>
      )}
      <dl className={`tw:m-0 tw:grid tw:grid-cols-2 tw:gap-x-6 tw:gap-y-1 tw:rounded-lg tw:bg-white tw:px-4 tw:py-3 tw:text-sm tw:ring-1 tw:ring-prise-border tw:sm:col-span-9 ${c.usesPenalty ? 'tw:md:grid-cols-5' : 'tw:md:grid-cols-4'}`} aria-live="polite">
        {[
          [`Avance de démarrage (${pct(c.advanceRate)})`, advance],
          [`Retenue garantie B.E. (${pct(c.guaranteeRate)})`, guarantee],
          ...(c.usesPenalty ? [[`Pénalité de retard (${weeks} sem.)`, penalty]] : []),
          ['Montant payé', gross - advance - guarantee - penalty],
          ['Solde avance après', advanceBalance - advance],
        ].map(([k, v]) => (
          <div key={k}><dt className="tw:text-[11px] tw:text-prise-muted">{k}</dt><dd className="tw:m-0 tw:font-tech tw:font-semibold tw:tabular-nums">{Number.isFinite(v) ? money(v) : '—'}</dd></div>
        ))}
      </dl>
      <div className="tw:flex tw:items-end tw:justify-end tw:gap-2 tw:sm:col-span-3">
        <button type="button" className={btnGhost} onClick={onCancel}>Annuler</button>
        <button type="submit" className={btnPrimary} disabled={busy}>Transmettre au chef de cellule</button>
      </div>
    </form>
  );
}

export default function DecomptesPage() {
  const [contractParam, setContract] = useParam('contrat');
  const { data, loading, error, reload } = useLoad('/decomptes', contractParam ? { contract_id: contractParam } : {});
  const { flash, setFlash, errors, setErrors, busy, call } = useMutation(reload);
  const [adding, setAdding] = useState(false);
  const [rejecting, setRejecting] = useState(null);

  if (loading && !data) return <Loading />;
  if (!data) return <Notice>{error}</Notice>;

  const header = (actions) => (
    <ModuleHeader icon="receipt-cutoff" title="Décomptes" unit={data.selected ? `Contrat ${data.selected.number}` : null} actions={actions}
      desc="Situation du contrat : chaque décompte apporté par l’entreprise, ses retenues (avance de démarrage, garantie de bonne exécution) et les soldes. Chaque ligne est validée ou rejetée par le chef de cellule infrastructures." />
  );
  if (!data.selected) return <>{header()}<Notice type="info">Aucun contrat dans cette phase.</Notice></>;

  const c = data.selected;
  const t = data.totals;
  const noValidator = data.canSubmit && !data.validators.length;
  const save = (f) => call(() => api.post(`/contracts/${c.id}/decomptes`, f), () => setAdding(false));
  const decide = (row, decision) => call(() => api.post(`/decomptes/${row.id}/decision`, { decision }));
  const rejected = async (message) => { setRejecting(null); setFlash({ type: 'success', text: message }); await reload(); };

  return (
    <>
      {header(
        <>
          <select aria-label="Contrat" className={`${input} tw:w-80`} value={c.id} onChange={(e) => setContract(e.target.value)}>
            {data.contracts.map((x) => <option key={x.id} value={x.id}>{x.number} — {x.company}</option>)}
          </select>
          <button type="button" className={btnGhost} onClick={() => window.print()}><i className="bi bi-printer" aria-hidden="true" />Imprimer</button>
        </>,
      )}
      {noValidator && <Notice type="warning">Aucun compte n’est encore habilité à valider les décomptes : le super-administrateur doit l’accorder au chef de cellule infrastructures dans Utilisateurs. Sans cela, aucun rappel par e-mail ne peut partir.</Notice>}
      <Notice type={flash.type} onClose={() => setFlash({ ...flash, text: '' })}>{flash.text}</Notice>

      <DecomptePrint c={c} rows={data.rows} totals={t} />


      <section className={`${panel} tw:print:border-0 tw:print:shadow-none`}>
        <header className="tw:flex tw:flex-wrap tw:items-center tw:gap-x-6 tw:gap-y-2 tw:border-b tw:border-prise-border tw:px-5 tw:py-4">
          <h2 className="tw:m-0 tw:font-display tw:text-[17px] tw:font-bold tw:text-prise-blue">{c.company}</h2>
          <dl className="tw:m-0 tw:flex tw:flex-wrap tw:gap-x-6 tw:gap-y-1 tw:text-sm">
            {[
              ['Catégorie', c.goodsProcedureLabel ? `${c.category} — ${c.goodsProcedureLabel}` : c.category],
              ['Montant du contrat', `${money(c.amount)} USD`],
              ['Avance perçue', c.isService ? 'aucune (contrat de service)' : c.advanceOnRequest && c.advanceStatus !== 'PAYEE' ? `non versée (${pct(c.advanceRate)}, sur demande)` : `${money(c.advanceReceived)} USD (${pct(c.advanceRate)})`],
              ['Retenue de garantie', c.guaranteeRate ? pct(c.guaranteeRate) : 'aucune garantie enregistrée'],
            ].map(([k, v]) => <div key={k} className="tw:flex tw:gap-1.5"><dt className="tw:text-prise-muted">{k}</dt><dd className="tw:m-0 tw:font-tech tw:font-semibold tw:tabular-nums">{v}</dd></div>)}
          </dl>
          <div className="tw:ml-auto tw:flex tw:flex-wrap tw:gap-2 tw:print:hidden">
            {data.release.canRelease && (
              <button type="button" className={btnGhost} disabled={busy} title="Reverse à l’entreprise la retenue de garantie cumulée ; le comptable valide la ligne"
                onClick={() => call(() => api.post(`/contracts/${c.id}/decomptes/release`, { operation_date: todayISO() }))}>
                <i className="bi bi-arrow-return-left" aria-hidden="true" />Reverser la retenue ({money(data.release.amount)} USD)
              </button>
            )}
            {data.canSubmit && !adding && (
              <button type="button" className={btnPrimary} onClick={() => { setErrors({}); setAdding(true); }}><i className="bi bi-plus-lg" aria-hidden="true" />Nouveau décompte</button>
            )}
          </div>
        </header>
        {!data.release.canRelease && data.release.amount > 0 && (
          <p className="tw:m-0 tw:border-b tw:border-prise-border tw:px-5 tw:py-2 tw:text-xs tw:text-prise-muted tw:print:hidden">
            Retenue de garantie encore détenue : <strong className="tw:font-tech">{money(data.release.amount)} USD</strong>. {data.release.blocker}
          </p>
        )}
        {!c.guaranteeRate && !c.isService && <p className="tw:m-0 tw:border-b tw:border-prise-border tw:bg-amber-50/70 tw:px-5 tw:py-2 tw:text-sm tw:text-amber-900">Aucune garantie de bonne exécution n’est enregistrée pour ce contrat : la retenue de garantie sera nulle. Enregistrez la garantie avant de saisir les décomptes.</p>}
        {data.submitBlocker && <p className="tw:m-0 tw:border-b tw:border-prise-border tw:bg-amber-50/70 tw:px-5 tw:py-2 tw:text-sm tw:text-amber-900 tw:print:hidden">{data.submitBlocker}</p>}
        {data.canSubmit && c.advanceOnRequest && !c.advanceStatus && !data.rows.some((r) => r.status !== 'REJETE') && c.advanceRate > 0 && (
          <p className="tw:m-0 tw:border-b tw:border-prise-border tw:bg-amber-50/70 tw:px-5 tw:py-2 tw:text-sm tw:text-amber-900 tw:print:hidden">L’avance n’a pas été demandée. Une fois un décompte saisi, elle ne pourra plus l’être : enregistrez d’abord la demande de l’entreprise dans <Link to={`/payments?contrat=${c.id}`}>Paiements</Link>, s’il y a lieu.</p>
        )}
        {adding && <DecompteForm c={c} rows={data.rows} nextNumber={data.nextNumber} onSubmit={save} onCancel={() => setAdding(false)} busy={busy} errors={errors} />}

        <div className="tw:overflow-x-auto">
          <table className="tw:w-full tw:border-collapse">
            <thead>
              <tr>
                <th className={`${th} tw:text-left`} scope="col">N°</th>
                <th className={`${th} tw:text-left`} scope="col">N° décompte</th>
                <th className={`${th} tw:text-left`} scope="col">Date</th>
                <th className={th} scope="col">Montant décompte USD</th>
                <th className={th} scope="col">Avance de démarrage USD</th>
                <th className={th} scope="col">Retenue garantie B.E. USD</th>
                {c.usesPenalty && <th className={th} scope="col">Pénalité de retard USD</th>}
                <th className={th} scope="col">Montant payé</th>
                <th className={th} scope="col">Montant contrat</th>
                <th className={th} scope="col">Solde contrat</th>
                <th className={th} scope="col">Avance perçue</th>
                <th className={th} scope="col">Solde avance</th>
                <th className={`${th} tw:text-left`} scope="col">Décision</th>
              </tr>
            </thead>
            <tbody>
              <tr className="tw:bg-prise-bg/60">
                <td className={`${td} tw:text-left`} />
                <td className={`${td} tw:text-left tw:font-body tw:font-bold`}>Avance perçue</td>
                <td className={td} /><td className={td} /><td className={td} /><td className={td} />
                {c.usesPenalty && <td className={td} />}
                <td className={`${td} tw:font-semibold`}>{money(c.advanceReceived)}</td>
                <td className={td}>{money(c.amount)}</td>
                <td className={td}>{money(c.amount - c.advanceReceived)}</td>
                <td className={td}>{money(c.advanceReceived)}</td>
                <td className={td}>{money(c.advanceReceived)}</td>
                <td className={td} />
              </tr>
              {data.rows.map((r) => {
                const rejected = r.status === 'REJETE';
                const s = rejected ? 'tw:text-prise-muted tw:line-through' : '';
                return (
                  <tr key={r.id} className={r.status === 'EN_ATTENTE' ? 'tw:bg-amber-50/40' : ''}>
                    <td className={`${td} tw:text-left tw:text-prise-muted`}>{r.lineNo}</td>
                    <td className={`${td} tw:text-left tw:font-body tw:font-semibold ${s} ${r.kind === 'LIBERATION' ? 'tw:uppercase tw:text-prise-blue' : ''}`}>{r.number}{r.observation && <span className="tw:block tw:text-[11px] tw:font-normal tw:text-prise-muted tw:no-underline">{r.observation}</span>}</td>
                    <td className={`${td} tw:text-left ${s}`}>{fr(r.date)}</td>
                    <td className={`${td} ${s}`}>{money(r.gross)}</td>
                    <td className={td}>{rejected ? '' : money(r.advance)}</td>
                    <td className={td}>{rejected ? '' : money(r.guarantee)}</td>
                    {c.usesPenalty && (
                      <td className={td}>
                        {rejected ? '' : money(r.penalty)}
                        {r.isFinal && !rejected && <span className="tw:ml-auto tw:block tw:max-w-24 tw:whitespace-normal tw:font-body tw:text-[11px] tw:leading-tight tw:text-prise-muted">réception {fr(r.receptionDate)}, {r.penaltyWeeks} sem.</span>}
                      </td>
                    )}
                    <td className={`${td} tw:font-semibold`}>{rejected ? '' : money(r.net)}</td>
                    <td className={td}>{rejected ? '' : money(c.amount)}</td>
                    <td className={`${td} ${r.contractBalance < 0 ? 'tw:text-prise-danger' : ''}`}>{rejected ? '' : money(r.contractBalance)}</td>
                    <td className={td}>{rejected ? '' : money(c.advanceReceived)}</td>
                    <td className={td}>{rejected ? '' : money(r.advanceBalance)}</td>
                    <td className={`${td} tw:text-left tw:font-body`}>
                      <span className={`tw:rounded-full tw:px-2 tw:py-0.5 tw:text-[11px] tw:font-bold ${STATUS[r.status][1]}`}>{STATUS[r.status][0]}</span>
                      {decisionText(r) && <span className="tw:block tw:max-w-64 tw:whitespace-normal tw:text-[11px] tw:text-prise-muted" title={r.decidedAt ? `Enregistré le ${frDateTime(r.decidedAt)}` : undefined}>{decisionText(r)}</span>}
                      {r.canDecide && (
                        <span className="tw:mt-1 tw:flex tw:gap-1 tw:print:hidden">
                          <button type="button" className={`${btn} tw:bg-prise-green-2 tw:text-white tw:hover:bg-prise-green`} disabled={busy} onClick={() => decide(r, 'VALIDE')}>Valider</button>
                          <button type="button" className={`${btn} tw:bg-transparent tw:text-prise-danger tw:hover:bg-red-50`} disabled={busy} onClick={() => setRejecting(r)}>Rejeter</button>
                        </span>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
            <tfoot>
              <tr className="tw:bg-prise-green-soft">
                <th scope="row" colSpan={3} className={`${td} tw:text-left tw:font-body tw:font-bold tw:text-prise-green`}>Total validé</th>
                <td className={`${td} tw:font-bold`}>{money(t.gross)}</td>
                <td className={`${td} tw:font-bold`}>{money(t.advance)}</td>
                <td className={`${td} tw:font-bold`}>{money(t.guarantee)}</td>
                {c.usesPenalty && <td className={`${td} tw:font-bold`}>{money(t.penalty)}</td>}
                <td className={`${td} tw:font-bold`}>{money(t.net + c.advanceReceived)}</td>
                <td className={td} colSpan={5}>{t.pending > 0 && <span className="tw:font-body tw:text-xs tw:text-amber-800">{t.pending} décompte(s) en attente : {money(t.pendingGross)} USD</span>}</td>
              </tr>
            </tfoot>
          </table>
        </div>
        {!data.rows.length && <p className="tw:m-0 tw:px-5 tw:py-6 tw:text-center tw:text-sm tw:text-prise-muted">Aucun décompte pour ce contrat.{data.canSubmit ? ' Saisissez le premier décompte apporté par l’entreprise.' : ''}</p>}
        {c.usesPenalty && <p className="tw:m-0 tw:px-5 tw:pt-3 tw:text-[11px] tw:text-prise-muted">Pénalité de retard = {num(c.penaltyRate, 3)} % du montant du marché par semaine complète entre la fin du contrat ({fr(c.endDate)}) et la réception des biens, plafonnée à {num(c.penaltyCap, 2)} %, déduite du montant payé du dernier décompte.</p>}
        <p className="tw:m-0 tw:px-5 tw:py-3 tw:text-[11px] tw:text-prise-muted">
          Avance de démarrage = montant du décompte × taux d’avance, jusqu’à épuisement de l’avance perçue · Retenue de garantie = montant du décompte × taux de la garantie · Montant payé = décompte − les deux retenues. « Total validé » du montant payé inclut l’avance perçue.
        </p>
      </section>

      {rejecting && <RejectDialog row={rejecting} onClose={() => setRejecting(null)} onDone={rejected} />}

    </>
  );
}

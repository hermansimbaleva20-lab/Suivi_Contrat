import React, { useState } from 'react';
import { api, apiError, fieldErrors } from '../../api.js';
import { fr, money, todayISO } from '../../lib/format.js';
import { Modal } from '../../components/ui.jsx';
import { btnGhost, btnPrimary, FieldError, input, label, panel } from '../shared/tw.jsx';
import { advanceSituation } from './advanceSituation.js';

/*
 * Avance de démarrage d'un marché de biens en procédure BAD ou nationale : l'entreprise la demande en présentant une caution
 * (garantie de restitution) d'un montant équivalent. Elle ne compte comme versée qu'une fois son paiement renseigné ici.
 * Décision du 23/11/2026 : l'enregistrement porte le n° de référence de l'avance, puis la date de la caution et sa date
 * d'expiration, propres à la caution et distinctes des dates du contrat (plus aucune reprise de la fin du contrat).
 */

// Validité de la caution, en jours, de sa date à sa date d'expiration (comme le tableau du projet).
const validity = (from, to) => (from && to ? Math.round((Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86400000) : null);

const DIALOGS = {
  request: { url: (c) => `/contracts/${c.id}/advance`, title: 'Demande d’avance de démarrage', submit: 'Enregistrer la demande', danger: false },
  pay: { url: (c) => `/advances/${c.advanceRequest.id}/pay`, title: 'Paiement de l’avance de démarrage', submit: 'Enregistrer le paiement', danger: false },
  cancel: { url: (c) => `/advances/${c.advanceRequest.id}/cancel`, title: 'Annuler l’avance de démarrage', submit: 'Confirmer l’annulation', danger: true },
};

function AdvanceDialog({ kind, c, onClose, onDone }) {
  const d = DIALOGS[kind];
  const [f, setF] = useState(() => ({
    request: { request_date: todayISO(), request_reference: '', guarantee_bank: '', guarantee_number: '', guarantee_amount: String(c.advanceDue).replace('.', ','), guarantee_issue_date: '', guarantee_end_date: '' },
    pay: { paid_date: todayISO(), payment_reference: '' },
    cancel: { reason: '' },
  }[kind]));
  const [errors, setErrors] = useState({});
  const [failure, setFailure] = useState('');
  const [busy, setBusy] = useState(false);
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value });
  const submit = async (e) => {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    setFailure('');
    try {
      const r = await api.post(d.url(c), f);
      onDone(r.data.message);
    } catch (err) {
      const fe = fieldErrors(err);
      setErrors(fe);
      if (!Object.keys(fe).length) setFailure(apiError(err));
      setBusy(false);
    }
  };
  const field = (id, text, attrs = {}, width = '') => (
    <div className={width}>
      <label className={label} htmlFor={`avance-${id}`}>{text}</label>
      <input id={`avance-${id}`} className={input} value={f[id]} onChange={set(id)} required {...attrs} />
      <FieldError text={errors[id]} />
    </div>
  );
  return (
    <Modal title={d.title} subtitle={`Contrat ${c.number} — ${money(c.advanceRequest?.amount ?? c.advanceDue)} USD`} size="md" onClose={onClose}
      footer={<><button type="button" className="btn btn-light" onClick={onClose}>Fermer</button><button type="submit" form="avance-form" className={d.danger ? 'btn btn-danger' : 'btn btn-primary'} disabled={busy}>{d.submit}</button></>}>
      <form id="avance-form" className="tw:grid tw:gap-3 tw:sm:grid-cols-2" onSubmit={submit}>
        {failure && <p className="tw:m-0 tw:text-sm tw:text-prise-danger tw:sm:col-span-2" role="alert">{failure}</p>}
        {kind === 'request' && (
          <>
            {field('request_reference', 'N° de référence de l’avance', { maxLength: 120, autoFocus: true, placeholder: 'Ex. : lettre n° 12/2026' })}
            {field('request_date', 'Date de la demande', { type: 'date', max: todayISO() })}
            <h3 className="tw:m-0 tw:mt-1 tw:font-display tw:text-sm tw:font-bold tw:text-prise-blue tw:sm:col-span-2">Caution d’avance de démarrage</h3>
            {field('guarantee_number', 'N° de la caution', { maxLength: 120 })}
            {field('guarantee_bank', 'Banque', { maxLength: 191 })}
            {field('guarantee_issue_date', 'Date de la caution', { type: 'date', min: c.signatureDate || c.startDate || undefined })}
            {field('guarantee_end_date', 'Date d’expiration', { type: 'date', min: f.guarantee_issue_date || undefined })}
            {field('guarantee_amount', 'Montant (USD)', { inputMode: 'decimal', autoComplete: 'off' })}
            <div>
              <span className={label}>Validité</span>
              <p className="tw:m-0 tw:rounded-lg tw:border tw:border-prise-border tw:bg-prise-green-soft tw:px-3 tw:py-2 tw:text-sm tw:font-semibold tw:text-prise-text tw:tabular-nums" aria-live="polite">
                {validity(f.guarantee_issue_date, f.guarantee_end_date) === null ? '—' : `${validity(f.guarantee_issue_date, f.guarantee_end_date)} jours`}
              </p>
            </div>
          </>
        )}
        {kind === 'pay' && (
          <>
            {field('paid_date', 'Date du paiement', { type: 'date', max: todayISO(), min: c.advanceRequest.requestDate })}
            {field('payment_reference', 'Référence du paiement', { maxLength: 120, autoFocus: true })}
          </>
        )}
        {kind === 'cancel' && (
          <div className="tw:sm:col-span-2">
            <label className={label} htmlFor="avance-reason">Motif</label>
            <textarea id="avance-reason" className={`${input} tw:min-h-24`} value={f.reason} maxLength={500} onChange={set('reason')} required autoFocus />
            <FieldError text={errors.reason} />
          </div>
        )}
      </form>
    </Modal>
  );
}

export default function AdvancePanel({ c, canManage, onDone }) {
  const [dialog, setDialog] = useState(null);
  if (!c.advanceOnRequest) return null;
  const a = c.advanceRequest;
  const canAct = canManage && !c.isTerminated;
  const expired = a && a.status === 'DEMANDEE' && a.guaranteeEndDate < todayISO();
  return (
    <section className={`${panel} action-card tw:mb-3 tw:print:hidden`} aria-labelledby="avance-title">
      <div className="tw:flex tw:flex-wrap tw:items-center tw:gap-x-4 tw:gap-y-3 tw:px-5 tw:py-4">
        <div className="tw:min-w-64 tw:flex-1">
          <h2 id="avance-title" className="tw:m-0 tw:font-display tw:text-[17px] tw:font-bold tw:text-prise-blue">Avance de démarrage sur demande</h2>
          <p className="tw:m-0 tw:mt-1 tw:text-sm tw:text-prise-text">{advanceSituation(c)}</p>
          {a ? (
            <p className="tw:m-0 tw:mt-0.5 tw:text-xs tw:text-prise-muted">
              Avance n° {a.requestReference} · caution n° {a.guaranteeNumber} ({a.guaranteeBank}) : {money(a.guaranteeAmount)} USD,
              {a.guaranteeIssueDate ? ` du ${fr(a.guaranteeIssueDate)}` : ''} jusqu’au {fr(a.guaranteeEndDate)}{a.guaranteeValidity !== null && a.guaranteeValidity !== undefined ? ` (${a.guaranteeValidity} jours)` : ''}.
            </p>
          ) : (
            <p className="tw:m-0 tw:mt-0.5 tw:text-xs tw:text-prise-muted">L’entreprise présente une caution d’un montant équivalent. À traiter avant le premier décompte : l’avance ne peut plus être demandée ensuite.</p>
          )}
          {expired && <p className="tw:m-0 tw:mt-1 tw:text-xs tw:font-semibold tw:text-prise-danger">La caution a expiré : vérifiez-la avant de payer.</p>}
        </div>
        {canAct && (
          <div className="tw:flex tw:flex-wrap tw:gap-2">
            {!a && c.initialFinancingRate > 0 && <button type="button" className={btnPrimary} onClick={() => setDialog('request')}><i className="bi bi-plus-lg" aria-hidden="true" />Enregistrer la demande</button>}
            {a?.status === 'DEMANDEE' && <button type="button" className={btnPrimary} onClick={() => setDialog('pay')}><i className="bi bi-pencil-square" aria-hidden="true" />Renseigner le paiement</button>}
            {a && <button type="button" className={btnGhost} onClick={() => setDialog('cancel')}>{a.status === 'PAYEE' ? 'Annuler le paiement' : 'Rejeter la demande'}</button>}
          </div>
        )}
      </div>
      {dialog && <AdvanceDialog kind={dialog} c={c} onClose={() => setDialog(null)} onDone={(message) => { setDialog(null); onDone(message); }} />}
    </section>
  );
}

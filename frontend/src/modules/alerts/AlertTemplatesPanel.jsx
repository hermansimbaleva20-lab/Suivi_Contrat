import React, { useRef, useState } from 'react';
import { api } from '../../api.js';
import { useLoad } from '../../lib/hooks.js';
import { frDateTime } from '../../lib/format.js';
import { Notice } from '../../components/ui.jsx';
import { btnDanger, btnGhost, btnPrimary, FieldError, input, label, panel } from '../shared/tw.jsx';
import { useMutation } from '../fleet/fleetHooks.js';

/*
 * Modèles des messages : aux entreprises (vigilance, retard, satisfaction, pour la garantie de bonne exécution et pour le
 * contrat), au personnel du projet (rappel hebdomadaire d'attention) et au chef de cellule infrastructures (rappel d'un
 * décompte à valider). Les champs entre accolades sont remplacés à chaque envoi ({numero_garantie}, {contrat}…).
 * Modification réservée à un administrateur de la coordination nationale ou au super-admin ; les autres consultent.
 */

const GROUPS = [
  ['GARANTIE', 'Entreprises — garantie de bonne exécution', 'bi-shield-check'], ['CONTRAT', 'Entreprises — contrats', 'bi-file-earmark-text'],
  ['PERSONNEL', 'Personnel du projet — rappel une fois par semaine', 'bi-people'],
];
const KIND = {
  VIGILANCE: ['Vigilance', 'tw:bg-amber-50 tw:text-amber-800'], RETARD: ['Retard', 'tw:bg-red-50 tw:text-prise-danger'], SATISFACTION: ['Satisfaction', 'tw:bg-prise-green-soft tw:text-prise-green-2'],
  GARANTIE: ['Garantie', 'tw:bg-sky-50 tw:text-sky-800'], CONTRAT: ['Contrat', 'tw:bg-sky-50 tw:text-sky-800'], RAPPEL: ['Rappel', 'tw:bg-sky-50 tw:text-sky-800'],
};
const kindOf = (t) => (t.group === 'PERSONNEL' ? t.about : t.code.split('_')[1]);
const pill = 'tw:inline-block tw:whitespace-nowrap tw:rounded-full tw:px-2 tw:py-0.5 tw:text-[11px] tw:font-bold';

/** Aperçu avec les valeurs d'exemple. */
const sample = (text, fields) => fields.reduce((t, f) => t.split(`{${f.key}}`).join(f.example), text);

function Editor({ tpl, fields, canEdit, busy, errors, onSave, onReset, onClose }) {
  const [f, setF] = useState({ subject: tpl.subject, body: tpl.body });
  const [target, setTarget] = useState('body');
  const refs = { subject: useRef(null), body: useRef(null) };
  const dirty = f.subject !== tpl.subject || f.body !== tpl.body;
  const insert = (key) => {
    const el = refs[target].current;
    const value = f[target];
    const at = el ? el.selectionStart : value.length;
    const end = el ? el.selectionEnd : value.length;
    const next = `${value.slice(0, at)}{${key}}${value.slice(end)}`;
    setF({ ...f, [target]: next });
    requestAnimationFrame(() => { if (el) { el.focus(); el.setSelectionRange(at + key.length + 2, at + key.length + 2); } });
  };
  return (
    <div className="tw:grid tw:gap-5 tw:border-t tw:border-prise-border tw:bg-prise-bg/50 tw:px-5 tw:py-4 tw:xl:grid-cols-2">
      <div className="tw:grid tw:content-start tw:gap-3">
        <div>
          <label className={label} htmlFor={`tpl-s-${tpl.code}`}>Objet</label>
          <input id={`tpl-s-${tpl.code}`} ref={refs.subject} className={input} value={f.subject} readOnly={!canEdit} onFocus={() => setTarget('subject')} onChange={(e) => setF({ ...f, subject: e.target.value })} maxLength={255} />
          <FieldError text={errors.subject} />
        </div>
        <div>
          <label className={label} htmlFor={`tpl-b-${tpl.code}`}>Texte du message</label>
          <textarea id={`tpl-b-${tpl.code}`} ref={refs.body} rows={13} className={`${input} tw:font-body tw:leading-relaxed`} value={f.body} readOnly={!canEdit} onFocus={() => setTarget('body')} onChange={(e) => setF({ ...f, body: e.target.value })} />
          <FieldError text={errors.body} />
        </div>
        {canEdit && (
          <div>
            <p className={label}>Insérer un champ ({target === 'subject' ? 'dans l’objet' : 'dans le texte'}, à l’emplacement du curseur)</p>
            <div className="tw:flex tw:flex-wrap tw:gap-1.5">
              {fields.map((x) => (
                <button key={x.key} type="button" title={`${x.label} — ex. : ${x.example}`} onClick={() => insert(x.key)}
                  className="tw:cursor-pointer tw:rounded-md tw:border tw:border-prise-border tw:bg-white tw:px-2 tw:py-1 tw:font-tech tw:text-[11px] tw:text-prise-text tw:hover:border-prise-green-2 tw:hover:text-prise-green-2">
                  {`{${x.key}}`}
                </button>
              ))}
            </div>
          </div>
        )}
        <div className="tw:flex tw:flex-wrap tw:gap-2">
          {canEdit && <button type="button" className={btnPrimary} disabled={busy || !dirty} onClick={() => onSave(f)}>Enregistrer le modèle</button>}
          {canEdit && !tpl.isDefault && <button type="button" className={btnDanger} disabled={busy} onClick={onReset}><i className="bi bi-arrow-counterclockwise" aria-hidden="true" />Rétablir le modèle par défaut</button>}
          <button type="button" className={btnGhost} onClick={onClose}>Fermer</button>
        </div>
      </div>
      <div className="tw:min-w-0">
        <p className={label}>Aperçu avec des valeurs d’exemple</p>
        <div className="tw:rounded-lg tw:border tw:border-prise-border tw:bg-white tw:p-4">
          <p className="tw:m-0 tw:mb-3 tw:border-b tw:border-prise-border tw:pb-2 tw:text-sm tw:font-semibold">{sample(f.subject, fields)}</p>
          <div className="tw:whitespace-pre-line tw:text-sm tw:leading-relaxed">{sample(f.body, fields)}</div>
        </div>
        <dl className="tw:m-0 tw:mt-3 tw:grid tw:grid-cols-[auto_1fr] tw:gap-x-3 tw:gap-y-1 tw:text-[11px]">
          {fields.map((x) => <React.Fragment key={x.key}><dt className="tw:font-tech tw:text-prise-text">{`{${x.key}}`}</dt><dd className="tw:m-0 tw:text-prise-muted">{x.label}</dd></React.Fragment>)}
        </dl>
      </div>
    </div>
  );
}

export default function AlertTemplatesPanel() {
  const { data, error, reload } = useLoad('/alerts/templates');
  const { flash, setFlash, errors, setErrors, busy, call } = useMutation(reload);
  const [open, setOpen] = useState(null);

  if (!data) return error ? <Notice>{error}</Notice> : null;
  const toggle = (code) => { setErrors({}); setOpen(open === code ? null : code); };

  return (
    <section className={`${panel} tw:mb-4`} aria-labelledby="tpl-title">
      <header className="tw:border-b tw:border-prise-border tw:px-5 tw:py-4">
        <h2 id="tpl-title" className="tw:m-0 tw:font-display tw:text-[17px] tw:font-bold tw:text-prise-blue">Modèles des messages</h2>
        <p className="tw:m-0 tw:mt-1 tw:text-xs tw:text-prise-muted">
          L’entreprise reçoit son message à l’adresse de sa fiche ; le personnel du projet reçoit un message distinct, sans copie de celui de l’entreprise. Les champs entre accolades sont remplacés à chaque envoi par les données du contrat ; le message d’une garantie porte toujours son numéro.
          {!data.canEdit && ' Les modèles sont modifiés par un administrateur de la coordination nationale.'}
        </p>
      </header>
      {flash.text && <div className="tw:px-5 tw:pt-3"><Notice type={flash.type} onClose={() => setFlash({ ...flash, text: '' })}>{flash.text}</Notice></div>}
      {GROUPS.filter(([g]) => data.templates.some((t) => t.group === g)).map(([g, title, icon]) => (
        <div key={g}>
          <h3 className="tw:m-0 tw:border-b tw:border-prise-border tw:bg-prise-bg tw:px-5 tw:py-2 tw:font-tech tw:text-[11px] tw:font-semibold tw:uppercase tw:tracking-wide tw:text-prise-muted"><i className={`bi ${icon} tw:mr-1`} aria-hidden="true" />{title}</h3>
          <ul className="tw:m-0 tw:list-none tw:p-0">
            {data.templates.filter((t) => t.group === g).map((t) => {
              const [kLabel, kCls] = KIND[kindOf(t)];
              return (
                <li key={t.code} className="tw:border-b tw:border-prise-border tw:last:border-b-0">
                  <div className="tw:flex tw:flex-wrap tw:items-center tw:gap-x-4 tw:gap-y-2 tw:px-5 tw:py-3">
                    <span className={`${pill} ${kCls} tw:w-24 tw:text-center`}>{kLabel}</span>
                    <div className="tw:min-w-0 tw:flex-1">
                      <p className="tw:m-0 tw:truncate tw:text-sm tw:font-semibold" title={t.subject}>{t.subject}</p>
                      <p className="tw:m-0 tw:text-[11px] tw:text-prise-muted">{t.when}</p>
                    </div>
                    <span className="tw:text-[11px] tw:text-prise-muted">{t.isDefault ? 'Modèle par défaut' : `Modifié le ${frDateTime(t.updatedAt)}${t.updatedBy ? ` par ${t.updatedBy}` : ''}`}</span>
                    <button type="button" className={btnGhost} aria-expanded={open === t.code} onClick={() => toggle(t.code)}>
                      <i className={`bi ${data.canEdit ? 'bi-pencil' : 'bi-eye'}`} aria-hidden="true" />{open === t.code ? 'Fermer' : data.canEdit ? 'Modifier' : 'Voir'}
                    </button>
                  </div>
                  {open === t.code && (
                    <Editor key={`${t.code}-${t.updatedAt}`} tpl={t} fields={t.fields} canEdit={data.canEdit} busy={busy} errors={errors} onClose={() => setOpen(null)}
                      onSave={(f) => call(() => api.put(`/alerts/templates/${t.code}`, f))} onReset={() => call(() => api.delete(`/alerts/templates/${t.code}`))} />
                  )}
                </li>
              );
            })}
          </ul>
        </div>
      ))}
    </section>
  );
}

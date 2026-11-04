import React, { useState } from 'react';
import { api, apiError } from '../../api.js';
import { ReasonDialog } from '../../components/ui.jsx';
import { btnDanger, btnGhost, btnPrimary, FieldError, input, label } from '../shared/tw.jsx';

/*
 * Briques communes aux pages Entretiens et Assurances : formulaire en ligne et suppression motivée.
 * Seuls les engins actifs des unités où l'utilisateur peut écrire sont proposés à la saisie.
 */

/** fields : [clé, libellé, { type, num, span, list, required, maxLength, placeholder }] ; onAssetChange permet de préremplir. */
export function RecordForm({ initial, assets, fields, onSubmit, onCancel, busy, errors, submitLabel, onAssetChange, lists = {} }) {
  const [f, setF] = useState(initial);
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value });
  return (
    <form className="tw:grid tw:gap-3 tw:border-b tw:border-prise-border tw:bg-prise-bg/60 tw:px-5 tw:py-4 tw:sm:grid-cols-12" onSubmit={(e) => { e.preventDefault(); onSubmit(f); }}>
      <div className="tw:sm:col-span-4">
        <label className={label} htmlFor="rec-asset">Engin</label>
        <select id="rec-asset" className={input} value={f.fleet_asset_id} disabled={!!f.id} required
          onChange={(e) => setF({ ...f, fleet_asset_id: e.target.value, ...(onAssetChange ? onAssetChange(e.target.value) : {}) })}>
          <option value="">Choisir…</option>
          {assets.map((a) => <option key={a.id} value={a.id}>{a.name}{a.registration ? ` — ${a.registration}` : ''} · {a.unitName}</option>)}
        </select>
        <FieldError text={errors.fleet_asset_id} />
      </div>
      {fields.map(([k, text, o = {}]) => (
        <div key={k} className={o.span || 'tw:sm:col-span-4'}>
          <label className={label} htmlFor={`rec-${k}`}>{text}</label>
          <input id={`rec-${k}`} type={o.type || 'text'} className={`${input} ${o.num ? 'tw:font-tech tw:text-right' : ''}`} value={f[k]} onChange={set(k)}
            inputMode={o.num ? 'decimal' : undefined} list={o.list} required={o.required} maxLength={o.maxLength} placeholder={o.placeholder} max={o.max} autoComplete="off" />
          <FieldError text={errors[k]} />
        </div>
      ))}
      {Object.entries(lists).map(([id, items]) => <datalist key={id} id={id}>{items.map((x) => <option key={x} value={x} />)}</datalist>)}
      <div className="tw:flex tw:items-end tw:justify-end tw:gap-2 tw:sm:col-span-12">
        <button type="button" className={btnGhost} onClick={onCancel}>Annuler</button>
        <button type="submit" className={btnPrimary} disabled={busy}>{f.id ? 'Enregistrer la correction' : submitLabel}</button>
      </div>
    </form>
  );
}

export function RowEdit({ name, onEdit, onDelete }) {
  return (
    <div className="tw:flex tw:justify-end tw:gap-1 tw:print:hidden">
      <button type="button" className={btnGhost} onClick={onEdit} aria-label={`Corriger ${name}`} title="Corriger"><i className="bi bi-pencil" aria-hidden="true" /></button>
      <button type="button" className={btnDanger} onClick={onDelete} aria-label={`Supprimer ${name}`} title="Supprimer"><i className="bi bi-trash" aria-hidden="true" /></button>
    </div>
  );
}

/** Suppression avec motif obligatoire (journalisée avec les valeurs supprimées). */
export function DeleteDialog({ title, text, url, onDone, onClose }) {
  const confirm = async (reason) => {
    try {
      const r = await api.delete(url, { data: { reason } });
      onDone(r.data.message);
    } catch (e) { throw new Error(apiError(e)); }
  };
  return <ReasonDialog title={title} text={text} confirmLabel="Supprimer" onConfirm={confirm} onClose={onClose} />;
}

import React, { useState } from 'react';
import { api, apiError, fieldErrors } from '../../api.js';
import { btnDanger, btnGhost, btnPrimary, FieldError, input, label } from '../shared/tw.jsx';

/*
 * Destinataires du côté du projet (personnel) : adresse, nom et poste occupé, depuis les paramètres SMTP. C'est la même
 * liste que celle de Administration > Alertes e-mail, où le super-admin peut restreindre les catégories d'un destinataire.
 * Les entreprises ne figurent pas ici : elles sont jointes à l'adresse de leur fiche.
 */

/** Postes de la liste Organisation, regroupés par unité : un même intitulé existe dans plusieurs unités. */
function PositionOptions({ positions }) {
  const units = [...new Set(positions.map((p) => p.unit))];
  return units.map((u) => (
    <optgroup key={u} label={u || 'Toutes les unités'}>
      {positions.filter((p) => p.unit === u).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
    </optgroup>
  ));
}

function RecipientForm({ initial, positions, saving, errors, submitLabel, onSubmit, onCancel }) {
  const [f, setF] = useState(initial);
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value });
  return (
    <form className="tw:mt-3 tw:grid tw:gap-3 tw:rounded-lg tw:border tw:border-prise-border tw:bg-prise-bg/60 tw:p-3 tw:sm:grid-cols-2" onSubmit={(e) => { e.preventDefault(); onSubmit(f); }}>
      <div className="tw:sm:col-span-2">
        <label className={label} htmlFor="pr-email">Adresse e-mail</label>
        <input id="pr-email" type="email" className={input} value={f.email} onChange={set('email')} required maxLength={190} autoComplete="off" autoFocus />
        <FieldError text={errors.email} />
      </div>
      <div>
        <label className={label} htmlFor="pr-name">Nom</label>
        <input id="pr-name" className={input} value={f.name} onChange={set('name')} required maxLength={150} autoComplete="off" />
        <FieldError text={errors.name} />
      </div>
      <div>
        <label className={label} htmlFor="pr-position">Poste occupé dans le projet</label>
        <select id="pr-position" className={input} value={f.position_id} onChange={set('position_id')} required>
          <option value="">Choisir le poste…</option>
          <PositionOptions positions={positions} />
        </select>
        <FieldError text={errors.position_id} />
      </div>
      <div className="tw:flex tw:gap-2 tw:sm:col-span-2">
        <button type="submit" className={btnPrimary} disabled={saving}>{submitLabel}</button>
        <button type="button" className={btnGhost} onClick={onCancel}>Annuler</button>
      </div>
    </form>
  );
}

export default function ProjectRecipients({ recipients, positions, canEdit, busy, onChanged, onError }) {
  const [editing, setEditing] = useState(null); // 'new' | id du destinataire
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);

  const open = (what) => { setErrors({}); setEditing(what); };
  const save = async (f) => {
    setSaving(true);
    setErrors({});
    try {
      const r = editing === 'new' ? await api.post('/settings/recipients', f) : await api.put(`/settings/recipients/${editing}`, f);
      setEditing(null);
      await onChanged(r.data.message);
    } catch (err) { const fe = fieldErrors(err); setErrors(fe); if (!Object.keys(fe).length) onError(apiError(err)); }
    finally { setSaving(false); }
  };
  const remove = async (x) => {
    if (!window.confirm(`Supprimer ${x.email} des destinataires du projet ?`)) return;
    setSaving(true);
    try { const r = await api.delete(`/settings/recipients/${x.id}`); await onChanged(r.data.message); }
    catch (err) { onError(apiError(err)); }
    finally { setSaving(false); }
  };
  const formProps = { positions, saving, errors, onSubmit: save, onCancel: () => setEditing(null) };

  return (
    <section className="cardx form-card p-3 mt-3" aria-labelledby="pr-title">
      <div className="tw:flex tw:flex-wrap tw:items-start tw:justify-between tw:gap-3">
        <div className="tw:min-w-0 tw:flex-1">
          <div id="pr-title" className="section-title mb-1">Destinataires du projet</div>
          <div className="section-subtitle">Le personnel du projet : il reçoit ses propres messages d’alerte, distincts de ceux des entreprises.</div>
        </div>
        {canEdit && editing !== 'new' && <button type="button" className={btnPrimary} onClick={() => open('new')}><i className="bi bi-plus-lg" aria-hidden="true" />Ajouter</button>}
      </div>

      {editing === 'new' && <RecipientForm {...formProps} initial={{ email: '', name: '', position_id: '' }} submitLabel="Ajouter le destinataire" />}

      <ul className="tw:m-0 tw:mt-3 tw:list-none tw:p-0">
        {recipients.map((x) => (
          <li key={x.id} className="tw:border-t tw:border-prise-border tw:py-2">
            {editing === x.id ? (
              <RecipientForm {...formProps} initial={{ email: x.email, name: x.name, position_id: x.positionId ? String(x.positionId) : '' }} submitLabel="Enregistrer" />
            ) : (
              <div className="tw:flex tw:flex-wrap tw:items-center tw:gap-x-3 tw:gap-y-1">
                <div className="tw:min-w-0 tw:flex-1">
                  <span className="tw:font-semibold tw:break-all">{x.email}</span>
                  {!x.isActive && <span className="tw:ml-2 tw:rounded-full tw:bg-slate-100 tw:px-2 tw:py-0.5 tw:text-[11px] tw:font-bold tw:text-slate-600">Suspendu</span>}
                  <span className="tw:block tw:text-sm tw:text-prise-muted">
                    {x.name || 'Nom à renseigner'}
                    {' · '}
                    {x.position || <span className="tw:font-semibold tw:text-amber-800">Poste à renseigner</span>}
                  </span>
                </div>
                {canEdit && (
                  <div className="tw:flex tw:gap-1">
                    <button type="button" className={btnGhost} disabled={saving || busy} onClick={() => open(x.id)} aria-label={`Modifier ${x.email}`}><i className="bi bi-pencil" aria-hidden="true" />Modifier</button>
                    <button type="button" className={btnDanger} disabled={saving || busy} onClick={() => remove(x)} aria-label={`Supprimer ${x.email}`}><i className="bi bi-trash" aria-hidden="true" />Supprimer</button>
                  </div>
                )}
              </div>
            )}
          </li>
        ))}
      </ul>
      {!recipients.length && <p className="tw:m-0 tw:mt-2 tw:text-sm tw:text-prise-muted">Aucun destinataire : le personnel du projet ne reçoit aucune alerte.</p>}
      {!canEdit && <p className="tw:m-0 tw:mt-2 tw:text-xs tw:text-prise-muted">La liste est gérée par la coordination nationale.</p>}
    </section>
  );
}

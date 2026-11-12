import React, { useState } from 'react';
import { api, apiError, fieldErrors } from '../../api.js';
import { useLoad } from '../../lib/hooks.js';
import { money } from '../../lib/format.js';
import { DecimalInput, Loading, Notice, PageHead } from '../../components/ui.jsx';
import { btn, btnDanger, btnGhost, btnPrimary, FieldError, input, label, panel } from '../shared/tw.jsx';

/*
 * Administration › Composantes du projet (décision du 10/11/2026) : intitulé et budget en UC de chaque composante,
 * colonnes « Composantes » et « Budget en millions d'UC » de la matrice d'exécution. Enregistrées ici au préalable, puis
 * choisies (facultativement) dans le formulaire du contrat. Réservé à la coordination nationale (« Gérer les paramètres »)
 * et au super-admin. Une composante utilisée par des contrats se désactive au lieu de se supprimer.
 */

function ComponentForm({ initial, onSubmit, onCancel, busy, errors }) {
  const [f, setF] = useState(initial);
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value });
  return (
    <form className="tw:grid tw:gap-3 tw:bg-prise-bg/60 tw:px-5 tw:py-4 tw:sm:grid-cols-12" onSubmit={(e) => { e.preventDefault(); onSubmit(f); }}>
      <div className="tw:sm:col-span-8">
        <label className={label} htmlFor="cmp-name">Intitulé de la composante</label>
        <input id="cmp-name" className={input} value={f.name} onChange={set('name')} maxLength={255} required
          placeholder="ex. Réalisation de 22 systèmes d’AEP en milieu rural avec énergie solaire/thermique" />
        <FieldError text={errors.name} />
      </div>
      <div className="tw:sm:col-span-4">
        <label className={label} htmlFor="cmp-budget">Budget (UC)</label>
        <DecimalInput id="cmp-budget" className="tw:text-right" value={f.budget} onChange={(v) => setF({ ...f, budget: v })} placeholder="ex. 12 010 331" />
        <FieldError text={errors.budget} />
      </div>
      <div className="tw:sm:col-span-12">
        <label className={label} htmlFor="cmp-desc">Description (facultatif)</label>
        <input id="cmp-desc" className={input} value={f.description} onChange={set('description')} maxLength={2000} />
      </div>
      <div className="tw:flex tw:justify-end tw:gap-2 tw:sm:col-span-12">
        <button type="button" className={btnGhost} onClick={onCancel}>Annuler</button>
        <button type="submit" className={btnPrimary} disabled={busy}>{initial.id ? 'Enregistrer' : 'Enregistrer la composante'}</button>
      </div>
    </form>
  );
}

function RowActions({ item, busy, onEdit, onToggle, onDelete }) {
  const [confirm, setConfirm] = useState(false);
  if (confirm) {
    return (
      <div className="tw:flex tw:items-center tw:gap-2">
        <span className="tw:text-xs tw:text-prise-danger">Supprimer définitivement ?</span>
        <button type="button" className={btnGhost} onClick={() => setConfirm(false)}>Non</button>
        <button type="button" className={`${btn} tw:bg-prise-danger tw:text-white tw:hover:bg-red-800`} disabled={busy} onClick={onDelete}>Supprimer</button>
      </div>
    );
  }
  return (
    <div className="tw:flex tw:items-center tw:gap-1">
      <button type="button" className={btnGhost} onClick={onEdit} aria-label={`Modifier ${item.name}`}><i className="bi bi-pencil" aria-hidden="true" />Modifier</button>
      <button type="button" className={btnGhost} disabled={busy} onClick={onToggle}>{item.isActive ? 'Désactiver' : 'Réactiver'}</button>
      {item.contracts === 0 && (
        <button type="button" className={btnDanger} onClick={() => setConfirm(true)} aria-label={`Supprimer ${item.name}`} title="Supprimer"><i className="bi bi-trash" aria-hidden="true" /></button>
      )}
    </div>
  );
}

const toInput = (v) => (v === null || v === undefined ? '' : String(v).replace('.', ','));

export default function ComponentsPage() {
  const { data, loading, error, reload } = useLoad('/components');
  const [flash, setFlash] = useState({ type: 'success', text: '' });
  const [editing, setEditing] = useState(null); // id | 'new'
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);

  if (loading && !data) return <Loading />;
  if (!data) return <Notice>{error}</Notice>;

  const call = async (fn, close = true) => {
    setBusy(true);
    setErrors({});
    try {
      const r = await fn();
      setFlash({ type: 'success', text: r.data.message });
      if (close) setEditing(null);
      await reload();
    } catch (e) {
      const fe = fieldErrors(e);
      setErrors(fe);
      if (!Object.keys(fe).length) setFlash({ type: 'danger', text: apiError(e) });
    } finally {
      setBusy(false);
    }
  };
  const open = (id) => { setErrors({}); setEditing(id); };
  const save = (f) => call(() => (f.id ? api.put(`/components/${f.id}`, f) : api.post('/components', f)));
  const total = data.filter((c) => c.isActive).reduce((a, c) => a + (Number(c.budget) || 0), 0);

  return (
    <>
      <PageHead title="Composantes du projet">
        {editing !== 'new' && <button type="button" className={btnPrimary} onClick={() => open('new')}><i className="bi bi-plus-lg" aria-hidden="true" />Nouvelle composante</button>}
      </PageHead>
      <Notice type={flash.type} onClose={() => setFlash({ ...flash, text: '' })}>{flash.text}</Notice>

      <section className={panel}>
        <header className="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-3 tw:border-b tw:border-prise-border tw:px-5 tw:py-4">
          <h2 className="tw:m-0 tw:font-display tw:text-[17px] tw:text-prise-blue">
            Composantes <span className="tw:ml-1 tw:font-tech tw:text-sm tw:font-medium tw:text-prise-muted tw:tabular-nums">{data.length}</span>
          </h2>
          {total > 0 && <span className="tw:text-sm tw:text-prise-muted">Budget des composantes actives : <strong className="tw:font-tech tw:tabular-nums tw:text-prise-text">{money(total).replace(/,00$/, '')} UC</strong></span>}
        </header>
        {editing === 'new' && (
          <div className="tw:border-b tw:border-prise-border">
            <ComponentForm initial={{ name: '', budget: '', description: '' }} onSubmit={save} onCancel={() => setEditing(null)} busy={busy} errors={errors} />
          </div>
        )}
        <ul className="tw:m-0 tw:list-none tw:p-0">
          {data.map((c) => (
            <li key={c.id} className="tw:border-b tw:border-prise-border tw:last:border-b-0">
              {editing === c.id ? (
                <ComponentForm initial={{ id: c.id, name: c.name, budget: toInput(c.budget), description: c.description }} onSubmit={save} onCancel={() => setEditing(null)} busy={busy} errors={errors} />
              ) : (
                <div className={`tw:flex tw:flex-wrap tw:items-center tw:gap-x-4 tw:gap-y-2 tw:px-5 tw:py-3.5 ${c.isActive ? '' : 'tw:opacity-60'}`}>
                  <div className="tw:min-w-0 tw:flex-1">
                    <div className="tw:flex tw:flex-wrap tw:items-baseline tw:gap-x-2">
                      <span className="tw:font-semibold tw:text-prise-text">{c.name}</span>
                      {!c.isActive && <span className="tw:rounded-full tw:bg-slate-100 tw:px-2 tw:py-0.5 tw:text-[11px] tw:font-bold tw:text-slate-600">Désactivée</span>}
                    </div>
                    <div className="tw:mt-0.5 tw:flex tw:flex-wrap tw:gap-x-3 tw:text-xs tw:text-prise-muted">
                      <span>{c.contracts === 0 ? 'Aucun contrat' : c.contracts === 1 ? '1 contrat' : `${c.contracts} contrats`}</span>
                      {c.description && <span>{c.description}</span>}
                    </div>
                  </div>
                  <div className="tw:min-w-36 tw:text-right">
                    <span className="tw:block tw:text-[11px] tw:font-semibold tw:uppercase tw:tracking-wide tw:text-prise-muted">Budget</span>
                    <span className="tw:font-tech tw:font-semibold tw:tabular-nums tw:text-prise-text">{c.budget === null ? '—' : `${money(c.budget).replace(/,00$/, '')} UC`}</span>
                  </div>
                  <RowActions item={c} busy={busy} onEdit={() => open(c.id)} onToggle={() => call(() => api.put(`/components/${c.id}/toggle`), false)} onDelete={() => call(() => api.delete(`/components/${c.id}`), false)} />
                </div>
              )}
            </li>
          ))}
        </ul>
        {!data.length && editing !== 'new' && (
          <p className="tw:m-0 tw:px-5 tw:py-8 tw:text-center tw:text-sm tw:text-prise-muted">Aucune composante enregistrée.</p>
        )}
      </section>
    </>
  );
}

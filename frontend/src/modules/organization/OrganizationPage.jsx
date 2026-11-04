import React, { useState } from 'react';
import { api, apiError, fieldErrors } from '../../api.js';
import { useLoad } from '../../lib/hooks.js';
import { Loading, Notice, PageHead } from '../../components/ui.jsx';
import { btn, btnDanger, btnGhost, btnPrimary, FieldError, input, label } from '../shared/tw.jsx';

/*
 * Administration > Organisation (TDR §5) : coordination nationale, pools et postes, administrables par projet.
 * Premier écran écrit en Tailwind (classes préfixées tw:), en cohabitation avec Bootstrap.
 */

function Section({ title, count, action, children }) {
  return (
    <section className="tw:rounded-card tw:border tw:border-prise-border tw:bg-white tw:shadow-card">
      <header className="tw:flex tw:items-center tw:justify-between tw:gap-3 tw:border-b tw:border-prise-border tw:px-5 tw:py-4">
        <h2 className="tw:m-0 tw:font-display tw:text-[17px] tw:text-prise-blue">
          {title} <span className="tw:ml-1 tw:font-tech tw:text-sm tw:font-medium tw:text-prise-muted tw:tabular-nums">{count}</span>
        </h2>
        {action}
      </header>
      {children}
    </section>
  );
}

function StatePill({ active }) {
  return active
    ? <span className="tw:rounded-full tw:bg-prise-green-soft tw:px-2 tw:py-0.5 tw:text-[11px] tw:font-bold tw:text-prise-green-2">Active</span>
    : <span className="tw:rounded-full tw:bg-slate-100 tw:px-2 tw:py-0.5 tw:text-[11px] tw:font-bold tw:text-slate-600">Désactivée</span>;
}

function UsersCount({ n }) {
  return <span className="tw:font-tech tw:text-xs tw:text-prise-muted tw:tabular-nums">{n === 0 ? 'Aucun compte' : n === 1 ? '1 compte' : `${n} comptes`}</span>;
}

/** Formulaire en ligne d'une unité (création ou modification). */
function UnitForm({ initial, onSubmit, onCancel, busy, errors }) {
  const [f, setF] = useState(initial);
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value });
  return (
    <form className="tw:grid tw:gap-3 tw:bg-prise-bg/60 tw:px-5 tw:py-4 tw:sm:grid-cols-6" onSubmit={(e) => { e.preventDefault(); onSubmit(f); }}>
      <div className="tw:sm:col-span-2">
        <label className={label} htmlFor="unit-type">Type</label>
        <select id="unit-type" className={input} value={f.type} onChange={set('type')}>
          <option value="POOL">Pool</option>
          <option value="COORDINATION">Coordination nationale</option>
        </select>
      </div>
      <div className="tw:sm:col-span-1">
        <label className={label} htmlFor="unit-code">Code</label>
        <input id="unit-code" className={`${input} tw:font-tech tw:uppercase`} value={f.code} onChange={set('code')} maxLength={30} placeholder="ex. KGA" required />
        <FieldError text={errors.code} />
      </div>
      <div className="tw:sm:col-span-3">
        <label className={label} htmlFor="unit-name">Désignation</label>
        <input id="unit-name" className={input} value={f.name} onChange={set('name')} maxLength={150} placeholder="ex. Pool de Kananga" required />
        <FieldError text={errors.name} />
      </div>
      <div className="tw:sm:col-span-2">
        <label className={label} htmlFor="unit-province">Province (facultatif)</label>
        <input id="unit-province" className={input} value={f.province} onChange={set('province')} maxLength={150} />
      </div>
      <div className="tw:sm:col-span-4">
        <label className={label} htmlFor="unit-desc">Observation (facultatif)</label>
        <input id="unit-desc" className={input} value={f.description} onChange={set('description')} maxLength={1000} />
      </div>
      <div className="tw:flex tw:justify-end tw:gap-2 tw:sm:col-span-6">
        <button type="button" className={btnGhost} onClick={onCancel}>Annuler</button>
        <button type="submit" className={btnPrimary} disabled={busy}>{initial.id ? 'Enregistrer' : 'Créer l’unité'}</button>
      </div>
    </form>
  );
}

function PositionForm({ initial, units, onSubmit, onCancel, busy, errors }) {
  const [f, setF] = useState(initial);
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value });
  return (
    <form className="tw:grid tw:gap-3 tw:bg-prise-bg/60 tw:px-5 tw:py-4" onSubmit={(e) => { e.preventDefault(); onSubmit(f); }}>
      <div>
        <label className={label} htmlFor="pos-unit">Unité</label>
        <select id="pos-unit" className={input} value={f.organizational_unit_id} onChange={set('organizational_unit_id')}>
          <option value="">Toutes les unités (poste commun)</option>
          {units.filter((u) => u.isActive || String(u.id) === String(f.organizational_unit_id)).map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
        </select>
        <p className="tw:m-0 tw:mt-1 tw:text-[11px] tw:text-prise-muted">À l’inscription, l’agent ne voit que les postes de l’unité qu’il a choisie.</p>
        <FieldError text={errors.organizational_unit_id} />
      </div>
      <div>
        <label className={label} htmlFor="pos-name">Intitulé du poste</label>
        <input id="pos-name" className={input} value={f.name} onChange={set('name')} maxLength={150} placeholder="ex. Logisticien de pool" required />
        <FieldError text={errors.name} />
      </div>
      <div>
        <label className={label} htmlFor="pos-desc">Description (facultatif)</label>
        <input id="pos-desc" className={input} value={f.description} onChange={set('description')} maxLength={1000} />
      </div>
      <div className="tw:flex tw:justify-end tw:gap-2">
        <button type="button" className={btnGhost} onClick={onCancel}>Annuler</button>
        <button type="submit" className={btnPrimary} disabled={busy}>{initial.id ? 'Enregistrer' : 'Créer le poste'}</button>
      </div>
    </form>
  );
}

/** Actions d'une ligne : modifier, activer/désactiver, supprimer (avec confirmation en ligne, seulement si aucun compte). */
function RowActions({ item, onEdit, onToggle, onDelete, busy }) {
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
      <button type="button" className={btnGhost} onClick={onEdit} aria-label={`Modifier ${item.name}`} title="Modifier"><i className="bi bi-pencil" aria-hidden="true" /></button>
      <button type="button" className={btnGhost} disabled={busy} onClick={onToggle}>{item.isActive ? 'Désactiver' : 'Réactiver'}</button>
      {item.usersCount === 0 && (
        <button type="button" className={btnDanger} onClick={() => setConfirm(true)} aria-label={`Supprimer ${item.name}`} title="Supprimer"><i className="bi bi-trash" aria-hidden="true" /></button>
      )}
    </div>
  );
}

export default function OrganizationPage() {
  const { data, loading, error, reload } = useLoad('/organization');
  const [flash, setFlash] = useState({ type: 'success', text: '' });
  const [editing, setEditing] = useState(null); // { kind: 'unit'|'position', id: number|'new' }
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);

  if (loading && !data) return <Loading />;
  if (!data) return <Notice>{error}</Notice>;

  const call = async (fn, { close = true } = {}) => {
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
  const open = (kind, id) => { setErrors({}); setEditing({ kind, id }); };
  const isEditing = (kind, id) => editing && editing.kind === kind && editing.id === id;

  const units = data.units;
  const coordination = units.filter((u) => u.type === 'COORDINATION');
  const pools = units.filter((u) => u.type === 'POOL');
  // Postes regroupés par unité (ordre des unités : coordination puis pools), les postes communs en dernier.
  const positionGroups = [
    ...units.map((u) => [u.name, data.positions.filter((p) => p.unitId === u.id)]),
    ['Commun à toutes les unités', data.positions.filter((p) => !p.unitId)],
  ].filter(([, list]) => list.length);
  const emptyUnit ={ type: 'POOL', code: '', name: '', province: '', description: '' };
  const saveUnit = (f) => call(() => (f.id ? api.put(`/organization/units/${f.id}`, f) : api.post('/organization/units', f)));
  const savePosition = (f) => call(() => (f.id ? api.put(`/organization/positions/${f.id}`, f) : api.post('/organization/positions', f)));

  const unitRow = (u) => (
    <li key={u.id} className="tw:border-b tw:border-prise-border tw:last:border-b-0">
      {isEditing('unit', u.id) ? (
        <UnitForm initial={{ id: u.id, type: u.type, code: u.code, name: u.name, province: u.province, description: u.description }} onSubmit={saveUnit} onCancel={() => setEditing(null)} busy={busy} errors={errors} />
      ) : (
        <div className={`tw:flex tw:flex-wrap tw:items-center tw:gap-x-4 tw:gap-y-2 tw:px-5 tw:py-3.5 ${u.isActive ? '' : 'tw:opacity-60'}`}>
          <div className="tw:min-w-0 tw:flex-1">
            <div className="tw:flex tw:flex-wrap tw:items-baseline tw:gap-x-2">
              <span className="tw:font-semibold tw:text-prise-text">{u.name}</span>
              <span className="tw:font-tech tw:text-xs tw:font-medium tw:text-prise-muted">{u.code}</span>
              {!u.isActive && <StatePill active={false} />}
            </div>
            <div className="tw:mt-0.5 tw:flex tw:flex-wrap tw:gap-x-3 tw:text-xs tw:text-prise-muted">
              {u.province && <span><i className="bi bi-geo-alt tw:mr-1" aria-hidden="true" />{u.province}</span>}
              <UsersCount n={u.usersCount} />
            </div>
          </div>
          <RowActions
            item={u}
            busy={busy}
            onEdit={() => open('unit', u.id)}
            onToggle={() => call(() => api.put(`/organization/units/${u.id}/toggle`))}
            onDelete={() => call(() => api.delete(`/organization/units/${u.id}`))}
          />
        </div>
      )}
    </li>
  );

  return (
    <>
      <PageHead title="Organisation du projet" desc="Coordination nationale, pools et postes proposés à l’inscription et utilisés pour limiter l’accès de chaque compte à son unité." />
      <Notice type={flash.type} onClose={() => setFlash({ ...flash, text: '' })}>{flash.text}</Notice>

      <div className="tw:grid tw:items-start tw:gap-6 tw:xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <Section
          title="Unités"
          count={units.length}
          action={!isEditing('unit', 'new') && <button type="button" className={btnPrimary} onClick={() => open('unit', 'new')}><i className="bi bi-plus-lg" aria-hidden="true" />Ajouter un pool</button>}
        >
          {isEditing('unit', 'new') && (
            <div className="tw:border-b tw:border-prise-border"><UnitForm initial={emptyUnit} onSubmit={saveUnit} onCancel={() => setEditing(null)} busy={busy} errors={errors} /></div>
          )}

          <h3 className="tw:m-0 tw:bg-prise-bg tw:px-5 tw:py-2 tw:font-tech tw:text-xs tw:font-semibold tw:text-prise-muted">Coordination nationale</h3>
          <ul className="tw:m-0 tw:list-none tw:p-0">{coordination.map(unitRow)}</ul>
          {!coordination.length && <p className="tw:m-0 tw:px-5 tw:py-3.5 tw:text-sm tw:text-prise-muted">Aucune coordination nationale. Ajoutez une unité de type « Coordination nationale ».</p>}

          <h3 className="tw:m-0 tw:border-t tw:border-prise-border tw:bg-prise-bg tw:px-5 tw:py-2 tw:font-tech tw:text-xs tw:font-semibold tw:text-prise-muted">Pools</h3>
          <ul className="tw:m-0 tw:list-none tw:p-0">{pools.map(unitRow)}</ul>
          {!pools.length && (
            <p className="tw:m-0 tw:px-5 tw:py-6 tw:text-sm tw:text-prise-muted">
              Aucun pool pour l’instant. Créez les pools du projet pour que chaque agent soit rattaché à son site.
            </p>
          )}
        </Section>

        <Section
          title="Postes"
          count={data.positions.length}
          action={!isEditing('position', 'new') && <button type="button" className={btnPrimary} onClick={() => open('position', 'new')}><i className="bi bi-plus-lg" aria-hidden="true" />Ajouter un poste</button>}
        >
          {isEditing('position', 'new') && (
            <div className="tw:border-b tw:border-prise-border"><PositionForm initial={{ name: '', description: '', organizational_unit_id: '' }} units={units} onSubmit={savePosition} onCancel={() => setEditing(null)} busy={busy} errors={errors} /></div>
          )}
          {positionGroups.map(([groupName, list]) => (
          <React.Fragment key={groupName}>
          <h3 className="tw:m-0 tw:border-b tw:border-prise-border tw:bg-prise-bg tw:px-5 tw:py-2 tw:font-tech tw:text-xs tw:font-semibold tw:text-prise-muted">{groupName}</h3>
          <ul className="tw:m-0 tw:list-none tw:p-0">
            {list.map((p) => (
              <li key={p.id} className="tw:border-b tw:border-prise-border tw:last:border-b-0">
                {isEditing('position', p.id) ? (
                  <PositionForm initial={{ id: p.id, name: p.name, description: p.description, organizational_unit_id: p.unitId ? String(p.unitId) : '' }} units={units} onSubmit={savePosition} onCancel={() => setEditing(null)} busy={busy} errors={errors} />
                ) : (
                  <div className={`tw:flex tw:flex-wrap tw:items-center tw:gap-x-4 tw:gap-y-2 tw:px-5 tw:py-3.5 ${p.isActive ? '' : 'tw:opacity-60'}`}>
                    <div className="tw:min-w-0 tw:flex-1">
                      <div className="tw:flex tw:flex-wrap tw:items-baseline tw:gap-x-2">
                        <span className="tw:font-semibold tw:text-prise-text">{p.name}</span>
                        {!p.isActive && <StatePill active={false} />}
                      </div>
                      <div className="tw:mt-0.5 tw:flex tw:flex-wrap tw:gap-x-3 tw:text-xs tw:text-prise-muted">
                        {p.description && <span>{p.description}</span>}
                        <UsersCount n={p.usersCount} />
                      </div>
                    </div>
                    <RowActions
                      item={p}
                      busy={busy}
                      onEdit={() => open('position', p.id)}
                      onToggle={() => call(() => api.put(`/organization/positions/${p.id}/toggle`))}
                      onDelete={() => call(() => api.delete(`/organization/positions/${p.id}`))}
                    />
                  </div>
                )}
              </li>
            ))}
          </ul>
          </React.Fragment>
          ))}
          {!data.positions.length && !isEditing('position', 'new') && (
            <p className="tw:m-0 tw:px-5 tw:py-6 tw:text-sm tw:text-prise-muted">
              Aucun poste défini. Les postes (logisticien, comptable/RAF, secrétaire-caissière…) sont proposés aux agents lors de leur demande d’accès.
            </p>
          )}
        </Section>
      </div>
    </>
  );
}

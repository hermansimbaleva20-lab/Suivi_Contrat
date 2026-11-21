import React, { useState } from 'react';
import { api, apiError, fieldErrors } from '../../api.js';
import { useLoad } from '../../lib/hooks.js';
import { num } from '../../lib/format.js';
import { DecimalInput, Loading, Notice, PageHead } from '../../components/ui.jsx';
import { btn, btnGhost, btnPrimary, FieldError, input, label, panel } from '../shared/tw.jsx';

/*
 * Administration › Composantes du projet, sur le modèle de la matrice d'état d'exécution (décisions du 10 et du 16/11/2026) :
 *   composante (intitulé) → catégories à intitulé libre (« Travaux », « Services », « Biens »…) → activités de la catégorie,
 *   chacune avec son coût en UC (obligatoire).
 * Le coût d'une catégorie est la somme des coûts de ses activités, et le montant total de la composante la somme des coûts
 * de ses catégories : rien de cela ne se saisit. Les contrats se rattachent à une activité (formulaire du contrat).
 * Réservé à la coordination nationale (« Gérer les paramètres ») et au super-admin ; activer ou désactiver une activité :
 * super-admin seulement. Ce qui est utilisé par des contrats se désactive au lieu de se supprimer.
 *
 * Présentation (16/11/2026) : chaque composante est un tableau budgétaire, comme un état financier : catégories numérotées
 * en lignes de groupe (1, 2…), activités numérotées dessous (1.1, 1.2…), coût, part de la composante, contrats rattachés,
 * état et actions, total en pied de tableau.
 */

// Montant en UC tel que saisi : les centimes s'affichent quand il y en a (13 718 000,50 ne devient pas 13 718 001).
const uc = (v) => (v === null || v === undefined ? '—' : `${num(v, Number.isInteger(Math.round(Number(v) * 100) / 100) ? 0 : 2)} UC`);
const toInput = (v) => (v === null || v === undefined ? '' : String(v).replace('.', ','));
const plural = (n, one, many) => `${n} ${n > 1 ? many : one}`;
const categoryTitle = (name) => (/^cat[ée]gorie\b/i.test(name) ? name : `Catégorie ${name}`);
const share = (part, whole) => (whole > 0 && part !== null && part !== undefined ? (Number(part) / whole) * 100 : null);

// Cellules du tableau, sur le modèle des autres tableaux de l'application (en-têtes en petites capitales, filets fins).
const th = 'tw:border-b tw:border-prise-border tw:bg-prise-bg tw:px-3 tw:py-2.5 tw:text-left tw:font-tech tw:text-[11px] tw:font-semibold tw:uppercase tw:tracking-wide tw:text-prise-muted tw:whitespace-nowrap';
const thNum = `${th} tw:text-right`;
const td = 'tw:border-b tw:border-prise-border tw:px-3 tw:py-2.5 tw:align-middle tw:text-sm tw:text-prise-text';
const tdNum = `${td} tw:text-right tw:font-tech tw:tabular-nums tw:whitespace-nowrap`;
const COLS = 6;
const iconBtn = `${btn} tw:size-8 tw:justify-center tw:px-0 tw:bg-transparent tw:text-prise-muted tw:hover:bg-prise-green-soft tw:hover:text-prise-text`;
const iconDanger = `${btn} tw:size-8 tw:justify-center tw:px-0 tw:bg-transparent tw:text-prise-danger tw:hover:bg-red-50`;

/** Formulaire en ligne : champs, puis Annuler / Enregistrer. */
function InlineForm({ initial, fields, submitLabel, onSubmit, onCancel, busy, errors, tone = 'tw:bg-prise-bg/60' }) {
  const [f, setF] = useState(initial);
  return (
    <form className={`tw:grid tw:gap-3 tw:px-5 tw:py-4 tw:sm:grid-cols-12 ${tone}`} onSubmit={(e) => { e.preventDefault(); onSubmit(f); }}>
      {fields.map((x) => (
        <div key={x.key} className={x.span}>
          <label className={label} htmlFor={x.id}>{x.label}</label>
          {x.amount
            ? <DecimalInput id={x.id} className="tw:text-right tw:font-tech" value={f[x.key]} onChange={(v) => setF({ ...f, [x.key]: v })} placeholder={x.placeholder} required={x.required} />
            : <input id={x.id} className={input} value={f[x.key]} onChange={(e) => setF({ ...f, [x.key]: e.target.value })} maxLength={x.max} required={x.required} placeholder={x.placeholder} autoFocus={x.autoFocus} />}
          <FieldError text={errors[x.key]} />
        </div>
      ))}
      <div className="tw:flex tw:items-end tw:justify-end tw:gap-2 tw:sm:col-span-12">
        <button type="button" className={btnGhost} onClick={onCancel}>Annuler</button>
        <button type="submit" className={btnPrimary} disabled={busy}>{submitLabel}</button>
      </div>
    </form>
  );
}

/** Ligne du tableau qui porte un formulaire sur toute la largeur. */
function FormRow({ children }) {
  return <tr><td colSpan={COLS} className="tw:border-b tw:border-prise-border tw:p-0">{children}</td></tr>;
}

/** Modifier, activer ou désactiver, supprimer (avec confirmation) : icônes avec infobulle ; la suppression n'est proposée que sans contrat. */
function Actions({ name, busy, onEdit, onToggle, active, onDelete }) {
  const [confirm, setConfirm] = useState(false);
  if (confirm) {
    return (
      <div className="tw:flex tw:items-center tw:justify-end tw:gap-1.5">
        <span className="tw:text-xs tw:font-semibold tw:text-prise-danger">Supprimer ?</span>
        <button type="button" className={btnGhost} onClick={() => setConfirm(false)}>Non</button>
        <button type="button" className={`${btn} tw:bg-prise-danger tw:text-white tw:hover:bg-red-800`} disabled={busy} onClick={onDelete}>Oui</button>
      </div>
    );
  }
  return (
    <div className="tw:flex tw:items-center tw:justify-end tw:gap-0.5">
      {onEdit && <button type="button" className={iconBtn} onClick={onEdit} aria-label={`Modifier ${name}`} title="Modifier"><i className="bi bi-pencil" aria-hidden="true" /></button>}
      {onToggle && (
        <button type="button" className={iconBtn} disabled={busy} onClick={onToggle} aria-label={`${active ? 'Désactiver' : 'Réactiver'} ${name}`} title={active ? 'Désactiver' : 'Réactiver'}>
          <i className={`bi ${active ? 'bi-toggle-on tw:text-prise-green-2' : 'bi-toggle-off'} tw:text-[17px]`} aria-hidden="true" />
        </button>
      )}
      {onDelete && <button type="button" className={iconDanger} onClick={() => setConfirm(true)} aria-label={`Supprimer ${name}`} title="Supprimer"><i className="bi bi-trash" aria-hidden="true" /></button>}
    </div>
  );
}

/** Part de la composante : pourcentage, avec une barre fine proportionnelle. */
function Share({ value, strong = false }) {
  if (value === null) return <span className="tw:text-prise-muted">—</span>;
  return (
    <div className="tw:flex tw:items-center tw:justify-end tw:gap-2.5">
      <span className="tw:h-1.5 tw:w-16 tw:overflow-hidden tw:rounded-full tw:bg-prise-border" aria-hidden="true">
        <span className="tw:block tw:h-full tw:rounded-full tw:bg-prise-green-2" style={{ width: `${Math.min(100, value)}%` }} />
      </span>
      <span className={`tw:w-14 tw:text-right ${strong ? 'tw:font-bold' : ''}`}>{num(value, 1)} %</span>
    </div>
  );
}

function Status({ active }) {
  return active
    ? <span className="tw:inline-flex tw:items-center tw:gap-1.5 tw:rounded-full tw:bg-prise-green-soft tw:px-2.5 tw:py-0.5 tw:text-[11px] tw:font-bold tw:text-prise-green-2"><span className="tw:size-1.5 tw:rounded-full tw:bg-current" aria-hidden="true" />Active</span>
    : <span className="tw:inline-flex tw:items-center tw:gap-1.5 tw:rounded-full tw:bg-slate-100 tw:px-2.5 tw:py-0.5 tw:text-[11px] tw:font-bold tw:text-slate-600"><span className="tw:size-1.5 tw:rounded-full tw:bg-current" aria-hidden="true" />Désactivée</span>;
}

/** Une catégorie : sa ligne de groupe, ses activités, l'ajout d'une activité. */
function CategoryRows({ k, index, total, ctx }) {
  const { editing, open, busy, errors, call, canToggle, ready } = ctx;
  return (
    <tbody>
      {editing === `k:${k.id}` ? (
        <FormRow>
          <InlineForm initial={{ name: k.name }} submitLabel="Enregistrer" busy={busy} errors={errors} onCancel={() => open(null)}
            fields={[{ key: 'name', id: `k-name-${k.id}`, label: 'Intitulé de la catégorie', span: 'tw:sm:col-span-12', max: 150, required: true, autoFocus: true }]}
            onSubmit={(f) => call(() => api.put(`/component-categories/${k.id}`, f))} />
        </FormRow>
      ) : (
        <tr className="tw:bg-prise-green-soft/60">
          <th scope="rowgroup" className={`${td} tw:text-left tw:font-normal`}>
            <div className="tw:flex tw:items-baseline tw:gap-3">
              <span className="tw:w-8 tw:shrink-0 tw:font-tech tw:text-[13px] tw:font-bold tw:text-prise-green-2">{index}</span>
              <span className="tw:min-w-0">
                <span className="tw:block tw:font-display tw:text-[15px] tw:font-bold tw:text-prise-blue">{categoryTitle(k.name)}</span>
                <span className="tw:text-xs tw:text-prise-muted">{plural(k.activities.length, 'activité', 'activités')}</span>
              </span>
            </div>
          </th>
          <td className={`${tdNum} tw:font-bold`}>{uc(k.cost)}</td>
          <td className={tdNum}><Share value={share(k.cost, total)} strong /></td>
          <td className={tdNum}>{k.contracts}</td>
          <td className={td} />
          <td className={td}>
            <Actions name={categoryTitle(k.name)} busy={busy} onEdit={() => open(`k:${k.id}`)}
              onDelete={k.contracts === 0 ? () => call(() => api.delete(`/component-categories/${k.id}`), false) : null} />
          </td>
        </tr>
      )}

      {k.activities.map((a, j) => (editing === `a:${a.id}` ? (
        <FormRow key={a.id}>
          <InlineForm initial={{ name: a.name, budget: toInput(a.budget) }} submitLabel="Enregistrer" busy={busy} errors={errors} onCancel={() => open(null)} tone=""
            fields={[
              { key: 'name', id: `a-name-${a.id}`, label: 'Intitulé de l’activité', span: 'tw:sm:col-span-8', max: 500, required: true, autoFocus: true },
              { key: 'budget', id: `a-budget-${a.id}`, label: 'Coût de l’activité (UC)', span: 'tw:sm:col-span-4', amount: true, required: true },
            ]}
            onSubmit={(f) => call(() => api.put(`/component-activities/${a.id}`, f))} />
        </FormRow>
      ) : (
        <tr key={a.id} className="tw:transition-colors tw:hover:bg-prise-bg/70">
          <th scope="row" className={`${td} tw:text-left tw:font-normal`}>
            <div className="tw:flex tw:items-baseline tw:gap-3 tw:pl-4">
              <span className="tw:w-8 tw:shrink-0 tw:font-tech tw:text-xs tw:text-prise-muted">{index}.{j + 1}</span>
              <span className={`tw:min-w-0 tw:leading-snug ${a.isActive ? '' : 'tw:text-prise-muted'}`}>{a.name}</span>
            </div>
          </th>
          <td className={`${tdNum} ${a.isActive ? '' : 'tw:text-prise-muted'}`}>{uc(a.budget)}</td>
          <td className={tdNum}><Share value={share(a.budget, total)} /></td>
          <td className={`${tdNum} ${a.contracts ? '' : 'tw:text-prise-muted'}`}>{a.contracts}</td>
          <td className={td}><Status active={a.isActive} /></td>
          <td className={td}>
            <Actions name={a.name} busy={busy} onEdit={() => open(`a:${a.id}`)} active={a.isActive}
              onToggle={canToggle ? () => call(() => api.put(`/component-activities/${a.id}/toggle`), false) : null}
              onDelete={a.contracts === 0 ? () => call(() => api.delete(`/component-activities/${a.id}`), false) : null} />
          </td>
        </tr>
      )))}

      {editing === `a-new:${k.id}` ? (
        <FormRow>
          <InlineForm initial={{ name: '', budget: '' }} submitLabel="Ajouter l’activité" busy={busy} errors={errors} onCancel={() => open(null)} tone=""
            fields={[
              { key: 'name', id: `a-new-name-${k.id}`, label: 'Intitulé de l’activité', span: 'tw:sm:col-span-8', max: 500, required: true, autoFocus: true, placeholder: 'ex. Réalisation de 22 systèmes d’AEP en milieu rural avec énergie solaire' },
              { key: 'budget', id: `a-new-budget-${k.id}`, label: 'Coût de l’activité (UC)', span: 'tw:sm:col-span-4', amount: true, required: true, placeholder: 'ex. 12 010 331' },
            ]}
            onSubmit={(f) => call(() => api.post(`/component-categories/${k.id}/activities`, f))} />
        </FormRow>
      ) : ready && (
        <tr>
          <td colSpan={COLS} className="tw:border-b tw:border-prise-border tw:py-1.5 tw:pl-14">
            <button type="button" className={btnGhost} onClick={() => open(`a-new:${k.id}`)} aria-label={`Ajouter une activité à la ${categoryTitle(k.name).toLowerCase()}`}><i className="bi bi-plus-lg" aria-hidden="true" />Ajouter une activité</button>
          </td>
        </tr>
      )}
    </tbody>
  );
}

function Component({ c, ctx }) {
  const { editing, open, busy, errors, call, ready } = ctx;
  const total = Number(c.total) || 0;
  const activities = c.categories.reduce((n, k) => n + k.activities.length, 0);
  return (
    <section className={`${panel} tw:mb-5 tw:overflow-hidden`} aria-label={c.name}>
      {editing === `c:${c.id}` ? (
        <InlineForm initial={{ name: c.name, description: c.description }} submitLabel="Enregistrer" busy={busy} errors={errors} onCancel={() => open(null)}
          fields={[
            { key: 'name', id: `c-name-${c.id}`, label: 'Intitulé de la composante', span: 'tw:sm:col-span-12', max: 255, required: true, autoFocus: true },
            { key: 'description', id: `c-desc-${c.id}`, label: 'Description (facultatif)', span: 'tw:sm:col-span-12', max: 2000 },
          ]}
          onSubmit={(f) => call(() => api.put(`/components/${c.id}`, f))} />
      ) : (
        <header className="tw:flex tw:flex-wrap tw:items-center tw:gap-x-6 tw:gap-y-3 tw:border-b tw:border-prise-border tw:px-5 tw:py-4">
          <div className="tw:min-w-0 tw:basis-full tw:sm:basis-0 tw:sm:grow">
            <div className="tw:flex tw:flex-wrap tw:items-center tw:gap-x-2.5 tw:gap-y-1">
              <h2 className={`tw:m-0 tw:font-display tw:text-lg tw:font-bold ${c.isActive ? 'tw:text-prise-blue' : 'tw:text-prise-muted'}`}>{c.name}</h2>
              {!c.isActive && <Status active={false} />}
            </div>
            <p className="tw:m-0 tw:mt-1 tw:text-xs tw:text-prise-muted">
              {[c.description, plural(c.categories.length, 'catégorie', 'catégories'), plural(activities, 'activité', 'activités'), plural(c.contracts, 'contrat rattaché', 'contrats rattachés')].filter(Boolean).join(' · ')}
            </p>
          </div>
          <div className="tw:text-right">
            <span className="tw:block tw:text-[11px] tw:font-semibold tw:uppercase tw:tracking-wide tw:text-prise-muted">{c.totalComputed || c.legacyBudget === null ? 'Montant total' : 'Budget saisi (ancien)'}</span>
            <span className="tw:font-tech tw:text-xl tw:font-bold tw:tabular-nums tw:text-prise-text">{uc(c.total)}</span>
          </div>
          <Actions name={c.name} busy={busy} onEdit={() => open(`c:${c.id}`)} active={c.isActive} onToggle={() => call(() => api.put(`/components/${c.id}/toggle`), false)}
            onDelete={c.contracts === 0 ? () => call(() => api.delete(`/components/${c.id}`), false) : null} />
        </header>
      )}

      <div className="tw:overflow-x-auto">
        <table className="tw:w-full tw:min-w-[760px] tw:border-collapse">
          <caption className="tw:sr-only">Catégories et activités de la composante {c.name}</caption>
          <colgroup>
            <col /><col className="tw:w-40" /><col className="tw:w-44" /><col className="tw:w-24" /><col className="tw:w-32" /><col className="tw:w-32" />
          </colgroup>
          <thead>
            <tr>
              <th scope="col" className={th}>Désignation</th>
              <th scope="col" className={thNum}>Coût (UC)</th>
              <th scope="col" className={thNum}>Part de la composante</th>
              <th scope="col" className={thNum}>Contrats</th>
              <th scope="col" className={th}>État</th>
              <th scope="col" className={th}><span className="tw:sr-only">Actions</span></th>
            </tr>
          </thead>
          {c.categories.map((k, i) => <CategoryRows key={k.id} k={k} index={i + 1} total={total} ctx={ctx} />)}
          <tbody>
            {!c.categories.length && editing !== `k-new:${c.id}` && (
              <tr><td colSpan={COLS} className={`${td} tw:py-6 tw:text-center tw:text-prise-muted`}>Aucune catégorie : ajoutez la première (Travaux, Services, Biens…), puis ses activités.</td></tr>
            )}
            {editing === `k-new:${c.id}` ? (
              <FormRow>
                <InlineForm initial={{ name: '' }} submitLabel="Ajouter la catégorie" busy={busy} errors={errors} onCancel={() => open(null)}
                  fields={[{ key: 'name', id: `k-new-name-${c.id}`, label: 'Intitulé de la catégorie', span: 'tw:sm:col-span-12', max: 150, required: true, autoFocus: true, placeholder: 'ex. Travaux, Services, Biens' }]}
                  onSubmit={(f) => call(() => api.post(`/components/${c.id}/categories`, f))} />
              </FormRow>
            ) : ready && (
              <tr>
                <td colSpan={COLS} className="tw:border-b tw:border-prise-border tw:px-3 tw:py-2">
                  <button type="button" className={btnGhost} onClick={() => open(`k-new:${c.id}`)}><i className="bi bi-plus-lg" aria-hidden="true" />Ajouter une catégorie</button>
                </td>
              </tr>
            )}
          </tbody>
          {c.categories.length > 0 && (
            <tfoot>
              <tr className="tw:bg-prise-bg">
                <th scope="row" className="tw:px-3 tw:py-3 tw:text-left tw:text-sm tw:font-bold tw:text-prise-text">
                  Total de la composante
                  <span className="tw:ml-2 tw:text-xs tw:font-normal tw:text-prise-muted">coût de chaque catégorie = somme de ses activités</span>
                </th>
                <td className="tw:px-3 tw:py-3 tw:text-right tw:font-tech tw:text-[15px] tw:font-bold tw:tabular-nums tw:whitespace-nowrap tw:text-prise-text">{uc(c.total)}</td>
                <td className="tw:px-3 tw:py-3 tw:text-right tw:font-tech tw:text-sm tw:font-bold tw:tabular-nums tw:text-prise-text">{c.total ? '100,0 %' : '—'}</td>
                <td className="tw:px-3 tw:py-3 tw:text-right tw:font-tech tw:text-sm tw:font-bold tw:tabular-nums tw:text-prise-text">{c.contracts}</td>
                <td colSpan={2} />
              </tr>
            </tfoot>
          )}
        </table>
      </div>
    </section>
  );
}

/** Chiffres clés du projet, en une ligne. */
function Figures({ list }) {
  const active = list.filter((c) => c.isActive);
  const cats = active.flatMap((c) => c.categories);
  const acts = cats.flatMap((k) => k.activities);
  const items = [
    ['Composantes actives', active.length],
    ['Catégories', cats.length],
    ['Activités', acts.length],
    ['Contrats rattachés', active.reduce((n, c) => n + c.contracts, 0)],
  ];
  return (
    <dl className={`${panel} tw:m-0 tw:mb-5 tw:flex tw:flex-wrap tw:items-end tw:gap-x-8 tw:gap-y-3 tw:px-5 tw:py-3.5`}>
      {items.map(([k, v]) => (
        <div key={k}>
          <dt className="tw:text-[11px] tw:font-semibold tw:uppercase tw:tracking-wide tw:text-prise-muted">{k}</dt>
          <dd className="tw:m-0 tw:font-tech tw:text-lg tw:font-bold tw:tabular-nums tw:text-prise-text">{v}</dd>
        </div>
      ))}
      <div className="tw:ml-auto tw:text-right">
        <dt className="tw:text-[11px] tw:font-semibold tw:uppercase tw:tracking-wide tw:text-prise-muted">Montant total des composantes actives</dt>
        <dd className="tw:m-0 tw:font-tech tw:text-lg tw:font-bold tw:tabular-nums tw:text-prise-green-2">{uc(active.reduce((a, c) => a + (Number(c.total) || 0), 0))}</dd>
      </div>
    </dl>
  );
}

export default function ComponentsPage() {
  const { data, loading, error, reload } = useLoad('/components');
  const [flash, setFlash] = useState({ type: 'success', text: '' });
  // Un seul formulaire ouvert à la fois : 'new', 'c:ID', 'k-new:ID', 'k:ID', 'a-new:ID', 'a:ID'.
  const [editing, setEditing] = useState(null);
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);

  if (loading && !data) return <Loading />;
  if (!data) return <Notice>{error}</Notice>;

  const open = (key) => { setErrors({}); setEditing(key); };
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
  const list = data.components;
  const ctx = { editing, open, busy, errors, call, canToggle: data.canToggleActivities, ready: data.detailReady };

  return (
    <>
      <PageHead title="Composantes du projet">
        {editing !== 'new' && <button type="button" className={btnPrimary} onClick={() => open('new')}><i className="bi bi-plus-lg" aria-hidden="true" />Nouvelle composante</button>}
      </PageHead>
      <Notice type={flash.type} onClose={() => setFlash({ ...flash, text: '' })}>{flash.text}</Notice>
      {!data.detailReady && (
        <Notice type="warning">Base non mise à jour : catégories et activités indisponibles (php artisan migrate, ou script V39_categories_activites_composantes.sql).</Notice>
      )}

      {list.length > 0 && <Figures list={list} />}

      {editing === 'new' && (
        <section className={`${panel} tw:mb-5 tw:overflow-hidden`}>
          <InlineForm initial={{ name: '', description: '' }} submitLabel="Enregistrer la composante" busy={busy} errors={errors} onCancel={() => open(null)}
            fields={[
              { key: 'name', id: 'cmp-name', label: 'Intitulé de la composante', span: 'tw:sm:col-span-12', max: 255, required: true, autoFocus: true, placeholder: 'ex. A. Développement des infrastructures' },
              { key: 'description', id: 'cmp-desc', label: 'Description (facultatif)', span: 'tw:sm:col-span-12', max: 2000, placeholder: 'ex. Prêt FAD' },
            ]}
            onSubmit={(f) => call(() => api.post('/components', f))} />
        </section>
      )}

      {list.map((c) => <Component key={c.id} c={c} ctx={ctx} />)}

      {!list.length && editing !== 'new' && (
        <section className={`${panel} tw:px-5 tw:py-10 tw:text-center`}>
          <p className="tw:m-0 tw:font-display tw:text-lg tw:font-bold tw:text-prise-blue">Aucune composante enregistrée</p>
          <p className="tw:m-0 tw:mt-1 tw:text-sm tw:text-prise-muted">Commencez par « Nouvelle composante », puis ajoutez ses catégories et leurs activités chiffrées.</p>
        </section>
      )}
    </>
  );
}

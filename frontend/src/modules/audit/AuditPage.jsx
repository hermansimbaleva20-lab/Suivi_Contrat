import React, { useState } from 'react';
import { apiError, download } from '../../api.js';
import { useLoad } from '../../lib/hooks.js';
import { Loading, Notice } from '../../components/ui.jsx';
import { btn, btnGhost, input, label, panel } from '../shared/tw.jsx';
import { ModuleHeader } from '../shared/ModuleHeader.jsx';

/*
 * Journal d'audit par utilisateur (TDR §12) : à gauche, l'activité de chaque compte (un clic filtre le journal sur cette
 * personne) ; à droite, les opérations filtrées par action, type d'élément, période et texte, avec les valeurs avant/après.
 */

const th = 'tw:border-b tw:border-prise-border tw:bg-prise-bg tw:px-3 tw:py-2.5 tw:text-left tw:font-tech tw:text-[11px] tw:font-semibold tw:uppercase tw:tracking-wide tw:text-prise-muted';
const td = 'tw:border-b tw:border-prise-border tw:px-3 tw:py-2.5 tw:text-sm tw:text-prise-text tw:align-top';
const fmt = (v) => (v === null || v === undefined || v === '' ? '—' : typeof v === 'object' ? JSON.stringify(v) : String(v));

/** Valeurs modifiées : seules les clés qui changent sont listées. */
function Changes({ before, after }) {
  const keys = [...new Set([...Object.keys(before || {}), ...Object.keys(after || {})])]
    .filter((k) => !['id', 'project_id', 'created_at', 'updated_at'].includes(k) && fmt(before?.[k]) !== fmt(after?.[k]));
  if (!keys.length) return null;
  return (
    <table className="tw:mt-2 tw:w-full tw:border-collapse tw:text-xs">
      <thead><tr><th className="tw:py-1 tw:pr-3 tw:text-left tw:text-prise-muted">Champ</th><th className="tw:py-1 tw:pr-3 tw:text-left tw:text-prise-muted">Avant</th><th className="tw:py-1 tw:text-left tw:text-prise-muted">Après</th></tr></thead>
      <tbody>
        {keys.map((k) => (
          <tr key={k} className="tw:border-t tw:border-prise-border">
            <td className="tw:py-1 tw:pr-3 tw:font-tech">{k}</td>
            <td className="tw:max-w-64 tw:break-words tw:py-1 tw:pr-3 tw:text-prise-danger">{before ? fmt(before[k]) : '—'}</td>
            <td className="tw:max-w-64 tw:break-words tw:py-1 tw:text-prise-success">{after ? fmt(after[k]) : '— (supprimé)'}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

export default function AuditPage() {
  const [f, setF] = useState({ user_id: '', action: '', entity: '', from: '', to: '', q: '' });
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [open, setOpen] = useState(null);
  const [msg, setMsg] = useState('');
  const params = Object.fromEntries(Object.entries({ ...f, page }).filter(([, v]) => v !== '' && v !== null));
  const { data, loading, error } = useLoad('/audit', params);
  const { data: users } = useLoad('/audit/users', Object.fromEntries(Object.entries(f).filter(([k, v]) => k !== 'user_id' && v !== '')));
  const set = (k, v) => { setF({ ...f, [k]: v }); setPage(1); };

  if (loading && !data) return <Loading />;
  if (!data) return <Notice>{error}</Notice>;
  const selected = users?.find((u) => String(u.userId) === String(f.user_id));

  return (
    <>
      <ModuleHeader icon="journal-text" title="Journal d’audit" unit={selected ? selected.user : 'Tous les comptes'}
        desc="Qui a saisi, modifié, validé ou supprimé quoi, et quand. Choisissez une personne pour suivre son activité ; chaque modification garde ses valeurs avant et après."
        actions={<button type="button" className={btnGhost} onClick={() => download('/audit/export', Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '')), 'journal_audit.xlsx').catch((e) => setMsg(apiError(e)))}><i className="bi bi-file-earmark-excel" aria-hidden="true" />Exporter</button>} />
      <Notice onClose={() => setMsg('')}>{msg}</Notice>

      <div className="tw:grid tw:items-start tw:gap-5 tw:lg:grid-cols-[minmax(0,1fr)_minmax(0,3fr)]">
        <section className={panel} aria-labelledby="au-users">
          <h2 id="au-users" className="tw:m-0 tw:border-b tw:border-prise-border tw:px-4 tw:py-3.5 tw:font-display tw:text-[15px] tw:font-bold tw:text-prise-blue">Activité par compte</h2>
          <ul className="tw:m-0 tw:max-h-[70vh] tw:list-none tw:overflow-y-auto tw:p-0">
            <li>
              <button type="button" onClick={() => set('user_id', '')} className={`${btn} tw:w-full tw:justify-between tw:rounded-none tw:px-4 tw:py-2.5 ${f.user_id === '' ? 'tw:bg-prise-green-soft tw:text-prise-green' : 'tw:bg-transparent tw:text-prise-text tw:hover:bg-prise-bg'}`}>
                Tous les comptes <span className="tw:font-tech tw:tabular-nums">{users ? users.reduce((s, u) => s + u.count, 0) : ''}</span>
              </button>
            </li>
            {(users || []).map((u) => (
              <li key={u.userId} className="tw:border-t tw:border-prise-border">
                <button type="button" onClick={() => set('user_id', String(u.userId))} aria-pressed={String(u.userId) === f.user_id}
                  className={`${btn} tw:w-full tw:flex-col tw:items-start tw:gap-0.5 tw:rounded-none tw:px-4 tw:py-2.5 tw:text-left tw:whitespace-normal ${String(u.userId) === f.user_id ? 'tw:bg-prise-green-soft' : 'tw:bg-transparent tw:hover:bg-prise-bg'}`}>
                  <span className="tw:flex tw:w-full tw:justify-between tw:gap-2 tw:font-body tw:text-sm tw:text-prise-text"><span className="tw:font-semibold">{u.user}</span><span className="tw:font-tech tw:tabular-nums">{u.count}</span></span>
                  <span className="tw:font-body tw:text-[11px] tw:font-normal tw:text-prise-muted">Dernière activité : {u.lastAt}</span>
                  <span className="tw:font-body tw:text-[11px] tw:font-normal tw:text-prise-muted">{u.topActions.map((a) => `${a.action} (${a.count})`).join(' · ')}</span>
                </button>
              </li>
            ))}
          </ul>
        </section>

        <section className={panel}>
          <div className="tw:flex tw:flex-wrap tw:items-end tw:gap-3 tw:border-b tw:border-prise-border tw:px-4 tw:py-3.5">
            <div><label className={label} htmlFor="au-action">Action</label>
              <select id="au-action" className={input} value={f.action} onChange={(e) => set('action', e.target.value)}><option value="">Toutes</option>{data.actions.map((a) => <option key={a} value={a}>{a}</option>)}</select></div>
            <div><label className={label} htmlFor="au-entity">Élément</label>
              <select id="au-entity" className={input} value={f.entity} onChange={(e) => set('entity', e.target.value)}><option value="">Tous</option>{data.entities.map((x) => <option key={x.key} value={x.key}>{x.label}</option>)}</select></div>
            <div><label className={label} htmlFor="au-from">Du</label><input id="au-from" type="date" className={`${input} tw:font-tech`} value={f.from} onChange={(e) => set('from', e.target.value)} /></div>
            <div><label className={label} htmlFor="au-to">Au</label><input id="au-to" type="date" className={`${input} tw:font-tech`} value={f.to} onChange={(e) => set('to', e.target.value)} /></div>
            <form className="tw:min-w-52 tw:flex-1" onSubmit={(e) => { e.preventDefault(); set('q', search.trim()); }}>
              <label className={label} htmlFor="au-q">Rechercher dans les détails</label>
              <input id="au-q" type="search" className={input} value={search} onChange={(e) => setSearch(e.target.value)} onBlur={() => search.trim() !== f.q && set('q', search.trim())} placeholder="N° de contrat, article, adresse…" />
            </form>
          </div>
          <p className="tw:m-0 tw:px-4 tw:py-2 tw:text-xs tw:text-prise-muted" role="status">{data.meta.total} opération(s){selected ? ` de ${selected.user}` : ''}</p>
          <div className="tw:overflow-x-auto">
            <table className="tw:w-full tw:border-collapse">
              <thead><tr>{['Date', 'Compte', 'Action', 'Élément', 'Détails'].map((h) => <th key={h} className={th} scope="col">{h}</th>)}</tr></thead>
              <tbody>
                {data.data.map((a) => (
                  <tr key={a.id}>
                    <td className={`${td} tw:font-tech tw:whitespace-nowrap`}>{a.date.slice(0, 16).replace('T', ' ')}</td>
                    <td className={`${td} tw:whitespace-nowrap`}>{a.user}{a.unit && <span className="tw:block tw:text-[11px] tw:text-prise-muted">{a.unit}</span>}</td>
                    <td className={`${td} tw:font-tech tw:text-xs tw:font-semibold tw:whitespace-nowrap`}>{a.action}</td>
                    <td className={`${td} tw:whitespace-nowrap`}>{a.entity}{a.entityId && <span className="tw:font-tech tw:text-xs tw:text-prise-muted"> #{a.entityId}</span>}</td>
                    <td className={`${td} tw:min-w-72`}>
                      {a.details}
                      {(a.before || a.after) && (
                        <button type="button" className={`${btn} tw:ml-1 tw:bg-transparent tw:px-1 tw:py-0 tw:text-xs tw:text-prise-green-2 tw:hover:underline`} aria-expanded={open === a.id} onClick={() => setOpen(open === a.id ? null : a.id)}>
                          {open === a.id ? 'Masquer' : 'Avant / après'}
                        </button>
                      )}
                      {open === a.id && <Changes before={a.before} after={a.after} />}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {!data.data.length && <p className="tw:m-0 tw:px-4 tw:py-6 tw:text-center tw:text-sm tw:text-prise-muted">Aucune opération ne correspond à ces critères.</p>}
          {data.meta.pages > 1 && (
            <div className="tw:flex tw:items-center tw:justify-center tw:gap-3 tw:px-4 tw:py-3 tw:text-sm">
              <button type="button" className={btnGhost} disabled={page <= 1} onClick={() => setPage(page - 1)}>Précédent</button>
              <span className="tw:text-prise-muted">Page {data.meta.page} sur {data.meta.pages}</span>
              <button type="button" className={btnGhost} disabled={page >= data.meta.pages} onClick={() => setPage(page + 1)}>Suivant</button>
            </div>
          )}
        </section>
      </div>
    </>
  );
}

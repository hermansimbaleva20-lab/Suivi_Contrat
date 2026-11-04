import React, { useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { api, apiError, download, fieldErrors } from '../../api.js';
import { useLoad } from '../../lib/hooks.js';
import { AlertBlink } from '../shared/AlertBlink.jsx';
import { stockAlertLevel } from '../shared/alertLevels.js';
import { fr, money, todayISO } from '../../lib/format.js';
import { Loading, Notice } from '../../components/ui.jsx';
import { btn, btnDanger, btnGhost, btnPrimary, FieldError, input, label, panel } from '../shared/tw.jsx';
import { ModuleHeader } from '../shared/ModuleHeader.jsx';
import { ReadOnlyNote } from '../shared/ReadOnlyNote.jsx';
import { PrintLetterhead } from '../shared/PrintLetterhead.jsx';
import { STOCK } from '../shared/logisticsNav.js';
import { LevelPill, td, tdNum, th } from './stockUi.jsx';
import { cardNo, packLabel, qty } from './stockFormat.js';

/*
 * Logistique > Stock. Deux rubriques, sur le modèle des documents du projet :
 *  - État du stock : fournitures (N°, article, unité, quantité, PU $, PT $) de l'unité, réception des livraisons de
 *    l'entreprise titulaire ; chaque ligne ouvre la fiche de stock de l'article ;
 *  - Catalogue & prix unitaires : liste « prix unitaires évalués », colisage et niveau minimum, import depuis un fichier.
 * L'agent travaille dans son unité sans la choisir ; la vue nationale peut consulter chaque unité.
 */

function ArticleForm({ initial, onSubmit, onCancel, busy, errors }) {
  const [f, setF] = useState(initial);
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value });
  const field = (k, text, o = {}) => (
    <div className={o.span || 'tw:sm:col-span-3'}>
      <label className={label} htmlFor={`art-${k}`}>{text}</label>
      <input id={`art-${k}`} className={`${input} ${o.num ? 'tw:font-tech tw:text-right' : ''}`} inputMode={o.num ? 'decimal' : undefined} value={f[k]} onChange={set(k)}
        placeholder={o.placeholder} required={o.required} maxLength={o.max} list={o.list} autoComplete="off" />
      {o.hint && <p className="tw:m-0 tw:mt-1 tw:text-[11px] tw:text-prise-muted">{o.hint}</p>}
      <FieldError text={errors[k]} />
    </div>
  );
  return (
    <form className="tw:grid tw:gap-3 tw:border-b tw:border-prise-border tw:bg-prise-bg/60 tw:px-5 tw:py-4 tw:sm:grid-cols-12" onSubmit={(e) => { e.preventDefault(); onSubmit(f); }}>
      {field('number', 'N° de fiche', { num: true, span: 'tw:sm:col-span-2', placeholder: 'Automatique' })}
      {field('designation', 'Article', { span: 'tw:sm:col-span-6', required: true, max: 255, placeholder: 'ex. Rame papier duplicateur A4' })}
      {field('unit', 'Unité de suivi', { span: 'tw:sm:col-span-4', required: true, max: 40, list: 'art-units', placeholder: 'ex. Pce, Pqt, Carton' })}
      <datalist id="art-units">{['Pce', 'Pqt', 'Pqt/50pces', 'Pqt/100pces', 'Rame', 'Boîte', 'Carton', 'Colis', 'Litre'].map((u) => <option key={u} value={u} />)}</datalist>
      {field('pack_size', 'Contenu d’une unité', { num: true, placeholder: 'ex. 12', hint: 'Facultatif : 1 carton = 12 pièces' })}
      {field('pack_unit', 'Exprimé en', { placeholder: 'ex. pièces', max: 40 })}
      {field('unit_price', 'PU évalué (USD)', { num: true, required: true, placeholder: '0,00' })}
      {field('alert_threshold', 'Niveau minimum (alerte)', { num: true, placeholder: 'ex. 5', hint: 'Alerte quand le stock l’atteint' })}
      <div className="tw:flex tw:justify-end tw:gap-2 tw:sm:col-span-12">
        <button type="button" className={btnGhost} onClick={onCancel}>Annuler</button>
        <button type="submit" className={btnPrimary} disabled={busy}>{initial.id ? 'Enregistrer' : 'Ajouter l’article'}</button>
      </div>
    </form>
  );
}

function CatalogActions({ a, busy, onEdit, onToggle, onDelete }) {
  const [confirm, setConfirm] = useState(false);
  if (confirm) {
    return (
      <div className="tw:flex tw:items-center tw:justify-end tw:gap-2">
        <span className="tw:text-xs tw:text-prise-danger">Supprimer ?</span>
        <button type="button" className={btnGhost} onClick={() => setConfirm(false)}>Non</button>
        <button type="button" className={`${btn} tw:bg-prise-danger tw:text-white tw:hover:bg-red-800`} disabled={busy} onClick={onDelete}>Supprimer</button>
      </div>
    );
  }
  return (
    <div className="tw:flex tw:items-center tw:justify-end tw:gap-1">
      <button type="button" className={btnGhost} onClick={onEdit} aria-label={`Modifier ${a.designation}`} title="Modifier"><i className="bi bi-pencil" aria-hidden="true" /></button>
      <button type="button" className={btnGhost} disabled={busy} onClick={onToggle}>{a.isActive ? 'Désactiver' : 'Réactiver'}</button>
      {!a.hasMovements && <button type="button" className={btnDanger} onClick={() => setConfirm(true)} aria-label={`Supprimer ${a.designation}`} title="Supprimer"><i className="bi bi-trash" aria-hidden="true" /></button>}
    </div>
  );
}

/** Réception d'une livraison de l'entreprise titulaire : plusieurs articles, une entrée par fiche. */
function DeliveryForm({ data, unitName, onDone, onCancel }) {
  const articles = data.articles.filter((a) => a.isActive);
  const [f, setF] = useState({ company_id: data.companies.length === 1 ? String(data.companies[0].id) : '', movement_date: todayISO(), reference: '', observation: '' });
  const [lines, setLines] = useState([{ key: 1, stock_article_id: '', quantity: '' }]);
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const setLine = (i, k, v) => setLines(lines.map((l, j) => (j === i ? { ...l, [k]: v } : l)));
  const used = new Set(lines.map((l) => l.stock_article_id));
  const total = lines.reduce((s, l) => {
    const a = articles.find((x) => String(x.id) === l.stock_article_id);
    const n = Number(String(l.quantity).replace(',', '.'));
    return a && n > 0 ? s + a.unitPrice * n : s;
  }, 0);

  const submit = async (e) => {
    e.preventDefault();
    setBusy(true); setErrors({}); setError('');
    try {
      const r = await api.post('/stock/deliveries', { ...f, lines: lines.map(({ stock_article_id, quantity }) => ({ stock_article_id, quantity })) });
      onDone(r.data.message);
    } catch (err) {
      const fe = fieldErrors(err);
      setErrors(fe);
      if (!Object.keys(fe).length) setError(apiError(err));
    } finally { setBusy(false); }
  };

  if (!data.companies.length) {
    return (
      <div className="tw:border-b tw:border-prise-border tw:bg-amber-50/70 tw:px-5 tw:py-4 tw:text-sm tw:text-amber-900">
        Aucune entreprise n’est enregistrée dans le projet. Ajoutez d’abord l’entreprise titulaire du contrat de fournitures dans Administration › Entreprises.
        <button type="button" className={`${btnGhost} tw:ml-2`} onClick={onCancel}>Fermer</button>
      </div>
    );
  }
  return (
    <form onSubmit={submit} className="tw:grid tw:gap-4 tw:border-b tw:border-prise-border tw:bg-prise-bg/60 tw:px-5 tw:py-4" aria-label="Réception d’une livraison">
      <div className="tw:flex tw:flex-wrap tw:items-baseline tw:justify-between tw:gap-2">
        <h3 className="tw:m-0 tw:font-display tw:text-[15px] tw:font-bold tw:text-prise-blue">Réception d’une livraison</h3>
        <span className="tw:text-xs tw:text-prise-muted">Entrée en stock à <strong className="tw:text-prise-text">{unitName}</strong></span>
      </div>
      {error && <p className="tw:m-0 tw:text-sm tw:text-prise-danger" role="alert">{error}</p>}
      <div className="tw:grid tw:gap-3 tw:sm:grid-cols-12">
        <div className="tw:sm:col-span-5">
          <label className={label} htmlFor="dl-company">Entreprise qui a livré</label>
          <select id="dl-company" className={input} value={f.company_id} onChange={(e) => setF({ ...f, company_id: e.target.value })} required>
            <option value="">Choisir…</option>
            {data.companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
          <FieldError text={errors.company_id} />
        </div>
        <div className="tw:sm:col-span-3">
          <label className={label} htmlFor="dl-date">Date de livraison</label>
          <input id="dl-date" type="date" className={`${input} tw:font-tech`} value={f.movement_date} max={todayISO()} onChange={(e) => setF({ ...f, movement_date: e.target.value })} required />
          <FieldError text={errors.movement_date} />
        </div>
        <div className="tw:sm:col-span-4">
          <label className={label} htmlFor="dl-ref">N° du bon de livraison</label>
          <input id="dl-ref" className={`${input} tw:font-tech`} value={f.reference} onChange={(e) => setF({ ...f, reference: e.target.value })} maxLength={100} placeholder="ex. BL-2026-041" />
        </div>
      </div>

      <div className="tw:overflow-x-auto tw:rounded-lg tw:border tw:border-prise-border tw:bg-white">
        <table className="tw:w-full tw:border-collapse">
          <thead><tr><th className={th} scope="col">Article livré</th><th className={`${th} tw:w-36 tw:text-right`} scope="col">Quantité</th><th className={`${th} tw:w-32 tw:text-right`} scope="col">PT (USD)</th><th className={`${th} tw:w-12`} scope="col"><span className="tw:sr-only">Retirer</span></th></tr></thead>
          <tbody>
            {lines.map((l, i) => {
              const a = articles.find((x) => String(x.id) === l.stock_article_id);
              const n = Number(String(l.quantity).replace(',', '.'));
              return (
                <tr key={l.key}>
                  <td className={td}>
                    <select aria-label={`Article de la ligne ${i + 1}`} className={input} value={l.stock_article_id} onChange={(e) => setLine(i, 'stock_article_id', e.target.value)} required>
                      <option value="">Choisir l’article…</option>
                      {articles.map((x) => <option key={x.id} value={x.id} disabled={used.has(String(x.id)) && String(x.id) !== l.stock_article_id}>{cardNo(x.number)} — {x.designation} ({x.unit})</option>)}
                    </select>
                    <FieldError text={errors[`lines.${i}.stock_article_id`]} />
                  </td>
                  <td className={td}>
                    <input aria-label={`Quantité de la ligne ${i + 1}`} className={`${input} tw:font-tech tw:text-right`} inputMode="decimal" value={l.quantity} onChange={(e) => setLine(i, 'quantity', e.target.value)} required placeholder={a ? a.unit : ''} />
                    <FieldError text={errors[`lines.${i}.quantity`]} />
                  </td>
                  <td className={tdNum}>{a && n > 0 ? money(a.unitPrice * n) : '—'}</td>
                  <td className={td}>
                    {lines.length > 1 && <button type="button" className={btnDanger} onClick={() => setLines(lines.filter((_, j) => j !== i))} aria-label={`Retirer la ligne ${i + 1}`}><i className="bi bi-x-lg" aria-hidden="true" /></button>}
                  </td>
                </tr>
              );
            })}
          </tbody>
          <tfoot>
            <tr>
              <td className={td}><button type="button" className={btnGhost} onClick={() => setLines([...lines, { key: Date.now(), stock_article_id: '', quantity: '' }])}><i className="bi bi-plus-lg" aria-hidden="true" />Ajouter un article</button></td>
              <td className={`${td} tw:text-right tw:text-xs tw:font-semibold tw:text-prise-muted`}>Total livré</td>
              <td className={`${tdNum} tw:font-bold`}>{money(total)}</td>
              <td className={td} />
            </tr>
          </tfoot>
        </table>
      </div>
      <FieldError text={errors.lines} />
      <div className="tw:flex tw:flex-wrap tw:items-end tw:gap-3">
        <div className="tw:min-w-60 tw:flex-1">
          <label className={label} htmlFor="dl-obs">Observation (facultatif)</label>
          <input id="dl-obs" className={input} value={f.observation} onChange={(e) => setF({ ...f, observation: e.target.value })} maxLength={500} />
        </div>
        <button type="button" className={btnGhost} onClick={onCancel}>Annuler</button>
        <button type="submit" className={btnPrimary} disabled={busy}>Enregistrer la livraison</button>
      </div>
    </form>
  );
}

/**
 * Stock par contrat : import du fichier des articles d'un contrat de fournitures (ou de la liste des prix unitaires
 * évalués), avec aperçu avant validation. Les nouveaux articles vont à l'unité du compte (catalogue commun pour la
 * coordination) ; un article du catalogue commun déjà connu est utilisé tel quel.
 */
function ImportPanel({ ownUnitName, canStock, contracts, owner, onDone, onCancel }) {
  const [contractId, setContractId] = useState('');
  const [file, setFile] = useState(null);
  const [initial, setInitial] = useState(false);
  const [date, setDate] = useState(todayISO());
  const [preview, setPreview] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const send = async (dry) => {
    const fd = new FormData();
    fd.append('file', file);
    if (contractId) fd.append('contract_id', contractId);
    if (dry) fd.append('dry_run', '1');
    if (initial) { fd.append('initial_stock', '1'); fd.append('movement_date', date); }
    return api.post('/stock/import', fd);
  };
  const analyse = async (f) => {
    setFile(f); setPreview(null); setError('');
    if (!f) return;
    setBusy(true);
    try {
      const fd = new FormData(); fd.append('file', f); fd.append('dry_run', '1');
      if (contractId) fd.append('contract_id', contractId);
      setPreview((await api.post('/stock/import', fd)).data);
    } catch (e) { setError(fieldErrors(e).file || apiError(e)); } finally { setBusy(false); }
  };
  const confirm = async () => {
    setBusy(true); setError('');
    try { onDone((await send(false)).data.message); } catch (e) { setError(Object.values(fieldErrors(e))[0] || apiError(e)); } finally { setBusy(false); }
  };
  const STATUS = {
    NEW: ['Nouveau', 'tw:bg-prise-green-soft tw:text-prise-green-2'], UPDATE: ['Mise à jour', 'tw:bg-sky-50 tw:text-sky-800'],
    KEEP: ['Catalogue commun', 'tw:bg-prise-bg tw:text-prise-muted'], ERROR: ['Erreur', 'tw:bg-red-50 tw:text-prise-danger'],
  };

  return (
    <div className="tw:grid tw:gap-4 tw:border-b tw:border-prise-border tw:bg-prise-bg/60 tw:px-5 tw:py-4">
      <div className="tw:flex tw:flex-wrap tw:items-baseline tw:justify-between tw:gap-2">
        <h3 className="tw:m-0 tw:font-display tw:text-[15px] tw:font-bold tw:text-prise-blue">Importer les articles d’un contrat</h3>
        <button type="button" className={`${btn} tw:bg-transparent tw:px-0 tw:text-prise-green-2 tw:hover:underline`} onClick={() => download('/stock/import/template', {}, 'modele_prix_unitaires_evalues.xlsx')}>
          <i className="bi bi-file-earmark-arrow-down" aria-hidden="true" />Télécharger le modèle Excel
        </button>
      </div>
      <p className="tw:m-0 tw:text-sm tw:text-prise-muted">
        Fichier Excel (.xlsx) ou CSV avec les colonnes N°, Article, Unité, Quantité, PU $. Les nouveaux articles vont à <strong className="tw:text-prise-text">{owner}</strong>.
        Un article que vous tenez déjà (même désignation) voit son unité et son prix mis à jour ; un article du catalogue commun est utilisé tel quel.
      </p>
      <div className="tw:sm:w-96">
        <label className={label} htmlFor="imp-contract">Contrat de fournitures</label>
        <select id="imp-contract" className={input} value={contractId} onChange={(e) => { setContractId(e.target.value); setPreview(null); setFile(null); }}>
          <option value="">— Sans contrat (liste des prix unitaires évalués) —</option>
          {contracts.map((c) => <option key={c.id} value={c.id}>{c.number}{c.company ? ` — ${c.company}` : ''}</option>)}
        </select>
      </div>
      <label className="tw:flex tw:cursor-pointer tw:items-center tw:justify-center tw:gap-3 tw:rounded-lg tw:border-2 tw:border-dashed tw:border-prise-border tw:bg-white tw:px-4 tw:py-5 tw:text-sm tw:text-prise-text tw:transition-colors tw:hover:border-prise-green-2">
        <i className="bi bi-cloud-arrow-up tw:text-xl tw:text-prise-green-2" aria-hidden="true" />
        <span>{file ? <strong>{file.name}</strong> : 'Choisir un fichier sur cet appareil'}</span>
        <input type="file" accept=".xlsx,.csv" className="tw:sr-only" onChange={(e) => analyse(e.target.files?.[0] || null)} />
      </label>
      {busy && !preview && <p className="tw:m-0 tw:text-sm tw:text-prise-muted" role="status">Lecture du fichier…</p>}
      {error && <p className="tw:m-0 tw:text-sm tw:text-prise-danger" role="alert">{error}</p>}
      {preview && (
        <>
          <p className="tw:m-0 tw:text-sm" role="status">
            <strong>{preview.counts.new}</strong> nouveau(x), <strong>{preview.counts.update}</strong> mise(s) à jour{preview.counts.keep > 0 && <>, <strong>{preview.counts.keep}</strong> déjà au catalogue commun</>}

            {preview.counts.error > 0 && <>, <strong className="tw:text-prise-danger">{preview.counts.error} en erreur</strong> : corrigez le fichier puis rechargez-le</>}.
          </p>
          <div className="tw:max-h-80 tw:overflow-auto tw:rounded-lg tw:border tw:border-prise-border tw:bg-white">
            <table className="tw:w-full tw:border-collapse">
              <thead className="tw:sticky tw:top-0"><tr>{['Ligne', 'N°', 'Article', 'Unité', 'Quantité', 'PU $', 'PT $', ''].map((h, i) => <th key={i} className={`${th} ${i >= 4 && i <= 6 ? 'tw:text-right' : ''}`} scope="col">{h}</th>)}</tr></thead>
              <tbody>
                {preview.rows.map((r) => (
                  <tr key={r.line}>
                    <td className={`${td} tw:font-tech tw:text-prise-muted`}>{r.line}</td>
                    <td className={`${td} tw:font-tech`}>{r.number ? cardNo(r.number) : 'auto'}</td>
                    <td className={td}>{r.designation}{r.errors.length > 0 && <span className="tw:block tw:text-xs tw:text-prise-danger">{r.errors.join(' · ')}</span>}</td>
                    <td className={td}>{r.unit}</td>
                    <td className={tdNum}>{r.quantity === null ? '—' : qty(r.quantity)}</td>
                    <td className={tdNum}>{r.unitPrice === null ? '—' : money(r.unitPrice)}</td>
                    <td className={tdNum}>{r.total === null ? '—' : money(r.total)}</td>
                    <td className={td}><span className={`tw:rounded-full tw:px-2 tw:py-0.5 tw:text-[11px] tw:font-bold ${STATUS[r.status][1]}`}>{STATUS[r.status][0]}</span></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {canStock && (
            <div className="tw:flex tw:flex-wrap tw:items-center tw:gap-3 tw:text-sm">
              <label className="tw:flex tw:cursor-pointer tw:items-center tw:gap-2">
                <input type="checkbox" className="tw:size-4 tw:accent-prise-green-2" checked={initial} onChange={(e) => setInitial(e.target.checked)} />
                Enregistrer les quantités comme stock initial de <strong>{ownUnitName}</strong>
              </label>
              {initial && <input type="date" aria-label="Date du stock initial" className={`${input} tw:w-auto tw:font-tech`} value={date} max={todayISO()} onChange={(e) => setDate(e.target.value)} />}
            </div>
          )}
        </>
      )}
      <div className="tw:flex tw:justify-end tw:gap-2">
        <button type="button" className={btnGhost} onClick={onCancel}>Annuler</button>
        <button type="button" className={btnPrimary} disabled={busy || !preview || preview.counts.error > 0} onClick={confirm}>Importer {preview ? preview.rows.length : ''} article(s)</button>
      </div>
    </div>
  );
}

export default function StockPage({ view }) {
  const [params, setParams] = useSearchParams();
  const unitParam = params.get('unit') || '';
  const { data, loading, error, reload } = useLoad('/stock', unitParam ? { unit_id: unitParam } : {});
  const [search, setSearch] = useState('');
  const [onlyLow, setOnlyLow] = useState(false);
  const [flash, setFlash] = useState({ type: 'success', text: '' });
  const [editing, setEditing] = useState(null); // id d'article | 'new' | 'delivery' | 'import'
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);

  const articles = useMemo(() => {
    const q = search.trim().toLowerCase();
    return (data?.articles || []).filter((a) => !q || a.designation.toLowerCase().includes(q) || cardNo(a.number).includes(q));
  }, [data, search]);

  if (loading && !data) return <Loading />;
  if (!data) return <Notice>{error}</Notice>;

  const setUnit = (v) => { const next = new URLSearchParams(params); if (v) next.set('unit', v); else next.delete('unit'); setParams(next, { replace: true }); };
  const unit = data.units.find((u) => u.id === data.unitId);
  const own = data.units.find((u) => u.id === data.ownUnitId);
  const cardLink = (a) => `/stock/articles/${a.id}${data.unitId ? `?unit=${data.unitId}` : ''}`;
  const done = async (text) => { setFlash({ type: 'success', text }); setEditing(null); await reload(); };

  const call = async (fn) => {
    setBusy(true); setErrors({});
    try { const r = await fn(); await done(r.data.message); } catch (e) {
      const fe = fieldErrors(e); setErrors(fe);
      if (!Object.keys(fe).length) setFlash({ type: 'danger', text: apiError(e) });
    } finally { setBusy(false); }
  };
  const saveArticle = (f) => call(() => (f.id ? api.put(`/stock/articles/${f.id}`, f) : api.post('/stock/articles', f)));
  const openForm = (id) => { setErrors({}); setEditing(id); };
  const toInput = (v) => (v === null || v === undefined ? '' : String(v).replace('.', ','));

  const stockRows = articles.filter((a) => (a.isActive || a.stock !== 0) && (!onlyLow || a.level === 'LOW' || a.level === 'OUT'));
  const lowCount = data.totals.low;
  const tabs = STOCK.tabs.map((t) => (t.to === '/stock' ? { ...t, badge: lowCount } : t));
  const unitPicker = data.canChooseUnit && (
    <select aria-label="Unité consultée" className={`${input} tw:w-64`} value={data.unitId || ''} onChange={(e) => setUnit(e.target.value)}>
      <option value="">Toutes les unités (vue nationale)</option>
      {data.units.map((u) => <option key={u.id} value={u.id}>{u.name}{u.isActive ? '' : ' (désactivée)'}</option>)}
    </select>
  );
  const searchBox = (
    <div className="tw:relative tw:w-full tw:sm:w-72">
      <i className="bi bi-search tw:pointer-events-none tw:absolute tw:top-1/2 tw:left-3 tw:-translate-y-1/2 tw:text-prise-muted" aria-hidden="true" />
      <input type="search" aria-label="Rechercher un article" className={`${input} tw:pl-9`} value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Article ou N° de fiche" />
    </div>
  );

  return (
    <>
      <ModuleHeader icon={STOCK.icon} title={STOCK.title} tabs={tabs} unit={unit ? unit.name : 'Toutes les unités'}
        desc={view === 'catalogue' ? 'Prix unitaires évalués : le catalogue commun tenu par la coordination nationale et les articles propres à chaque antenne. Deux façons d’ajouter des articles : par contrat (import du fichier des articles du contrat) ou sans contrat, un par un. Montants en USD.' : 'Fournitures livrées par l’entreprise titulaire et sorties sur demande. Montants en USD.'}
        actions={unitPicker} />
      <Notice type={flash.type} onClose={() => setFlash({ ...flash, text: '' })}>{flash.text}</Notice>
      {view !== 'catalogue' && own && unit && unit.id !== own.id && <ReadOnlyNote unitName={unit.name} />}

      {view !== 'catalogue' ? (
        <section className={panel}>
          <header className="tw:flex tw:flex-wrap tw:items-center tw:gap-x-6 tw:gap-y-3 tw:border-b tw:border-prise-border tw:px-5 tw:py-4">
            {searchBox}
            <dl className="tw:m-0 tw:flex tw:flex-wrap tw:gap-x-6 tw:gap-y-1 tw:text-sm">
              <div className="tw:flex tw:gap-1.5"><dt className="tw:text-prise-muted">Valeur</dt><dd className="tw:m-0 tw:font-tech tw:font-semibold tw:tabular-nums">{money(data.totals.value)} USD</dd></div>
              <div className="tw:flex tw:gap-1.5"><dt className="tw:text-prise-muted">Articles</dt><dd className="tw:m-0 tw:font-tech tw:font-semibold tw:tabular-nums">{data.totals.articles}</dd></div>
              <div className="tw:flex tw:gap-1.5"><dt className="tw:text-prise-muted">Sous le minimum</dt><dd className={`tw:m-0 tw:font-tech tw:font-semibold tw:tabular-nums ${lowCount ? 'tw:text-prise-danger' : ''}`}>{lowCount}</dd></div>
            </dl>
            <div className="tw:ml-auto tw:flex tw:flex-wrap tw:items-center tw:gap-3">
              <label className="tw:flex tw:cursor-pointer tw:items-center tw:gap-2 tw:text-sm">
                <input type="checkbox" className="tw:size-4 tw:accent-prise-green-2" checked={onlyLow} onChange={(e) => setOnlyLow(e.target.checked)} />
                À réapprovisionner
              </label>
              {own && (!unit || unit.id === own.id) && editing !== 'delivery' && (
                <button type="button" className={btnPrimary} onClick={() => setEditing('delivery')}><i className="bi bi-truck" aria-hidden="true" />Réceptionner une livraison</button>
              )}
            </div>
          </header>
          {editing === 'delivery' && <DeliveryForm data={data} unitName={own?.name} onDone={done} onCancel={() => setEditing(null)} />}
          <div className="tw:overflow-x-auto">
            <table className="tw:w-full tw:border-collapse">
              <thead>
                <tr>
                  <th className={th} scope="col">N°</th>
                  <th className={th} scope="col">Article</th>
                  <th className={th} scope="col">Unité</th>
                  <th className={`${th} tw:text-right`} scope="col">Quantité</th>
                  <th className={`${th} tw:text-right`} scope="col">PU ($)</th>
                  <th className={`${th} tw:text-right`} scope="col">PT ($)</th>
                  <th className={th} scope="col">Dernier mouvement</th>
                  <th className={th} scope="col"><span className="tw:sr-only">État</span></th>
                </tr>
              </thead>
              <tbody>
                {stockRows.map((a) => (
                  <tr key={a.id} className="tw:hover:bg-prise-green-soft/50">
                    <td className={`${td} tw:font-tech tw:text-prise-muted tw:tabular-nums`}>{cardNo(a.number)}</td>
                    <td className={td}><AlertBlink level={stockAlertLevel(a.level)}><Link to={cardLink(a)} className="tw:font-semibold tw:text-prise-green-2 tw:no-underline tw:hover:underline">{a.designation}</Link></AlertBlink></td>
                    <td className={`${td} tw:whitespace-nowrap`}>{a.unit}</td>
                    <td className={`${tdNum} tw:font-semibold`}>
                      {qty(a.stock)}
                      {a.packSize && a.stock > 0 && <span className="tw:block tw:text-[11px] tw:font-normal tw:text-prise-muted">{packLabel(a.stock, a)}</span>}
                    </td>
                    <td className={tdNum}>{money(a.unitPrice)}</td>
                    <td className={tdNum}>{money(a.value)}</td>
                    <td className={`${td} tw:whitespace-nowrap tw:text-prise-muted`}>{a.lastMovementDate ? fr(a.lastMovementDate) : '—'}</td>
                    <td className={td}><LevelPill level={a.level} /></td>
                  </tr>
                ))}
              </tbody>
              {stockRows.length > 0 && (
                <tfoot><tr className="tw:bg-prise-bg/60"><th scope="row" colSpan={5} className={`${td} tw:text-right tw:text-xs tw:font-semibold tw:text-prise-muted`}>Total</th><td className={`${tdNum} tw:font-bold`}>{money(stockRows.reduce((s, a) => s + a.value, 0))}</td><td colSpan={2} className={td} /></tr></tfoot>
              )}
            </table>
          </div>
          {!stockRows.length && (
            <p className="tw:m-0 tw:px-5 tw:py-8 tw:text-center tw:text-sm tw:text-prise-muted">
              {data.articles.length ? 'Aucun article ne correspond à ces critères.' : 'Le catalogue est vide : ajoutez ou importez les articles dans « Catalogue & prix unitaires ».'}
            </p>
          )}
        </section>
      ) : (
        <section className={`${panel} tw:print:border-0 tw:print:shadow-none`}>
          <header className="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-3 tw:border-b tw:border-prise-border tw:px-5 tw:py-4 tw:print:hidden">
            {searchBox}
            <div className="tw:flex tw:flex-wrap tw:gap-2">
              <button type="button" className={btnGhost} onClick={() => window.print()}><i className="bi bi-printer" aria-hidden="true" />Imprimer</button>
              {data.canManageCatalog && editing !== 'import' && <button type="button" className={btnGhost} onClick={() => setEditing('import')} title="Par contrat : importer le fichier des articles du contrat"><i className="bi bi-upload" aria-hidden="true" />Importer les articles d’un contrat</button>}
              {data.canManageCatalog && editing !== 'new' && <button type="button" className={btnPrimary} onClick={() => openForm('new')} title="Sans contrat : un article à la fois"><i className="bi bi-plus-lg" aria-hidden="true" />Ajouter un article</button>}
            </div>
          </header>
          <PrintLetterhead title="Prix unitaires évalués" />
          {data.canManageCatalog && data.catalogOwner && <p className="tw:m-0 tw:border-b tw:border-prise-border tw:px-5 tw:py-2 tw:text-xs tw:text-prise-muted tw:print:hidden">Les articles que vous ajoutez vont à <strong className="tw:text-prise-text">{data.catalogOwner}</strong>.</p>}
          {editing === 'import' && <ImportPanel ownUnitName={own?.name} canStock={!!own} contracts={data.contracts || []} owner={data.catalogOwner} onDone={done} onCancel={() => setEditing(null)} />}
          {editing === 'new' && <ArticleForm initial={{ number: '', designation: '', unit: '', pack_size: '', pack_unit: '', unit_price: '', alert_threshold: '' }} onSubmit={saveArticle} onCancel={() => setEditing(null)} busy={busy} errors={errors} />}
          <div className="tw:overflow-x-auto">
            <table className="tw:w-full tw:border-collapse">
              <thead>
                <tr>
                  <th className={th} scope="col">N°</th>
                  <th className={th} scope="col">Article</th>
                  <th className={th} scope="col">Unité</th>
                  <th className={th} scope="col">Colisage</th>
                  <th className={`${th} tw:text-right`} scope="col">PU ($)</th>
                  <th className={`${th} tw:text-right`} scope="col">Niveau minimum</th>
                  <th className={th} scope="col">Origine</th>
                  {data.canManageCatalog && <th className={`${th} tw:print:hidden`} scope="col"><span className="tw:sr-only">Actions</span></th>}
                </tr>
              </thead>
              <tbody>
                {articles.map((a) => (editing === a.id ? (
                  <tr key={a.id}><td colSpan={8} className="tw:p-0">
                    <ArticleForm initial={{ id: a.id, number: String(a.number), designation: a.designation, unit: a.unit, pack_size: toInput(a.packSize), pack_unit: a.packUnit || '', unit_price: toInput(a.unitPrice), alert_threshold: toInput(a.alertThreshold) }}
                      onSubmit={saveArticle} onCancel={() => setEditing(null)} busy={busy} errors={errors} />
                  </td></tr>
                ) : (
                  <tr key={a.id} className={a.isActive ? 'tw:hover:bg-prise-green-soft/50' : 'tw:text-prise-muted tw:print:hidden'}>
                    <td className={`${td} tw:font-tech tw:text-prise-muted tw:tabular-nums`}>{cardNo(a.number)}</td>
                    <td className={td}><AlertBlink level={a.isActive ? stockAlertLevel(a.level) : null}>{a.designation}</AlertBlink>{!a.isActive && <span className="tw:ml-2 tw:rounded-full tw:bg-slate-100 tw:px-2 tw:py-0.5 tw:text-[11px] tw:font-bold tw:text-slate-600">Désactivé</span>}</td>
                    <td className={`${td} tw:whitespace-nowrap`}>{a.unit}</td>
                    <td className={`${td} tw:whitespace-nowrap tw:text-prise-muted`}>{a.packSize ? `1 ${a.unit} = ${qty(a.packSize)} ${a.packUnit}` : '—'}</td>
                    <td className={tdNum}>{money(a.unitPrice)}</td>
                    <td className={tdNum}>{a.alertThreshold === null ? '—' : qty(a.alertThreshold)}</td>
                    <td className={`${td} tw:text-xs`}>
                      <span className="tw:block tw:font-semibold tw:text-prise-text">{a.ownerUnit || 'Catalogue commun'}</span>
                      <span className="tw:block tw:text-prise-muted">{a.contractNumber ? `Contrat ${a.contractNumber}` : 'Sans contrat'}</span>
                    </td>
                    {data.canManageCatalog && (
                      <td className={`${td} tw:print:hidden`}>
                        {a.canEdit
                          ? <CatalogActions a={a} busy={busy} onEdit={() => openForm(a.id)} onToggle={() => call(() => api.put(`/stock/articles/${a.id}/toggle`))} onDelete={() => call(() => api.delete(`/stock/articles/${a.id}`))} />
                          : <span className="tw:text-[11px] tw:text-prise-muted" title="Tenu par la coordination nationale ou par une autre unité">Lecture seule</span>}
                      </td>
                    )}
                  </tr>
                )))}
              </tbody>
            </table>
          </div>
          {!articles.length && editing !== 'new' && (
            <p className="tw:m-0 tw:px-5 tw:py-8 tw:text-center tw:text-sm tw:text-prise-muted">
              {data.articles.length ? 'Aucun article ne correspond à cette recherche.' : data.canManageCatalog ? 'Catalogue vide : importez les articles d’un contrat ou ajoutez-les un à un, sans contrat.' : 'Aucun article pour le moment.'}

            </p>
          )}
        </section>
      )}
    </>
  );
}

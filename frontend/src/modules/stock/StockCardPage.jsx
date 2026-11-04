import React, { useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { api, apiError, fieldErrors } from '../../api.js';
import { useLoad } from '../../lib/hooks.js';
import { fr, frDateTime, todayISO } from '../../lib/format.js';
import { Loading, Notice, ReasonDialog } from '../../components/ui.jsx';
import { btn, btnGhost, btnPrimary, FieldError, input, label, panel } from '../shared/tw.jsx';
import { td, tdNum, th } from './stockUi.jsx';
import { cardNo, packLabel, qty } from './stockFormat.js';
import { ModuleHeader } from '../shared/ModuleHeader.jsx';
import { ReadOnlyNote } from '../shared/ReadOnlyNote.jsx';
import { STOCK } from '../shared/logisticsNav.js';
import { PrintDocument } from '../shared/PrintDocument.jsx';
import { PrintLetterhead } from '../shared/PrintLetterhead.jsx';

/*
 * Fiche de stock d'un article dans une unité, reprise du modèle papier du projet :
 * N°, DATE, ORIGINE OU DESTINATION, ENTRÉE, SORTIE, STOCK, OBSERVATION, puis visas logistique / comptabilité / coordination.
 * Une ligne n'est jamais modifiée : elle est annulée avec un motif et reste visible, barrée.
 */

function MovementForm({ type, setType, article, unitName, available, origins, positions, companies, onSubmit, onCancel, busy, errors }) {
  const [f, setF] = useState({ movement_date: todayISO(), quantity: '', origin_destination: type === 'ENTREE' ? 'Approvisionnement' : '', position_id: '', company_id: '', reference: '', observation: '' });
  // Une entrée livrée par l'entreprise titulaire : l'origine devient « Livraison <entreprise> ».
  const chooseCompany = (id) => {
    const c = companies.find((x) => String(x.id) === id);
    const auto = !f.origin_destination || f.origin_destination === 'Approvisionnement' || f.origin_destination.startsWith('Livraison ');
    setF({ ...f, company_id: id, origin_destination: auto ? (c ? `Livraison ${c.name}` : 'Approvisionnement') : f.origin_destination });
  };
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value });
  const isEntry = type === 'ENTREE';
  const choose = (t) => {
    setType(t);
    if (t === 'ENTREE' && !f.origin_destination) setF({ ...f, origin_destination: 'Approvisionnement' });
    if (t === 'SORTIE' && f.origin_destination === 'Approvisionnement') setF({ ...f, origin_destination: '' });
  };
  const seg = (t, text) => (
    <button
      type="button"
      aria-pressed={type === t}
      className={`${btn} tw:rounded-md ${type === t ? 'tw:bg-white tw:text-prise-green tw:shadow-sm' : 'tw:bg-transparent tw:text-prise-muted tw:hover:text-prise-text'}`}
      onClick={() => choose(t)}
    >
      {text}
    </button>
  );
  return (
    <form
      className="tw:grid tw:gap-3 tw:border-b tw:border-prise-border tw:bg-prise-bg/60 tw:px-5 tw:py-4 tw:sm:grid-cols-12 tw:print:hidden"
      onSubmit={(e) => { e.preventDefault(); onSubmit({ ...f, type }); }}
    >
      <div className="tw:flex tw:flex-wrap tw:items-center tw:gap-3 tw:sm:col-span-12">
        <div className="tw:inline-flex tw:gap-1 tw:rounded-lg tw:bg-prise-border/60 tw:p-1">{seg('ENTREE', 'Entrée')}{seg('SORTIE', 'Sortie')}</div>
        {!isEntry && (
          <span className="tw:text-sm tw:text-prise-muted">
            Disponible : <strong className="tw:font-tech tw:tabular-nums tw:text-prise-text">{qty(available)} {article.unit}</strong>
          </span>
        )}
      </div>
      <div className="tw:sm:col-span-3">
        <label className={label} htmlFor="mv-date">Date</label>
        <input id="mv-date" type="date" className={`${input} tw:font-tech`} value={f.movement_date} max={todayISO()} onChange={set('movement_date')} required />
        <FieldError text={errors.movement_date} />
      </div>
      <div className="tw:sm:col-span-2">
        <label className={label} htmlFor="mv-qty">Quantité ({article.unit})</label>
        <input id="mv-qty" className={`${input} tw:font-tech tw:text-right`} inputMode="decimal" autoComplete="off" value={f.quantity} onChange={set('quantity')} required autoFocus />
        <FieldError text={errors.quantity} />
      </div>
      <div className="tw:sm:col-span-7">
        {isEntry ? (
          <>
            <label className={label} htmlFor="mv-who">Origine</label>
            <input id="mv-who" className={input} value={f.origin_destination} onChange={set('origin_destination')} maxLength={190} list="mv-origins" placeholder="ex. Approvisionnement, transfert de la coordination" required />
            <datalist id="mv-origins">{origins.map((o) => <option key={o} value={o} />)}</datalist>
          </>
        ) : (
          <>
            <label className={label} htmlFor="mv-who">Destination (poste de l’unité)</label>
            <select id="mv-who" className={input} value={f.position_id} onChange={set('position_id')} required>
              <option value="">Choisir le poste…</option>
              {positions.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </select>
            {!positions.length && <p className="tw:m-0 tw:mt-1 tw:text-xs tw:text-amber-800">Aucun poste n’est enregistré pour {unitName} : ajoutez-les dans Administration › Organisation.</p>}
            <FieldError text={errors.position_id} />
          </>
        )}
        <FieldError text={errors.origin_destination} />
      </div>
      {isEntry && companies.length > 0 && (
        <>
          <div className="tw:sm:col-span-5">
            <label className={label} htmlFor="mv-company">Entreprise qui a livré (facultatif)</label>
            <select id="mv-company" className={input} value={f.company_id} onChange={(e) => chooseCompany(e.target.value)}>
              <option value="">—</option>
              {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
            <FieldError text={errors.company_id} />
          </div>
          <div className="tw:sm:col-span-3">
            <label className={label} htmlFor="mv-ref">N° bon de livraison</label>
            <input id="mv-ref" className={`${input} tw:font-tech`} value={f.reference} onChange={set('reference')} maxLength={100} />
          </div>
        </>
      )}
      <div className="tw:sm:col-span-9">
        <label className={label} htmlFor="mv-obs">Observation (facultatif)</label>
        <input id="mv-obs" className={input} value={f.observation} onChange={set('observation')} maxLength={500} />
      </div>
      <div className="tw:flex tw:items-end tw:justify-end tw:gap-2 tw:sm:col-span-3">
        <button type="button" className={btnGhost} onClick={onCancel}>Annuler</button>
        <button type="submit" className={btnPrimary} disabled={busy}>{isEntry ? 'Enregistrer l’entrée' : 'Enregistrer la sortie'}</button>
      </div>
    </form>
  );
}

/** N° du bon de livraison, sans répéter « BL » s'il figure déjà dans le numéro. */
const blLabel = (reference) => (/^BL/i.test(reference) ? reference : `BL ${reference}`);

/*
 * Impression de la fiche de stock, sur le modèle papier du projet (annexes) : entête, titre « FICHE DE STOCK N° 001 RAME PAPIER… »,
 * tableau N°, DATE, ORIGINE OU DESTINATION, ENTRÉE, SORTIE, STOCK, OBSERVATION, lignes vides jusqu'à 20, puis les visas.
 */
function StockCardPrint({ article, unit, period, lines }) {
  const used = lines.length + (period.from ? 1 : 0);
  const blanks = Array.from({ length: Math.max(0, 20 - used) }, (_, i) => used + i + 1);
  return (
    <PrintDocument>
      <PrintLetterhead always title={`Fiche de stock N° ${cardNo(article.number)} ${article.designation}`} subtitle={unit.name} />
      <table className="pdoc-table pdoc-card">
        <thead><tr><th>N°</th><th>Date</th><th>Origine ou destination</th><th>Entrée</th><th>Sortie</th><th>Stock</th><th>Observation</th></tr></thead>
        <tbody>
          {period.from && <tr><td /><td className="pdoc-left">{fr(period.from)}</td><td className="pdoc-left">Report du stock antérieur</td><td /><td /><td className="pdoc-strong">{qty(period.opening)}</td><td /></tr>}
          {lines.map((l, i) => (
            <tr key={l.id} className={l.isCancelled ? 'pdoc-rejected' : ''}>
              <td>{l.operationNo ?? i + 1}</td>
              <td className="pdoc-left pdoc-nowrap"><span className="pdoc-amount">{fr(l.date)}</span></td>
              <td className="pdoc-left"><span className="pdoc-amount">{l.originDestination}</span>{l.reference && <span className="pdoc-note">{blLabel(l.reference)}</span>}</td>
              <td><span className="pdoc-amount">{l.entry === null ? '' : qty(l.entry)}</span></td>
              <td><span className="pdoc-amount">{l.exit === null ? '' : qty(l.exit)}</span></td>
              <td className="pdoc-strong">{qty(l.stock)}</td>
              <td className="pdoc-left">{l.isCancelled ? `Annulé : ${l.cancelReason}` : l.observation}</td>
            </tr>
          ))}
          {blanks.map((n) => <tr key={`vide-${n}`}><td>{n}</td><td /><td /><td /><td /><td /><td /></tr>)}
        </tbody>
      </table>
      <div className="pdoc-visas"><div>Pour la logistique</div><div>Comptabilité</div><div>Coordonnateur national</div></div>
    </PrintDocument>
  );
}

export default function StockCardPage() {
  const { id } = useParams();
  const [params, setParams] = useSearchParams();
  const query = {};
  for (const [k, p] of [['unit_id', 'unit'], ['from', 'du'], ['to', 'au']]) if (params.get(p)) query[k] = params.get(p);
  const { data, loading, error, reload } = useLoad(`/stock/articles/${id}/card`, query);
  const [formType, setFormType] = useState(null); // 'ENTREE' | 'SORTIE' | null
  const [cancelling, setCancelling] = useState(null);
  const [flash, setFlash] = useState({ type: 'success', text: '' });
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);

  if (loading && !data) return <Loading />;
  if (!data) return <Notice>{error}</Notice>;

  const { article, unit, period } = data;
  const setParam = (k, v) => {
    const next = new URLSearchParams(params);
    if (v) next.set(k, v); else next.delete(k);
    setParams(next, { replace: true });
  };
  const backQuery = new URLSearchParams();
  if (params.get('unit')) backQuery.set('unit', params.get('unit'));
  if (params.get('contrat')) backQuery.set('contrat', params.get('contrat'));
  const back = `/stock${backQuery.toString() ? `?${backQuery}` : ''}`;


  const save = async (f) => {
    setBusy(true);
    setErrors({});
    try {
      const r = await api.post('/stock/movements', { ...f, stock_article_id: article.id, organizational_unit_id: unit.id });
      setFlash({ type: 'success', text: r.data.message });
      setFormType(null);
      await reload();
    } catch (e) {
      const fe = fieldErrors(e);
      setErrors(fe);
      if (!Object.keys(fe).length) setFlash({ type: 'danger', text: apiError(e) });
    } finally {
      setBusy(false);
    }
  };
  const cancel = async (reason) => {
    try {
      const r = await api.post(`/stock/movements/${cancelling.id}/cancel`, { reason });
      setCancelling(null);
      setFlash({ type: 'success', text: r.data.message });
      await reload();
    } catch (e) {
      throw new Error(apiError(e));
    }
  };
  const open = (t) => { setErrors({}); setFormType(t); };

  return (
    <>
      <ModuleHeader icon={STOCK.icon} title={STOCK.title} tabs={STOCK.tabs} unit={unit.name}
        desc="Fiche de stock : chaque approvisionnement et chaque retrait, avec le reste en stock après l’opération."
        actions={(
          <Link to={back} className="tw:inline-flex tw:items-center tw:gap-1.5 tw:text-sm tw:font-semibold tw:text-prise-green-2 tw:no-underline tw:hover:underline">
            <i className="bi bi-arrow-left" aria-hidden="true" />Retour à l’état du stock
          </Link>
        )} />

      <StockCardPrint article={article} unit={unit} period={period} lines={data.lines} />

      <Notice type={flash.type} onClose={() => setFlash({ ...flash, text: '' })}>{flash.text}</Notice>
      {data.otherUnit && <ReadOnlyNote unitName={unit.name} />}

      <section className={`${panel} tw:print:border-0 tw:print:shadow-none`}>
        <header className="tw:flex tw:flex-wrap tw:items-start tw:justify-between tw:gap-4 tw:border-b tw:border-prise-border tw:px-5 tw:py-4">
          <div className="tw:min-w-0">
            <p className="tw:m-0 tw:font-tech tw:text-sm tw:font-semibold tw:text-prise-muted tw:print:hidden">Fiche de stock N° {cardNo(article.number)}</p>
            <h1 className="tw:m-0 tw:mt-0.5 tw:font-display tw:text-[22px] tw:font-bold tw:text-prise-blue tw:print:hidden">{article.designation}</h1>
            <dl className="tw:m-0 tw:mt-2 tw:flex tw:flex-wrap tw:gap-x-6 tw:gap-y-1 tw:text-sm">
              <div className="tw:flex tw:gap-1.5"><dt className="tw:text-prise-muted">Unité de mesure</dt><dd className="tw:m-0 tw:font-semibold">{article.unit}</dd></div>
              <div className="tw:flex tw:gap-1.5"><dt className="tw:text-prise-muted">Stock</dt><dd className="tw:m-0 tw:font-tech tw:font-semibold tw:tabular-nums">{qty(data.stock)}{article.packSize ? ` ${packLabel(data.stock, article)}` : ''}</dd></div>
              {article.alertThreshold !== null && <div className="tw:flex tw:gap-1.5"><dt className="tw:text-prise-muted">Niveau minimum</dt><dd className={`tw:m-0 tw:font-tech tw:tabular-nums ${data.stock <= article.alertThreshold ? 'tw:font-semibold tw:text-prise-danger' : ''}`}>{qty(article.alertThreshold)}</dd></div>}
            </dl>
            {!article.isActive && <p className="tw:m-0 tw:mt-2 tw:text-sm tw:text-amber-800">Article désactivé dans le catalogue : aucun nouveau mouvement n’est possible.</p>}
          </div>
          <div className="tw:flex tw:flex-wrap tw:gap-2 tw:print:hidden">
            {data.canWrite && formType === null && (
              <>
                <button type="button" className={btnPrimary} onClick={() => open('ENTREE')}><i className="bi bi-box-arrow-in-down" aria-hidden="true" />Entrée</button>
                <button type="button" className={btnPrimary} onClick={() => open('SORTIE')} disabled={data.stock <= 0}><i className="bi bi-box-arrow-up" aria-hidden="true" />Sortie</button>
              </>
            )}
            <button type="button" className={btnGhost} onClick={() => window.print()}><i className="bi bi-printer" aria-hidden="true" />Imprimer la fiche</button>
          </div>
        </header>

        <div className="tw:flex tw:flex-wrap tw:items-end tw:gap-3 tw:border-b tw:border-prise-border tw:px-5 tw:py-3 tw:print:hidden">
          <div>
            <label className={label} htmlFor="card-from">Du</label>
            <input id="card-from" type="date" className={`${input} tw:font-tech`} value={params.get('du') || ''} onChange={(e) => setParam('du', e.target.value)} />
          </div>
          <div>
            <label className={label} htmlFor="card-to">Au</label>
            <input id="card-to" type="date" className={`${input} tw:font-tech`} value={params.get('au') || ''} onChange={(e) => setParam('au', e.target.value)} />
          </div>
          {(period.from || period.to) && (
            <button type="button" className={btnGhost} onClick={() => { const n = new URLSearchParams(params); n.delete('du'); n.delete('au'); setParams(n, { replace: true }); }}>
              Toute la fiche
            </button>
          )}
        </div>

        {formType && (
          <MovementForm
            type={formType}
            setType={setFormType}
            article={article}
            unitName={unit.name}
            available={data.stock}
            origins={data.origins}
            positions={data.positions}
            companies={data.companies}
            onSubmit={save}
            onCancel={() => setFormType(null)}
            busy={busy}
            errors={errors}
          />
        )}

        <div className="tw:overflow-x-auto">
          <table className="tw:w-full tw:border-collapse">
            <thead>
              <tr>
                <th className={`${th} tw:w-12`} scope="col" title="N° d’opération sur la fiche">N°</th>
                <th className={th} scope="col">Date</th>
                <th className={th} scope="col">Origine ou destination</th>
                <th className={`${th} tw:text-right`} scope="col">Entrée</th>
                <th className={`${th} tw:text-right`} scope="col">Sortie</th>
                <th className={`${th} tw:text-right`} scope="col">Stock</th>
                <th className={th} scope="col">Observation</th>
                {data.canWrite && <th className={`${th} tw:print:hidden`} scope="col"><span className="tw:sr-only">Actions</span></th>}
              </tr>
            </thead>
            <tbody>
              {period.from && (
                <tr className="tw:bg-prise-bg/60">
                  <td className={td} />
                  <td className={`${td} tw:font-tech tw:whitespace-nowrap`}>{fr(period.from)}</td>
                  <td className={`${td} tw:italic tw:text-prise-muted`}>Report du stock antérieur</td>
                  <td className={tdNum} />
                  <td className={tdNum} />
                  <td className={`${tdNum} tw:font-semibold`}>{qty(period.opening)}</td>
                  <td className={td} />
                  {data.canWrite && <td className={`${td} tw:print:hidden`} />}
                </tr>
              )}
              {data.lines.map((l, i) => (
                <tr key={l.id} className={l.isCancelled ? 'tw:text-prise-muted' : 'tw:hover:bg-prise-green-soft/50'}>
                  <td className={`${td} tw:font-tech tw:text-prise-muted tw:tabular-nums`}>{l.operationNo ?? i + 1}</td>
                  <td className={`${td} tw:font-tech tw:whitespace-nowrap ${l.isCancelled ? 'tw:line-through' : ''}`}>{fr(l.date)}</td>
                  <td className={`${td} ${l.isCancelled ? 'tw:line-through' : ''}`}>
                    {l.originDestination}
                    {l.reference && <span className="tw:block tw:font-tech tw:text-[11px] tw:text-prise-muted">{blLabel(l.reference)}</span>}
                  </td>
                  <td className={`${tdNum} ${l.isCancelled ? 'tw:line-through' : 'tw:text-prise-success'}`}>{l.entry === null ? '' : qty(l.entry)}</td>
                  <td className={`${tdNum} ${l.isCancelled ? 'tw:line-through' : ''}`}>{l.exit === null ? '' : qty(l.exit)}</td>
                  <td className={`${tdNum} tw:font-semibold`}>{qty(l.stock)}</td>
                  <td className={`${td} tw:min-w-56 tw:text-xs`}>
                    {l.isCancelled
                      ? <span title={`Annulé par ${l.cancelledBy || '—'} le ${frDateTime(l.cancelledAt)}`}><strong className="tw:text-prise-danger">Annulé</strong> : {l.cancelReason}</span>
                      : l.observation}
                    {l.createdBy && <span className="tw:block tw:text-[11px] tw:text-prise-muted tw:print:hidden">Saisi par {l.createdBy}</span>}
                  </td>
                  {data.canWrite && (
                    <td className={`${td} tw:text-right tw:print:hidden`}>
                      {!l.isCancelled && (
                        <button type="button" className={`${btn} tw:bg-transparent tw:text-xs tw:font-medium tw:text-prise-muted tw:hover:bg-red-50 tw:hover:text-prise-danger`} onClick={() => setCancelling(l)} aria-label={`Annuler la ligne ${i + 1}`}>Annuler</button>
                      )}
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {!data.lines.length && (
          <p className="tw:m-0 tw:px-5 tw:py-8 tw:text-center tw:text-sm tw:text-prise-muted">
            {period.from || period.to ? 'Aucun mouvement sur cette période.' : `Aucun mouvement pour cet article à ${unit.name}.${data.canWrite ? ' Commencez par enregistrer l’approvisionnement reçu.' : ''}`}
          </p>
        )}
      </section>

      {cancelling && (
        <ReasonDialog
          title="Annuler ce mouvement"
          text={`${cancelling.entry !== null ? 'Entrée' : 'Sortie'} de ${qty(cancelling.entry ?? cancelling.exit)} ${article.unit} du ${fr(cancelling.date)} (${cancelling.originDestination}). La ligne restera visible, barrée, et ne comptera plus dans le stock.`}
          confirmLabel="Annuler le mouvement"
          onConfirm={cancel}
          onClose={() => setCancelling(null)}
        />
      )}
    </>
  );
}

import React, { useState } from 'react';
import { api, apiError, download, fieldErrors } from '../../api.js';
import { useLoad } from '../../lib/hooks.js';
import { fr, money } from '../../lib/format.js';
import { Loading, Notice, ReasonDialog } from '../../components/ui.jsx';
import { HBarChart } from '../../components/Charts.jsx';
import { btnDanger, btnGhost, btnPrimary, FieldError, input, label, panel } from '../shared/tw.jsx';
import { ModuleHeader } from '../shared/ModuleHeader.jsx';
import { q } from '../fleet/fleetFormat.js';

/*
 * Rapports périodiques et historisation (TDR §11) : aperçu d'une période (trimestre, mois, année ou dates libres),
 * état figé conservé tel quel, comparaison de deux états (ex. T1 contre T2, ou un état contre la situation actuelle),
 * export Excel avec les colonnes choisies.
 */

const th = 'tw:border-b tw:border-prise-border tw:bg-prise-bg tw:px-3 tw:py-2.5 tw:text-left tw:font-tech tw:text-[11px] tw:font-semibold tw:uppercase tw:tracking-wide tw:text-prise-muted tw:whitespace-nowrap';
const td = 'tw:border-b tw:border-prise-border tw:px-3 tw:py-2.5 tw:text-sm tw:text-prise-text';
const tdNum = `${td} tw:text-right tw:font-tech tw:tabular-nums tw:whitespace-nowrap`;
const MONTHS = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];

function Delta({ v, suffix = ' pts' }) {
  if (v === null || v === undefined) return null;
  const tone = v > 0 ? 'tw:text-prise-success' : v < 0 ? 'tw:text-prise-danger' : 'tw:text-prise-muted';
  return <span className={`tw:font-tech tw:text-xs tw:font-semibold ${tone}`}>{v > 0 ? '+' : ''}{q(v)}{suffix}</span>;
}

function Summary({ s, fuel }) {
  const items = [
    ['Contrats', s.contracts], ['Exécution physique moyenne', `${q(s.progress)} %`], ['Taux de paiement global', `${q(s.paymentRate)} %`],
    ['Montant payé', `${money(s.paid)} USD`], ['Montant restant', `${money(s.remaining)} USD`],
    fuel && ['Carburant véhicules', `${q(fuel.vehicles.litresPurchased)} L · ${q(fuel.vehicles.distance)} km`],
    fuel && ['Carburant groupes', `${q(fuel.generators.litresPurchased)} L · ${q(fuel.generators.distance)} h`],
  ].filter(Boolean);
  return (
    <dl className="tw:m-0 tw:grid tw:grid-cols-2 tw:gap-x-6 tw:gap-y-2 tw:px-5 tw:py-4 tw:sm:grid-cols-4 tw:xl:grid-cols-7">
      {items.map(([k, v]) => <div key={k}><dt className="tw:text-[11px] tw:text-prise-muted">{k}</dt><dd className="tw:m-0 tw:font-tech tw:text-[15px] tw:font-semibold tw:tabular-nums">{v}</dd></div>)}
    </dl>
  );
}

function StateTable({ rows }) {
  return (
    <div className="tw:overflow-x-auto">
      <table className="tw:w-full tw:border-collapse">
        <thead><tr>{['N° contrat', 'Entreprise', 'Exécution physique', 'Délai consommé', 'Statut', 'Montant payé', 'Taux paiement', 'Restant', 'Observation'].map((h) => <th key={h} className={th} scope="col">{h}</th>)}</tr></thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.id}>
              <td className={`${td} tw:font-semibold tw:whitespace-nowrap`}>{r.number}</td>
              <td className={td}>{r.company}</td>
              <td className={tdNum}>{q(r.progress)} %</td>
              <td className={tdNum}>{q(r.elapsed)} %</td>
              <td className={`${td} tw:whitespace-nowrap`}>{r.status}</td>
              <td className={tdNum}>{money(r.paid)}</td>
              <td className={tdNum}>{q(r.paymentRate)} %</td>
              <td className={`${tdNum} ${r.remaining < 0 ? 'tw:text-prise-danger' : ''}`}>{money(r.remaining)}</td>
              <td className={`${td} tw:min-w-56 tw:text-xs tw:text-prise-muted`}>{r.observation}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

export default function ReportSnapshotsPage() {
  const { data, loading, error, reload } = useLoad('/report-snapshots');
  const [period, setPeriod] = useState(null);
  const [preview, setPreview] = useState(null);
  const [comment, setComment] = useState('');
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState({});
  const [flash, setFlash] = useState({ type: 'success', text: '' });
  const [viewing, setViewing] = useState(null);
  const [cmp, setCmp] = useState({ a: '', b: 'live', result: null });
  const [exportFor, setExportFor] = useState(null);
  const [columns, setColumns] = useState(null);
  const [deleting, setDeleting] = useState(null);

  if (loading && !data) return <Loading />;
  if (!data) return <Notice>{error}</Notice>;

  const p = period || { period_type: 'TRIMESTRE', year: data.defaults.year, index: data.defaults.quarter, from: '', to: '' };
  const cols = columns || data.columns.map((c) => c.key);
  const setP = (k) => (e) => setPeriod({ ...p, [k]: e.target.value, ...(k === 'period_type' ? { index: e.target.value === 'MOIS' ? data.defaults.month : 1 } : {}) });
  const run = async (fn, ok) => {
    setBusy(true); setErrors({});
    try { const r = await fn(); ok(r.data); } catch (e) {
      const fe = fieldErrors(e); setErrors(fe);
      if (!Object.keys(fe).length) setFlash({ type: 'danger', text: apiError(e) });
    } finally { setBusy(false); }
  };
  const showPreview = () => run(() => api.get('/report-snapshots/preview', { params: p }), (d) => { setPreview(d); setViewing(null); });
  const freeze = () => run(() => api.post('/report-snapshots', { ...p, comment }), async (d) => { setFlash({ type: 'success', text: d.message }); setComment(''); setPreview(null); await reload(); });
  const open = (s) => run(() => api.get(`/report-snapshots/${s.id}`), (d) => { setViewing(d); setPreview(null); });
  const compare = () => run(() => api.get('/report-snapshots/compare', { params: { a: cmp.a, b: cmp.b } }), (d) => setCmp({ ...cmp, result: d }));
  const exportXlsx = async () => {
    try { await download('/report-snapshots/export', { id: exportFor.id, columns: cols }, `etat_${exportFor.label}.xlsx`); setExportFor(null); } catch (e) { setFlash({ type: 'danger', text: apiError(e) }); }
  };
  const remove = async (reason) => {
    try { const r = await api.delete(`/report-snapshots/${deleting.id}`, { data: { reason } }); setDeleting(null); setFlash({ type: 'success', text: r.data.message }); await reload(); } catch (e) { throw new Error(apiError(e)); }
  };
  const shown = preview || viewing;
  const chartRows = shown ? shown.rows.slice(0, 25) : [];

  return (
    <>
      <ModuleHeader icon="clock-history" title="États figés & comparaisons"
        desc="Situation des contrats et du carburant sur une période, figée pour comparer d’un trimestre à l’autre : taux d’exécution physique, taux de paiement, consommation de carburant et observations."
        tabs={[{ to: '/reports', label: 'Rapport de suivi', icon: 'bar-chart-line', end: true }, { to: '/reports/snapshots', label: 'États figés & comparaisons', icon: 'clock-history' }]} />
      <Notice type={flash.type} onClose={() => setFlash({ ...flash, text: '' })}>{flash.text}</Notice>

      <section className={`${panel} tw:mb-5`}>
        <header className="tw:border-b tw:border-prise-border tw:px-5 tw:py-4">
          <h2 className="tw:m-0 tw:font-display tw:text-[17px] tw:font-bold tw:text-prise-blue">Préparer un état</h2>
        </header>
        <div className="tw:flex tw:flex-wrap tw:items-end tw:gap-3 tw:px-5 tw:py-4">
          <div>
            <label className={label} htmlFor="rp-type">Période</label>
            <select id="rp-type" className={input} value={p.period_type} onChange={setP('period_type')}>
              <option value="TRIMESTRE">Trimestre</option><option value="MOIS">Mois</option><option value="ANNEE">Année</option><option value="LIBRE">Dates libres</option>
            </select>
          </div>
          {p.period_type !== 'LIBRE' ? (
            <>
              {p.period_type !== 'ANNEE' && (
                <div>
                  <label className={label} htmlFor="rp-index">{p.period_type === 'MOIS' ? 'Mois' : 'Trimestre'}</label>
                  <select id="rp-index" className={input} value={p.index} onChange={setP('index')}>
                    {(p.period_type === 'MOIS' ? MONTHS : ['T1 (janv.–mars)', 'T2 (avr.–juin)', 'T3 (juil.–sept.)', 'T4 (oct.–déc.)']).map((m, i) => <option key={m} value={i + 1}>{m}</option>)}
                  </select>
                </div>
              )}
              <div>
                <label className={label} htmlFor="rp-year">Année</label>
                <input id="rp-year" type="number" min="2000" max="2100" className={`${input} tw:w-28 tw:font-tech`} value={p.year} onChange={setP('year')} />
              </div>
            </>
          ) : (
            <>
              <div><label className={label} htmlFor="rp-from">Du</label><input id="rp-from" type="date" className={`${input} tw:font-tech`} value={p.from} onChange={setP('from')} /><FieldError text={errors.from} /></div>
              <div><label className={label} htmlFor="rp-to">Au</label><input id="rp-to" type="date" className={`${input} tw:font-tech`} value={p.to} onChange={setP('to')} /><FieldError text={errors.to} /></div>
            </>
          )}
          <button type="button" className={btnPrimary} disabled={busy} onClick={showPreview}><i className="bi bi-eye" aria-hidden="true" />Aperçu</button>
          <FieldError text={errors.period_type} />
        </div>
      </section>

      {shown && (
        <section className={`${panel} tw:mb-5`}>
          <header className="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-3 tw:border-b tw:border-prise-border tw:px-5 tw:py-4">
            <div>
              <h2 className="tw:m-0 tw:font-display tw:text-[17px] tw:font-bold tw:text-prise-blue">{preview ? `Aperçu — ${shown.label}` : `État figé — ${shown.label}`}</h2>
              <p className="tw:m-0 tw:text-xs tw:text-prise-muted">Du {fr(shown.start)} au {fr(shown.end)} · situation au {fr(shown.asOf)}{viewing ? ` · figé le ${viewing.createdAt} par ${viewing.createdBy || '—'}` : ''}</p>
            </div>
            <button type="button" className={btnGhost} onClick={() => { setPreview(null); setViewing(null); }}>Fermer</button>
          </header>
          {viewing?.comment && <p className="tw:m-0 tw:border-b tw:border-prise-border tw:px-5 tw:py-3 tw:text-sm tw:italic">{viewing.comment}</p>}
          <Summary s={shown.summary} fuel={shown.fuel} />
          <div className="tw:border-t tw:border-prise-border tw:px-5 tw:py-4">
            <HBarChart labels={chartRows.map((r) => r.number)} datasets={[
              { label: 'Exécution physique', data: chartRows.map((r) => r.progress), color: '#087a3f' },
              { label: 'Délai consommé', data: chartRows.map((r) => Math.min(100, r.elapsed)), color: '#d98900' },
              { label: 'Taux de paiement', data: chartRows.map((r) => Math.min(100, r.paymentRate)), color: '#2f6db3' },
            ]} />
          </div>
          <StateTable rows={shown.rows} />
          {preview && data.canFreeze && (
            <div className="tw:flex tw:flex-wrap tw:items-end tw:gap-3 tw:border-t tw:border-prise-border tw:bg-prise-bg/60 tw:px-5 tw:py-4">
              <div className="tw:min-w-72 tw:flex-1">
                <label className={label} htmlFor="rp-comment">Commentaire de la période (facultatif)</label>
                <textarea id="rp-comment" rows="2" className={input} value={comment} onChange={(e) => setComment(e.target.value)} maxLength={2000} placeholder="Faits marquants, difficultés, perspectives…" />
              </div>
              <button type="button" className={btnPrimary} disabled={busy} onClick={freeze}><i className="bi bi-lock" aria-hidden="true" />Figer l’état « {preview.label} »</button>
            </div>
          )}
        </section>
      )}

      <div className="tw:grid tw:items-start tw:gap-5 tw:xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <section className={panel}>
          <header className="tw:border-b tw:border-prise-border tw:px-5 tw:py-4"><h2 className="tw:m-0 tw:font-display tw:text-[17px] tw:font-bold tw:text-prise-blue">États figés <span className="tw:font-tech tw:text-sm tw:font-medium tw:text-prise-muted">{data.snapshots.length}</span></h2></header>
          <div className="tw:overflow-x-auto">
            <table className="tw:w-full tw:border-collapse">
              <thead><tr>{['État', 'Situation au', 'Exécution moy.', 'Paiement', ''].map((h, i) => <th key={i} className={th} scope="col">{h}</th>)}</tr></thead>
              <tbody>
                {data.snapshots.map((s) => (
                  <tr key={s.id} className="tw:hover:bg-prise-green-soft/50">
                    <td className={td}><span className="tw:font-semibold">{s.label}</span><span className="tw:block tw:text-[11px] tw:text-prise-muted">figé le {s.createdAt}{s.createdBy ? ` par ${s.createdBy}` : ''}</span></td>
                    <td className={`${td} tw:font-tech`}>{fr(s.asOf)}</td>
                    <td className={tdNum}>{q(s.summary.progress)} %</td>
                    <td className={tdNum}>{q(s.summary.paymentRate)} %</td>
                    <td className={`${td} tw:whitespace-nowrap tw:text-right`}>
                      <button type="button" className={btnGhost} onClick={() => open(s)}>Voir</button>
                      <button type="button" className={btnGhost} onClick={() => setExportFor(s)}><i className="bi bi-file-earmark-excel" aria-hidden="true" />Exporter</button>
                      {data.canDelete && <button type="button" className={btnDanger} onClick={() => setDeleting(s)} aria-label={`Supprimer l’état ${s.label}`}><i className="bi bi-trash" aria-hidden="true" /></button>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {!data.snapshots.length && <p className="tw:m-0 tw:px-5 tw:py-6 tw:text-center tw:text-sm tw:text-prise-muted">Aucun état figé. Préparez l’état d’un trimestre puis figez-le pour pouvoir le comparer aux suivants.</p>}
          {exportFor && (
            <div className="tw:grid tw:gap-3 tw:border-t tw:border-prise-border tw:bg-prise-bg/60 tw:px-5 tw:py-4">
              <p className="tw:m-0 tw:text-sm tw:font-semibold">Colonnes à exporter — {exportFor.label}</p>
              <div className="tw:grid tw:gap-1.5 tw:sm:grid-cols-2">
                {data.columns.map((c) => (
                  <label key={c.key} className="tw:flex tw:cursor-pointer tw:items-center tw:gap-2 tw:text-sm">
                    <input type="checkbox" className="tw:size-4 tw:accent-prise-green-2" checked={cols.includes(c.key)} onChange={(e) => setColumns(e.target.checked ? [...cols, c.key] : cols.filter((k) => k !== c.key))} />{c.label}
                  </label>
                ))}
              </div>
              <div className="tw:flex tw:justify-end tw:gap-2">
                <button type="button" className={btnGhost} onClick={() => setExportFor(null)}>Annuler</button>
                <button type="button" className={btnPrimary} disabled={!cols.length} onClick={exportXlsx}>Télécharger ({cols.length} colonne(s))</button>
              </div>
            </div>
          )}
        </section>

        <section className={panel}>
          <header className="tw:border-b tw:border-prise-border tw:px-5 tw:py-4"><h2 className="tw:m-0 tw:font-display tw:text-[17px] tw:font-bold tw:text-prise-blue">Comparer deux états</h2></header>
          <div className="tw:grid tw:gap-3 tw:px-5 tw:py-4 tw:sm:grid-cols-2">
            <div>
              <label className={label} htmlFor="cmp-a">État de référence (A)</label>
              <select id="cmp-a" className={input} value={cmp.a} onChange={(e) => setCmp({ ...cmp, a: e.target.value, result: null })}>
                <option value="">Choisir…</option>
                {data.snapshots.map((s) => <option key={s.id} value={s.id}>{s.label}</option>)}
              </select>
            </div>
            <div>
              <label className={label} htmlFor="cmp-b">Comparé à (B)</label>
              <select id="cmp-b" className={input} value={cmp.b} onChange={(e) => setCmp({ ...cmp, b: e.target.value, result: null })}>
                <option value="live">Situation actuelle</option>
                {data.snapshots.filter((s) => String(s.id) !== cmp.a).map((s) => <option key={s.id} value={s.id}>{s.label}</option>)}
              </select>
            </div>
            <div className="tw:flex tw:justify-end tw:sm:col-span-2"><button type="button" className={btnPrimary} disabled={!cmp.a || busy} onClick={compare}>Comparer</button></div>
          </div>
        </section>
      </div>

      {cmp.result && (
        <section className={`${panel} tw:mt-5`}>
          <header className="tw:border-b tw:border-prise-border tw:px-5 tw:py-4">
            <h2 className="tw:m-0 tw:font-display tw:text-[17px] tw:font-bold tw:text-prise-blue">{cmp.result.a.label} → {cmp.result.b.label}</h2>
            <p className="tw:m-0 tw:text-xs tw:text-prise-muted">
              Exécution moyenne {q(cmp.result.a.summary.progress)} % → {q(cmp.result.b.summary.progress)} % <Delta v={Math.round((cmp.result.b.summary.progress - cmp.result.a.summary.progress) * 100) / 100} /> ·
              {' '}Paiement {q(cmp.result.a.summary.paymentRate)} % → {q(cmp.result.b.summary.paymentRate)} % <Delta v={Math.round((cmp.result.b.summary.paymentRate - cmp.result.a.summary.paymentRate) * 100) / 100} />
            </p>
          </header>
          <div className="tw:px-5 tw:py-4">
            <HBarChart labels={cmp.result.rows.slice(0, 25).map((r) => r.number)} datasets={[
              { label: `Exécution — ${cmp.result.a.label}`, data: cmp.result.rows.slice(0, 25).map((r) => r.progressA ?? 0), color: '#9bb8aa' },
              { label: `Exécution — ${cmp.result.b.label}`, data: cmp.result.rows.slice(0, 25).map((r) => r.progressB), color: '#087a3f' },
            ]} />
          </div>
          <div className="tw:overflow-x-auto">
            <table className="tw:w-full tw:border-collapse">
              <thead><tr>{['N° contrat', 'Entreprise', 'Exécution A', 'Exécution B', 'Écart', 'Paiement A', 'Paiement B', 'Écart', 'Payé : écart (USD)', 'Statut A → B'].map((h, i) => <th key={i} className={th} scope="col">{h}</th>)}</tr></thead>
              <tbody>
                {cmp.result.rows.map((r) => (
                  <tr key={r.id}>
                    <td className={`${td} tw:font-semibold tw:whitespace-nowrap`}>{r.number}{r.isNew && <span className="tw:ml-2 tw:text-[11px] tw:font-bold tw:text-prise-green-2">nouveau</span>}</td>
                    <td className={td}>{r.company}</td>
                    <td className={tdNum}>{r.progressA === null ? '—' : `${q(r.progressA)} %`}</td>
                    <td className={tdNum}>{q(r.progressB)} %</td>
                    <td className={tdNum}><Delta v={r.progressDelta} /></td>
                    <td className={tdNum}>{r.paymentRateA === null ? '—' : `${q(r.paymentRateA)} %`}</td>
                    <td className={tdNum}>{q(r.paymentRateB)} %</td>
                    <td className={tdNum}><Delta v={r.paymentRateDelta} /></td>
                    <td className={tdNum}><Delta v={r.paidDelta} suffix="" /></td>
                    <td className={`${td} tw:whitespace-nowrap`}>{r.statusA ?? '—'} → {r.statusB}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}

      {deleting && <ReasonDialog title={`Supprimer l’état ${deleting.label}`} text="Un état figé sert de référence pour les comparaisons. Sa suppression est définitive et tracée dans le journal d’audit." confirmLabel="Supprimer" onConfirm={remove} onClose={() => setDeleting(null)} />}
    </>
  );
}

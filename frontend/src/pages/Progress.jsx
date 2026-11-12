import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { api, apiError } from '../api.js';
import { useLoad } from '../lib/hooks.js';
import { fr, money, num, parseDecimal, todayISO } from '../lib/format.js';
import { DecimalInput, Empty, Field, Kpi, Loading, Modal, Notice, PageHead, Pager, Progress as Bar, ReasonDialog, StatusPill } from '../components/ui.jsx';
import { ProgressBreakdown } from '../components/ProgressBreakdown.jsx';
import { EvolutionChart, SiteChart } from '../components/Charts.jsx';
import { useAuth } from '../context/AuthContext.jsx';

const wrap = (fn) => async (...a) => { try { return await fn(...a); } catch (e) { throw new Error(apiError(e)); } };
const blank = () => ({ site_id: '', date: todayISO(), progress: '', installation_progress: '', realization_physical: '', observation: '', es_performance: null });

/*
 * Suivi & avancement (décision du 10/11/2026). Taux d'avancement physique =
 *   (taux d'installation de chantier et travaux préparatoires × coût d'installation ÷ montant du contrat)
 * + (taux d'exécution du site 1 × montant du site 1 ÷ montant du contrat) + (site 2) + …
 * L'installation concerne tout le contrat : son taux actuel est toujours affiché et, tant qu'il est sous 100 %, un champ
 * permet de l'alimenter à chaque situation. La saisie montre les montants et l'effet de la situation avant l'enregistrement.
 */

/** Effet d'un taux saisi : null sans saisie, { over } s'il dépasse le reste, sinon le nouveau cumul et le terme de la formule. */
function effect(x, typed, base) {
  if (!x || typed === null || !(typed > 0)) return null;
  if (typed > x.remaining + 1e-9) return { x, over: true };
  const after = Math.min(100, x.progress + typed);
  // Terme de la formule : (taux × montant ÷ montant du contrat), seulement quand les montants sont renseignés.
  const gain = base && x.amount !== null ? ((after - x.progress) * x.amount) / base : null;
  return { x, after, gain };
}

/** Repères du site choisi : montant prévu, taux déjà réalisé, reste. */
function Facts({ x }) {
  return (
    <dl>
      <div><dt>Montant prévu</dt><dd>{x.amount === null ? 'non renseigné' : `${money(x.amount)} USD`}</dd></div>
      <div><dt>Taux actuel</dt><dd>{num(x.progress, 2)} %</dd></div>
      <div><dt>Reste à réaliser</dt><dd>{num(x.remaining, 2)} %</dd></div>
    </dl>
  );
}

/** Après la situation : nouveau taux du site et de l'installation, et taux d'avancement physique du contrat. */
function SituationPreview({ site, effects, contractProgress, base }) {
  const done = effects.filter(Boolean);
  const over = done.find((e) => e.over);
  const computable = !!base && done.every((e) => e.gain !== null);
  const gain = done.reduce((a, e) => a + (e.gain || 0), 0);
  return (
    <div className={`progress-preview ${site && done.length ? '' : 'is-single'}`} aria-live="polite">
      {site && (
        <div className="progress-preview-facts">
          <div className="progress-preview-name">{site.label}</div>
          <Facts x={site} />
        </div>
      )}
      {done.length > 0 && <div className={`progress-preview-after ${over ? 'is-over' : ''}`}>
        {over ? (
          <p className="mb-0"><i className="bi bi-exclamation-triangle-fill me-2" aria-hidden="true" />Taux supérieur au reste à réaliser ({num(over.x.remaining, 2)} %).</p>
        ) : (
          <>
            <div className="progress-preview-label">Après cette situation</div>
            {done.map((e) => (
              <div key={e.x.key} className="progress-preview-line">
                <span>{e.x.label}</span>
                <span>{num(e.x.progress, 2)} % <i className="bi bi-arrow-right" aria-hidden="true" /> <strong>{num(e.after, 2)} %</strong></span>
              </div>
            ))}
            <div className="progress-preview-line is-total">
              <span>Avancement physique du contrat</span>
              {computable
                ? <span>{num(contractProgress, 2)} % <i className="bi bi-arrow-right" aria-hidden="true" /> <strong>{num(Math.min(100, contractProgress + gain), 2)} %</strong></span>
                : <span>—</span>}
            </div>
          </>
        )}
      </div>}
    </div>
  );
}

export default function ProgressPage() {
  const { can } = useAuth();
  const [contractId, setContractId] = useState('');
  const [page, setPage] = useState(1);
  const { data: d, loading, error, reload } = useLoad('/progress', { contract_id: contractId || undefined, page });
  const [form, setForm] = useState(blank());
  const [edit, setEdit] = useState(null);
  const [del, setDel] = useState(null);
  const [flash, setFlash] = useState({ type: 'success', text: '' });
  const [busy, setBusy] = useState(false);

  if (loading && !d) return <Loading />;
  if (!d) return <Notice>{error}</Notice>;
  const c = d.selected;
  const manage = can('canManageProgress');
  const editable = manage && c && !c.isTerminated;
  const weighted = !!c?.progressWeighted;
  // Sites numérotés comme dans la formule : site 1, site 2…
  const sites = c ? d.sites.map((s, i) => ({ key: String(s.id), label: `Site ${i + 1} — ${s.name}`, amount: s.amount ?? null, progress: s.progress, remaining: s.remaining })) : [];
  const site = sites.find((x) => x.key === form.site_id) || null;
  // Installation de chantier et travaux préparatoires : tout le contrat, taux cumulé (100 % au plus), saisie dans son propre champ.
  const inst = c && d.installation ? { key: 'INSTALLATION', label: 'Installation de chantier et travaux préparatoires', amount: d.installation.amount ?? null, progress: d.installation.progress, remaining: d.installation.remaining } : null;
  const instOpen = !!inst && inst.remaining > 0;
  const base = weighted ? d.breakdown?.base : null;
  const effects = [effect(site, parseDecimal(form.progress), base), instOpen ? effect(inst, parseDecimal(form.installation_progress), base) : null];
  const sitesTotal = d.sites.reduce((a, s) => a + (Number(s.amount) || 0), 0);
  const esValue = form.es_performance ?? (d.esPerformance || '');

  const say = (text, type = 'success') => setFlash({ type, text });
  const save = async (e) => {
    e.preventDefault();
    setBusy(true);
    try {
      const r = await api.post('/progress', { ...form, es_performance: esValue, installation_progress: instOpen ? form.installation_progress : '', contract_id: c.id });
      say(r.data.message);
      setForm({ ...blank(), site_id: form.site_id, date: form.date });
      await reload();
    } catch (err) { say(apiError(err), 'danger'); } finally { setBusy(false); }
  };
  const saveEdit = async () => {
    setBusy(true);
    try { const r = await api.put(`/progress/${edit.id}`, { date: edit.date, progress: edit.progress, realization_physical: edit.realization, observation: edit.observation, es_performance: edit.esPerformance || '' }); say(r.data.message); setEdit(null); await reload(); }
    catch (err) { say(apiError(err), 'danger'); } finally { setBusy(false); }
  };

  return (
    <>
      <PageHead kicker="SUIVI-ÉVALUATION" title="Suivi & Avancement">
        <select className="form-select" style={{ minWidth: 260 }} aria-label="Contrat" value={c?.id || ''} onChange={(e) => { setContractId(e.target.value); setPage(1); setForm(blank()); }}>
          {d.contracts.map((x) => <option key={x.id} value={x.id}>{x.number} — {x.company}{x.isTerminated ? ' (résilié)' : ''}</option>)}
        </select>
      </PageHead>
      <Notice type={flash.type} onClose={() => say('')}>{flash.text}</Notice>
      {!c ? <Empty text="Aucun contrat dans cette phase." /> : (
        <>
          {c.isTerminated && <div className="alert alert-warning">Ce contrat est résilié : aucune nouvelle situation ne peut être saisie.</div>}
          <div className="row g-3 mb-3">
            <div className="col-xl-3 col-md-6"><Kpi tone="green" label="AVANCEMENT PHYSIQUE" value={`${num(c.progress, 1)} %`} sub={`${weighted ? '' : 'Provisoire · '}${inst ? `Installation de chantier : ${num(inst.progress, 1)} % · ` : ''}${c.sitesCount} site(s)`} /></div>
            <div className="col-xl-3 col-md-6"><Kpi tone="orange" label="JOURS CONSOMMÉS" value={`${num(c.elapsed, 1)} %`} sub={`Fin prévue le ${fr(c.endDate)}`} /></div>
            <div className="col-xl-3 col-md-6"><Kpi label="TAUX DE PAIEMENT" value={`${num(c.paymentRate, 1)} %`} /></div>
            <div className="col-xl-3 col-md-6"><div className="cardx kpi"><div className="label">STATUT</div><div className="value" style={{ fontSize: 22 }}><StatusPill status={c.status} /></div></div></div>
          </div>

          {editable && (
            <form className="cardx form-card p-3 mb-3" onSubmit={save}>
              <div className="section-title mb-2">Nouvelle situation d’avancement</div>
              {/* Repères du contrat : ce sur quoi porte le calcul de l'avancement physique. */}
              <dl className="progress-context">
                <div><dt>Montant du contrat</dt><dd>{money(c.amount)} <span>USD</span></dd></div>
                {c.hasInstallation && <div><dt>Coût d’installation de chantier et travaux préparatoires</dt><dd>{c.installationCost === null || c.installationCost === undefined ? <span>non renseigné</span> : <>{money(c.installationCost)} <span>USD</span></>}</dd></div>}
                <div><dt>Montant des sites</dt><dd>{weighted ? <>{money(sitesTotal)} <span>USD</span></> : <span>non renseigné</span>}</dd></div>
                <div><dt>Taux d’avancement physique actuel</dt><dd>{num(c.progress, 2)} %{!weighted && <span> provisoire</span>}</dd></div>
              </dl>
              {!weighted && d.sites.length > 0 && (
                <div className="progress-calc-missing" role="note">
                  <i className="bi bi-exclamation-triangle-fill" aria-hidden="true" />
                  <span>Montants non renseignés : avancement provisoire.{can('canManageContracts') && <> <Link to={`/contracts/${c.id}/edit`}>Compléter le contrat</Link></>}</span>
                </div>
              )}
              {inst && (
                <div className={`installation-entry ${instOpen ? '' : 'is-done'}`}>
                  <div className="installation-entry-head">
                    <div>
                      <div className="installation-entry-title">Installation de chantier et travaux préparatoires</div>
                      <div className="small text-secondary">Coût : {inst.amount === null ? 'non renseigné' : `${money(inst.amount)} USD`}</div>
                    </div>
                    <div className="installation-entry-rate">
                      <span className="installation-entry-value">{num(inst.progress, 2)} %</span>
                      <span className="small text-secondary">taux actuel</span>
                    </div>
                  </div>
                  <Bar value={inst.progress} />
                  {instOpen ? (
                    <div className="row g-3 mt-0 align-items-end">
                      <Field className="col-lg-3 col-md-4" label="Taux de la période (%)">
                        <DecimalInput value={form.installation_progress} onChange={(v) => setForm({ ...form, installation_progress: v })} placeholder={`Reste ${num(inst.remaining, 2)} %`} aria-label="Taux de la période pour l’installation de chantier (%)" />
                      </Field>
                    </div>
                  ) : <div className="small fw-semibold mt-2 installation-entry-ok"><i className="bi bi-check-circle-fill me-1" aria-hidden="true" />Installation terminée à 100 %.</div>}
                </div>
              )}
              <div className="row g-3">
                <Field className="col-lg-5" label="Ouvrage / site">
                  <select className="form-select" value={form.site_id} onChange={(e) => setForm({ ...form, site_id: e.target.value })} required={d.sites.length > 0 && !(instOpen && parseDecimal(form.installation_progress) > 0)}>
                    <option value="">{instOpen ? '— Aucun —' : '— Sélectionner —'}</option>
                    {sites.map((s) => (
                      <option key={s.key} value={s.key} disabled={s.remaining <= 0}>
                        {s.label}{s.amount !== null ? ` — ${money(s.amount)} USD` : ''} · réalisé {num(s.progress, 1)} %{s.remaining <= 0 ? ' · terminé' : ''}
                      </option>
                    ))}
                  </select>
                </Field>
                <Field className="col-lg-2 col-md-4" label="Date de situation"><input type="date" className="form-control" value={form.date} onChange={(e) => setForm({ ...form, date: e.target.value })} required /></Field>
                <Field className="col-lg-2 col-md-4" label="Taux de la période (%)"><DecimalInput value={form.progress} onChange={(v) => setForm({ ...form, progress: v })} placeholder="Ex. : 12,5" required={!!form.site_id || (d.sites.length === 0)} disabled={d.sites.length > 0 && !form.site_id} /></Field>
                <Field className="col-lg-3 col-md-4" label="Réalisation physique"><input className="form-control" value={form.realization_physical} onChange={(e) => setForm({ ...form, realization_physical: e.target.value })} /></Field>
                {(site || effects[1]) && <div className="col-12"><SituationPreview site={site} effects={effects} contractProgress={Number(c.progress) || 0} base={base} /></div>}
                <Field className="col-md-7" label="Observation"><input className="form-control" value={form.observation} onChange={(e) => setForm({ ...form, observation: e.target.value })} /></Field>
                <Field className="col-md-3" label="Performance environnementale et sociale">
                  <select className="form-select" value={esValue} onChange={(e) => setForm({ ...form, es_performance: e.target.value })}>
                    <option value="">— Non évaluée —</option>
                    {(d.esLevels || []).map((x) => <option key={x}>{x}</option>)}
                  </select>
                </Field>
                <div className="col-md-2 d-flex align-items-end"><button className="btn btn-primary w-100" disabled={busy}><i className="bi bi-plus-lg me-1" />Enregistrer</button></div>
              </div>
            </form>
          )}

          {d.breakdown?.rows.length > 0 && (
            <div className="cardx p-3 mb-3">
              <div className="section-title mb-2">Détail de l’avancement physique</div>
              <ProgressBreakdown breakdown={d.breakdown} progress={c.progress} highlight={form.site_id || (parseDecimal(form.installation_progress) > 0 ? 'INSTALLATION' : null)}
                editLink={can('canManageContracts') ? `/contracts/${c.id}/edit` : null} />
            </div>
          )}

          <div className="cardx p-3 mb-3">
            <div className="section-title mb-3">Situations enregistrées</div>
            <div className="table-wrap">
              <table className="table table-hover align-middle">
                <thead><tr><th>Date</th><th>Installation ou site</th><th>Taux de la période</th><th>Taux cumulé</th><th>Réalisation physique</th><th>Observation</th><th>Perf. env. et sociale</th>{editable && <th />}</tr></thead>
                <tbody>
                  {d.entries.map((e) => (
                    <tr key={e.id}>
                      <td>{fr(e.date)}</td><td><strong>{e.site}</strong></td><td>{num(e.progress, 2)} %</td>
                      <td style={{ minWidth: 130 }}><strong>{num(e.cumulative, 1)}%</strong><Bar value={e.cumulative} /></td>
                      <td>{e.realization || '—'}</td><td>{e.observation || '—'}</td><td>{e.esPerformance || '—'}</td>
                      {editable && (
                        <td className="text-nowrap">
                          <button className="btn btn-sm btn-outline-primary" title="Corriger" onClick={() => setEdit({ ...e })}><i className="bi bi-pencil" /></button>
                          <button className="btn btn-sm btn-outline-danger ms-1" title="Supprimer" onClick={() => setDel(e)}><i className="bi bi-trash" /></button>
                        </td>
                      )}
                    </tr>
                  ))}
                  {!d.entries.length && <tr><td colSpan="8"><Empty text="Aucune situation enregistrée pour ce contrat." /></td></tr>}
                </tbody>
              </table>
            </div>
            <Pager meta={d.meta} onPage={setPage} />
          </div>

          <div className="row g-3 mb-4">
            <div className="col-xl-7"><div className="cardx p-3 h-100"><div className="section-title">Évolution du contrat</div><div className="section-subtitle mb-2">Avancement physique, jours consommés et taux de paiement à chaque situation saisie.</div><EvolutionChart rows={d.evolution} /></div></div>
            <div className="col-xl-5"><div className="cardx p-3 h-100"><div className="section-title">Évolution de chaque composante</div><div className="section-subtitle mb-2">Au {d.sitePrevLabel} et au {d.siteCurLabel}.</div><SiteChart sites={d.siteEvolution} prevLabel={d.sitePrevLabel} curLabel={d.siteCurLabel} /></div></div>
          </div>
        </>
      )}

      {edit && (
        <Modal title="Corriger la situation" subtitle={`${edit.site} — ${fr(edit.date)}`} size="md" onClose={() => setEdit(null)}
          footer={<><button className="btn btn-light" onClick={() => setEdit(null)}>Annuler</button><button className="btn btn-primary" disabled={busy} onClick={saveEdit}>Enregistrer la correction</button></>}>
          <div className="row g-3">
            <Field className="col-md-6" label="Date de situation"><input type="date" className="form-control" value={edit.date} onChange={(e) => setEdit({ ...edit, date: e.target.value })} /></Field>
            <Field className="col-md-6" label="Taux de la période (%)"><DecimalInput value={String(edit.progress)} onChange={(v) => setEdit({ ...edit, progress: v })} /></Field>
            <Field className="col-12" label="Réalisation physique"><input className="form-control" value={edit.realization} onChange={(e) => setEdit({ ...edit, realization: e.target.value })} /></Field>
            <Field className="col-12" label="Observation"><input className="form-control" value={edit.observation} onChange={(e) => setEdit({ ...edit, observation: e.target.value })} /></Field>
            <Field className="col-md-6" label="Performance environnementale et sociale">
              <select className="form-select" value={edit.esPerformance || ''} onChange={(e) => setEdit({ ...edit, esPerformance: e.target.value })}>
                <option value="">— Non évaluée —</option>
                {(d.esLevels || []).map((x) => <option key={x}>{x}</option>)}
              </select>
            </Field>
          </div>
        </Modal>
      )}
      {del && (
        <ReasonDialog title="Supprimer la situation" text={`Situation du ${fr(del.date)} (${num(del.progress, 2)} %) de « ${del.site} ».`} confirmLabel="Supprimer" onClose={() => setDel(null)}
          onConfirm={wrap(async (reason) => { await api.delete(`/progress/${del.id}`, { data: { reason } }); say('La situation d’avancement a été supprimée.'); setDel(null); await reload(); })} />
      )}
    </>
  );
}

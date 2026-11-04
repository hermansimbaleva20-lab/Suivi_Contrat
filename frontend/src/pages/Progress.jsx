import React, { useState } from 'react';
import { api, apiError } from '../api.js';
import { useLoad } from '../lib/hooks.js';
import { fr, num, todayISO } from '../lib/format.js';
import { DecimalInput, Empty, Field, Kpi, Loading, Modal, Notice, PageHead, Pager, Progress as Bar, ReasonDialog, StatusPill } from '../components/ui.jsx';
import { EvolutionChart, SiteChart } from '../components/Charts.jsx';
import { useAuth } from '../context/AuthContext.jsx';

const wrap = (fn) => async (...a) => { try { return await fn(...a); } catch (e) { throw new Error(apiError(e)); } };
const blank = () => ({ site_id: '', date: todayISO(), progress: '', realization_physical: '', observation: '' });

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

  const say = (text, type = 'success') => setFlash({ type, text });
  const save = async (e) => {
    e.preventDefault();
    setBusy(true);
    try {
      const r = await api.post('/progress', { ...form, contract_id: c.id });
      say(r.data.message);
      setForm({ ...blank(), site_id: form.site_id });
      await reload();
    } catch (err) { say(apiError(err), 'danger'); } finally { setBusy(false); }
  };
  const saveEdit = async () => {
    setBusy(true);
    try { const r = await api.put(`/progress/${edit.id}`, { date: edit.date, progress: edit.progress, realization_physical: edit.realization, observation: edit.observation }); say(r.data.message); setEdit(null); await reload(); }
    catch (err) { say(apiError(err), 'danger'); } finally { setBusy(false); }
  };

  return (
    <>
      <PageHead kicker="SUIVI-ÉVALUATION" title="Suivi & Avancement" desc="La date de situation est indépendante de la date d’encodage. L’avancement d’un site est le cumul de ses situations (100 % maximum).">
        <select className="form-select" style={{ minWidth: 260 }} value={c?.id || ''} onChange={(e) => { setContractId(e.target.value); setPage(1); }}>
          {d.contracts.map((x) => <option key={x.id} value={x.id}>{x.number} — {x.company}{x.isTerminated ? ' (résilié)' : ''}</option>)}
        </select>
      </PageHead>
      <Notice type={flash.type} onClose={() => say('')}>{flash.text}</Notice>
      {!c ? <Empty text="Aucun contrat dans cette phase." /> : (
        <>
          {c.isTerminated && <div className="alert alert-warning">Ce contrat est résilié : aucune nouvelle situation ne peut être saisie.</div>}
          <div className="row g-3 mb-3">
            <div className="col-xl-3 col-md-6"><Kpi tone="green" label="AVANCEMENT PHYSIQUE" value={`${num(c.progress, 1)} %`} sub={`${c.sitesCount} site(s)`} /></div>
            <div className="col-xl-3 col-md-6"><Kpi tone="orange" label="JOURS CONSOMMÉS" value={`${num(c.elapsed, 1)} %`} sub={`Fin prévue le ${fr(c.endDate)}`} /></div>
            <div className="col-xl-3 col-md-6"><Kpi label="TAUX DE PAIEMENT" value={`${num(c.paymentRate, 1)} %`} /></div>
            <div className="col-xl-3 col-md-6"><div className="cardx kpi"><div className="label">STATUT</div><div className="value" style={{ fontSize: 22 }}><StatusPill status={c.status} /></div></div></div>
          </div>

          {editable && (
            <form className="cardx form-card p-3 mb-3" onSubmit={save}>
              <div className="section-title mb-3">Nouvelle situation d’avancement</div>
              <div className="row g-3">
                <Field className="col-md-4" label="Ouvrage / site">
                  <select className="form-select" value={form.site_id} onChange={(e) => setForm({ ...form, site_id: e.target.value })} required={d.sites.length > 0}>
                    <option value="">— Sélectionner —</option>
                    {d.sites.map((s) => <option key={s.id} value={s.id} disabled={s.remaining <= 0}>{s.name} ({num(s.progress, 1)} % — reste {num(s.remaining, 1)} %)</option>)}
                  </select>
                </Field>
                <Field className="col-md-2" label="Date de situation"><input type="date" className="form-control" value={form.date} onChange={(e) => setForm({ ...form, date: e.target.value })} required /></Field>
                <Field className="col-md-2" label="Avancement (%)"><DecimalInput value={form.progress} onChange={(v) => setForm({ ...form, progress: v })} placeholder="Ex. : 12,5" required /></Field>
                <Field className="col-md-4" label="Réalisation physique"><input className="form-control" value={form.realization_physical} onChange={(e) => setForm({ ...form, realization_physical: e.target.value })} /></Field>
                <Field className="col-md-10" label="Observation"><input className="form-control" value={form.observation} onChange={(e) => setForm({ ...form, observation: e.target.value })} /></Field>
                <div className="col-md-2 d-flex align-items-end"><button className="btn btn-primary w-100" disabled={busy}><i className="bi bi-plus-lg me-1" />Enregistrer</button></div>
              </div>
            </form>
          )}

          <div className="cardx p-3 mb-3">
            <div className="section-title mb-3">Situations enregistrées</div>
            <div className="table-wrap">
              <table className="table table-hover align-middle">
                <thead><tr><th>Date</th><th>Site</th><th>Avancement</th><th>Cumul du site</th><th>Réalisation physique</th><th>Observation</th>{editable && <th />}</tr></thead>
                <tbody>
                  {d.entries.map((e) => (
                    <tr key={e.id}>
                      <td>{fr(e.date)}</td><td><strong>{e.site}</strong></td><td>{num(e.progress, 2)} %</td>
                      <td style={{ minWidth: 130 }}><strong>{num(e.cumulative, 1)}%</strong><Bar value={e.cumulative} /></td>
                      <td>{e.realization || '—'}</td><td>{e.observation || '—'}</td>
                      {editable && (
                        <td className="text-nowrap">
                          <button className="btn btn-sm btn-outline-primary" title="Corriger" onClick={() => setEdit({ ...e })}><i className="bi bi-pencil" /></button>
                          <button className="btn btn-sm btn-outline-danger ms-1" title="Supprimer" onClick={() => setDel(e)}><i className="bi bi-trash" /></button>
                        </td>
                      )}
                    </tr>
                  ))}
                  {!d.entries.length && <tr><td colSpan="7"><Empty text="Aucune situation enregistrée pour ce contrat." /></td></tr>}
                </tbody>
              </table>
            </div>
            <Pager meta={d.meta} onPage={setPage} />
          </div>

          <div className="row g-3 mb-4">
            <div className="col-xl-7"><div className="cardx p-3 h-100"><div className="section-title">Évolution du contrat</div><div className="section-subtitle mb-2">Avancement physique, jours consommés et taux de paiement à chaque situation saisie.</div><EvolutionChart rows={d.evolution} /></div></div>
            <div className="col-xl-5"><div className="cardx p-3 h-100"><div className="section-title">Évolution de chaque site</div><div className="section-subtitle mb-2">Au {d.sitePrevLabel} et au {d.siteCurLabel}.</div><SiteChart sites={d.siteEvolution} prevLabel={d.sitePrevLabel} curLabel={d.siteCurLabel} /></div></div>
          </div>
        </>
      )}

      {edit && (
        <Modal title="Corriger la situation" subtitle={`${edit.site} — ${fr(edit.date)}`} size="md" onClose={() => setEdit(null)}
          footer={<><button className="btn btn-light" onClick={() => setEdit(null)}>Annuler</button><button className="btn btn-primary" disabled={busy} onClick={saveEdit}>Enregistrer la correction</button></>}>
          <div className="row g-3">
            <Field className="col-md-6" label="Date de situation"><input type="date" className="form-control" value={edit.date} onChange={(e) => setEdit({ ...edit, date: e.target.value })} /></Field>
            <Field className="col-md-6" label="Avancement (%)"><DecimalInput value={String(edit.progress)} onChange={(v) => setEdit({ ...edit, progress: v })} /></Field>
            <Field className="col-12" label="Réalisation physique"><input className="form-control" value={edit.realization} onChange={(e) => setEdit({ ...edit, realization: e.target.value })} /></Field>
            <Field className="col-12" label="Observation"><input className="form-control" value={edit.observation} onChange={(e) => setEdit({ ...edit, observation: e.target.value })} /></Field>
          </div>
        </Modal>
      )}
      {del && (
        <ReasonDialog title="Supprimer la situation" text={`Situation du ${fr(del.date)} (${num(del.progress, 2)} %) du site « ${del.site} ».`} confirmLabel="Supprimer" onClose={() => setDel(null)}
          onConfirm={wrap(async (reason) => { await api.delete(`/progress/${del.id}`, { data: { reason } }); say('La situation d’avancement a été supprimée.'); setDel(null); await reload(); })} />
      )}
    </>
  );
}

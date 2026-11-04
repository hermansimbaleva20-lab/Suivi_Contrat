import React, { useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { api, apiError } from '../api.js';
import { useLoad } from '../lib/hooks.js';
import { fr, money, num, todayISO } from '../lib/format.js';
import { DecimalInput, Empty, Field, Kpi, Loading, Modal, Notice, PageHead, Pager, ReasonDialog } from '../components/ui.jsx';
import { useAuth } from '../context/AuthContext.jsx';
import AdvancePanel from '../modules/payments/AdvancePanel.jsx';
import DecomptesToPay from '../modules/payments/DecomptesToPay.jsx';

const wrap = (fn) => async (...a) => { try { return await fn(...a); } catch (e) { throw new Error(apiError(e)); } };
const blank = () => ({ date: todayISO(), amount: '', reference: '', observation: '' });

export default function Payments() {
  const { can } = useAuth();
  const [params] = useSearchParams();
  const [contractId, setContractId] = useState(params.get('contrat') || ''); // la notification ouvre le contrat concerné
  const [page, setPage] = useState(1);
  const { data: d, loading, error, reload } = useLoad('/payments', { contract_id: contractId || undefined, page });
  const [form, setForm] = useState(blank());
  const [edit, setEdit] = useState(null);
  const [del, setDel] = useState(null);
  const [flash, setFlash] = useState({ type: 'success', text: '' });
  const [busy, setBusy] = useState(false);
  const [paying, setPaying] = useState(null); // décompte validé dont on renseigne le paiement
  const formRef = useRef(null);

  if (loading && !d) return <Loading />;
  if (!d) return <Notice>{error}</Notice>;
  const c = d.selected;
  const editable = can('canManagePayments') && c && !c.isTerminated;
  // Montant restant au sens du taux de paiement : montant du contrat − avance de démarrage − paiements enregistrés.
  const remaining = c ? Number(c.remainingAmount ?? (Number(c.amount) - Number(c.initialFinancing) - Number(d.totalPaid))) : 0;
  const say = (text, type = 'success') => setFlash({ type, text });

  const save = async (e) => {
    e.preventDefault();
    setBusy(true);
    try {
      const r = await api.post('/payments', paying ? { ...form, contract_id: paying.contractId, decompte_id: paying.id } : { ...form, contract_id: c.id });
      say(r.data.message); setForm(blank()); setPaying(null); await reload();
    }
    catch (err) { say(apiError(err), 'danger'); } finally { setBusy(false); }
  };
  // Le montant vient de la colonne MONTANT PAYÉ du décompte : il reste à vérifier et à compléter la date et les références.
  const pay = (x) => {
    setPaying(x);
    setContractId(String(x.contractId));
    setPage(1);
    setForm({ ...blank(), amount: String(x.amount).replace('.', ','), reference: x.number });
    requestAnimationFrame(() => formRef.current?.scrollIntoView({ behavior: 'smooth', block: 'center' }));
  };
  const cancelPay = () => { setPaying(null); setForm(blank()); };
  const saveEdit = async () => {
    setBusy(true);
    try { const r = await api.put(`/payments/${edit.id}`, edit); say(r.data.message); setEdit(null); await reload(); }
    catch (err) { say(apiError(err), 'danger'); } finally { setBusy(false); }
  };

  return (
    <>
      <PageHead kicker="SUIVI FINANCIER" title="Paiements" desc="Les montants acceptent la virgule ou le point (1500,75). Toute correction ou suppression est tracée dans le journal d’audit.">
        <select className="form-select" style={{ minWidth: 260 }} value={c?.id || ''} onChange={(e) => { setContractId(e.target.value); setPage(1); cancelPay(); }}>
          {d.contracts.map((x) => <option key={x.id} value={x.id}>{x.number} — {x.company}{x.isTerminated ? ' (résilié)' : ''}</option>)}
        </select>
      </PageHead>
      <Notice type={flash.type} onClose={() => say('')}>{flash.text}</Notice>
      <DecomptesToPay items={d.toPay || []} activeId={paying?.id} onPay={pay} />
      {!c ? <Empty text="Aucun contrat dans cette phase." /> : (
        <>
          {c.isTerminated && <div className="alert alert-warning">Ce contrat est résilié : aucun nouveau paiement ne peut être enregistré.</div>}
          {/* Cinq indicateurs ; le montant restant suit le taux de paiement. */}
          <div className="row g-3 mb-3 row-cols-1 row-cols-md-2 row-cols-xl-5 kpi-row-5">
            <div className="col"><Kpi tone="green" label="MONTANT DU CONTRAT" value={money(c.amount)} /></div>
            <div className="col"><Kpi label="AVANCE DE DÉMARRAGE" value={money(c.initialFinancing)} sub={c.advanceOnRequest && c.advanceRequest?.status !== 'PAYEE' ? `${num(c.initialFinancingRate, 2)} % — ${c.advanceRequest ? 'demandée, à payer' : 'sur demande'}` : `${num(c.initialFinancingRate, 2)} %`} /></div>
            <div className="col"><Kpi tone="orange" label="PAIEMENTS ENREGISTRÉS" value={money(d.totalPaid)} /></div>
            <div className="col"><Kpi label="TAUX DE PAIEMENT" value={`${num(c.paymentRate, 1)} %`} sub={`Avancement physique : ${num(c.progress, 1)} %`} /></div>
            <div className="col"><Kpi tone={remaining < 0 ? 'red' : 'green'} label="MONTANT RESTANT" value={money(remaining)} sub={remaining < 0 ? 'Paiements supérieurs au montant du contrat' : `${num(Math.max(0, 100 - c.paymentRate), 1)} % du contrat`} /></div>
          </div>

          <AdvancePanel c={c} canManage={can('canManagePayments')} onDone={(message) => { say(message); reload(); }} />

          {editable && (
            <form ref={formRef} className="cardx form-card p-3 mb-3" onSubmit={save}>
              <div className="section-title mb-1">{paying ? `Paiement de ${paying.number}` : 'Nouveau paiement'}</div>
              <div className="section-subtitle mb-3">{paying ? `Contrat ${paying.contractNumber}. Vérifiez le montant, repris de la colonne MONTANT PAYÉ du décompte, puis renseignez la date et les références.` : (c.advanceOnRequest ? 'Paiement sans décompte. L’avance de démarrage de ce marché se renseigne dans le panneau ci-dessus.' : 'Paiement sans décompte, par exemple l’avance de démarrage.')}</div>
              <div className="row g-3">
                <Field className="col-md-3" label="Date du paiement"><input type="date" className="form-control" value={form.date} onChange={(e) => setForm({ ...form, date: e.target.value })} required /></Field>
                <Field className="col-md-3" label="Montant payé">
                  {paying
                    ? <input className="form-control fw-semibold" value={money(paying.amount)} readOnly aria-describedby="pay-amount-note" />
                    : <DecimalInput value={form.amount} onChange={(v) => setForm({ ...form, amount: v })} placeholder="Ex. : 1500,75" required />}
                  {paying && <div id="pay-amount-note" className="form-text">Montant du décompte, non modifiable.</div>}
                </Field>
                <Field className="col-md-3" label="Référence"><input className="form-control" value={form.reference} onChange={(e) => setForm({ ...form, reference: e.target.value })} placeholder={c.advanceOnRequest ? 'Ex. : facture n° 12' : 'Ex. : Avance de démarrage'} /></Field>
                <Field className="col-md-3" label="Observation"><input className="form-control" value={form.observation} onChange={(e) => setForm({ ...form, observation: e.target.value })} /></Field>
                <div className="col-12 text-end">
                  {paying && <button type="button" className="btn btn-light me-2" onClick={cancelPay}>Annuler</button>}
                  <button className="btn btn-primary" disabled={busy}><i className="bi bi-plus-lg me-1" />Enregistrer le paiement</button>
                </div>
              </div>
            </form>
          )}

          <div className="cardx p-3 mb-4">
            <div className="section-title mb-3">Paiements enregistrés</div>
            <div className="table-wrap">
              <table className="table table-hover align-middle">
                <thead><tr><th>Date</th><th>Montant</th><th>Référence</th><th>Observation</th>{editable && <th />}</tr></thead>
                <tbody>
                  {d.payments.map((p) => (
                    <tr key={p.id}>
                      <td>{fr(p.date)}</td><td className="text-money fw-semibold">{money(p.amount)}</td><td>{p.reference || '—'}</td><td>{p.observation || '—'}</td>
                      {editable && (
                        <td className="text-nowrap">
                          {p.decompteId && <span className="badge-soft me-2" title="Paiement d’un décompte validé : son montant est celui de la colonne MONTANT PAYÉ">Décompte</span>}
                          <>
                              <button className="btn btn-sm btn-outline-primary" title="Corriger" onClick={() => setEdit({ id: p.id, decompteId: p.decompteId, date: p.date, amount: String(p.amount).replace('.', ','), reference: p.reference, observation: p.observation })}><i className="bi bi-pencil" /></button>
                              <button className="btn btn-sm btn-outline-danger ms-1" title="Supprimer" onClick={() => setDel(p)}><i className="bi bi-trash" /></button>
                          </>
                        </td>
                      )}
                    </tr>
                  ))}
                  {!d.payments.length && <tr><td colSpan="5"><Empty text="Aucun paiement enregistré pour ce contrat." /></td></tr>}
                </tbody>
              </table>
            </div>
            <Pager meta={d.meta} onPage={setPage} />
          </div>
        </>
      )}

      {edit && (
        <Modal title="Corriger le paiement" size="md" onClose={() => setEdit(null)}
          footer={<><button className="btn btn-light" onClick={() => setEdit(null)}>Annuler</button><button className="btn btn-primary" disabled={busy} onClick={saveEdit}>Enregistrer la correction</button></>}>
          <div className="row g-3">
            <Field className="col-md-6" label="Date du paiement"><input type="date" className="form-control" value={edit.date} onChange={(e) => setEdit({ ...edit, date: e.target.value })} /></Field>
            <Field className="col-md-6" label="Montant payé">
              {edit.decompteId
                ? <><input className="form-control fw-semibold" value={edit.amount} readOnly /><div className="form-text">Montant du décompte, non modifiable.</div></>
                : <DecimalInput value={edit.amount} onChange={(v) => setEdit({ ...edit, amount: v })} />}
            </Field>
            <Field className="col-12" label="Référence"><input className="form-control" value={edit.reference} onChange={(e) => setEdit({ ...edit, reference: e.target.value })} /></Field>
            <Field className="col-12" label="Observation"><input className="form-control" value={edit.observation} onChange={(e) => setEdit({ ...edit, observation: e.target.value })} /></Field>
          </div>
        </Modal>
      )}
      {del && (
        <ReasonDialog title="Supprimer le paiement" text={`Paiement du ${fr(del.date)} de ${money(del.amount)}.${del.decompteId ? ' Le décompte reviendra dans les décomptes à payer.' : ''}`} confirmLabel="Supprimer" onClose={() => setDel(null)}
          onConfirm={wrap(async (reason) => { await api.delete(`/payments/${del.id}`, { data: { reason } }); say('Le paiement a été supprimé.'); setDel(null); await reload(); })} />
      )}
    </>
  );
}

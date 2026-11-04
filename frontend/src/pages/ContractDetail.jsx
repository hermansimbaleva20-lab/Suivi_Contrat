import React, { useRef, useState } from 'react';
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom';
import { api, apiError, download, fieldErrors } from '../api.js';
import { useLoad } from '../lib/hooks.js';
import { addMonths, fr, frDateTime, money, num, parseDecimal, todayISO } from '../lib/format.js';
import { DecimalInput, Empty, Field, Kpi, Loading, Modal, Notice, PageHead, Progress, ReasonDialog, StatusPill } from '../components/ui.jsx';
import { EvolutionChart, HBarChart, OverpaidNote } from '../components/Charts.jsx';
import { COLORS, paymentColor } from '../lib/chartColors.js';
import { useAuth } from '../context/AuthContext.jsx';

const wrap = (fn) => async (...a) => { try { return await fn(...a); } catch (e) { throw new Error(apiError(e)); } };
const size = (b) => (b > 1048576 ? `${(b / 1048576).toFixed(1)} Mo` : `${Math.max(1, Math.round(b / 1024))} Ko`);

/** Fiche d'un contrat. readOnly = page de consultation (Dashboard) : aucune modification, suppression ni résiliation. */
export default function ContractDetail({ readOnly = false }) {
  const { id } = useParams();
  const nav = useNavigate();
  const location = useLocation();
  const { can } = useAuth();
  const { data: d, loading, error, reload } = useLoad(`/contracts/${id}`);
  const [dialog, setDialog] = useState(null);
  const [flash, setFlash] = useState({ type: 'success', text: '' });
  const [termDate, setTermDate] = useState(todayISO());
  const [amend, setAmend] = useState({ date: todayISO(), added_months: '3', reason: '' });
  const [renew, setRenew] = useState(null);
  const [renewErrors, setRenewErrors] = useState({});
  const [busy, setBusy] = useState(false);
  const file = useRef(null);

  if (loading && !d) return <Loading />;
  if (!d) return <Notice>{error}</Notice>;
  const c = d.contract;
  const g = d.guarantee;
  const manage = !readOnly;
  const canEdit = manage && can('canManageContracts') && !c.isTerminated;

  const say = (text, type = 'success') => setFlash({ type, text });
  const act = async (fn, ok) => {
    setBusy(true);
    try { const r = await fn(); say(ok || r?.data?.message || 'Opération effectuée.'); setDialog(null); await reload(); } catch (e) { say(apiError(e), 'danger'); } finally { setBusy(false); }
  };

  const openRenew = () => {
    setRenewErrors({});
    setRenew({ reference: c.nextRenewalNumber, date: todayISO(), period_start: c.endDate || todayISO(), months: '12', added_amount: '', reason: '' });
    setDialog('renew');
  };
  const saveRenew = async () => {
    setBusy(true);
    setRenewErrors({});
    try {
      const r = await api.post(`/contracts/${id}/renew`, renew);
      say(r.data.message);
      setDialog(null);
      await reload();
    } catch (e) {
      const fe = fieldErrors(e);
      setRenewErrors(fe);
      if (!Object.keys(fe).length) { say(apiError(e), 'danger'); setDialog(null); }
    } finally { setBusy(false); }
  };

  const upload = async (e) => {
    const f = e.target.files?.[0];
    if (!f) return;
    const fd = new FormData();
    fd.append('file', f);
    await act(() => api.post(`/contracts/${id}/documents`, fd), 'Document ajouté au dossier du contrat.');
    e.target.value = '';
  };

  return (
    <>
      <PageHead kicker={readOnly ? 'CONSULTATION DU CONTRAT' : 'FICHE DU CONTRAT'} title={c.number} desc={`${c.company} — ${(c.provinces || []).join(', ') || c.province || ''}`}>
        {readOnly ? (
          <>
            <span className="readonly-badge"><i className="bi bi-eye me-1" /> Lecture seule</span>
            <button className="btn btn-light" onClick={() => nav(-1)}><i className="bi bi-arrow-left me-1" /> Retour</button>
          </>
        ) : (
          <>
            <Link to="/contracts" className="btn btn-light"><i className="bi bi-arrow-left me-1" /> Contrats</Link>
            {canEdit && <Link to={`/contracts/${id}/edit`} className="btn btn-outline-primary"><i className="bi bi-pencil me-1" /> Modifier</Link>}
            {canEdit && <button className="btn btn-outline-primary" onClick={() => setDialog('amend')}><i className="bi bi-calendar-plus me-1" /> Avenant</button>}
            {canEdit && ['BIENS', 'BIEN'].includes(c.category) && <button className="btn btn-outline-success" onClick={openRenew}><i className="bi bi-arrow-repeat me-1" /> Renouveler</button>}
            {canEdit && <button className="btn btn-outline-warning" onClick={() => setDialog('terminate')}><i className="bi bi-slash-circle me-1" /> Résilier</button>}
            {can('canDeleteContracts') && <button className="btn btn-outline-danger" onClick={() => setDialog('delete')}><i className="bi bi-trash me-1" /> Supprimer</button>}
          </>
        )}
      </PageHead>

      <Notice type={flash.type} onClose={() => say('')}>{flash.text}</Notice>
      {location.state?.documentUploadWarning && <div className="alert alert-warning" role="alert">{location.state.documentUploadWarning}</div>}
      {c.isTerminated && (
        <div className="alert alert-danger"><strong><i className="bi bi-slash-circle me-1" /> Contrat résilié le {fr(c.terminationDate)}.</strong><div className="small mt-1">{c.terminationReason}</div></div>
      )}

      <div className="row g-3 mb-3">
        <div className="col-xl-3 col-md-6"><Kpi tone="green" label="AVANCEMENT PHYSIQUE" value={`${num(c.progress, 1)} %`} sub={`${c.sitesCount} site(s)`} /></div>
        <div className="col-xl-3 col-md-6"><Kpi tone="orange" label="JOURS CONSOMMÉS" value={`${num(c.elapsed, 1)} %`} sub={c.daysRemaining !== null ? (c.daysRemaining >= 0 ? `${c.daysRemaining} jour(s) restant(s)` : `${-c.daysRemaining} jour(s) de dépassement`) : ''} /></div>
        <div className="col-xl-3 col-md-6"><Kpi label="TAUX DE PAIEMENT" value={`${num(c.paymentRate, 1)} %`} sub={`${money(c.paidIncludingInitial)} versés`} /></div>
        <div className="col-xl-3 col-md-6"><div className="cardx kpi"><div className="label">STATUT</div><div className="value" style={{ fontSize: 22 }}><StatusPill status={c.status} /></div><div className="small text-secondary">Écart : {num(c.gap, 1)} pts</div></div></div>
      </div>

      <div className="cardx p-3 mb-3">
        <div className="section-title mb-3">Informations générales</div>
        <div className="info-grid">
          <div><span>Entreprise</span><strong>{c.company}</strong></div>
          <div><span>Catégorie</span><strong>{c.category || '—'}</strong></div>
          <div><span>Nature des travaux</span><strong>{c.workTypeText || '—'}</strong></div>
          <div><span>Signature</span><strong>{fr(c.signatureDate)}</strong></div>
          <div><span>Démarrage</span><strong>{fr(c.startDate)}</strong></div>
          <div><span>Fin prévue</span><strong>{fr(c.endDate)} ({c.durationMonths} mois)</strong></div>
          <div><span>Montant du contrat</span><strong className="text-money">{money(c.amount)}</strong></div>
          <div><span>Avance de démarrage</span><strong className="text-money">{num(c.initialFinancingRate, 2)} % — {money(c.initialFinancing)}</strong></div>
          <div><span>Paiements enregistrés</span><strong className="text-money">{money(c.totalPaid)}</strong></div>
          {d.startupAdvance && <div><span>Avance de démarrage</span><strong className="text-money">{money(d.startupAdvance.amount)} ({fr(d.startupAdvance.date)})</strong></div>}
          <div className="grid-span"><span>Activité</span><strong>{c.activity || '—'}</strong></div>
        </div>
      </div>

      <div className="cardx p-3 mb-3">
        <div className="section-title mb-3">Sites d’exécution</div>
        <div className="table-wrap">
          <table className="table align-middle">
            <thead><tr><th>Site</th><th>Province</th><th>Type d’ouvrage</th><th>Description</th><th>Quantité</th><th style={{ minWidth: 150 }}>Avancement</th></tr></thead>
            <tbody>
              {c.sites.map((s) => (
                <tr key={s.id}>
                  <td><strong>{s.name}</strong></td><td>{s.province}</td><td>{s.workType || '—'}</td><td>{s.description || '—'}</td><td>{num(s.quantity, 2)}</td>
                  <td><strong>{num(s.progress, 1)}%</strong><Progress value={s.progress} /></td>
                </tr>
              ))}
              {!c.sites.length && <tr><td colSpan="6"><Empty text="Aucun site enregistré." /></td></tr>}
            </tbody>
          </table>
        </div>
      </div>

      <div className="row g-3 mb-3">
        <div className="col-xl-7">
          <div className="cardx p-3 h-100">
            <div className="section-title">Évolution du contrat</div>
            <div className="section-subtitle mb-2">Histogramme horizontal : avancement physique, jours consommés, taux de paiement et consommation de la garantie, mois par mois.</div>
            <EvolutionChart rows={d.history} withGuarantee={!!g} />
          </div>
        </div>
        <div className="col-xl-5">
          <div className="cardx p-3 h-100">
            <div className="section-title">Indicateurs d’exécution du contrat</div>
            <div className="section-subtitle mb-2">Comparaison synthétique du temps consommé, des paiements et de l’avancement physique.</div>
            {/* Chaque indicateur dans sa couleur ; le taux de paiement en rouge s'il dépasse l'avancement physique. */}
            <HBarChart
              legend={false}
              labels={['Jours consommés', 'Taux de paiement', 'Taux d’avancement']}
              datasets={[{
                label: 'Taux actuel',
                data: [Number(c.elapsed) || 0, Number(c.paymentRate) || 0, Number(c.progress) || 0],
                color: [COLORS.days, paymentColor(c.paymentRate, c.progress), COLORS.progress],
              }]}
            />
            <OverpaidNote show={Number(c.paymentRate) > Number(c.progress)} />

          </div>
        </div>
      </div>

      <div className="cardx p-3 mb-3 guarantee-detail-card">
        <div className="d-flex justify-content-between align-items-center mb-3">
          <div className="section-title"><i className="bi bi-shield-check me-1" /> Garantie de bonne exécution</div>
          {manage && can('canManageGuarantees') && <Link to="/guarantees" className="btn btn-sm btn-outline-primary">Gérer les garanties</Link>}
        </div>
        {!g ? <Empty text="Aucune garantie enregistrée pour ce contrat." /> : (
          <>
            <div className="info-grid mb-3">
              <div><span>Banque</span><strong>{g.bank || '—'}</strong></div>
              <div><span>N° garantie</span><strong>{g.account || '—'}</strong></div>
              <div><span>Taux / montant</span><strong>{num(g.rate, 2)} % — {money(g.amount)}</strong></div>
              <div><span>Début</span><strong>{fr(g.startDate)}</strong></div>
              <div><span>Échéance</span><strong>{fr(g.endDate)}</strong></div>
              <div><span>État</span><strong><span className={`status status-${g.statusCss}`}>{g.statusLabel}</span>{!g.contractRenewed && <> · {num(g.daysConsumedPercent)} % consommés</>}</strong></div>
            </div>
            <div className="fw-bold mb-2">Historique des prolongements et renouvellements</div>
            {!g.extensions.length ? <div className="text-secondary small">Aucun mouvement enregistré.</div> : (
              <div className="table-wrap">
                <table className="table table-sm">
                  <thead><tr><th>Nature</th><th>Début</th><th>Fin</th><th>Motif</th><th>Enregistré le</th></tr></thead>
                  <tbody>
                    {g.extensions.map((e) => (
                      <tr key={e.id}><td><span className={`status ${e.isRenewal ? 'status-vigilance' : 'status-normal'}`}>{e.kind}</span></td><td>{fr(e.startDate)}</td><td>{fr(e.endDate)}</td><td>{e.reason}</td><td>{frDateTime(e.createdAt)}</td></tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </>
        )}
      </div>

      {d.renewals.length > 0 && (
        <div className="cardx p-3 mb-3">
          <div className="section-title mb-2">Renouvellements</div>
          <div className="table-wrap"><table className="table table-sm">
            <thead><tr><th>Date</th><th>Numéro</th><th>Nouvelle période</th><th>Montant ajouté</th><th>Motif</th></tr></thead>
            <tbody>{d.renewals.map((x) => <tr key={x.id}><td className="text-nowrap">{fr(x.date)}</td><td className="text-nowrap">{x.previous_reference} → <strong>{x.new_reference}</strong></td><td className="text-nowrap">{fr(x.period_start)} → {fr(x.new_end)}</td><td className="text-money text-nowrap">{money(x.added_amount)}</td><td>{x.reason}</td></tr>)}</tbody>
          </table></div>
        </div>
      )}

      <div className="row g-3 mb-3">
        <div className="col-xl-6">
          <div className="cardx p-3 h-100">
            <div className="section-title mb-2">Avenants</div>
            {!d.amendments.length ? <Empty text="Aucun avenant." /> : (
              <div className="table-wrap"><table className="table table-sm">
                <thead><tr><th>Date</th><th>Durée</th><th>Ancienne fin</th><th>Nouvelle fin</th><th>Motif</th></tr></thead>
                <tbody>{d.amendments.map((a) => <tr key={a.id}><td>{fr(a.date)}</td><td>+{a.added_months} mois</td><td>{fr(a.previous_end)}</td><td>{fr(a.new_end)}</td><td>{a.reason}</td></tr>)}</tbody>
              </table></div>
            )}
          </div>
        </div>
        <div className="col-xl-6">
          <div className="cardx p-3 h-100">
            <div className="d-flex justify-content-between align-items-center mb-2">
              <div className="section-title">Documents du contrat</div>
              {canEdit && (
                <>
                  <input ref={file} type="file" accept=".pdf,.doc,.docx" hidden onChange={upload} />
                  <button className="btn btn-sm btn-outline-primary" onClick={() => file.current?.click()} disabled={busy}><i className="bi bi-upload me-1" /> Ajouter (PDF / Word)</button>
                </>
              )}
            </div>
            {!d.documents.length ? <Empty text="Aucun document dans le dossier du contrat." /> : (
              <div className="document-list">
                {d.documents.map((x) => (
                  <div className="document-item d-flex align-items-center justify-content-between gap-2 py-2 border-bottom" key={x.id}>
                    <div><i className="bi bi-file-earmark-text me-2" /><strong>{x.fileName}</strong><div className="small text-secondary">{size(x.size)} · {frDateTime(x.uploadedAt)}{x.uploadedBy ? ` · ${x.uploadedBy}` : ''}</div></div>
                    <div className="text-nowrap">
                      <button className="btn btn-sm btn-outline-primary" onClick={() => download(`/documents/${x.id}/download`, undefined, x.fileName).catch((e) => say(apiError(e), 'danger'))}><i className="bi bi-download" /></button>
                      {canEdit && <button className="btn btn-sm btn-outline-danger ms-1" onClick={() => window.confirm('Supprimer ce document ?') && act(() => api.delete(`/documents/${x.id}`), 'Document supprimé.')}><i className="bi bi-trash" /></button>}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      </div>

      <div className="row g-3 mb-4">
        <div className="col-xl-6">
          <div className="cardx p-3 h-100">
            <div className="section-title mb-2">Derniers paiements</div>
            {!d.payments.length ? <Empty text="Aucun paiement enregistré." /> : (
              <div className="table-wrap"><table className="table table-sm">
                <thead><tr><th>Date</th><th>Montant</th><th>Référence</th><th>Observation</th></tr></thead>
                <tbody>{d.payments.slice(0, 10).map((p) => <tr key={p.id}><td>{fr(p.date)}</td><td className="text-money">{money(p.amount)}</td><td>{p.reference || '—'}</td><td>{p.observation || '—'}</td></tr>)}</tbody>
              </table></div>
            )}
          </div>
        </div>
        <div className="col-xl-6">
          <div className="cardx p-3 h-100">
            <div className="section-title mb-2">Dernières situations d’avancement</div>
            {!d.entries.length ? <Empty text="Aucune situation enregistrée." /> : (
              <div className="table-wrap"><table className="table table-sm">
                <thead><tr><th>Date</th><th>Site</th><th>Avancement</th><th>Réalisation / observation</th></tr></thead>
                <tbody>{d.entries.slice(0, 10).map((e) => <tr key={e.id}><td>{fr(e.date)}</td><td>{e.site}</td><td>{num(e.progress, 1)} %</td><td className="small">{e.realization || e.observation || '—'}</td></tr>)}</tbody>
              </table></div>
            )}
          </div>
        </div>
      </div>

      {dialog === 'amend' && (
        <Modal title="Ajouter un avenant" subtitle={`Contrat ${c.number} — fin actuelle : ${fr(c.endDate)}`} size="md" onClose={() => setDialog(null)}
          footer={<><button className="btn btn-light" onClick={() => setDialog(null)}>Annuler</button><button className="btn btn-primary" disabled={busy} onClick={() => act(() => api.post(`/contracts/${id}/amendments`, amend))}>Enregistrer l’avenant</button></>}>
          <div className="row g-3">
            <Field className="col-md-6" label="Date de l’avenant"><input type="date" className="form-control" value={amend.date} onChange={(e) => setAmend({ ...amend, date: e.target.value })} /></Field>
            <Field className="col-md-6" label="Durée supplémentaire (mois)"><input type="number" min="1" className="form-control" value={amend.added_months} onChange={(e) => setAmend({ ...amend, added_months: e.target.value })} /></Field>
            <Field className="col-12" label="Motif"><textarea className="form-control" rows="3" value={amend.reason} onChange={(e) => setAmend({ ...amend, reason: e.target.value })} /></Field>
          </div>
        </Modal>
      )}
      {dialog === 'renew' && renew && (
        <Modal title="Renouveler le contrat" subtitle={`Contrat ${c.number} — fin actuelle : ${fr(c.endDate)}`} size="md" onClose={() => setDialog(null)}
          footer={<><button className="btn btn-light" onClick={() => setDialog(null)}>Annuler</button><button className="btn btn-success" disabled={busy} onClick={saveRenew}>Enregistrer le renouvellement</button></>}>
          <p className="small text-secondary">Le contrat garde sa fiche et son historique. Aucune nouvelle avance ni garantie n’est demandée ; les décomptes suivants continuent de retenir le taux de garantie en cours.</p>
          <div className="row g-3">
            <Field className="col-md-6" label="Nouveau numéro" error={renewErrors.reference}><input className="form-control" value={renew.reference} onChange={(e) => setRenew({ ...renew, reference: e.target.value })} /></Field>
            <Field className="col-md-6" label="Date du renouvellement" error={renewErrors.date}><input type="date" className="form-control" value={renew.date} onChange={(e) => setRenew({ ...renew, date: e.target.value })} /></Field>
            <Field className="col-md-6" label="Début de la nouvelle période" error={renewErrors.period_start}><input type="date" className="form-control" value={renew.period_start} onChange={(e) => setRenew({ ...renew, period_start: e.target.value })} /></Field>
            <Field className="col-md-6" label="Durée (mois)" error={renewErrors.months}><input type="number" min="1" className="form-control" value={renew.months} onChange={(e) => setRenew({ ...renew, months: e.target.value })} /></Field>
            <Field className="col-12" label="Montant ajouté (USD)" error={renewErrors.added_amount}><DecimalInput value={renew.added_amount} onChange={(v) => setRenew({ ...renew, added_amount: v })} placeholder="Ex. : 25000" /></Field>
            <Field className="col-12" label="Motif" error={renewErrors.reason}><textarea className="form-control" rows="2" value={renew.reason} onChange={(e) => setRenew({ ...renew, reason: e.target.value })} /></Field>
          </div>
          <p className="small mt-3 mb-0">
            {renew.period_start && Number(renew.months) > 0 ? <>Nouvelle fin : <strong>{fr(addMonths(renew.period_start, Number(renew.months)))}</strong>. </> : ''}
            Nouveau montant du contrat : <strong>{money(Number(c.amount) + (parseDecimal(renew.added_amount) || 0))} USD</strong>.
          </p>
        </Modal>
      )}
      {dialog === 'terminate' && (
        <ReasonDialog title="Résilier le contrat" text="La résiliation bloque les nouveaux suivis, paiements et avenants. L’opération est tracée dans le journal d’audit." confirmLabel="Résilier le contrat" onClose={() => setDialog(null)}
          extra={<Field label="Date de résiliation" className="mb-3"><input type="date" className="form-control" value={termDate} onChange={(e) => setTermDate(e.target.value)} /></Field>}
          onConfirm={wrap(async (reason) => { await api.post(`/contracts/${id}/terminate`, { termination_date: termDate, reason }); say('Le contrat a été marqué comme résilié.'); setDialog(null); await reload(); })} />
      )}
      {dialog === 'delete' && (
        <ReasonDialog title="Supprimer le contrat" text="Cette action supprime définitivement le contrat, ses sites, suivis, paiements, garantie et documents." confirmLabel="Supprimer définitivement" onClose={() => setDialog(null)}
          onConfirm={wrap(async (reason) => { await api.delete(`/contracts/${id}`, { data: { reason } }); nav('/contracts', { replace: true }); })} />
      )}
    </>
  );
}

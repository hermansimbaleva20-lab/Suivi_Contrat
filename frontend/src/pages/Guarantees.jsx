import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { api, apiError } from '../api.js';
import { useLoad } from '../lib/hooks.js';
import { fr, frDateTime, money, num, todayISO } from '../lib/format.js';
import { DecimalInput, Empty, Field, Kpi, Loading, Modal, Notice, PageHead, Progress } from '../components/ui.jsx';
import GuaranteeAlertsPanel from '../modules/guarantees/GuaranteeAlertsPanel.jsx';

export default function Guarantees() {
  const { data: d, loading, error, reload } = useLoad('/guarantees');
  const [dlg, setDlg] = useState(null); // { kind: 'edit'|'extend'|'renew'|'history', g, form }
  const [flash, setFlash] = useState({ type: 'success', text: '' });
  const [busy, setBusy] = useState(false);
  const [rev, setRev] = useState(0); // recharge les relances après une prolongation, un renouvellement ou une modification
  const [q, setQ] = useState('');
  const [page, setPage] = useState(1);
  const pageSize = 5;

  if (loading && !d) return <Loading />;
  if (!d) return <Notice>{error}</Notice>;
  const rows = d.data.filter((g) => `${g.contractNumber} ${g.company} ${g.bank}`.toLowerCase().includes(q.toLowerCase()));
  const pages = Math.max(1, Math.ceil(rows.length / pageSize));
  const pageRows = rows.slice((page - 1) * pageSize, page * pageSize);
  const say = (text, type = 'success') => setFlash({ type, text });

  const open = (kind, g) => {
    const base = {
      edit: { bank_name: g.bank, account_number: g.account, rate: String(g.rate).replace('.', ','), start_date: g.startDate, end_date: g.endDate, observation: g.observation },
      extend: { start_date: g.endDate, end_date: '', reason: '' },
      renew: { bank_name: g.bank, account_number: g.account, rate: String(g.rate).replace('.', ','), start_date: g.endDate, end_date: '', reason: '' },
      history: {},
    }[kind];
    setDlg({ kind, g, form: base });
  };
  const setForm = (k, v) => setDlg((s) => ({ ...s, form: { ...s.form, [k]: v } }));
  const submit = async () => {
    const { kind, g, form } = dlg;
    const path = { edit: ['put', `/guarantees/${g.id}`], extend: ['post', `/guarantees/${g.id}/extend`], renew: ['post', `/guarantees/${g.id}/renew`] }[kind];
    setBusy(true);
    try { const r = await api[path[0]](path[1], form); say(r.data.message); setDlg(null); await reload(); setRev((n) => n + 1); }
    catch (e) { say(apiError(e), 'danger'); setDlg(null); } finally { setBusy(false); }
  };

  return (
    <>
      <PageHead kicker="GARANTIES BANCAIRES" title="Garanties de bonne exécution" desc="Suivi des garanties bancaires : modification, renouvellement et prolongation de chaque garantie, avec historique complet." />
      <Notice type={flash.type} onClose={() => say('')}>{flash.text}</Notice>
      <div className="row g-3 mb-3">
        <div className="col-xl-3 col-md-6"><Kpi tone="green" label="GARANTIES SUIVIES" value={d.data.length} /></div>
        <div className="col-xl-3 col-md-6"><Kpi tone="orange" label="À SURVEILLER" value={d.alertCount} sub="Échues, proches ou > 80 %" /></div>
        <div className="col-xl-3 col-md-6"><Kpi tone="red" label="ÉCHUES" value={d.data.filter((g) => g.isExpired).length} /></div>
        <div className="col-xl-3 col-md-6"><Kpi label="MONTANT GARANTI" value={money(d.data.reduce((a, g) => a + g.amount, 0))} /></div>
      </div>
      <div className="cardx p-3 mb-4">
        <input className="form-control mb-3" style={{ maxWidth: 360 }} placeholder="Rechercher un contrat, une entreprise, une banque…" value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} />
        <div className="table-wrap">
          <table className="table table-hover align-middle">
            <thead><tr><th>Contrat</th><th>Entreprise</th><th>Banque / N° garantie</th><th>Taux</th><th>Montant</th><th>Période</th><th>Jours consommés</th><th>Mouvements</th><th>État</th><th>Actions</th></tr></thead>
            <tbody>
              {pageRows.map((g) => (
                <tr key={g.id}>
                  <td><Link className="contract-link" to={`/overview/${g.contractId}`}>{g.contractNumber}</Link></td>
                  <td>{g.company}</td>
                  <td>{g.bank || '—'}<div className="small text-secondary">{g.account}</div></td>
                  <td>{num(g.rate, 2)} %</td>
                  <td className="text-money">{money(g.amount)}</td>
                  <td className="small">{fr(g.startDate)} → <strong>{fr(g.endDate)}</strong>{!g.contractRenewed && !g.isExpired && g.daysToExpiry <= 30 && <div className="text-warning-emphasis">dans {g.daysToExpiry} j</div>}</td>
                  <td style={{ minWidth: 120 }}>{g.contractRenewed ? '—' : <><strong className={g.daysConsumedPercent > 80 ? 'text-danger' : ''}>{num(g.daysConsumedPercent)}%</strong><Progress value={g.daysConsumedPercent} danger={g.daysConsumedPercent > 80} /></>}</td>
                  <td><button className="btn btn-sm btn-light gm-history-btn" onClick={() => open('history', g)}><i className="bi bi-clock-history me-1" />{g.extensionsCount} prol. · {g.renewalsCount} renouv.</button></td>
                  <td><span className={`status status-${g.statusCss}`}>{g.needsAttention && <i className="bi bi-shield-exclamation me-1" />}{g.statusLabel}</span></td>
                  <td className="text-nowrap">
                    <button className="btn btn-sm btn-outline-primary" title="Modifier" onClick={() => open('edit', g)}><i className="bi bi-pencil" /></button>
                    <button className="btn btn-sm btn-outline-success ms-1" title="Renouveler" onClick={() => open('renew', g)}><i className="bi bi-arrow-repeat" /></button>
                    <button className="btn btn-sm btn-outline-secondary ms-1" title="Prolonger" onClick={() => open('extend', g)}><i className="bi bi-calendar-plus" /></button>
                  </td>
                </tr>
              ))}
              {!rows.length && <tr><td colSpan="10"><Empty text="Aucune garantie enregistrée." /></td></tr>}
            </tbody>
          </table>
        </div>
        <div className="dashboard-pager">
          <button className="btn btn-sm btn-light" disabled={page <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))}><i className="bi bi-chevron-left me-1" />Précédent</button>
          <span>Page {Math.min(page, pages)} sur {pages} · {rows.length} garantie(s)</span>
          <button className="btn btn-sm btn-light" disabled={page >= pages} onClick={() => setPage((p) => Math.min(pages, p + 1))}>Suivant<i className="bi bi-chevron-right ms-1" /></button>
        </div>
      </div>
      <GuaranteeAlertsPanel rev={rev} />

      {dlg && dlg.kind === 'history' && (
        <Modal title={`Historique de la garantie — ${dlg.g.contractNumber}`} subtitle={`${dlg.g.company} · échéance actuelle ${fr(dlg.g.endDate)}`} onClose={() => setDlg(null)}>
          {!dlg.g.extensions.length ? <Empty text="Aucun prolongement ni renouvellement enregistré." /> : (
            <div className="table-wrap"><table className="table">
              <thead><tr><th>Nature</th><th>Début</th><th>Fin</th><th>Motif</th><th>Enregistré le</th></tr></thead>
              <tbody>{dlg.g.extensions.map((e) => <tr key={e.id}><td><span className={`status ${e.isRenewal ? 'status-vigilance' : 'status-normal'}`}>{e.kind}</span></td><td>{fr(e.startDate)}</td><td>{fr(e.endDate)}</td><td>{e.reason}</td><td>{frDateTime(e.createdAt)}</td></tr>)}</tbody>
            </table></div>
          )}
        </Modal>
      )}
      {dlg && dlg.kind !== 'history' && (
        <Modal
          title={{ edit: 'Modifier la garantie', extend: 'Prolonger la garantie', renew: 'Renouveler la garantie' }[dlg.kind] + ` — ${dlg.g.contractNumber}`}
          subtitle={dlg.kind === 'renew' ? `La nouvelle garantie remplace l’actuelle (échéance au ${fr(dlg.g.endDate)}) ; l’ancienne reste dans l’historique.` : dlg.kind === 'extend' ? `La nouvelle fin doit dépasser l’échéance actuelle (${fr(dlg.g.endDate)}).` : undefined}
          onClose={() => setDlg(null)}
          footer={<><button className="btn btn-light" onClick={() => setDlg(null)}>Annuler</button><button className={`btn ${dlg.kind === 'renew' ? 'btn-success' : 'btn-primary'}`} disabled={busy} onClick={submit}>{{ edit: 'Enregistrer', extend: 'Enregistrer le prolongement', renew: 'Enregistrer le renouvellement' }[dlg.kind]}</button></>}
        >
          <div className="row g-3">
            {dlg.kind !== 'extend' && (
              <>
                <Field className="col-md-6" label="Banque émettrice"><input className="form-control" value={dlg.form.bank_name} onChange={(e) => setForm('bank_name', e.target.value)} /></Field>
                <Field className="col-md-6" label="N° garantie"><input className="form-control" value={dlg.form.account_number} onChange={(e) => setForm('account_number', e.target.value)} /></Field>
                <Field className="col-md-4" label="Taux (%)"><DecimalInput value={dlg.form.rate} onChange={(v) => setForm('rate', v)} /></Field>
              </>
            )}
            <Field className="col-md-4" label={dlg.kind === 'edit' ? 'Début' : dlg.kind === 'renew' ? 'Début de la nouvelle garantie' : 'Début du prolongement'}><input type="date" className="form-control" value={dlg.form.start_date} onChange={(e) => setForm('start_date', e.target.value)} /></Field>
            <Field className="col-md-4" label={dlg.kind === 'edit' ? 'Échéance' : 'Nouvelle échéance'}><input type="date" className="form-control" value={dlg.form.end_date} onChange={(e) => setForm('end_date', e.target.value)} /></Field>
            {dlg.kind === 'edit' ? (
              <Field className="col-12" label="Observation"><textarea className="form-control" rows="2" value={dlg.form.observation} onChange={(e) => setForm('observation', e.target.value)} /></Field>
            ) : (
              <Field className="col-12" label={dlg.kind === 'renew' ? 'Motif du renouvellement' : 'Motif du prolongement'}><textarea className="form-control" rows="3" value={dlg.form.reason} onChange={(e) => setForm('reason', e.target.value)} placeholder="Ex. : garantie arrivée à échéance, avenant de prolongation du contrat…" /></Field>
            )}
          </div>
        </Modal>
      )}
    </>
  );
}

import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { apiError, download } from '../api.js';
import { useLoad } from '../lib/hooks.js';
import { fr, money, num, statusClass } from '../lib/format.js';
import { Empty, Kpi, Loading, Notice, PageHead, Progress, StatusPill } from '../components/ui.jsx';
import { EvolutionChart, SiteChart, Pie3DChart } from '../components/Charts.jsx';
import { useAuth } from '../context/AuthContext.jsx';

const CELL = { Valide: 'gm-ok', 'Alerte >80%': 'gm-warn', 'Expirée': 'gm-bad', 'À venir': 'gm-future', 'Non constituée': 'gm-none' };
const SHORT = { Valide: 'OK', 'Alerte >80%': '>80', 'Expirée': 'Éch.', 'À venir': '…', 'Non constituée': '—' };
const GSTATUS = { 'Expirée': 'status-retard', 'Alerte >80%': 'status-vigilance', Valide: 'status-normal' };

export default function Reports() {
  const { can } = useAuth();
  const [filter, setFilter] = useState({ mode: 'current', date: '', from: '', to: '', show_all: false, contract_id: '' });
  const [applied, setApplied] = useState(filter);
  const [msg, setMsg] = useState('');
  const params = { ...applied, show_all: applied.show_all ? 1 : 0, contract_id: applied.contract_id || undefined, date: applied.date || undefined, from: applied.from || undefined, to: applied.to || undefined };
  const { data: d, loading, error } = useLoad('/reports', params);
  const set = (k) => (e) => setFilter((s) => ({ ...s, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value }));

  if (loading && !d) return <Loading />;
  if (!d) return <Notice>{error}</Notice>;
  const m = d.matrix;
  const label = d.mode === 'period' && d.from ? `du ${fr(d.from)} au ${fr(d.asOf)}` : `au ${fr(d.asOf)}`;
  const exportXlsx = async () => { try { await download('/reports/export', params, `rapport_${d.asOf}.xlsx`); } catch (e) { setMsg(apiError(e)); } };

  return (
    <>
      <PageHead kicker="ANALYSE ET SUIVI" title="Rapport de suivi" desc="Rapport consolidé par date ou par période, avec matrice des garanties de bonne exécution et histogrammes d’évolution.">
        <Link className="btn btn-success" to="/reports/synthese"><i className="bi bi-file-earmark-bar-graph me-1" /> Synthèse contrat</Link>
        <button className="btn btn-outline-success" onClick={exportXlsx}><i className="bi bi-file-earmark-excel me-1" /> Exporter le rapport</button>
      </PageHead>
      <Notice onClose={() => setMsg('')}>{msg}</Notice>

      <form className="cardx p-3 mb-3" onSubmit={(e) => { e.preventDefault(); setApplied(filter); }}>
        <div className="section-title mb-1">Situation à produire</div>
        <div className="section-subtitle mb-3">Le rapport ne présente que les contrats concernés : à une date précise, les contrats existants à cette date ; sur une période, les contrats actifs pendant la période.</div>
        <div className="row g-3 align-items-end">
          <div className="col-xl-3 col-md-4"><label className="form-label">Type de situation</label>
            <select className="form-select" value={filter.mode} onChange={set('mode')}>
              <option value="current">Situation actuelle (aujourd’hui)</option><option value="date">À une date précise</option><option value="period">Sur une période</option>
            </select>
          </div>
          {filter.mode === 'date' && <div className="col-xl-3 col-md-4"><label className="form-label">Date</label><input type="date" className="form-control" value={filter.date} onChange={set('date')} required /></div>}
          {filter.mode === 'period' && (
            <>
              <div className="col-xl-3 col-md-4"><label className="form-label">Du</label><input type="date" className="form-control" value={filter.from} onChange={set('from')} required /></div>
              <div className="col-xl-3 col-md-4"><label className="form-label">Au</label><input type="date" className="form-control" value={filter.to} onChange={set('to')} required /></div>
            </>
          )}
          <div className="col-xl-3 col-md-4"><label className="form-label">Contrat pour les histogrammes</label>
            <select className="form-select" value={filter.contract_id} onChange={set('contract_id')}><option value="">Premier contrat de la liste</option>{d.contracts.map((c) => <option key={c.id} value={c.id}>{c.number}</option>)}</select>
          </div>
          <div className="col-xl-1 col-md-4"><button className="btn btn-primary w-100" title="Appliquer"><i className="bi bi-search" /></button></div>
          <div className="col-12"><div className="form-check"><input className="form-check-input" type="checkbox" id="showAll" checked={filter.show_all} onChange={set('show_all')} /><label className="form-check-label" htmlFor="showAll">Afficher aussi les contrats hors de la date ou de la période</label></div></div>
        </div>
      </form>

      <div className="row g-3 mb-3">
        <div className="col-xl-3 col-md-6"><Kpi tone="green" label="CONTRATS DU RAPPORT" value={d.kpis.count} sub={d.showAll || d.mode === 'current' ? `Situation ${label}` : `sur ${d.totalContracts} · ${label}`} /></div>
        <div className="col-xl-3 col-md-6"><Kpi label="AVANCEMENT MOYEN" value={`${num(d.kpis.average, 1)} %`} /></div>
        <div className="col-xl-3 col-md-6"><Kpi tone="orange" label="VIGILANCE" value={d.kpis.vigilance} /></div>
        <div className="col-xl-3 col-md-6"><Kpi tone="red" label="RETARD" value={d.kpis.retard} sub={`${d.kpis.termine} terminé(s) · ${d.kpis.resilie} résilié(s)`} /></div>
      </div>

      <div className="cardx p-3 mb-3">
        <div className="section-title mb-1">Synthèse globale des contrats — répartition par statut</div>
        <div className="section-subtitle mb-3">Représentation en secteurs 3D de l’ensemble des contrats inclus dans le rapport filtré.</div>
        <Pie3DChart items={[
          { label: 'Normaux', value: d.rows.filter((r) => r.status === 'Normal').length, color: '#087a3f' },
          { label: 'Vigilance', value: d.rows.filter((r) => r.status === 'Vigilance').length, color: '#d98900' },
          { label: 'En retard', value: d.rows.filter((r) => r.status === 'Retard').length, color: '#c62828' },
          { label: 'Terminés', value: d.rows.filter((r) => r.status === 'Terminé').length, color: '#2f6db3' },
          { label: 'Résiliés', value: d.rows.filter((r) => r.status === 'Résilié').length, color: '#7a5bb5' },
        ]} />
      </div>

      <div className="cardx p-3 mb-3">
        <div className="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
          <div><div className="section-title">Rapport de suivi consolidé</div><div className="section-subtitle">Contrat, exécution, financement, avenants et résiliation {label}. La situation des garanties est présentée dans sa propre matrice ci-dessous.</div></div>
          <span className="badge-soft">{d.rows.length} contrat(s)</span>
        </div>
        <div className="table-wrap report-table-wrap">
          <table className="table table-hover report-table">
            <thead><tr><th>N° contrat</th><th>Entreprise</th><th>Catégorie</th><th>Province(s)</th><th>Site(s)</th><th>Démarrage</th><th>Fin</th><th>Montant</th><th>Avancement</th><th>Jours</th><th>Paiement</th><th>Statut</th><th>Total payé</th><th>Avenants</th><th>Dernière réalisation</th></tr></thead>
            <tbody>
              {d.rows.map((r) => (
                <tr key={r.contractId}>
                  <td><Link className="contract-link" to={`/overview/${r.contractId}`}>{r.number}</Link></td><td>{r.company}</td><td>{r.category}</td><td>{r.province}</td><td className="small">{r.sites}</td>
                  <td>{fr(r.startDate)}</td><td>{fr(r.endDate)}</td><td className="text-money">{money(r.amount)}</td>
                  <td style={{ minWidth: 110 }}><strong>{num(r.progress)}%</strong><Progress value={r.progress} /></td><td>{num(r.days)}%</td><td>{num(r.payment)}%</td>
                  <td><StatusPill status={r.status} /></td><td className="text-money">{money(r.paidIncludingInitial)}</td><td>{r.amendments}</td><td className="small">{r.latestRealization || '—'}</td>
                </tr>
              ))}
              {!d.rows.length && <tr><td colSpan="15"><Empty text="Aucun contrat ne correspond à la date ou à la période demandée." /></td></tr>}
            </tbody>
          </table>
        </div>
      </div>

      <div className="cardx p-3 mb-3 guarantee-matrix-card" id="matrice-garanties">
        <div className="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
          <div><div className="section-title"><i className="bi bi-shield-check me-1" /> Matrice des garanties de bonne exécution</div><div className="section-subtitle">Situation de chaque garantie au {fr(d.asOf)} et état mois par mois sur la période.</div></div>
          <span className="badge-soft">{m.rows.length} garantie(s)</span>
        </div>
        <div className="guarantee-mini-kpis mb-3">
          <div><span>Valides</span><strong>{m.valid}</strong></div><div className="warn"><span>Alerte &gt; 80 %</span><strong>{m.warn}</strong></div>
          <div className="bad"><span>Expirées</span><strong>{m.expired}</strong></div><div><span>Montant garanti en vigueur</span><strong style={{ fontSize: 15 }}>{money(m.amount)}</strong></div>
        </div>
        <div className="table-wrap report-table-wrap">
          <table className="table table-hover report-table gm-table">
            <thead>
              <tr><th colSpan="4" className="report-group-head">CONTRAT</th><th colSpan="7" className="report-group-head">GARANTIE AU {fr(d.asOf)}</th><th colSpan={Math.max(1, m.months.length)} className="report-group-head">ÉTAT MENSUEL</th></tr>
              <tr><th>N° contrat</th><th>Entreprise</th><th>Banque</th><th>Compte</th><th>Taux</th><th>Montant</th><th>Début</th><th>Échéance</th><th>Consommation</th><th>Mouvements</th><th>Statut</th>{m.months.map((x) => <th key={x} className="gm-month">{x}</th>)}</tr>
            </thead>
            <tbody>
              {m.rows.map((g) => (
                <tr key={g.contractId}>
                  <td><Link className="contract-link" to={`/overview/${g.contractId}`}>{g.number}</Link></td><td>{g.company}</td><td>{g.bank}</td><td>{g.account}</td>
                  <td>{num(g.rate, 2)}%</td><td className="text-money">{money(g.amount)}</td><td>{fr(g.start)}</td>
                  <td>{fr(g.end)}{g.daysLeft !== null && g.daysLeft >= 0 && g.daysLeft <= 30 && <small className="d-block text-warning-emphasis">dans {g.daysLeft} j</small>}</td>
                  <td><strong className={g.consumed >= 80 ? 'text-danger' : ''}>{num(g.consumed)}%</strong><Progress value={g.consumed} danger={g.consumed >= 80} /></td>
                  <td className="text-nowrap"><span className="gm-chip">{g.extensions} prol.</span> <span className="gm-chip">{g.renewals} renouv.</span></td>
                  <td><span className={`status ${GSTATUS[g.status] || 'status-termine'}`}>{g.status}</span></td>
                  {g.timeline.map((s, i) => <td key={i} className={`gm-cell ${CELL[s] || 'gm-none'}`} title={`${m.months[i]} — ${s}`}>{SHORT[s] || '—'}</td>)}
                </tr>
              ))}
              {!m.rows.length && <tr><td colSpan={11 + Math.max(1, m.months.length)}><Empty text="Aucune garantie pour les contrats de cette date ou période." /></td></tr>}
            </tbody>
          </table>
        </div>
        <div className="gm-legend mt-2">
          <span><i className="gm-dot gm-ok" />Valide</span><span><i className="gm-dot gm-warn" />Plus de 80 % consommés</span><span><i className="gm-dot gm-bad" />Expirée</span><span><i className="gm-dot gm-future" />À venir</span><span><i className="gm-dot gm-none" />Non constituée</span>
        </div>
      </div>

      {d.selected && (
        <div className="row g-3 mb-4">
          <div className="col-xl-7"><div className="cardx p-3 h-100"><div className="section-title">Évolution du contrat</div><div className="section-subtitle mb-2">Histogramme horizontal : avancement physique, jours consommés, taux de paiement et consommation de la garantie, période par période.</div><EvolutionChart rows={d.history} withGuarantee /></div></div>
          <div className="col-xl-5"><div className="cardx p-3 h-100"><div className="section-title">Évolution de chaque site</div><div className="section-subtitle mb-2">Au {d.sitePrevLabel} et au {d.siteCurLabel}.</div><SiteChart sites={d.siteEvolution} prevLabel={d.sitePrevLabel} curLabel={d.siteCurLabel} /></div></div>
        </div>
      )}
    </>
  );
}

import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { useLoad } from '../lib/hooks.js';
import { fr, money, num, statusClass } from '../lib/format.js';
import { Empty, Kpi, Loading, Notice, PageHead, Progress, StatusPill } from '../components/ui.jsx';
import ProvinceMap from '../components/ProvinceMap.jsx';
import { useAuth } from '../context/AuthContext.jsx';

export default function Dashboard() {
  const { data: d, loading, error } = useLoad('/dashboard');
  const { can } = useAuth();
  const [contractPage, setContractPage] = useState(1);
  const [guaranteePage, setGuaranteePage] = useState(1);
  if (loading && !d) return <Loading />;
  if (!d) return <Notice>{error}</Notice>;
  const g = d.guarantees;
  const pageSize = 5;
  const contractPages = Math.max(1, Math.ceil((d.contracts || []).length / pageSize));
  const guaranteePages = Math.max(1, Math.ceil((g.rows || []).length / pageSize));
  const contractRows = (d.contracts || []).slice((contractPage - 1) * pageSize, contractPage * pageSize);
  const guaranteeRows = (g.rows || []).slice((guaranteePage - 1) * pageSize, guaranteePage * pageSize);
  const dist = [['Normal', d.normal], ['Vigilance', d.vigilance], ['Retard', d.retard], ['Terminé', d.termines], ['Résilié', d.resilies]];

  return (
    <>
      <PageHead kicker="VUE D’ENSEMBLE" title="Dashboard" desc="Vue consolidée de l’exécution contractuelle, de l’avancement physique, du financement et des garanties.">
        {can('canViewReports') && <Link to="/reports" className="btn btn-primary">Ouvrir les rapports <i className="bi bi-arrow-right ms-1" /></Link>}
      </PageHead>

      <div className="row g-3 mb-3">
        <div className="col-xl-3 col-md-6"><Kpi tone="green" label="TOTAL DES CONTRATS" value={d.total} sub="Contrats enregistrés" /></div>
        <div className="col-xl-3 col-md-6"><Kpi label="CONTRATS NORMAUX" value={d.normal} sub="Situation normale" /></div>
        <div className="col-xl-3 col-md-6"><Kpi tone="orange" label="CONTRATS EN VIGILANCE" value={d.vigilance} sub="Écart à surveiller" /></div>
        <div className="col-xl-3 col-md-6"><Kpi tone="red" label="CONTRATS EN ALERTE" value={d.alertCount} sub="Retards, satisfaction et garanties" /></div>
      </div>

      <div className="cardx p-3 mb-3">
        <div className="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
          <div>
            <div className="section-title">Aperçu des contrats</div>
            <div className="section-subtitle">Aperçu paginé de 5 contrats par page. Le bouton « Détail » ouvre une page de consultation en lecture seule.</div>
          </div>
          <Link to="/contracts" className="btn btn-outline-primary btn-sm">Consulter tous les contrats</Link>
        </div>
        <div className="table-wrap">
          <table className="table table-hover align-middle dashboard-table">
            <thead>
              <tr><th>N° contrat</th><th>Entreprise</th><th>Catégorie</th><th>Province</th><th>Durée</th><th>Démarrage</th><th>Jours consommés</th><th>Avancement</th><th>Coût du contrat</th><th>Montant payé</th><th>Taux paiement</th><th>Montant restant</th><th>Statut</th><th /></tr>
            </thead>
            <tbody>
              {contractRows.map((c) => (
                <tr key={c.id}>
                  <td><strong>{c.number}</strong></td>
                  <td>{c.company}</td>
                  <td>{c.category || '—'}</td>
                  <td>{(c.provinces || []).join(', ') || c.province || '—'}</td>
                  <td>{c.periodMonths} mois</td>
                  <td>{fr(c.periodStart)}</td>
                  <td>{num(c.elapsed)}%</td>
                  <td style={{ minWidth: 130 }}><strong>{num(c.progress)}%</strong><Progress value={c.progress} /></td>
                  <td className="text-money">{money(c.amount)}</td>
                  <td className="text-money">{money(c.paidIncludingInitial)}</td>
                  <td><strong className={c.paymentRate > c.progress ? 'text-danger' : ''}>{num(c.paymentRate)}%</strong></td>
                  <td className={`text-money${c.remainingAmount < 0 ? ' text-danger' : ''}`}>{money(c.remainingAmount)}</td>
                  <td><StatusPill status={c.status} /></td>
                  <td><Link to={`/overview/${c.id}`} className="btn btn-sm btn-outline-primary">Détail</Link></td>
                </tr>
              ))}
              {!d.contracts.length && <tr><td colSpan="14"><Empty text="Aucun contrat enregistré pour cette phase." /></td></tr>}
            </tbody>
          </table>
        </div>
        <div className="dashboard-pager">
          <button className="btn btn-sm btn-light" disabled={contractPage <= 1} onClick={() => setContractPage((p) => Math.max(1, p - 1))}><i className="bi bi-chevron-left me-1" />Précédent</button>
          <span>Page {Math.min(contractPage, contractPages)} sur {contractPages} · {(d.contracts || []).length} contrat(s)</span>
          <button className="btn btn-sm btn-light" disabled={contractPage >= contractPages} onClick={() => setContractPage((p) => Math.min(contractPages, p + 1))}>Suivant<i className="bi bi-chevron-right ms-1" /></button>
        </div>
      </div>

      <div className="cardx p-3 mb-3 guarantee-overview">
        <div className="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
          <div>
            <div className="section-title"><i className="bi bi-shield-check me-1" /> Aperçu des garanties de bonne exécution</div>
            <div className="section-subtitle">Les garanties à surveiller apparaissent en premier : échues, proches de l’échéance ou au-delà de 80 % de la durée.</div>
          </div>
          {can('canManageGuarantees') && <Link to="/guarantees" className="btn btn-outline-primary btn-sm">Toutes les garanties</Link>}
        </div>
        <div className="guarantee-mini-kpis">
          <div><span>Garanties suivies</span><strong>{g.total}</strong></div>
          <div className="warn"><span>Échéance sous 30 jours</span><strong>{g.soon}</strong></div>
          <div className="warn"><span>Plus de 80 % consommés</span><strong>{g.over80}</strong></div>
          <div className="bad"><span>Échues</span><strong>{g.expired}</strong></div>
        </div>
        <div className="table-wrap">
          <table className="table table-hover align-middle">
            <thead><tr><th>Contrat</th><th>Entreprise</th><th>Banque</th><th>Montant garanti</th><th>Fin</th><th>Jours consommés</th><th>État</th></tr></thead>
            <tbody>
              {guaranteeRows.map((x) => {
                const danger = !x.contractRenewed && (x.isExpired || x.daysConsumedPercent > 80);
                return (
                  <tr key={x.id}>
                    <td><Link className="contract-link" to={`/overview/${x.contractId}`}>{x.contractNumber}</Link></td>
                    <td>{x.company || '—'}</td>
                    <td>{x.bank || '—'}</td>
                    <td className="fw-semibold text-money">{money(x.amount)}</td>
                    <td>{fr(x.endDate)}{!x.contractRenewed && !x.isExpired && x.daysToExpiry <= 30 && <div className="small text-secondary">dans {x.daysToExpiry} j</div>}</td>
                    <td style={{ minWidth: 130 }}><strong className={danger ? 'text-danger' : ''}>{num(x.daysConsumedPercent)}%</strong><Progress value={x.daysConsumedPercent} danger={danger} /></td>
                    <td><span className={`status status-${x.statusCss}`}>{x.statusLabel}</span></td>
                  </tr>
                );
              })}
              {!g.rows.length && <tr><td colSpan="7"><Empty text="Aucune garantie enregistrée pour cette phase." /></td></tr>}
            </tbody>
          </table>
        </div>
        <div className="dashboard-pager">
          <button className="btn btn-sm btn-light" disabled={guaranteePage <= 1} onClick={() => setGuaranteePage((p) => Math.max(1, p - 1))}><i className="bi bi-chevron-left me-1" />Précédent</button>
          <span>Page {Math.min(guaranteePage, guaranteePages)} sur {guaranteePages} · {(g.rows || []).length} garantie(s)</span>
          <button className="btn btn-sm btn-light" disabled={guaranteePage >= guaranteePages} onClick={() => setGuaranteePage((p) => Math.min(guaranteePages, p + 1))}>Suivant<i className="bi bi-chevron-right ms-1" /></button>
        </div>
      </div>

      <div className="row g-3 mb-3 dashboard-situation-row">
        <div className="col-xl-6">
          <div className="d-flex align-items-center mb-3 dashboard-section-heading">
            <div className="section-title">Situation générale des contrats</div>
          </div>
          <div className="cardx p-3 h-100 general-situation">
            {dist.map(([label, n]) => (
              <div className="mb-3" key={label}>
                <div className="d-flex justify-content-between"><span>{label}</span><strong>{n}</strong></div>
                <div className="progress-bar-prise"><span className={label === 'Retard' ? 'low' : ''} style={{ width: `${d.total ? (n / d.total) * 100 : 0}%` }} /></div>
              </div>
            ))}
            <div className="small text-secondary">Avancement physique moyen : <strong>{num(d.average, 1)} %</strong> · Fin de contrat sous 30 jours : <strong>{d.near}</strong></div>
          </div>
        </div>
        <div className="col-xl-6">
          <div className="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2 dashboard-section-heading">
            <div className="section-title">Alertes récentes</div>
            {(can('canViewAlertsContracts') || can('canViewAlertsGuarantees') || can('canViewAlertsLogistics')) && <Link to="/alerts" className="btn btn-outline-primary btn-sm">Centre des alertes</Link>}
          </div>
          <div className="cardx p-3 h-100">
            <div className="alert-list">
              {g.alerts.map((x) => (
                <Link key={`g${x.id}`} to="/alerts" className="alert-item">
                  <span className={`alert-item-icon ${x.isExpired ? 'danger' : 'warning'}`}><i className="bi bi-shield-exclamation" /></span>
                  <span>
                    <strong>{x.contractNumber} — {x.company}</strong>
                    <small>{x.isExpired ? `Garantie échue le ${fr(x.endDate)}` : x.isExpiringSoon ? `Garantie à renouveler avant le ${fr(x.endDate)}` : 'Garantie : plus de 80 % de la durée consommée'}</small>
                  </span>
                </Link>
              ))}
              {d.recentAlerts.map((c) => (
                <Link key={`c${c.id}`} to={`/overview/${c.id}`} className="alert-item">
                  <span className={`alert-item-icon ${c.status === 'Retard' ? 'danger' : 'success'}`}><i className={`bi ${c.status === 'Retard' ? 'bi-exclamation-triangle-fill' : 'bi-patch-check-fill'}`} /></span>
                  <span>
                    <strong>{c.number} — {c.company}</strong>
                    <small>{c.status === 'Retard' ? `Retard — avancement ${num(c.progress)} %, fin prévue ${fr(c.endDate)}` : `Satisfaction — avancement ${num(c.progress)} %`}</small>
                  </span>
                </Link>
              ))}
              {!g.alerts.length && !d.recentAlerts.length && <div className="alert alert-success mb-0"><i className="bi bi-check-circle me-2" />Aucune alerte de contrat ou de garantie pour le moment.</div>}
            </div>
          </div>
        </div>
      </div>

      <div className="cardx p-3 map-card">
        <div className="section-title">Provinces concernées</div>
        <div className="section-subtitle mb-3">Contrats et sites par province ; la couleur indique l’état le plus critique.</div>
        <div className="row g-3">
          <div className="col-xl-7"><ProvinceMap rows={d.provinceMap} /></div>
          <div className="col-xl-5">
            <div className="table-wrap">
              <table className="table table-sm align-middle">
                <thead><tr><th>Province</th><th>Contrats</th><th>Sites</th><th>Avancement</th></tr></thead>
                <tbody>
                  {d.provinceMap.map((p) => (
                    <tr key={p.name} title={`${p.categories} — ${p.sites}`}>
                      <td><strong>{p.name}</strong><div className="small text-secondary">{p.categories}</div></td>
                      <td>{p.contracts}</td>
                      <td>{p.siteCount}</td>
                      <td><span className={`status ${p.retard ? 'status-retard' : p.vigilance ? 'status-vigilance' : 'status-normal'}`}>{num(p.progress)}%</span></td>
                    </tr>
                  ))}
                  {!d.provinceMap.length && <tr><td colSpan="4"><Empty text="Aucune province renseignée." /></td></tr>}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </>
  );
}

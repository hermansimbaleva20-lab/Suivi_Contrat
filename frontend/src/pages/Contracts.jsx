import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { useLoad } from '../lib/hooks.js';
import { fr, money, num } from '../lib/format.js';
import { Empty, Kpi, Loading, Notice, PageHead, Pager, Progress, StatusPill } from '../components/ui.jsx';
import { useAuth } from '../context/AuthContext.jsx';

export default function Contracts() {
  const { can } = useAuth();
  const [page, setPage] = useState(1);
  const [q, setQ] = useState('');
  const [query, setQuery] = useState('');
  // 5 contrats par page ; « Précédent » et « Suivant » n'apparaissent que s'il y en a davantage.
  const { data, loading, error } = useLoad('/contracts', { page, size: 5, q: query });
  const rows = data?.data || [];
  const scope = query ? 'Résultats de la recherche' : 'Dans la phase active';

  const submit = (e) => { e.preventDefault(); setPage(1); setQuery(q); };
  const count = (s) => data?.statusCounts?.[s] ?? 0;

  return (
    <>
      <PageHead kicker="GESTION CONTRACTUELLE" title="Contrats" desc="Registre contractuel de la phase active. Les archives restent accessibles en sélectionnant une autre phase.">
        {(can('canManageContracts') || can('canManageStock')) && <Link to="/contracts/new" className="btn btn-primary"><i className="bi bi-plus-lg me-1" /> Nouveau contrat</Link>}
      </PageHead>
      <Notice>{error}</Notice>

      <div className="row g-3 mb-3">
        <div className="col-xl-3 col-md-6"><Kpi tone="green" label="CONTRATS NORMAUX" value={count('Normal')} sub={scope} /></div>
        <div className="col-xl-3 col-md-6"><Kpi tone="orange" label="VIGILANCE" value={count('Vigilance')} sub={scope} /></div>
        <div className="col-xl-3 col-md-6"><Kpi tone="red" label="RETARD" value={count('Retard')} sub={scope} /></div>
        <div className="col-xl-3 col-md-6"><Kpi label="TOTAL" value={data?.meta?.total ?? 0} sub={query ? 'Contrats trouvés' : 'Contrats de la phase'} /></div>

      </div>

      <div className="cardx p-3">
        <form className="filterbar d-flex gap-2 mb-3 flex-wrap" onSubmit={submit}>
          <input className="form-control" style={{ maxWidth: 380 }} placeholder="Rechercher : numéro, entreprise, province, site…" value={q} onChange={(e) => setQ(e.target.value)} />
          <button className="btn btn-primary"><i className="bi bi-search" /></button>
          {query && <button type="button" className="btn btn-light" onClick={() => { setQ(''); setQuery(''); setPage(1); }}>Effacer</button>}
        </form>
        {loading && !data ? <Loading /> : (
          <div className="table-wrap">
            <table className="table table-hover align-middle contracts-table">
              <thead>
                <tr><th>N° contrat</th><th>Entreprise</th><th>Catégorie</th><th>Province(s)</th><th>Sites</th><th>Période</th><th>Montant</th><th>Avancement</th><th>Jours</th><th>Paiement</th><th>Statut</th><th /></tr>
              </thead>
              <tbody>
                {rows.map((c) => (
                  <tr key={c.id}>
                    <td><Link className="contract-link" to={`/contracts/${c.id}`}>{c.number}</Link></td>
                    <td>{c.company}</td>
                    <td>{c.category || '—'}{c.goodsProcedureLabel && <div className="small text-secondary">{c.goodsProcedureLabel}{c.unitName ? ` · ${c.unitName}` : ''}</div>}</td>
                    <td>{(c.provinces || []).join(', ') || c.province || '—'}</td>
                    <td className="small">{(c.siteNames || []).join(', ') || '—'}</td>
                    <td className="small">{fr(c.periodStart)} → {fr(c.endDate)}<div className="text-secondary">{c.periodMonths} mois</div></td>
                    <td className="text-money">{c.stockOnly ? <span className="small">Min. {money(c.goodsMinAmount)}<br />Max. {money(c.goodsMaxAmount)}</span> : money(c.amount)}</td>
                    <td style={{ minWidth: 120 }}>{c.stockOnly ? '—' : <><strong>{num(c.progress)}%</strong><Progress value={c.progress} /></>}</td>
                    <td>{c.stockOnly ? '—' : `${num(c.elapsed)}%`}</td>
                    <td>{c.stockOnly ? '—' : <strong className={c.paymentRate > c.progress ? 'text-danger' : ''}>{num(c.paymentRate)}%</strong>}</td>
                    <td>{c.stockOnly ? <span className="status status-termine">Stock</span> : <StatusPill status={c.status} />}</td>
                    <td className="text-nowrap">
                      <Link to={`/contracts/${c.id}`} className="btn btn-sm btn-outline-primary" title="Gérer le contrat"><i className="bi bi-folder2-open" /></Link>
                      {c.canManage && !c.isTerminated && <Link to={`/contracts/${c.id}/edit`} className="btn btn-sm btn-outline-secondary ms-1" title="Modifier"><i className="bi bi-pencil" /></Link>}
                    </td>
                  </tr>
                ))}
                {!rows.length && <tr><td colSpan="12"><Empty text="Aucun contrat ne correspond à votre recherche." /></td></tr>}
              </tbody>
            </table>
          </div>
        )}
        <Pager meta={data?.meta} onPage={setPage} />
      </div>
    </>
  );
}

import React, { useEffect, useMemo, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { api, apiError } from '../api.js';
import { useLoad } from '../lib/hooks.js';
import { fr, money, num } from '../lib/format.js';
import { Kpi, Loading, Modal, Notice, PageHead, Progress } from '../components/ui.jsx';
import { useAuth } from '../context/AuthContext.jsx';
import AlertTemplatesPanel from '../modules/alerts/AlertTemplatesPanel.jsx';
import AlertHistoryPanel from '../modules/alerts/AlertHistoryPanel.jsx';
import LogisticsAlertsPanel from '../modules/alerts/LogisticsAlertsPanel.jsx';

/** Tableau d'alertes (contrats ou garanties), avec ses actions : consulter, gérer, envoyer. */
function AlertTable({ rows, empty, sending, onSend, canGuarantees }) {
  return (
    <div className="table-wrap">
      <table className="table alert-table align-middle">
        <thead><tr><th>Gravité</th><th>Type</th><th>Élément concerné</th><th>Détail</th><th>Indicateur</th><th>Échéance</th><th className="text-end">Actions</th></tr></thead>
        <tbody>
          {rows.map((r, i) => (
            <tr key={r.key} className={`alert-tr sev-${r.sev}`} style={{ '--i': Math.min(i, 12) }}>
              <td><span className={`sev-pill sev-${r.sev}`}><i className={`bi ${r.sev === 'critical' ? 'bi-x-octagon-fill' : r.sev === 'warning' ? 'bi-exclamation-circle-fill' : 'bi-info-circle-fill'}`} />{r.sevLabel}</span></td>
              <td><span className={`kind-chip kind-${r.kind}`}><i className={`bi ${r.icon}`} />{r.typeLabel}</span></td>
              <td><strong>{r.title}</strong><small className="d-block text-secondary">{r.sub}</small></td>
              <td><b>{r.detail}</b><small className="d-block text-secondary">{r.detail2}</small></td>
              <td className="alert-meter"><div className="meter-line"><span>{r.meter[0]}</span><strong className={r.meter[2] ? 'text-danger' : 'text-success'}>{num(r.meter[1])}%</strong></div><Progress value={r.meter[1]} danger={r.meter[2]} /></td>
              <td className="text-nowrap">{r.due}{r.dueSub && <small className={`d-block ${r.dueDanger ? 'text-danger' : 'text-secondary'}`}>{r.dueSub}</small>}</td>
              <td className="alert-actions-cell">
                <div className="alert-actions">
                  <Link className="btn btn-sm btn-light" to={`/overview/${r.contractId}`} title="Consulter" aria-label="Consulter"><i className="bi bi-eye" /></Link>
                  {r.kind === 'garantie' && canGuarantees && <Link className="btn btn-sm btn-outline-secondary" to="/guarantees" title="Gérer les garanties" aria-label="Gérer les garanties"><i className="bi bi-shield-check" /></Link>}
                  <button className="btn btn-sm btn-outline-warning" title="Voir et envoyer le message" aria-label="Voir et envoyer le message" disabled={sending === r.key} onClick={() => onSend(r)}><i className="bi bi-envelope" /></button>
                </div>
              </td>
            </tr>
          ))}
          {!rows.length && <tr><td colSpan="7" className="empty-state"><i className="bi bi-check-circle me-1" />{empty}</td></tr>}
        </tbody>
      </table>
    </div>
  );
}

export default function Alerts() {
  const { can, me } = useAuth();
  const { data: d, loading, error, reload } = useLoad('/alerts');
  const { hash } = useLocation();
  const seeContracts = can('canViewAlertsContracts');
  const seeGuarantees = can('canViewAlertsGuarantees');
  const seeLogistics = can('canViewAlertsLogistics');
  // Pas de can() : être administrateur ne suffit pas pour les modèles et l'historique.
  const seeHistory = me.user.permissions.canViewAlertHistory && (seeContracts || seeGuarantees);
  const [filter, setFilter] = useState('all');
  const [flash, setFlash] = useState({ type: 'success', text: '' });
  const [sending, setSending] = useState('');
  const [confirm, setConfirm] = useState(null); // { row, preview } : message à vérifier avant l'envoi
  const [rev, setRev] = useState(0); // recharge l'historique après un envoi

  const rows = useMemo(() => {
    if (!d) return [];
    const out = [];
    d.guarantees.forEach((g) => out.push({
      key: `g${g.id}`, kind: 'garantie', sev: g.isExpired ? 'critical' : 'warning', sevLabel: g.isExpired ? 'Critique' : 'À surveiller',
      icon: g.isExpired ? 'bi-shield-x' : 'bi-shield-exclamation', typeLabel: 'Garantie', title: g.contractNumber, sub: g.company, detail: g.statusLabel,
      detail2: `Garantie ${num(g.rate, 2)} % · ${money(g.amount)}${g.bank ? ` · ${g.bank}` : ''}`, meter: ['Jours consommés', g.daysConsumedPercent, g.daysConsumedPercent > 80],
      due: fr(g.endDate), dueSub: g.isExpired ? `échue depuis ${-g.daysToExpiry} j` : `dans ${g.daysToExpiry} j`, dueDanger: g.isExpired, contractId: g.contractId, send: { type: 'garantie', id: g.id },
    }));
    d.retards.forEach((c) => out.push({
      key: `r${c.id}`, kind: 'retard', sev: 'critical', sevLabel: 'Critique', icon: 'bi-exclamation-triangle-fill', typeLabel: 'Retard', title: c.number, sub: c.company, detail: 'Retard contractuel',
      detail2: `Avancement ${num(c.progress, 1)} % pour ${num(c.elapsed, 1)} % de jours consommés`, meter: ['Avancement', c.progress, true], due: fr(c.endDate), contractId: c.id, send: { type: 'retard', id: c.id },
    }));
    d.vigilances.forEach((c) => out.push({
      key: `v${c.id}`, kind: 'vigilance', sev: 'warning', sevLabel: 'À surveiller', icon: 'bi-hourglass-split', typeLabel: 'Vigilance', title: c.number, sub: c.company, detail: 'Exécution à surveiller',
      detail2: `Avancement ${num(c.progress, 1)} % pour ${num(c.elapsed, 1)} % de jours consommés`, meter: ['Avancement', c.progress, true], due: fr(c.endDate), contractId: c.id, send: { type: 'vigilance', id: c.id },
    }));
    d.sites.forEach((s) => out.push({
      key: `s${s.id}`, kind: 'site', sev: 'info', sevLabel: 'Information', icon: 'bi-patch-check-fill', typeLabel: 'Site à 100 %', title: s.name, sub: `Contrat ${s.contractNumber}`, detail: 'Satisfaction du site',
      detail2: 'Réalisation de l’ouvrage terminée', meter: ['Réalisation', 100, false], due: '—', contractId: s.contractId, send: { type: 'site', id: s.id },
    }));
    d.contracts.forEach((c) => out.push({
      key: `c${c.id}`, kind: 'contrat', sev: 'info', sevLabel: 'Information', icon: 'bi-trophy-fill', typeLabel: 'Contrat à 100 %', title: c.number, sub: c.company, detail: 'Satisfaction du contrat',
      detail2: c.category, meter: ['Avancement', 100, false], due: fr(c.endDate), contractId: c.id, send: { type: 'contrat', id: c.id },
    }));
    return out;
  }, [d]);

  // Le compteur de l'en-tête mène directement à la rubrique logistique.
  const loaded = !!d;
  useEffect(() => { if (loaded && hash === '#logistique') document.getElementById('logistique')?.scrollIntoView({ block: 'start' }); }, [loaded, hash]);

  if (loading && !d) return <Loading />;
  if (!d) return <Notice>{error}</Notice>;
  const n = (k) => rows.filter((r) => r.kind === k).length;
  // Deux tableaux : les alertes de contrats (retards, sites et contrats terminés) et celles des garanties de bonne exécution.
  const contractRows = rows.filter((r) => r.kind !== 'garantie');
  const guaranteeRows = rows.filter((r) => r.kind === 'garantie');
  const shown = filter === 'all' ? contractRows : contractRows.filter((r) => r.kind === filter);
  const sevCount = (list, s) => list.filter((r) => r.sev === s).length;
  const say = (text, type = 'success') => setFlash({ type, text });

  // Le message est d'abord affiché (destinataires, objet, texte tiré du modèle), puis envoyé sur confirmation.
  const send = async (r) => {
    setSending(r.key);
    try { const res = await api.post('/alerts/send', { ...r.send, preview: true }); setConfirm({ row: r, preview: res.data }); }
    catch (e) { say(apiError(e), 'danger'); } finally { setSending(''); }
  };
  const confirmSend = async () => {
    const r = confirm.row;
    setSending(r.key);
    try { const res = await api.post('/alerts/send', r.send); say(res.data.message); setConfirm(null); await reload(); }
    catch (e) { say(apiError(e), 'danger'); setConfirm(null); } finally { setSending(''); setRev((x) => x + 1); }
  };

  const filters = [['all', 'Toutes', contractRows.length, ''], ['retard', 'Retards', n('retard'), 'bi-exclamation-triangle-fill'], ['vigilance', 'Vigilance', n('vigilance'), 'bi-hourglass-split'], ['site', 'Sites à 100 %', n('site'), 'bi-patch-check-fill'], ['contrat', 'Contrats à 100 %', n('contrat'), 'bi-trophy-fill']];

  return (
    <>
      <PageHead kicker="SURVEILLANCE CONTRACTUELLE" title="Alertes" desc={seeContracts || seeGuarantees ? `Les alertes sont détectées dans le projet et la phase actifs.${seeHistory ? ' Les messages aux entreprises suivent les modèles ci-dessous et sont tracés dans l’historique des envois.' : ''}` : 'Stock, charroi et groupes électrogènes de votre périmètre : les produits concernés clignotent.'} />
      <Notice type={flash.type} onClose={() => say('')}>{flash.text}</Notice>
      {(seeContracts || seeGuarantees) && <div className="alert alert-info alert-policy"><i className="bi bi-shield-check me-2" /><strong>Envois aux entreprises.</strong> Les alertes des contrats partent automatiquement une fois par semaine, et les relances des garanties selon leur séquence (Finance › Garanties). Le bouton les envoie aussi à la demande, après vérification du message. Le personnel du projet reçoit son propre rappel, une fois par semaine.</div>}

      {(seeContracts || seeGuarantees) && <div className="row g-3 alert-kpis mb-4">
        {seeGuarantees && <div className="col-xl col-md-6"><Kpi tone="orange" label="GARANTIES À SURVEILLER" value={n('garantie')} sub="Échues, proches ou > 80 %" /></div>}
        {seeLogistics && <div className="col-xl col-md-6"><Kpi tone="red" label="ALERTES LOGISTIQUES" value={d.logistics.length} sub="Stock, charroi et groupes" /></div>}
        {seeContracts && <>
        <div className="col-xl col-md-6"><Kpi tone="red" label="RETARDS" value={n('retard')} sub={`Contrats nécessitant une action · ${n('vigilance')} en vigilance`} /></div>
        <div className="col-xl col-md-6"><Kpi tone="green" label="SITES À 100 %" value={n('site')} sub="Satisfaction à notifier" /></div>
        <div className="col-xl col-md-6"><Kpi tone="green" label="CONTRATS À 100 %" value={n('contrat')} sub="Satisfaction à notifier" /></div>
        </>}
      </div>}

      {seeContracts && <div className="cardx alert-table-card mb-4" id="contrats">
        <div className="alert-table-head">
          <div>
            <div className="section-title"><i className="bi bi-file-earmark-text-fill me-1" /> Alertes des contrats</div>
            <div className="section-subtitle">{contractRows.length} alerte(s) : {sevCount(contractRows, 'critical')} retard(s) critique(s), {sevCount(contractRows, 'warning')} en vigilance, {sevCount(contractRows, 'info')} satisfaction(s) à notifier. Les plus urgentes sont en tête.</div>
          </div>
          <div className="alert-filters" role="group" aria-label="Filtrer les alertes des contrats">
            {filters.map(([k, label, count, icon]) => (
              <button key={k} type="button" className={`alert-filter ${filter === k ? 'active' : ''}`} aria-pressed={filter === k} onClick={() => setFilter(k)}>{icon && <i className={`bi ${icon}`} />} {label} <b>{count}</b></button>
            ))}
          </div>
        </div>
        <AlertTable rows={shown} sending={sending} onSend={send} canGuarantees={can('canManageGuarantees')}
          empty={contractRows.length ? 'Aucune alerte de ce type.' : 'Aucune alerte de contrat pour le moment.'} />
      </div>}

      {seeGuarantees && <div className="cardx alert-table-card mb-4" id="garanties">
        <div className="alert-table-head">
          <div>
            <div className="section-title"><i className="bi bi-shield-exclamation me-1" /> Alertes des garanties de bonne exécution</div>
            <div className="section-subtitle">{guaranteeRows.length} garantie(s) à surveiller : {sevCount(guaranteeRows, 'critical')} échue(s), {sevCount(guaranteeRows, 'warning')} proche(s) de l’échéance ou au-delà de 80 % de leur durée.</div>
          </div>
        </div>
        <AlertTable rows={guaranteeRows} sending={sending} onSend={send} canGuarantees={can('canManageGuarantees')} empty="Aucune garantie à surveiller pour le moment." />
      </div>}

      {seeLogistics && <LogisticsAlertsPanel items={d.logistics} />}
      {seeHistory && <AlertTemplatesPanel />}
      {seeHistory && <AlertHistoryPanel rev={rev} />}

      {confirm && (
        <Modal
          title="Vérifier le message avant l’envoi" subtitle={`${confirm.row.typeLabel} · ${confirm.row.title}${confirm.preview.guaranteeNumber ? ` · garantie n° ${confirm.preview.guaranteeNumber}` : ''}`}
          onClose={() => setConfirm(null)}
          footer={<><button className="btn btn-light" onClick={() => setConfirm(null)}>Annuler</button><button className="btn btn-warning" disabled={!!sending || (!confirm.preview.to.length && confirm.row.kind !== 'site')} onClick={confirmSend}><i className="bi bi-send me-1" />Envoyer</button></>}
        >
          {!confirm.preview.to.length && confirm.row.kind !== 'site' && <div className="alert alert-warning py-2">L’entreprise {confirm.preview.company} n’a pas d’adresse e-mail : complétez sa fiche dans Entreprises avant l’envoi.</div>}
          <p className="small text-secondary mb-1">À : {confirm.preview.to.join(', ') || '—'}</p>
          <p className="fw-semibold mb-2">{confirm.preview.subject}</p>
          <div className="border rounded p-3 bg-light small" style={{ whiteSpace: 'pre-line', lineHeight: 1.6 }}>{confirm.preview.text}</div>
        </Modal>
      )}
    </>
  );
}

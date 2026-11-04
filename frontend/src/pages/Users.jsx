import React, { useState } from 'react';
import { api, apiError } from '../api.js';
import { useLoad } from '../lib/hooks.js';
import { frDateTime } from '../lib/format.js';
import { Loading, Modal, Notice, PageHead, Empty } from '../components/ui.jsx';
import { useAuth } from '../context/AuthContext.jsx';

const PERMS = [
  ['canManageSettings', 'Paramètres'], ['canManageUsers', 'Utilisateurs'], ['canDeleteContracts', 'Supprimer contrats'], ['canManageContracts', 'Gérer contrats'],
  ['canManageCompanies', 'Gérer entreprises'], ['canManageProgress', 'Gérer avancements'], ['canManagePayments', 'Gérer paiements'], ['canViewReports', 'Voir rapports'],
  ['canViewAlertsContracts', 'Alertes des contrats'], ['canViewAlertsGuarantees', 'Alertes de bonne exécution'], ['canViewSituation', 'Voir situation'], ['canViewArchives', 'Accéder aux archives'], ['canManageGuarantees', 'Gérer garanties'],
  ['canViewAlertsLogistics', 'Alertes logistiques (stock, charroi, groupes électrogènes)'],
  ['canViewStock', 'Consulter le stock'], ['canManageStock', 'Gérer le stock'],
  ['canViewFleet', 'Consulter le charroi et les groupes électrogènes'], ['canManageFleet', 'Gérer le charroi et les groupes électrogènes'],
  ['canValidateDecomptes', 'Valider les décomptes (chef de cellule infrastructures)'],
];
// Logistique : chaque unité (coordination nationale, antennes) la gère de manière autonome, dans son seul périmètre.
const LOGISTICS = ['canViewAlertsLogistics', 'canViewStock', 'canManageStock', 'canViewFleet', 'canManageFleet'];
// Autorisation des demandes logistiques : une case par poste, selon l'unité du compte (une seule qualité à la fois).
const AUTHORITIES = {
  POOL: [['CA', 'C.A — autorise les demandes logistiques de l’antenne']],
  COORDINATION: [['HRAF', 'HRAF — autorise les demandes logistiques de la coordination'], ['COMPTABLE', 'Comptable — autorise les demandes logistiques de la coordination']],
};
const AUTHORITY_LABEL = { CA: 'Autorise : C.A', HRAF: 'Autorise : HRAF', COMPTABLE: 'Autorise : comptable' };
const snake = (k) => k.replace(/[A-Z]/g, (m) => `_${m.toLowerCase()}`);
const chips = { canManageContracts: 'Contrats', canManageProgress: 'Avancement', canManagePayments: 'Paiements', canManageGuarantees: 'Garanties', canViewReports: 'Rapports', canViewAlertsContracts: 'Alertes contrats', canViewAlertsGuarantees: 'Alertes garanties', canViewAlertsLogistics: 'Alertes logistique', canViewSituation: 'Situation', canManageStock: 'Stocks', canManageFleet: 'Charroi', canAuthorizeFleet: 'Visa charroi' };

const ROLES = [
  ['UTILISATEUR', 'Utilisateur simple', 'Seulement les habilitations cochées ci-dessous.'],
  ['ADMINISTRATEUR', 'Administrateur', 'Toutes les habilitations, dans son unité seulement.'],
];
const roleLabel = (u) => (u.isSuperAdmin ? 'Super-administrateur' : u.isAdmin ? 'Administrateur' : 'Utilisateur simple');

export default function Users() {
  const { me } = useAuth();
  const { data, loading, error, reload } = useLoad('/users');
  const [flash, setFlash] = useState({ type: 'success', text: '' });
  const [perm, setPerm] = useState(null);
  const [temp, setTemp] = useState(null);
  const [assign, setAssign] = useState(null);
  const org = useLoad('/organization', {}, !!assign);
  if (loading && !data) return <Loading />;
  if (!data) return <Notice>{error}</Notice>;
  const say = (text, type = 'success') => setFlash({ type, text });
  const pending = data.users.filter((u) => u.isPending);
  const members = data.users.filter((u) => !u.isPending);
  const unread = data.resetRequests.filter((r) => !r.isRead).length;

  const call = async (fn, after) => {
    try { const r = await fn(); say(r.data.message); if (after) after(r.data); await reload(); } catch (e) { say(apiError(e), 'danger'); }
  };
  const saveAssign = () => call(() => api.put(`/users/${assign.id}/assignment`, { organizational_unit_id: assign.unitId || null, position_id: assign.positionId || null }), () => setAssign(null));
  const unitLabel = (u) => (u.unit ? u.unit.name : 'Non affecté');
  const savePerm = () => call(() => api.put(`/users/${perm.id}/permissions`, { ...Object.fromEntries(PERMS.map(([k]) => [snake(k), !!perm.permissions[k]])), can_view_pools: perm.canViewPools, logistics_authority: perm.authority || null, role: perm.role }), () => setPerm(null));
  // Hors super-admin, on n'affecte qu'à sa propre unité.
  const assignableUnits = (units) => (data.callerIsSuper ? units : units.filter((u) => me.user.unit && u.id === me.user.unit.id));

  return (
    <>
      <PageHead kicker="ADMINISTRATION" title="Utilisateurs & habilitations" desc={data.callerIsSuper
        ? 'Comptes du projet : validation des demandes, rôle (administrateur ou utilisateur simple), habilitations, vue nationale des pools et mots de passe temporaires.'
        : 'Comptes de votre unité : validation des demandes et mots de passe temporaires. Les habilitations sont octroyées par le super-administrateur.'} />
      <Notice type={flash.type} onClose={() => say('')}>{flash.text}</Notice>
      {temp && (
        <div className="temp-password-card mb-3">
          <strong><i className="bi bi-key-fill me-1" /> Mot de passe temporaire de {temp.user}</strong>
          <div><code>{temp.temporaryPassword}</code></div>
          <div className="small text-secondary">Communiquez-le par un canal sûr. Il n’est affiché qu’une seule fois ; l’utilisateur doit le remplacer via « Mot de passe oublié ».</div>
          <button className="btn btn-sm btn-light mt-2" onClick={() => setTemp(null)}>J’ai noté le mot de passe</button>
        </div>
      )}
      {pending.length > 0 && (
        <>
          <div className="pending-banner mb-3"><i className="bi bi-person-exclamation" /><div><strong>{pending.length} demande(s) d’inscription à valider</strong><div className="small">Les comptes en attente n’ont aucun accès tant que vous ne les approuvez pas.</div></div></div>
          <div className="cardx p-3 mb-3">
            <div className="section-title mb-3">Demandes d’inscription</div>
            <div className="table-wrap"><table className="table align-middle">
              <thead><tr><th>Utilisateur</th><th>Poste et unité demandés</th><th>Demande du</th><th /></tr></thead>
              <tbody>{pending.map((u) => (
                <tr key={u.id}><td><strong>{u.name}</strong><div className="small text-secondary">{u.email}</div></td><td>{u.title}<div className="small text-secondary"><i className="bi bi-geo-alt" aria-hidden="true" /> {unitLabel(u)}</div></td><td>{frDateTime(u.createdAt)}</td>
                  <td className="text-nowrap">
                    <button className="btn btn-sm btn-success" onClick={() => call(() => api.post(`/users/${u.id}/approve`))}><i className="bi bi-check-lg me-1" />Approuver</button>
                    <button className="btn btn-sm btn-outline-danger ms-1" onClick={() => window.confirm('Refuser et supprimer cette demande ?') && call(() => api.post(`/users/${u.id}/reject`))}><i className="bi bi-x-lg me-1" />Refuser</button>
                  </td></tr>
              ))}</tbody>
            </table></div>
          </div>
        </>
      )}
      {unread > 0 && <div className="alert alert-warning"><i className="bi bi-key-fill me-2" /><strong>{unread} demande(s) de réinitialisation</strong> : un lien à usage unique a été demandé.</div>}

      <div className="cardx p-3 mb-3">
        <div className="section-title mb-3">Notifications de sécurité</div>
        <div className="table-wrap"><table className="table">
          <thead><tr><th>Date</th><th>Utilisateur</th><th>E-mail</th><th>Résultat</th><th>État</th><th /></tr></thead>
          <tbody>
            {data.resetRequests.map((r) => (
              <tr key={r.id}><td>{frDateTime(r.date)}</td><td>{r.user}</td><td>{r.email}</td><td>{r.status}</td>
                <td><span className={`status ${r.isRead ? 'status-normal' : 'status-vigilance'}`}>{r.isRead ? 'Lue' : 'Nouvelle'}</span></td>
                <td>{!r.isRead && <button className="btn btn-sm btn-outline-primary" onClick={() => call(() => api.put(`/password-reset-requests/${r.id}/read`))}>Marquer comme lue</button>}</td></tr>
            ))}
            {!data.resetRequests.length && <tr><td colSpan="6"><Empty text="Aucune notification de réinitialisation." /></td></tr>}
          </tbody>
        </table></div>
      </div>

      <div className="cardx p-3 mb-4">
        <div className="section-title mb-3">Comptes du projet</div>
        <div className="table-wrap"><table className="table align-middle">
          <thead><tr><th>Utilisateur</th><th>Poste et unité</th><th>Rôle</th><th>Statut</th><th>Dernière connexion</th><th>Habilitations</th><th /></tr></thead>
          <tbody>
            {members.map((u) => {
              const locked = u.isSuperAdmin && !data.callerIsSuper;
              return (
                <tr key={u.id}>
                  <td><strong>{u.name}</strong>{u.isSuperAdmin && <span className="super-badge"><i className="bi bi-stars" />Super-admin</span>}<div className="small text-secondary">{u.email}</div></td>
                  {/* L'unité d'affectation sous le poste : on voit d'un coup d'œil à quelle unité appartient le compte. */}
                  <td>{u.title}<div className="small text-secondary"><i className="bi bi-geo-alt" aria-hidden="true" /> {u.unit ? u.unit.name : 'Non affecté'}</div></td>
                  <td>
                    <span className={`status ${u.isAdmin ? 'status-vigilance' : 'status-normal'}`}>{roleLabel(u)}</span>
                    {u.canViewPools && !u.isSuperAdmin && <div className="small text-secondary mt-1" title="Désigné par le super-administrateur : consultation de tous les pools, sans modification"><i className="bi bi-eye" /> Vue nationale des pools</div>}
                  </td>
                  <td><span className={`status ${u.isActive ? 'status-normal' : 'status-retard'}`}>{u.isActive ? 'Actif' : 'Désactivé'}</span>{u.lockedUntil && <div className="small text-danger mt-1"><i className="bi bi-lock-fill" /> Verrouillé jusqu’à {u.lockedUntil}</div>}</td>
                  <td>{u.lastLoginAt ? frDateTime(u.lastLoginAt) : 'Jamais'}</td>
                  <td><div className="permission-chips">{u.isSuperAdmin ? <span>Toutes</span> : Object.keys(chips).filter((k) => u.permissions[k] && k !== 'canAuthorizeFleet').map((k) => <span key={k}>{chips[k]}</span>)}{!u.isSuperAdmin && u.logisticsAuthority && <span>{AUTHORITY_LABEL[u.logisticsAuthority]}</span>}</div></td>
                  <td className="text-nowrap">
                    {locked ? <span className="small text-secondary">Protégé</span> : (
                      <>
                        <button className="btn btn-sm btn-outline-primary me-1" title="Affectation (unité et poste)" aria-label={`Affectation de ${u.name}`} onClick={() => setAssign({ id: u.id, name: u.name, unitId: u.unit ? u.unit.id : '', positionId: u.positionId || '' })}><i className="bi bi-diagram-3" /></button>
                        {!u.isSuperAdmin && data.callerIsSuper && <button className="btn btn-sm btn-outline-primary" title="Rôle et habilitations" aria-label={`Rôle et habilitations de ${u.name}`} onClick={() => setPerm({ id: u.id, name: u.name, title: u.title, unit: u.unit, role: u.isAdmin ? 'ADMINISTRATEUR' : 'UTILISATEUR', canViewPools: !!u.canViewPools, authority: u.logisticsAuthority || '', permissions: { ...u.permissions } })}><i className="bi bi-shield-check" /></button>}
                        <button className="btn btn-sm btn-outline-secondary ms-1" title="Mot de passe temporaire" onClick={() => window.confirm(`Générer un mot de passe temporaire pour ${u.name} ? Son mot de passe actuel sera remplacé.`) && call(() => api.post(`/users/${u.id}/admin-reset`), setTemp)}><i className="bi bi-key" /></button>
                        {!u.isSuperAdmin && u.id !== data.callerId && <button className={`btn btn-sm ms-1 ${u.isActive ? 'btn-outline-danger' : 'btn-outline-success'}`} onClick={() => call(() => api.post(`/users/${u.id}/toggle`))}>{u.isActive ? 'Désactiver' : 'Activer'}</button>}
                      </>
                    )}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table></div>
      </div>

      {assign && (
        <Modal title="Affectation" subtitle={assign.name} size="md" onClose={() => setAssign(null)}
          footer={<><button className="btn btn-light" onClick={() => setAssign(null)}>Annuler</button><button className="btn btn-primary" disabled={!org.data} onClick={saveAssign}>Enregistrer l’affectation</button></>}>
          {!org.data ? (org.error ? <Notice>{org.error}</Notice> : <Loading text="Chargement des unités…" />) : (
            <div className="row g-3">
              <div className="col-12">
                <label className="form-label" htmlFor="assign-unit">Unité</label>
                <select id="assign-unit" className="form-select" value={assign.unitId} onChange={(e) => setAssign({ ...assign, unitId: e.target.value, positionId: '' })}>
                  <option value="">Non affecté (vue du projet, aucune saisie par unité)</option>
                  {assignableUnits(org.data.units).map((u) => <option key={u.id} value={u.id} disabled={!u.isActive && String(u.id) !== String(assign.unitId)}>{u.type === 'COORDINATION' ? 'Coordination — ' : 'Pool — '}{u.name}{u.isActive ? '' : ' (désactivée)'}</option>)}
                </select>
                <div className="small text-secondary mt-1">Chaque unité gère son espace : le compte ne voit et ne saisit que les opérations de son unité. La consultation des pools au niveau national est accordée par le super-administrateur.</div>
              </div>
              <div className="col-12">
                <label className="form-label" htmlFor="assign-position">Poste</label>
                <select id="assign-position" className="form-select" value={assign.positionId} onChange={(e) => setAssign({ ...assign, positionId: e.target.value })}>
                  <option value="">Aucun poste du référentiel</option>
                  {org.data.positions.filter((p) => !p.unitId || String(p.unitId) === String(assign.unitId)).map((p) => <option key={p.id} value={p.id} disabled={!p.isActive && String(p.id) !== String(assign.positionId)}>{p.name}{p.isActive ? '' : ' (désactivé)'}</option>)}
                </select>
              </div>
            </div>
          )}
        </Modal>
      )}
      {perm && (
        <Modal title="Rôle et habilitations" subtitle={`${perm.name} — ${perm.title}${perm.unit ? ` (${perm.unit.name})` : ''}`} onClose={() => setPerm(null)}
          footer={<><button className="btn btn-light" onClick={() => setPerm(null)}>Annuler</button><button className="btn btn-primary" onClick={savePerm}>Enregistrer</button></>}>
          <fieldset className="mb-3">
            <legend className="section-title mb-2" style={{ fontSize: 15 }}>Rôle dans le système</legend>
            <div className="row g-2">
              {ROLES.map(([value, title, help]) => (
                <div className="col-md-6" key={value}>
                  <label className="permission-toggle h-100 align-items-start">
                    <input type="radio" name="perm-role" value={value} checked={perm.role === value} onChange={() => setPerm({ ...perm, role: value })} />
                    <span><strong>{title}</strong><span className="d-block small text-secondary">{help}</span></span>
                  </label>
                </div>
              ))}
            </div>
          </fieldset>
          <fieldset className="mb-3">
            <legend className="section-title mb-2" style={{ fontSize: 15 }}>Périmètre</legend>
            <label className="permission-toggle align-items-start">
              <input type="checkbox" checked={perm.canViewPools} onChange={(e) => setPerm({ ...perm, canViewPools: e.target.checked })} />
              <span><strong>Consulter les pools au niveau national</strong><span className="d-block small text-secondary">Lecture seule de tous les pools et de la coordination ; aucune modification ni suppression hors de sa propre unité.</span></span>
            </label>
          </fieldset>
          <fieldset>
            <legend className="section-title mb-2" style={{ fontSize: 15 }}>Habilitations</legend>
            {perm.role === 'ADMINISTRATEUR' && <div className="small text-secondary mb-2">Un administrateur dispose déjà de toutes les habilitations dans son unité. Les cases ci-dessous servent s’il redevient utilisateur simple.</div>}
            <div className="row g-2">
              {PERMS.filter(([k]) => !LOGISTICS.includes(k)).map(([k, label]) => (
                <div className="col-md-6" key={k}>
                  <label className="permission-toggle"><input type="checkbox" disabled={perm.role === 'ADMINISTRATEUR'} checked={perm.role === 'ADMINISTRATEUR' || !!perm.permissions[k]} onChange={(e) => setPerm({ ...perm, permissions: { ...perm.permissions, [k]: e.target.checked } })} /><span>{label}</span></label>
                </div>
              ))}
            </div>
          </fieldset>
          <fieldset className="mt-3">
            <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
              <legend className="section-title m-0 w-auto" style={{ fontSize: 15 }}>Logistique de l’unité{perm.unit ? ` — ${perm.unit.name}` : ''}</legend>
              {perm.role !== 'ADMINISTRATEUR' && (
                // À la coordination nationale, le logisticien voit aussi la logistique des antennes, en lecture seule.
                <button type="button" className="btn btn-sm btn-outline-primary" onClick={() => setPerm({
                  ...perm,
                  canViewPools: perm.unit?.type === 'COORDINATION' ? true : perm.canViewPools,
                  permissions: { ...perm.permissions, ...Object.fromEntries(LOGISTICS.map((k) => [k, true])) },
                })}>
                  <i className="bi bi-check2-all me-1" />{perm.unit?.type === 'COORDINATION' ? 'Accorder la logistique nationale et la vue des antennes' : 'Accorder toute la logistique'}
                </button>
              )}
            </div>
            <div className="small text-secondary mb-2">
              Stock, charroi et groupes électrogènes, dans l’unité du compte seulement : chaque unité gère sa logistique de manière autonome.
              {perm.unit?.type === 'COORDINATION' && ' Pour la coordination nationale, le bouton coche aussi « Consulter les pools au niveau national » : la situation logistique des antennes est alors visible en lecture seule, sans ajout ni modification.'}
            </div>

            <div className="row g-2">
              {PERMS.filter(([k]) => LOGISTICS.includes(k)).map(([k, label]) => (
                <div className="col-md-6" key={k}>
                  <label className="permission-toggle"><input type="checkbox" disabled={perm.role === 'ADMINISTRATEUR'} checked={perm.role === 'ADMINISTRATEUR' || !!perm.permissions[k]} onChange={(e) => setPerm({ ...perm, permissions: { ...perm.permissions, [k]: e.target.checked } })} /><span>{label}</span></label>
                </div>
              ))}
            </div>
            <div className="section-title mt-3 mb-1" style={{ fontSize: 14 }}>Autorisation des demandes logistiques</div>
            {perm.unit && AUTHORITIES[perm.unit.type] ? (
              <>
                <div className="small text-secondary mb-2">
                  {perm.unit.type === 'POOL' ? 'Dans une antenne, c’est le C.A qui autorise les demandes logistiques de son antenne.' : 'À la coordination nationale, ce sont le HRAF et le comptable qui autorisent les demandes logistiques de la coordination.'} Cochez le poste occupé par ce compte.
                </div>
                <div className="row g-2">
                  {AUTHORITIES[perm.unit.type].map(([value, label]) => (
                    <div className="col-md-6" key={value}>
                      <label className="permission-toggle">
                        <input type="checkbox" checked={perm.authority === value} onChange={(e) => setPerm({ ...perm, authority: e.target.checked ? value : '' })} />
                        <span>{label}</span>
                      </label>
                    </div>
                  ))}
                </div>
              </>
            ) : <div className="small text-secondary">Affectez d’abord ce compte à une unité pour lui confier l’autorisation des demandes logistiques.</div>}
          </fieldset>


        </Modal>

      )}
    </>
  );
}

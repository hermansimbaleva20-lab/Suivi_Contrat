import React, { useEffect, useState } from 'react';
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext.jsx';
import { Modal } from './ui.jsx';
import NotificationBell, { NotificationToast } from './NotificationBell.jsx';
import ThemeSwitch from './ThemeSwitch.jsx';
import { useNotifications } from '../lib/notifications.js';
import { FINANCE } from '../lib/navigation.js';

const fleet = (c) => c('canViewFleet') || c('canManageFleet') || c('canAuthorizeFleet');
const stock = (c) => c('canViewStock') || c('canManageStock');
const reports = (c) => c('canViewReports') || c('canViewSituation');

// [groupe, libellé, libellé court, chemin, icône, visibilité, actif seulement sur le chemin exact]
const NAV = [
  ['Suivi', 'Dashboard', 'Accueil', '/dashboard', 'grid-1x2-fill', () => true],
  ['Suivi', 'Contrats', 'Contrats', '/contracts', 'file-earmark-text-fill', () => true],
  ['Suivi', 'Suivi & Avancement', 'Suivi', '/progress', 'activity', (c) => c('canManageProgress') || c('canViewSituation')],
  // « Finance » regroupe paiements, décomptes et garanties ; elle ouvre la première sous-option accessible.
  ['Suivi', 'Finance', 'Finance', '/finance', 'wallet2', (c) => FINANCE.some((s) => s[3](c)), true, FINANCE],

  ['Analyse', 'Rapports & suivi', 'Rapports', '/reports', 'bar-chart-line-fill', reports, true, [
    ['Synthèse des contrats', '/reports/synthese', 'file-earmark-bar-graph', reports],
    ['États figés & comparaisons', '/reports/snapshots', 'clock-history', reports],
  ]],
  ['Analyse', 'Alertes', 'Alertes', '/alerts', 'bell-fill', (c) => c('canViewAlertsContracts') || c('canViewAlertsGuarantees') || c('canViewAlertsLogistics')],
  ['Analyse', 'Archives', 'Archives', '/archives', 'archive-fill', (c) => c('canViewArchives')],
  // Une seule option « Logistique » (tableau de bord), avec ses sous-options : [libellé, chemin, icône, visibilité].
  ['Opérations', 'Logistique', 'Logistique', '/logistique', 'boxes', (c) => stock(c) || fleet(c), true, [
    ['Stock', '/stock', 'box-seam', stock],
    ['Charroi', '/fleet', 'truck-front', fleet],
    ['Groupes électrogènes', '/generators', 'lightning-charge', fleet],
    ['Autorisations', '/logistique/autorisations', 'journal-check', (c) => c('canAuthorizeFleet')],
  ]],
  ['Administration', 'Entreprises', 'Entreprises', '/companies', 'building', (c) => c('canManageCompanies')],
  ['Administration', 'Utilisateurs', 'Utilisateurs', '/users', 'people-fill', (c) => c('canManageUsers')],
  ['Administration', 'Organisation', 'Organisation', '/organization', 'diagram-3-fill', (c) => c('canManageSettings')],
  ['Administration', 'Phases', 'Phases', '/phases', 'layers-fill', (c) => c('canManageSettings')],
  ['Administration', 'Paramètres', 'Réglages', '/settings', 'gear-fill', (c) => c('canManageSettings'), true],
  ['Administration', 'Alertes e-mail', 'E-mails', '/settings/alerts', 'envelope-paper-fill', (c, user) => !!user?.isSuperAdmin],
  ['Administration', 'Journal d’audit', 'Audit', '/audit', 'journal-text', (c) => c('canManageUsers') || c('canManageSettings')],
];

function AlertChips({ summary, perms }) {
  const a = summary || { contracts: 0, guarantees: 0, logistics: 0, security: 0 };
  const chip = (to, cls, icon, count, title, allowed) => {
    const inner = (
      <>
        <i className={`bi ${icon}`} />
        {allowed && count > 0 && <span className="chip-badge">{count}</span>}
      </>
    );
    if (!allowed) return <span className={`alert-chip ${cls} alert-chip-disabled`} title={`${title} (accès non autorisé)`}>{inner}</span>;
    return (
      <Link to={to} className={`alert-chip ${cls} ${count > 0 ? 'has-alert' : ''}`} title={`${title} : ${count}`} aria-label={`${title} : ${count}`}>
        {inner}
      </Link>
    );
  };
  return (
    <>
      {chip('/alerts', 'alert-chip-contract', 'bi-bell-fill', a.contracts, 'Alertes de contrats', perms.contracts)}
      {chip(perms.guarantees ? '/guarantees' : '/alerts', 'alert-chip-guarantee', 'bi-shield-exclamation', a.guarantees, 'Alertes de garanties', perms.guaranteeAlerts)}
      {chip('/alerts#logistique', 'alert-chip-logistics', 'bi-box-seam-fill', a.logistics, 'Alertes logistiques', perms.logistics)}
      {chip('/users', 'alert-chip-security', 'bi-key-fill', a.security, 'Sécurité et comptes', perms.users)}
    </>
  );
}

function PhaseSelect({ phases, phaseId, onChange, className, ariaLabel = 'Phase active' }) {
  return (
    <select className={className} value={phaseId || ''} onChange={(e) => onChange(Number(e.target.value))} aria-label={ariaLabel}>
      {phases.map((p) => (
        <option key={p.id} value={p.id}>
          Phase {p.number} — {p.name}{p.isArchived ? ' (archivée)' : ''}
        </option>
      ))}
    </select>
  );
}

export default function Layout() {
  const { me, can, logout, selectPhase } = useAuth();
  const nav = useNavigate();
  const loc = useLocation();
  const [menu, setMenu] = useState(false);
  const [logoutOpen, setLogoutOpen] = useState(false);

  useEffect(() => { setMenu(false); }, [loc.pathname]);

  const { user, project, phases, phaseId, alerts } = me;
  const code = project.code;
  const logo = code === 'PREDIRE' ? '/images/predire_logo.png' : '/images/prise2.png';
  const initials = (user.name || 'U').split(' ').filter(Boolean).map((p) => p[0]).slice(0, 2).join('').toUpperCase();
  const items = NAV.filter((n) => n[5](can, user));
  const groups = [...new Set(items.map((n) => n[0]))];
  const perms = { contracts: can('canViewAlertsContracts'), guaranteeAlerts: can('canViewAlertsGuarantees'), logistics: can('canViewAlertsLogistics'), guarantees: can('canManageGuarantees'), users: can('canManageUsers') };
  // Le poste occupé dans le projet tient lieu de rôle affiché (Coordonnateur National, C.A Kananga…).
  const userLine = user.title ? `${code} · ${user.title}` : code;
  const notifications = useNotifications();
  const doLogout = async () => { await logout(); nav('/login'); };

  return (
    <>
      <div className="app-shell">
        <aside className="sidebar">
          <div className="brand">
            <div className="brand-title">{project.name}</div>
            <div className="brand-code">{code}</div>
            <div className="brand-sub">SUIVI DES CONTRATS</div>
          </div>
          <div className="phase-switcher">
            <div className="phase-label"><i className="bi bi-layers-fill" /> Phase</div>
            <PhaseSelect className="phase-select" phases={phases} phaseId={phaseId} onChange={selectPhase} />
          </div>
          <nav className="side-nav" aria-label="Navigation principale">
            {groups.map((g) => (
              <React.Fragment key={g}>
                <div className="nav-section"><span>{g}</span></div>
                {items.filter((n) => n[0] === g).map((n) => {
                  const subs = (n[7] || []).filter((s) => s[3](can, user));
                  const inSection = loc.pathname === n[3] || loc.pathname.startsWith(`${n[3]}/`) || subs.some((s) => loc.pathname.startsWith(s[1]));
                  return (
                    <React.Fragment key={n[3]}>
                      <NavLink to={n[3]} end={!!n[6]} className={({ isActive }) => `nav-link ${isActive ? 'active' : inSection && subs.length ? 'open' : ''} ${subs.length ? 'has-subnav' : ''}`} title={n[1]} aria-label={n[1]} aria-expanded={subs.length ? inSection : undefined}>
                        <i className={`bi bi-${n[4]}`} />
                        <span className="nav-full">{n[1]}</span>
                        <span className="nav-short">{n[2]}</span>
                        {subs.length > 0 && <i className={`bi bi-chevron-${inSection ? 'down' : 'right'} nav-caret`} aria-hidden="true" />}
                      </NavLink>
                      {subs.length > 0 && inSection && (
                        <div className="nav-subnav" role="group" aria-label={`Sous-options — ${n[1]}`}>
                          {subs.map((s) => (
                            <NavLink key={s[1]} to={s[1]} className={({ isActive }) => `nav-link nav-sub ${isActive ? 'active' : ''}`} title={s[0]} aria-label={s[0]}>
                              <i className={`bi bi-${s[2]}`} />
                              <span className="nav-full">{s[0]}</span>
                            </NavLink>
                          ))}
                        </div>
                      )}
                    </React.Fragment>
                  );
                })}
              </React.Fragment>
            ))}
          </nav>
          <div className="sidebar-user" title={`${user.name} — ${user.title}${user.unit ? ` (${user.unit.name})` : ''} — ${code}`}>
            <div className="session-label">SESSION UTILISATEUR</div>
            <div className="su-row">
              <div className="avatar">{initials}</div>
              <div className="su-text">
                <div className="name">{user.name}</div>
                <div className="meta">{userLine}</div>
                {user.unit && <div className="meta meta-unit"><i className="bi bi-geo-alt-fill" aria-hidden="true" /> {user.unit.name}</div>}
              </div>
              <button type="button" className="logout-btn" onClick={() => setLogoutOpen(true)} title="Déconnexion" aria-label="Déconnexion">
                <i className="bi bi-box-arrow-right" /><span>Déconnexion</span>
              </button>
            </div>
          </div>
        </aside>

        <main className="main">
          <header className="topbar">
            <button className="mobile-menu" type="button" onClick={() => setMenu(true)} aria-label="Menu"><i className="bi bi-list" /></button>
            <img src={logo} className={`topbar-logo project-logo-${code.toLowerCase()}`} alt={code} />
            <div className="topbar-actions">
              <div className="topbar-alerts" role="group" aria-label="Notifications et alertes"><NotificationBell data={notifications} /><AlertChips summary={alerts} perms={perms} /></div>
              <ThemeSwitch />
              <form className="phase-pill-form" onSubmit={(e) => e.preventDefault()}>
                <i className="bi bi-layers-fill phase-pill-icon" />
                <PhaseSelect className="phase-pill-select" phases={phases} phaseId={phaseId} onChange={selectPhase} />
              </form>
            </div>
          </header>
          <div className="mobile-utility-strip" role="group" aria-label="Phase et alertes">
            <div className="mobile-utility-left">
              <form className="mobile-phase-pill" onSubmit={(e) => e.preventDefault()}>
                <i className="bi bi-layers-fill phase-pill-icon" />
                <PhaseSelect className="mobile-phase-select" phases={phases} phaseId={phaseId} onChange={selectPhase} />
              </form>
            </div>
            <div className="mobile-utility-right"><NotificationBell data={notifications} /><AlertChips summary={alerts} perms={perms} /><ThemeSwitch /></div>
          </div>
          <section className="content"><Outlet /></section>
          <NotificationToast data={notifications} />
        </main>
      </div>

      {menu && <div className="offcanvas-backdrop fade show" onClick={() => setMenu(false)} />}
      <div className={`offcanvas offcanvas-start prise-mobile-nav ${menu ? 'show' : ''}`} tabIndex="-1" id="mobileNav" style={menu ? { visibility: 'visible' } : undefined}>
        <div className="offcanvas-header">
          <img src={logo} className={`mobile-logo project-logo-${code.toLowerCase()}`} alt={code} />
          <button type="button" className="btn-close btn-close-white" aria-label="Fermer" onClick={() => setMenu(false)} />
        </div>
        <div className="offcanvas-body">
          <div className="mobile-session">
            <span className="mobile-avatar">{initials}</span>
            <div>
              <strong>{user.name}</strong>
              <small>{userLine}</small>
              {user.unit && <small>{user.unit.name}</small>}
            </div>
          </div>
          <div className="mobile-phase">
            <div className="phase-label"><i className="bi bi-layers-fill" /> Phase active</div>
            <PhaseSelect className="phase-select" phases={phases} phaseId={phaseId} onChange={selectPhase} />
          </div>
          {groups.map((g) => (
            <React.Fragment key={g}>
              <div className="mobile-nav-copy">{g}</div>
              {items.filter((n) => n[0] === g).map((n) => (
                <React.Fragment key={n[3]}>
                  <NavLink to={n[3]} end={!!n[6]} className={({ isActive }) => `mobile-nav-link ${isActive ? 'active' : ''}`}>
                    <i className={`bi bi-${n[4]}`} />{n[1]}
                  </NavLink>
                  {(n[7] || []).filter((s) => s[3](can, user)).map((s) => (
                    <NavLink key={s[1]} to={s[1]} className={({ isActive }) => `mobile-nav-link mobile-nav-sub ${isActive ? 'active' : ''}`}>
                      <i className={`bi bi-${s[2]}`} />{s[0]}
                    </NavLink>
                  ))}
                </React.Fragment>
              ))}
            </React.Fragment>
          ))}
          <button className="btn btn-outline-light w-100 mt-3" onClick={() => { setMenu(false); setLogoutOpen(true); }}>
            <i className="bi bi-box-arrow-right me-1" /> Déconnexion
          </button>
        </div>
      </div>

      {logoutOpen && (
        <Modal
          title="Déconnexion"
          size="md"
          onClose={() => setLogoutOpen(false)}
          footer={<><button className="btn btn-light" onClick={() => setLogoutOpen(false)}>Annuler</button><button className="btn btn-danger" onClick={doLogout}>Déconnexion</button></>}
        >
          Voulez-vous fermer votre session <strong>{code}</strong> ?
        </Modal>
      )}
    </>
  );
}

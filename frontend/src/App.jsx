import React, { useEffect, useState } from 'react';
import { Navigate, Route, Routes, useLocation } from 'react-router-dom';
import { useAuth } from './context/AuthContext.jsx';
import Layout from './components/Layout.jsx';
import { Loading } from './components/ui.jsx';
import { Login, Register, ForgotPassword, ResetPassword } from './pages/Auth.jsx';
import Dashboard from './pages/Dashboard.jsx';
import Contracts from './pages/Contracts.jsx';
import ContractForm from './pages/ContractForm.jsx';
import ContractDetail from './pages/ContractDetail.jsx';
import Progress from './pages/Progress.jsx';
import Payments from './pages/Payments.jsx';
import Guarantees from './pages/Guarantees.jsx';
import Reports from './pages/Reports.jsx';
import Alerts from './pages/Alerts.jsx';
import Archives from './pages/Archives.jsx';
import Companies from './pages/Companies.jsx';
import Users from './pages/Users.jsx';
import Phases from './pages/Phases.jsx';
import Settings from './pages/Settings.jsx';
import Audit from './modules/audit/AuditPage.jsx';
import ErrorPage from './pages/ErrorPage.jsx';
import OrganizationPage from './modules/organization/OrganizationPage.jsx';
import StockPage from './modules/stock/StockPage.jsx';
import StockCardPage from './modules/stock/StockCardPage.jsx';
import FleetPage from './modules/fleet/FleetPage.jsx';
import LogbookPage from './modules/fleet/LogbookPage.jsx';
import MaintenancePage from './modules/fleet/MaintenancePage.jsx';
import InsurancePage from './modules/fleet/InsurancePage.jsx';
import FleetReportPage from './modules/fleet/FleetReportPage.jsx';
import AuthorizationsPage from './modules/fleet/AuthorizationsPage.jsx';
import LogisticsDashboard from './modules/logistics/LogisticsDashboard.jsx';
import DecomptesPage from './modules/decomptes/DecomptesPage.jsx';
import ReportSnapshotsPage from './modules/reports/ReportSnapshotsPage.jsx';
import SynthesisPage from './modules/reports/SynthesisPage.jsx';
import AlertSettingsPage from './modules/alerts/AlertSettingsPage.jsx';
import AiSettingsPage from './modules/ai/AiSettingsPage.jsx';
import ContractExtractPage from './modules/ai/ContractExtractPage.jsx';
import OpeningLoader from './components/OpeningLoader.jsx';
import { FINANCE } from './lib/navigation.js';

const FLEET = ['canViewFleet', 'canManageFleet', 'canAuthorizeFleet'];
const STOCK = ['canViewStock', 'canManageStock'];

function Protected() {
  const { loading, me } = useAuth();
  const loc = useLocation();
  if (loading) return <div className="p-4"><Loading text="Ouverture de la session…" /></div>;
  if (!me) return <Navigate to="/login" replace state={{ from: loc.pathname }} />;
  return <Layout />;
}

/** « Finance » : ouvre la première sous-option accessible (paiements, décomptes, garanties). */
function FinanceHome() {
  const { can } = useAuth();
  const first = FINANCE.find((s) => s[3](can));
  return <Navigate to={first ? first[1] : '/dashboard'} replace />;
}

/** Affiche « accès refusé » si l'utilisateur n'a aucune des habilitations demandées. */
function Guard({ any, children }) {
  const { can } = useAuth();
  if (any && !any.some((p) => can(p))) {
    return (
      <div className="cardx p-4">
        <h4 className="mb-2"><i className="bi bi-lock-fill me-2" />Accès non autorisé</h4>
        <div className="text-secondary">Vous ne disposez pas de l’habilitation nécessaire pour ce module. Contactez l’administrateur de votre projet.</div>
      </div>
    );
  }
  return children;
}

/** Écrans réservés au super-administrateur (alertes par e-mail) ; l'API applique la même règle. */
function SuperAdminGuard({ children }) {
  const { me } = useAuth();
  if (!me?.user?.isSuperAdmin) {
    return (
      <div className="cardx p-4">
        <h4 className="mb-2"><i className="bi bi-lock-fill me-2" />Accès réservé</h4>
        <div className="text-secondary">Ce réglage est réservé au super-administrateur.</div>
      </div>
    );
  }
  return children;
}

export default function App() {
  const [showLoader, setShowLoader] = useState(true);
  useEffect(() => {
    const timer = window.setTimeout(() => setShowLoader(false), 6000);
    return () => window.clearTimeout(timer);
  }, []);
  return (
    <>
    <Routes>
      <Route path="/error" element={<ErrorPage />} />
      <Route path="/login" element={<Login />} />
      <Route path="/register" element={<Register />} />
      <Route path="/forgot-password" element={<ForgotPassword />} />
      <Route path="/reset-password" element={<ResetPassword />} />
      <Route element={<Protected />}>
        <Route path="/" element={<Navigate to="/dashboard" replace />} />
        <Route path="/dashboard" element={<Dashboard />} />
        <Route path="/contracts" element={<Contracts />} />
        <Route path="/contracts/new" element={<Guard any={['canManageContracts', 'canManageStock']}><ContractForm /></Guard>} />
        <Route path="/contracts/:id/edit" element={<Guard any={['canManageContracts', 'canManageStock']}><ContractForm /></Guard>} />
        <Route path="/contracts/:id" element={<ContractDetail />} />
        <Route path="/overview/:id" element={<ContractDetail readOnly />} />
        <Route path="/progress" element={<Guard any={['canManageProgress', 'canViewSituation']}><Progress /></Guard>} />
        <Route path="/finance" element={<FinanceHome />} />
        <Route path="/payments" element=
{<Guard any={['canManagePayments', 'canViewSituation']}><Payments /></Guard>} />
        <Route path="/guarantees" element={<Guard any={['canManageGuarantees']}><Guarantees /></Guard>} />
        <Route path="/reports" element={<Guard any={['canViewReports', 'canViewSituation']}><Reports /></Guard>} />
        <Route path="/reports/synthese" element={<Guard any={['canViewReports', 'canViewSituation']}><SynthesisPage /></Guard>} />
        <Route path="/reports/snapshots" element={<Guard any={['canViewReports', 'canViewSituation']}><ReportSnapshotsPage /></Guard>} />
        <Route path="/alerts" element={<Guard any={['canViewAlertsContracts', 'canViewAlertsGuarantees', 'canViewAlertsLogistics']}><Alerts /></Guard>} />
        <Route path="/archives" element={<Guard any={['canViewArchives']}><Archives /></Guard>} />
        <Route path="/companies" element={<Guard any={['canManageCompanies']}><Companies /></Guard>} />
        <Route path="/users" element={<Guard any={['canManageUsers']}><Users /></Guard>} />
        <Route path="/decomptes" element={<Guard any={['canManagePayments', 'canValidateDecomptes', 'canViewSituation']}><DecomptesPage /></Guard>} />
        <Route path="/logistique" element={<Guard any={[...STOCK, ...FLEET]}><LogisticsDashboard /></Guard>} />
        <Route path="/logistique/autorisations" element={<Guard any={['canAuthorizeFleet']}><AuthorizationsPage /></Guard>} />
        <Route path="/stock" element={<Guard any={STOCK}><StockPage view="etat" /></Guard>} />
        <Route path="/stock/catalogue" element={<Guard any={STOCK}><StockPage view="catalogue" /></Guard>} />
        <Route path="/stock/articles/:id" element={<Guard any={STOCK}><StockCardPage /></Guard>} />
        <Route path="/fleet" element={<Guard any={FLEET}><FleetPage kind="VEHICULE" /></Guard>} />
        <Route path="/fleet/logbook" element={<Guard any={FLEET}><LogbookPage kind="VEHICULE" /></Guard>} />
        <Route path="/fleet/maintenance" element={<Guard any={FLEET}><MaintenancePage kind="VEHICULE" /></Guard>} />
        <Route path="/fleet/insurance" element={<Guard any={FLEET}><InsurancePage /></Guard>} />
        <Route path="/fleet/reports" element={<Guard any={FLEET}><FleetReportPage kind="VEHICULE" /></Guard>} />
        <Route path="/fleet/generators" element={<Navigate to="/generators/logbook" replace />} />
        <Route path="/generators" element={<Guard any={FLEET}><FleetPage kind="GROUPE" /></Guard>} />
        <Route path="/generators/logbook" element={<Guard any={FLEET}><LogbookPage kind="GROUPE" /></Guard>} />
        <Route path="/generators/maintenance" element={<Guard any={FLEET}><MaintenancePage kind="GROUPE" /></Guard>} />
        <Route path="/generators/reports" element={<Guard any={FLEET}><FleetReportPage kind="GROUPE" /></Guard>} />
        <Route path="/organization" element={<Guard any={['canManageSettings']}><OrganizationPage /></Guard>} />
        <Route path="/phases" element={<Guard any={['canManageSettings']}><Phases /></Guard>} />
        <Route path="/settings/ai" element={<SuperAdminGuard><AiSettingsPage /></SuperAdminGuard>} />
        <Route path="/contracts/ai-extract" element={<Guard any={['canManageContracts', 'canManageCompanies']}><ContractExtractPage /></Guard>} />
        <Route path="/settings/alerts" element={<SuperAdminGuard><AlertSettingsPage /></SuperAdminGuard>} />
        <Route path="/settings" element={<Guard any={['canManageSettings']}><Settings /></Guard>} />
        <Route path="/audit" element={<Guard any={['canManageUsers', 'canManageSettings']}><Audit /></Guard>} />
      </Route>
      <Route path="*" element={<Navigate to="/dashboard" replace />} />
    </Routes>
    {showLoader && <OpeningLoader />}
    </>
  );
}

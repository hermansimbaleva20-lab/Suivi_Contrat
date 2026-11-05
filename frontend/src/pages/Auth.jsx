import React, { useEffect, useState } from 'react';
import { Link, useLocation, useNavigate, useSearchParams } from 'react-router-dom';
import { api, apiError } from '../api.js';
import { useAuth } from '../context/AuthContext.jsx';
import { Notice } from '../components/ui.jsx';
import '../styles/login-motion.css';

const PROJECTS = {
  PRISE: {
    name: 'Projet de Renforcement des Infrastructures Socio-Économiques dans la Zone Centre de la RDC',
    desc: 'Systèmes d’AEP • Écoles primaires • Centres de santé • Espaces à vivre • Latrines publiques',
  },
  PREDIRE: {
    name: 'Programme Régional d’Appui au Développement des Infrastructures et de Valorisation des Ressources en Eau Transfrontalières entre la RCA et la RDC',
    desc: 'Systèmes d’AEP solaires et thermiques • Renforcement d’expérience opérationnelle  • Campagnes d\'IEC pour le changement de comportements',
  },
};

/* Fond des pages de connexion, de demande d'accès et de mot de passe oublié : la photo reprend sa dérive là où elle en était (login-motion.css). */
function AuthPage({ children }) {
  const [drift] = useState(() => `-${Math.round(performance.now())}ms`);
  return <div className="login-bg" style={{ '--login-drift': drift }}><div className="login-page">{children}</div></div>;
}

/* Autour de la carte : ondes qui s'en éloignent, tracé du contour à l'arrivée, puis lueur qui en fait le tour. */
function CardHalo() {
  const edge = { x: 0, y: 0, width: '100%', height: '100%', rx: 22, pathLength: 100 };
  return (
    <span className="card-halo" aria-hidden="true">
      <i /><i /><i />
      <svg className="card-trace">
        <defs>
          <linearGradient id="card-trace-water" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" stopColor="#10b981" />
            <stop offset=".45" stopColor="#14b8a6" />
            <stop offset=".75" stopColor="#d4a72c" />
            <stop offset="1" stopColor="#22c55e" />
          </linearGradient>
        </defs>
        <rect className="trace-draw" {...edge} />
      </svg>
      <b className="card-glint" />
    </span>
  );
}

function Seal() {
  return <img src="/images/logo-min.jpg" alt="Sceau de la République démocratique du Congo" width="84" height="84" className="login-seal" />;
}

function Hero({ code, title }) {
  const p = PROJECTS[code] || PROJECTS.PRISE;
  return (
    <section className="login-hero">
      <div className="prise-word">{code}</div>
      <h2>{title || p.name}</h2>
      <div className="eyebrow">SUIVI DU CONTRAT ET GARANTIE DE BONNE EXECUTION</div>
      <p className="mt-3">{p.desc}</p>
    </section>
  );
}

function ProjectSelect({ value, onChange }) {
  return (
    <>
      <label className="form-label">Projet</label>
      <select className="form-select mb-3" value={value} onChange={(e) => onChange(e.target.value)} required>
        {Object.keys(PROJECTS).map((c) => <option key={c} value={c}>{c} — {PROJECTS[c].name}</option>)}
      </select>
    </>
  );
}

function PasswordField({ label, value, onChange, autoComplete }) {
  const [show, setShow] = useState(false);
  return (
    <>
      <label className="form-label">{label}</label>
      <div className="password-wrap mb-3">
        <input className="form-control" type={show ? 'text' : 'password'} value={value} onChange={(e) => onChange(e.target.value)} autoComplete={autoComplete} required />
        <button type="button" className="password-toggle" aria-label="Afficher le mot de passe" onClick={() => setShow(!show)}><i className={`bi ${show ? 'bi-eye-slash' : 'bi-eye'}`} /></button>
      </div>
    </>
  );
}

export function Login() {
  const { login } = useAuth();
  const nav = useNavigate();
  const loc = useLocation();
  const [project, setProject] = useState(localStorage.getItem('prise_project') || 'PRISE');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const info = loc.state?.message;

  const submit = async (e) => {
    e.preventDefault();
    if (busy) return;
    setBusy(true);
    setError('');
    try {
      await login(email, password, project);
      nav(loc.state?.from || '/dashboard', { replace: true });
    } catch (err) {
      setError(apiError(err));
      setBusy(false);
    }
  };

  return (
    <AuthPage>
        <Hero code={project} />
      <section className="login-card">
        <CardHalo />
        <div className="text-center mb-4">
          <Seal />
          <h2 className="mt-2 mb-1">Bienvenue</h2>
          <div className="text-secondary">Connectez-vous à votre espace du projet PRISE ou programme PREDIRE</div>
        </div>
        {info && <div className="alert alert-info small">{info}</div>}
        <form onSubmit={submit}>
          <Notice>{error}</Notice>
          <ProjectSelect value={project} onChange={setProject} />
          <label className="form-label">Adresse e-mail</label>
          <input className="form-control mb-3" type="email" value={email} onChange={(e) => setEmail(e.target.value)} autoComplete="username" autoFocus required />
          <PasswordField label="Mot de passe" value={password} onChange={setPassword} autoComplete="current-password" />
          <button type="submit" className="btn btn-primary w-100" disabled={busy}>{busy ? <><span className="spinner-border spinner-border-sm" aria-hidden="true" />CONNEXION…</> : 'SE CONNECTER'}</button>
        </form>
        <div className="d-flex justify-content-between mt-4 pt-3 border-top">
          <Link to="/forgot-password" style={{ color: '#087a3f', fontWeight: 750 }}>Mot de passe oublié ?</Link>
          <Link to="/register" style={{ color: '#087a3f', fontWeight: 750 }}>Demander un accès</Link>
        </div>
      </section>
    </AuthPage>
  );
}

export function Register() {
  const nav = useNavigate();
  const [f, setF] = useState({ project: localStorage.getItem('prise_project') || 'PRISE', name: '', email: '', project_position: '', organizational_unit_id: '', position_id: '', password: '', password_confirmation: '' });
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [options, setOptions] = useState({ units: [], positions: [] });
  const set = (k) => (v) => setF((s) => ({ ...s, [k]: v }));

  // Unités et postes gérés par l'administration du projet choisi (TDR §5).
  useEffect(() => {
    let alive = true;
    setF((s) => ({ ...s, organizational_unit_id: '', position_id: '' }));
    api.get('/auth/registration-options', { params: { project: f.project } })
      .then(({ data }) => { if (alive) setOptions(data); })
      .catch(() => { if (alive) setOptions({ units: [], positions: [] }); });
    return () => { alive = false; };
  }, [f.project]);

  const submit = async (e) => {
    e.preventDefault();
    if (f.password !== f.password_confirmation) { setError('Les deux mots de passe ne correspondent pas.'); return; }
    setBusy(true);
    setError('');
    try {
      const { data } = await api.post('/auth/register', f, { headers: { 'X-Project': f.project } });
      nav('/login', { state: { message: data.message } });
    } catch (err) {
      setError(apiError(err));
      setBusy(false);
    }
  };

  const text = (label, key, props = {}) => (
    <div className="col-md-6">
      <label className="form-label">{label}</label>
      <input className="form-control" value={f[key]} onChange={(e) => set(key)(e.target.value)} required {...props} />
    </div>
  );

  return (
    <AuthPage>
      <Hero code={f.project} title="Demande d’accès à un projet" />
      <section className="login-card">
        <CardHalo />
        <div className="text-center mb-3"><Seal /><h2 className="mt-2 mb-1">Demande d’accès</h2><div className="text-secondary">Votre compte sera activé après validation par l’administrateur du projet.</div></div>
        <form onSubmit={submit}>
          <Notice>{error}</Notice>
          <ProjectSelect value={f.project} onChange={set('project')} />
          <div className="row g-3 mb-3">
            {text('Nom complet', 'name', { autoComplete: 'name' })}
            {text('Adresse e-mail', 'email', { type: 'email', autoComplete: 'email' })}
            {options.units.length > 0 && (
              <div className="col-md-6">
                <label className="form-label" htmlFor="reg-unit">Unité</label>
                <select id="reg-unit" className="form-select" value={f.organizational_unit_id} onChange={(e) => setF((s) => ({ ...s, organizational_unit_id: e.target.value, position_id: '' }))} required>
                  <option value="">Choisissez votre unité…</option>
                  {options.units.map((u) => <option key={u.id} value={u.id}>{u.type === 'COORDINATION' ? 'Coordination — ' : 'Pool — '}{u.name}</option>)}
                </select>
              </div>
            )}
            {options.positions.length > 0 ? (
              <div className="col-md-6">
                <label className="form-label" htmlFor="reg-position">Poste dans le projet</label>
                {/* Seuls les postes prévus pour l'unité choisie (et les postes communs à toutes les unités) sont proposés. */}
                <select id="reg-position" className="form-select" value={f.position_id} onChange={(e) => set('position_id')(e.target.value)} required disabled={options.units.length > 0 && !f.organizational_unit_id}>
                  <option value="">{options.units.length > 0 && !f.organizational_unit_id ? 'Choisissez d’abord votre unité' : 'Choisissez votre poste…'}</option>
                  {options.positions.filter((p) => !p.unitId || String(p.unitId) === String(f.organizational_unit_id)).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                </select>
              </div>
            ) : text('Poste dans le projet', 'project_position')}
          </div>
          <PasswordField label="Mot de passe (8 caractères minimum)" value={f.password} onChange={set('password')} autoComplete="new-password" />
          <PasswordField label="Confirmation" value={f.password_confirmation} onChange={set('password_confirmation')} autoComplete="new-password" />
          <button type="submit" className="btn btn-primary w-100" disabled={busy}>{busy ? <><span className="spinner-border spinner-border-sm" aria-hidden="true" />ENVOI…</> : 'ENVOYER MA DEMANDE'}</button>
        </form>
        <div className="text-center mt-4 pt-3 border-top"><Link to="/login" style={{ color: '#087a3f', fontWeight: 750 }}>← Retour à la connexion</Link></div>
      </section>
    </AuthPage>
  );
}

export function ForgotPassword() {
  const [project, setProject] = useState(localStorage.getItem('prise_project') || 'PRISE');
  const [email, setEmail] = useState('');
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const submit = async (e) => {
    e.preventDefault();
    setBusy(true);
    setError('');
    try {
      const { data } = await api.post('/auth/forgot-password', { email, project }, { headers: { 'X-Project': project } });
      setMessage(data.message);
    } catch (err) {
      setError(apiError(err));
    } finally { setBusy(false); }
  };
  return (
    <AuthPage>
      <Hero code={project} title="Réinitialisation du mot de passe" />
      <section className="login-card">
        <CardHalo />
        <div className="text-center mb-4"><Seal /><h2 className="mt-2 mb-1">Mot de passe oublié</h2><div className="text-secondary">Recevoir un lien de réinitialisation valable 30 minutes</div></div>
        {message ? <div className="alert alert-success">{message}</div> : (
          <form onSubmit={submit}>
            <Notice>{error}</Notice>
            <ProjectSelect value={project} onChange={setProject} />
            <label className="form-label">Adresse e-mail</label>
            <input className="form-control mb-3" type="email" value={email} onChange={(e) => setEmail(e.target.value)} autoFocus required />
            <div className="alert alert-info small">Pour votre sécurité, le message est identique que le compte existe ou non. Si le serveur d’envoi n’est pas configuré, demandez à votre administrateur de générer un mot de passe temporaire.</div>
            <button type="submit" className="btn btn-primary w-100" disabled={busy}>ENVOYER LE LIEN</button>
          </form>
        )}
        <div className="text-center mt-4 pt-3 border-top"><Link to="/login" style={{ color: '#087a3f', fontWeight: 750 }}>← Retour à la connexion</Link></div>
      </section>
    </AuthPage>
  );
}

export function ResetPassword() {
  const [params] = useSearchParams();
  const nav = useNavigate();
  const project = (params.get('project') || 'PRISE').toUpperCase();
  const token = params.get('token') || '';
  const [valid, setValid] = useState(null);
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  React.useEffect(() => {
    api.get('/auth/reset-check', { params: { project, token }, headers: { 'X-Project': project } }).then((r) => setValid(r.data.valid)).catch(() => setValid(false));
  }, [project, token]);

  const submit = async (e) => {
    e.preventDefault();
    if (password !== confirm) { setError('Les deux mots de passe ne correspondent pas.'); return; }
    setBusy(true);
    setError('');
    try {
      const { data } = await api.post('/auth/reset-password', { project, token, password, password_confirmation: confirm }, { headers: { 'X-Project': project } });
      localStorage.setItem('prise_project', project);
      nav('/login', { state: { message: data.message } });
    } catch (err) {
      setError(apiError(err));
      setBusy(false);
    }
  };

  return (
    <div className="login-page">
      <Hero code={project} title="Nouveau mot de passe" />
      <section className="login-card">
        <div className="text-center mb-4"><h2 className="mt-2 mb-1">Définir mon mot de passe</h2><div className="text-secondary">8 caractères minimum. Ce lien ne peut être utilisé qu’une seule fois.</div></div>
        {valid === false ? (
          <>
            <div className="alert alert-danger"><strong>Lien invalide ou expiré.</strong><div className="small mt-1">Les liens sont valables 30 minutes et ne servent qu’une fois.</div></div>
            <Link to="/forgot-password" className="btn btn-primary w-100">DEMANDER UN NOUVEAU LIEN</Link>
          </>
        ) : (
          <form onSubmit={submit}>
            <Notice>{error}</Notice>
            <PasswordField label="Nouveau mot de passe" value={password} onChange={setPassword} autoComplete="new-password" />
            <PasswordField label="Confirmation" value={confirm} onChange={setConfirm} autoComplete="new-password" />
            <button type="submit" className="btn btn-primary w-100" disabled={busy || valid === null}>ENREGISTRER LE MOT DE PASSE</button>
          </form>
        )}
        <div className="text-center mt-4 pt-3 border-top"><Link to="/login" style={{ color: '#087a3f', fontWeight: 750 }}>← Retour à la connexion</Link></div>
      </section>
    </div>
  );
}

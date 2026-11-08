import React, { useState } from 'react';
import { api, apiError, fieldErrors } from '../api.js';
import { Modal, Notice, SubmitButton } from './ui.jsx';
import { animateModalOut } from '../lib/modalMotion.js';

function Secret({ id, label, value, onChange, autoComplete, error, hint }) {
  const [show, setShow] = useState(false);
  return (
    <div className="mb-3">
      <label className="form-label" htmlFor={id}>{label}</label>
      <div className="password-wrap">
        <input id={id} className={`form-control${error ? ' is-invalid' : ''}`} type={show ? 'text' : 'password'} value={value} onChange={(e) => onChange(e.target.value)} autoComplete={autoComplete} />
        <button type="button" className="password-toggle" aria-label={show ? 'Masquer le mot de passe' : 'Afficher le mot de passe'} onClick={() => setShow(!show)}><i className={`bi ${show ? 'bi-eye-slash' : 'bi-eye'}`} /></button>
      </div>
      {error ? <div className="invalid-feedback d-block">{error}</div> : hint && <div className="form-text">{hint}</div>}
    </div>
  );
}

/**
 * Changement du mot de passe dans sa session. L'API ferme toutes les sessions du compte : on revient à la connexion,
 * avec un message, pour se reconnecter avec le nouveau mot de passe.
 */
export default function ChangePasswordDialog({ onClose }) {
  const [f, setF] = useState({ current_password: '', password: '', password_confirmation: '' });
  const [state, setState] = useState('idle');
  const [error, setError] = useState('');
  const [errors, setErrors] = useState({});
  const set = (k) => (v) => setF((x) => ({ ...x, [k]: v }));

  const save = async () => {
    if (state !== 'idle') return;
    setState('busy'); setError(''); setErrors({});
    let message;
    try {
      message = (await api.post('/auth/password', f)).data.message;
    } catch (e) {
      const fe = fieldErrors(e) || {};
      setErrors(fe);
      setError(Object.keys(fe).length ? '' : apiError(e));
      setState('idle');
      return;
    }
    setState('done');
    await new Promise((r) => setTimeout(r, 650));
    await animateModalOut();
    try { localStorage.removeItem('prise_token'); localStorage.removeItem('prise_phase'); sessionStorage.setItem('prise_login_info', message); } catch { /* stockage indisponible */ }
    window.location.assign('/login');
  };
  const first = (k) => (Array.isArray(errors[k]) ? errors[k][0] : errors[k]);

  return (
    <Modal title="Modifier mon mot de passe" subtitle="Toutes vos sessions seront fermées : vous vous reconnecterez avec le nouveau mot de passe." size="md" onClose={state === 'idle' ? onClose : undefined}
      footer={<>
        {error && <div className="modal-error"><Notice>{error}</Notice></div>}
        <button className="btn btn-light" disabled={state !== 'idle'} onClick={onClose}>Annuler</button>
        <SubmitButton state={state} disabled={!f.current_password || !f.password || !f.password_confirmation} onClick={save}>Modifier le mot de passe</SubmitButton>
      </>}>
      <form onSubmit={(e) => { e.preventDefault(); save(); }}>
        <Secret id="pw-current" label="Mot de passe actuel" value={f.current_password} onChange={set('current_password')} autoComplete="current-password" error={first('current_password')} />
        <Secret id="pw-new" label="Nouveau mot de passe" value={f.password} onChange={set('password')} autoComplete="new-password" error={first('password')} hint="10 caractères au moins, avec des lettres et des chiffres." />
        <Secret id="pw-confirm" label="Confirmer le nouveau mot de passe" value={f.password_confirmation} onChange={set('password_confirmation')} autoComplete="new-password" error={first('password_confirmation')} />
        <button type="submit" hidden aria-hidden="true" tabIndex={-1} />
      </form>
    </Modal>
  );
}

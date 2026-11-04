import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api, apiError, download } from '../api.js';
import { useLoad } from '../lib/hooks.js';
import { frDateTime } from '../lib/format.js';
import { Field, Loading, Notice, PageHead } from '../components/ui.jsx';
import ProjectRecipients from '../modules/alerts/ProjectRecipients.jsx';

const mo = (b) => (b > 1048576 ? `${(b / 1048576).toFixed(1)} Mo` : `${Math.max(1, Math.round(b / 1024))} Ko`);

export default function Settings() {
  const { data, loading, error, reload } = useLoad('/settings');
  const [f, setF] = useState(null);
  const [flash, setFlash] = useState({ type: 'success', text: '' });
  const [busy, setBusy] = useState('');
  useEffect(() => { if (data) setF({ host: data.smtp.host, port: data.smtp.port, sender_email: data.smtp.senderEmail, password: '', use_ssl: data.smtp.useSsl }); }, [data]);
  if (loading && !data) return <Loading />;
  if (!data || !f) return <Notice>{error}</Notice>;
  const say = (text, type = 'success') => setFlash({ type, text });
  const set = (k) => (e) => setF((s) => ({ ...s, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value }));
  const run = async (name, fn, refresh) => { setBusy(name); try { const r = await fn(); say(r.data.message); if (refresh) await reload(); } catch (e) { say(apiError(e), 'danger'); } finally { setBusy(''); } };

  return (
    <>
      <PageHead kicker="ADMINISTRATION" title="Paramètres" desc="Serveur d’envoi des e-mails (alertes et réinitialisation des mots de passe) et sauvegarde de la base de données." />
      <Notice type={flash.type} onClose={() => say('')}>{flash.text}</Notice>
      <div className="row g-3 mb-4">
        <div className="col-xl-7">
          <form className="cardx form-card p-3" onSubmit={(e) => { e.preventDefault(); run('save', () => api.put('/settings/smtp', { ...f, port: Number(f.port) }), true); }}>
            <div className="section-title mb-1">Paramètres SMTP</div>
            <div className="section-subtitle mb-3">Les mêmes paramètres servent aux alertes envoyées manuellement et aux liens de réinitialisation de mot de passe.</div>
            <div className="row g-3">
              <Field className="col-md-8" label="Serveur SMTP"><input className="form-control" value={f.host} onChange={set('host')} required /></Field>
              <Field className="col-md-4" label="Port"><input type="number" className="form-control" value={f.port} onChange={set('port')} required /></Field>
              <Field className="col-md-6" label="Adresse d’expédition (identifiant)"><input type="email" className="form-control" value={f.sender_email} onChange={set('sender_email')} required /></Field>
              <Field className="col-md-6" label="Mot de passe (ou mot de passe d’application)"><input type="password" className="form-control" autoComplete="new-password" value={f.password} onChange={set('password')} placeholder={data.smtp.hasPassword ? '•••••••• (laisser vide pour conserver)' : ''} /><div className="form-text">Stocké chiffré ; jamais réaffiché.</div></Field>
              <div className="col-12"><div className="form-check"><input className="form-check-input" type="checkbox" id="ssl" checked={f.use_ssl} onChange={set('use_ssl')} /><label className="form-check-label" htmlFor="ssl">Connexion chiffrée (STARTTLS sur 587, SSL sur 465)</label></div></div>
              <div className="col-12 d-flex gap-2 justify-content-end">
                <button type="button" className="btn btn-outline-secondary" disabled={!!busy} onClick={() => run('test', () => api.post('/settings/smtp/test'))}><i className="bi bi-send me-1" />Envoyer un e-mail de test</button>
                <button className="btn btn-primary" disabled={!!busy}><i className="bi bi-check-lg me-1" />Enregistrer</button>
              </div>
            </div>
          </form>
          <ProjectRecipients recipients={data.recipients} positions={data.positions} canEdit={data.canEditRecipients} busy={!!busy} onChanged={async (text) => { say(text); await reload(); }} onError={(text) => say(text, 'danger')} />
        </div>
        <div className="col-xl-5">
          <div className="cardx p-3 mb-3">
            <div className="section-title mb-1">Sauvegarde de la base</div>
            <div className="section-subtitle mb-3">Génère un fichier SQL compressé couvrant les deux projets.</div>
            {data.isSuperAdmin ? (
              <>
                <button className="btn btn-primary mb-3" disabled={!!busy} onClick={() => run('backup', () => api.post('/settings/backup'), true)}><i className="bi bi-database-down me-1" /> Lancer une sauvegarde maintenant</button>
                {!data.backups.length ? <div className="small text-secondary">Aucune sauvegarde enregistrée.</div> : (
                  <div className="table-wrap"><table className="table table-sm">
                    <thead><tr><th>Fichier</th><th>Date</th><th>Taille</th><th /></tr></thead>
                    <tbody>{data.backups.map((b) => <tr key={b.name}><td className="small">{b.name}</td><td className="small">{frDateTime(b.date)}</td><td className="small">{mo(b.size)}</td><td><button className="btn btn-sm btn-outline-primary" onClick={() => download(`/settings/backups/${b.name}`, undefined, b.name).catch((e) => say(apiError(e), 'danger'))}><i className="bi bi-download" /></button></td></tr>)}</tbody>
                  </table></div>
                )}
              </>
            ) : <div className="alert alert-secondary small mb-0"><i className="bi bi-lock-fill me-1" />La sauvegarde manuelle est réservée au super-administrateur : elle couvre les deux projets.</div>}
          </div>
          <div className="cardx p-3"><div className="section-title mb-1">Journal d’audit</div><div className="section-subtitle mb-2">Traçabilité de toutes les opérations du projet.</div><Link to="/audit" className="btn btn-outline-primary btn-sm">Ouvrir le journal</Link></div>
        </div>
      </div>
    </>
  );
}

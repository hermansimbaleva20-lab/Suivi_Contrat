import React, { useState } from 'react';
import { api, apiError } from '../api.js';
import { useLoad } from '../lib/hooks.js';
import { Empty, Field, Loading, Modal, Notice, PageHead, SubmitButton } from '../components/ui.jsx';
import { animateModalOut } from '../lib/modalMotion.js';
import { useAuth } from '../context/AuthContext.jsx';

export default function Phases() {
  const { data, loading, error, reload } = useLoad('/phases');
  const { me } = useAuth();
  const [form, setForm] = useState(null);
  const [submit, setSubmit] = useState({ state: 'idle', error: '' });
  const [flash, setFlash] = useState({ type: 'success', text: '' });
  if (loading && !data) return <Loading />;
  if (!data) return <Notice>{error}</Notice>;
  const isSuper = !!me?.user?.isSuperAdmin;
  const say = (text, type = 'success') => setFlash({ type, text });
  const call = async (fn) => { try { const r = await fn(); say(r.data.message); await reload(); } catch (e) { say(apiError(e), 'danger'); } };
  const next = Math.max(0, ...data.map((p) => p.number)) + 1;

  // Création ou modification dans la boîte : elle se ferme une fois l'opération réussie, l'erreur s'affiche dans la boîte.
  const save = async () => {
    if (submit.state !== 'idle') return;
    setSubmit({ state: 'busy', error: '' });
    const body = { number: form.number, name: form.name, description: form.description };
    let message;
    try {
      message = (await (form.id ? api.put(`/phases/${form.id}`, body) : api.post('/phases', body))).data.message;
    } catch (e) {
      setSubmit({ state: 'idle', error: apiError(e) });
      return;
    }
    setSubmit({ state: 'done', error: '' });
    await new Promise((r) => setTimeout(r, 650));
    await animateModalOut();
    setForm(null);
    setSubmit({ state: 'idle', error: '' });
    say(message);
    await reload();
  };
  const close = () => { if (submit.state === 'idle') { setForm(null); setSubmit({ state: 'idle', error: '' }); } };

  return (
    <>
      <PageHead title="Phases du projet" desc="La phase active est celle affichée par défaut. Une phase archivée n’est plus consultable que par le super-administrateur et les comptes qu’il autorise, sur demande depuis la page Archives.">
        <button className="btn btn-primary" onClick={() => setForm({ number: next, name: `Phase ${next}`, description: '' })}><i className="bi bi-plus-lg me-1" /> Nouvelle phase</button>
      </PageHead>
      <Notice type={flash.type} onClose={() => say('')}>{flash.text}</Notice>
      <div className="cardx p-3">
        <div className="table-wrap"><table className="table table-hover align-middle">
          <thead><tr><th>N°</th><th>Phase</th><th>Description</th><th>Contrats</th><th>État</th><th /></tr></thead>
          <tbody>
            {data.map((p) => (
              <tr key={p.id}>
                <td>{p.number}</td><td><strong>{p.name}</strong></td><td>{p.description || '—'}</td><td>{p.contracts}</td>
                <td>{p.isCurrent ? <span className="status status-normal">Active</span> : p.isArchived ? <span className="status status-termine">Archivée</span> : <span className="status status-vigilance">Disponible</span>}</td>
                <td className="text-nowrap text-end">
                  <button className="btn btn-sm btn-light" onClick={() => setForm({ id: p.id, number: p.number, name: p.name, description: p.description || '' })}><i className="bi bi-pencil me-1" />Modifier</button>
                  {!p.isCurrent && !p.isArchived && <button className="btn btn-sm btn-outline-success ms-1" onClick={() => call(() => api.put(`/phases/${p.id}/current`))}>Rendre active</button>}
                  {!p.isCurrent && !p.isArchived && <button className="btn btn-sm btn-outline-secondary ms-1" onClick={() => window.confirm(`Archiver la phase ${p.name} ? Elle ne sera plus consultable que par le super-administrateur et les comptes qu’il autorise.`) && call(() => api.put(`/phases/${p.id}/archive`))}>Archiver</button>}
                  {p.isArchived && isSuper && <button className="btn btn-sm btn-outline-primary ms-1" onClick={() => window.confirm(`Restaurer la phase ${p.name} ? Elle redeviendra consultable par tous.`) && call(() => api.put(`/phases/${p.id}/restore`))}>Restaurer</button>}
                </td>
              </tr>
            ))}
            {!data.length && <tr><td colSpan="6"><Empty text="Aucune phase." /></td></tr>}
          </tbody>
        </table></div>
      </div>
      {form && (
        <Modal title={form.id ? `Modifier la phase ${form.name}` : 'Nouvelle phase'} size="md" onClose={close}
          footer={<>
            {submit.error && <div className="modal-error"><Notice>{submit.error}</Notice></div>}
            <button className="btn btn-light" disabled={submit.state !== 'idle'} onClick={close}>Annuler</button>
            <SubmitButton state={submit.state} disabled={!String(form.number).trim() || !form.name.trim()} onClick={save}>{form.id ? 'Enregistrer' : 'Créer la phase'}</SubmitButton>
          </>}>
          <div className="row g-3">
            <Field className="col-md-4" label="Numéro"><input type="number" min="1" className="form-control" value={form.number} onChange={(e) => setForm({ ...form, number: e.target.value })} /></Field>
            <Field className="col-md-8" label="Nom"><input className="form-control" maxLength={200} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></Field>
            <Field className="col-12" label="Description"><textarea className="form-control" rows="3" maxLength={1000} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} /></Field>
          </div>
        </Modal>
      )}
    </>
  );
}

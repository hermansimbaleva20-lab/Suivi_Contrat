import React, { useState } from 'react';
import { api, apiError } from '../api.js';
import { useLoad } from '../lib/hooks.js';
import { Empty, Field, Loading, Modal, Notice, PageHead } from '../components/ui.jsx';

export default function Phases() {
  const { data, loading, error, reload } = useLoad('/phases');
  const [form, setForm] = useState(null);
  const [flash, setFlash] = useState({ type: 'success', text: '' });
  if (loading && !data) return <Loading />;
  if (!data) return <Notice>{error}</Notice>;
  const say = (text, type = 'success') => setFlash({ type, text });
  const call = async (fn) => { try { const r = await fn(); say(r.data.message); setForm(null); await reload(); } catch (e) { say(apiError(e), 'danger'); } };
  const next = Math.max(0, ...data.map((p) => p.number)) + 1;

  return (
    <>
      <PageHead kicker="ADMINISTRATION" title="Phases du projet" desc="La phase active est celle affichée par défaut. Les phases archivées restent consultables par les utilisateurs habilités.">
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
                  {!p.isCurrent && !p.isArchived && <button className="btn btn-sm btn-outline-success" onClick={() => call(() => api.put(`/phases/${p.id}/current`))}>Rendre active</button>}
                  {!p.isCurrent && !p.isArchived && <button className="btn btn-sm btn-outline-secondary ms-1" onClick={() => window.confirm(`Archiver la phase ${p.name} ?`) && call(() => api.put(`/phases/${p.id}/archive`))}>Archiver</button>}
                  {p.isArchived && <button className="btn btn-sm btn-outline-primary" onClick={() => call(() => api.put(`/phases/${p.id}/restore`))}>Restaurer</button>}
                </td>
              </tr>
            ))}
            {!data.length && <tr><td colSpan="6"><Empty text="Aucune phase." /></td></tr>}
          </tbody>
        </table></div>
      </div>
      {form && (
        <Modal title="Nouvelle phase" size="md" onClose={() => setForm(null)} footer={<><button className="btn btn-light" onClick={() => setForm(null)}>Annuler</button><button className="btn btn-primary" onClick={() => call(() => api.post('/phases', form))}>Créer la phase</button></>}>
          <div className="row g-3">
            <Field className="col-md-4" label="Numéro"><input type="number" min="1" className="form-control" value={form.number} onChange={(e) => setForm({ ...form, number: e.target.value })} /></Field>
            <Field className="col-md-8" label="Nom"><input className="form-control" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></Field>
            <Field className="col-12" label="Description"><textarea className="form-control" rows="2" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} /></Field>
          </div>
        </Modal>
      )}
    </>
  );
}

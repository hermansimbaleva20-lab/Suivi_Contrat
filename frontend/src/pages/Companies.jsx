import React, { useState } from 'react';
import { api, apiError } from '../api.js';
import { useLoad } from '../lib/hooks.js';
import { Empty, Field, Loading, Modal, Notice, PageHead } from '../components/ui.jsx';

const blank = { name: '', address: '', phone: '', email: '', observation: '' };

export default function Companies() {
  const { data, loading, error, reload } = useLoad('/companies');
  const [q, setQ] = useState('');
  const [form, setForm] = useState(null);
  const [flash, setFlash] = useState({ type: 'success', text: '' });
  const [busy, setBusy] = useState(false);
  if (loading && !data) return <Loading />;
  if (!data) return <Notice>{error}</Notice>;
  const rows = data.filter((c) => `${c.name} ${c.email} ${c.phone}`.toLowerCase().includes(q.toLowerCase()));
  const say = (text, type = 'success') => setFlash({ type, text });
  const set = (k) => (e) => setForm((s) => ({ ...s, [k]: e.target.value }));

  const save = async (e) => {
    e.preventDefault();
    setBusy(true);
    try {
      const r = form.id ? await api.put(`/companies/${form.id}`, form) : await api.post('/companies', form);
      say(r.data.message); setForm(null); await reload();
    } catch (err) { say(apiError(err), 'danger'); setForm(null); } finally { setBusy(false); }
  };
  const remove = async (c) => {
    if (!window.confirm(`Supprimer l’entreprise ${c.name} ?`)) return;
    try { const r = await api.delete(`/companies/${c.id}`); say(r.data.message); await reload(); } catch (err) { say(apiError(err), 'danger'); }
  };

  return (
    <>
      <PageHead kicker="RÉFÉRENTIEL" title="Entreprises" desc="Référentiel des entreprises contractantes du projet et de leurs coordonnées. Les garanties sont enregistrées séparément pour chaque contrat.">
        <button className="btn btn-primary" onClick={() => setForm({ ...blank })}><i className="bi bi-plus-lg me-1" /> Nouvelle entreprise</button>
      </PageHead>
      <Notice type={flash.type} onClose={() => say('')}>{flash.text}</Notice>
      <div className="cardx p-3">
        <input className="form-control mb-3" style={{ maxWidth: 360 }} placeholder="Rechercher une entreprise…" value={q} onChange={(e) => setQ(e.target.value)} />
        <div className="table-wrap">
          <table className="table table-hover align-middle">
            <thead><tr><th>Entreprise</th><th>Coordonnées</th><th>Contrats</th><th /></tr></thead>
            <tbody>
              {rows.map((c) => (
                <tr key={c.id}>
                  <td><strong>{c.name}</strong><div className="small text-secondary">{c.address}</div></td>
                  <td className="small">{c.phone || '—'}<div className="text-secondary">{c.email}</div></td>
                  <td>{c.contracts}</td>
                  <td className="text-nowrap text-end">
                    <button className="btn btn-sm btn-outline-primary" title="Modifier" onClick={() => setForm({ id: c.id, name: c.name, address: c.address, phone: c.phone, email: c.email, observation: c.observation || '' })}><i className="bi bi-pencil" /></button>
                    {c.contracts === 0 && <button className="btn btn-sm btn-outline-danger ms-1" title="Supprimer" onClick={() => remove(c)}><i className="bi bi-trash" /></button>}
                  </td>
                </tr>
              ))}
              {!rows.length && <tr><td colSpan="4"><Empty text="Aucune entreprise enregistrée." /></td></tr>}
            </tbody>
          </table>
        </div>
      </div>
      {form && (
        <Modal title={form.id ? 'Modifier l’entreprise' : 'Nouvelle entreprise'} onClose={() => setForm(null)}
          footer={<><button className="btn btn-light" onClick={() => setForm(null)}>Annuler</button><button className="btn btn-primary" type="submit" form="company-form" disabled={busy}>Enregistrer</button></>}>
          <form id="company-form" onSubmit={save}>
            <div className="row g-3">
              <Field className="col-12" label="Nom de l’entreprise"><input className="form-control" value={form.name} onChange={set('name')} required /></Field>
              <Field className="col-12" label="Adresse"><input className="form-control" value={form.address} onChange={set('address')} /></Field>
              <Field className="col-md-6" label="Téléphone"><input className="form-control" value={form.phone} onChange={set('phone')} /></Field>
              <Field className="col-md-6" label="E-mail"><input type="email" className="form-control" value={form.email} onChange={set('email')} /></Field>
              <Field className="col-12" label="Observation"><input className="form-control" value={form.observation} onChange={set('observation')} /></Field>
            </div>
          </form>
        </Modal>
      )}
    </>
  );
}

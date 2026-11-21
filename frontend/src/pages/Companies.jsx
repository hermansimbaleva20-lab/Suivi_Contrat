import React, { useState } from 'react';
import { api, apiError } from '../api.js';
import { useLoad } from '../lib/hooks.js';
import { Empty, Field, Loading, Modal, Notice, PageHead } from '../components/ui.jsx';

/*
 * Référentiel des entreprises du projet. Chaque entreprise a une catégorie (décision du 18/11/2026) : Travaux, Biens ou
 * Services, la même que celle des contrats ; le formulaire du contrat ne propose ensuite que les entreprises de la catégorie
 * choisie. Une entreprise enregistrée avant cette décision peut rester « à renseigner » jusqu'à sa prochaine modification.
 */

const COMPANY_CATEGORIES = [['TRAVAUX', 'Travaux'], ['BIENS', 'Biens'], ['SERVICE', 'Services']];
const LABEL = Object.fromEntries(COMPANY_CATEGORIES);
const blank = { name: '', category: '', address: '', phone: '', email: '', observation: '' };

function CategoryBadge({ value }) {
  return value
    ? <span className="badge-soft">{LABEL[value] || value}</span>
    : <span className="badge rounded-pill text-bg-warning-subtle text-warning-emphasis border border-warning-subtle">À renseigner</span>;
}

export default function Companies() {
  const { data, loading, error, reload } = useLoad('/companies');
  const [q, setQ] = useState('');
  const [tab, setTab] = useState('');
  const [form, setForm] = useState(null);
  const [formError, setFormError] = useState('');
  const [flash, setFlash] = useState({ type: 'success', text: '' });
  const [busy, setBusy] = useState(false);
  if (loading && !data) return <Loading />;
  if (!data) return <Notice>{error}</Notice>;
  const count = (k) => data.filter((c) => (k === 'NONE' ? !c.category : c.category === k)).length;
  const missing = count('NONE');
  const rows = data
    .filter((c) => !tab || (tab === 'NONE' ? !c.category : c.category === tab))
    .filter((c) => `${c.name} ${c.email} ${c.phone}`.toLowerCase().includes(q.toLowerCase()));
  const say = (text, type = 'success') => setFlash({ type, text });
  const set = (k) => (e) => setForm((s) => ({ ...s, [k]: e.target.value }));
  const open = (f) => { setFormError(''); setForm(f); };

  const save = async (e) => {
    e.preventDefault();
    setBusy(true);
    setFormError('');
    try {
      const r = form.id ? await api.put(`/companies/${form.id}`, form) : await api.post('/companies', form);
      say(r.data.message); setForm(null); await reload();
    } catch (err) { setFormError(apiError(err)); } finally { setBusy(false); }
  };
  const remove = async (c) => {
    if (!window.confirm(`Supprimer l’entreprise ${c.name} ?`)) return;
    try { const r = await api.delete(`/companies/${c.id}`); say(r.data.message); await reload(); } catch (err) { say(apiError(err), 'danger'); }
  };
  const tabs = [['', 'Toutes', data.length], ...COMPANY_CATEGORIES.map(([k, l]) => [k, l, count(k)]), ...(missing ? [['NONE', 'À renseigner', missing]] : [])];

  return (
    <>
      <PageHead kicker="RÉFÉRENTIEL" title="Entreprises" desc="Référentiel des entreprises contractantes du projet, classées par catégorie (Travaux, Biens, Services). Le formulaire d’un contrat ne propose que les entreprises de sa catégorie.">
        <button className="btn btn-primary" onClick={() => open({ ...blank, category: ['TRAVAUX', 'BIENS', 'SERVICE'].includes(tab) ? tab : '' })}><i className="bi bi-plus-lg me-1" /> Nouvelle entreprise</button>
      </PageHead>
      <Notice type={flash.type} onClose={() => say('')}>{flash.text}</Notice>
      {missing > 0 && (
        <Notice type="warning">
          {missing === 1 ? '1 entreprise n’a' : `${missing} entreprises n’ont`} pas encore de catégorie : modifiez-{missing === 1 ? 'la' : 'les'} pour la choisir, sinon elles restent proposées à tous les contrats.
        </Notice>
      )}
      <div className="cardx p-3">
        <div className="d-flex flex-wrap align-items-center gap-2 mb-3">
          <div className="btn-group flex-wrap" role="group" aria-label="Filtrer par catégorie">
            {tabs.map(([k, l, n]) => (
              <button key={k || 'all'} type="button" className={`btn btn-sm ${tab === k ? 'btn-primary' : 'btn-outline-secondary'}`} aria-pressed={tab === k} onClick={() => setTab(k)}>
                {l} <span className="ms-1 opacity-75">{n}</span>
              </button>
            ))}
          </div>
          <input className="form-control ms-md-auto" style={{ maxWidth: 320 }} placeholder="Rechercher une entreprise…" aria-label="Rechercher une entreprise" value={q} onChange={(e) => setQ(e.target.value)} />
        </div>
        <div className="table-wrap">
          <table className="table table-hover align-middle">
            <thead><tr><th>Entreprise</th><th>Catégorie</th><th>Coordonnées</th><th>Contrats</th><th /></tr></thead>
            <tbody>
              {rows.map((c) => (
                <tr key={c.id}>
                  <td><strong>{c.name}</strong><div className="small text-secondary">{c.address}</div></td>
                  <td><CategoryBadge value={c.category} /></td>
                  <td className="small">{c.phone || '—'}<div className="text-secondary">{c.email}</div></td>
                  <td>{c.contracts}</td>
                  <td className="text-nowrap text-end">
                    <button className="btn btn-sm btn-outline-primary" title="Modifier" aria-label={`Modifier ${c.name}`} onClick={() => open({ id: c.id, name: c.name, category: c.category || '', address: c.address, phone: c.phone, email: c.email, observation: c.observation || '' })}><i className="bi bi-pencil" /></button>
                    {c.contracts === 0 && <button className="btn btn-sm btn-outline-danger ms-1" title="Supprimer" aria-label={`Supprimer ${c.name}`} onClick={() => remove(c)}><i className="bi bi-trash" /></button>}
                  </td>
                </tr>
              ))}
              {!rows.length && <tr><td colSpan="5"><Empty text={tab || q ? 'Aucune entreprise ne correspond à ce filtre.' : 'Aucune entreprise enregistrée.'} /></td></tr>}
            </tbody>
          </table>
        </div>
      </div>
      {form && (
        <Modal title={form.id ? 'Modifier l’entreprise' : 'Nouvelle entreprise'} onClose={() => setForm(null)}
          footer={<><button className="btn btn-light" onClick={() => setForm(null)}>Annuler</button><button className="btn btn-primary" type="submit" form="company-form" disabled={busy}>Enregistrer</button></>}>
          <form id="company-form" onSubmit={save}>
            {formError && <div className="alert alert-danger py-2 small">{formError}</div>}
            <div className="row g-3">
              <Field className="col-md-8" label="Nom de l’entreprise"><input className="form-control" value={form.name} onChange={set('name')} required autoFocus /></Field>
              <Field className="col-md-4" label="Catégorie">
                <select className="form-select" value={form.category} onChange={set('category')} required>
                  <option value="">Choisir…</option>
                  {COMPANY_CATEGORIES.map(([k, l]) => <option key={k} value={k}>{l}</option>)}
                </select>
              </Field>
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

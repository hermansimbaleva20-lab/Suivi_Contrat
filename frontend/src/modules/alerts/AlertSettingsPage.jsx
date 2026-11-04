import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../api.js';
import { useLoad } from '../../lib/hooks.js';
import { Loading, Notice } from '../../components/ui.jsx';
import { btn, btnDanger, btnGhost, btnPrimary, FieldError, input, label, panel } from '../shared/tw.jsx';
import { ModuleHeader } from '../shared/ModuleHeader.jsx';
import { useMutation } from '../fleet/fleetHooks.js';

/*
 * Administration > Alertes e-mail (super-administrateur) : adresses qui reçoivent les alertes, par catégorie et par
 * unité ; séquence d'envoi de chaque catégorie ; simulation et envoi immédiat ; historique des envois.
 * L'envoi automatique a lieu chaque jour à 7 h (commande planifiée prise:send-alerts).
 */

const th = 'tw:border-b tw:border-prise-border tw:bg-prise-bg tw:px-3 tw:py-2.5 tw:text-left tw:font-tech tw:text-[11px] tw:font-semibold tw:uppercase tw:tracking-wide tw:text-prise-muted';
const td = 'tw:border-b tw:border-prise-border tw:px-3 tw:py-2.5 tw:text-sm tw:text-prise-text tw:align-top';
const STATUS = { ENVOYE: ['Envoyé', 'tw:bg-prise-green-soft tw:text-prise-green-2'], ECHEC: ['Échec', 'tw:bg-red-50 tw:text-prise-danger'], SIMULATION: ['Simulation', 'tw:bg-sky-50 tw:text-sky-800'], SANS_ADRESSE: ['Adresse manquante', 'tw:bg-amber-50 tw:text-amber-800'], SANS_DESTINATAIRE: ['Aucun valideur', 'tw:bg-amber-50 tw:text-amber-800'] };
// Les décomptes ne vont pas à cette liste : leur rappel part au compte habilité à les valider.
const forRecipients = (categories) => categories.filter((c) => c !== 'DECOMPTE' && c.key !== 'DECOMPTE');
const Badge = ({ s }) => <span className={`tw:whitespace-nowrap tw:rounded-full tw:px-2 tw:py-0.5 tw:text-[11px] tw:font-bold ${STATUS[s][1]}`}>{STATUS[s][0]}</span>;

function RecipientForm({ initial, data, onSubmit, onCancel, busy, errors }) {
  const [f, setF] = useState(initial);
  const toggle = (k) => setF({ ...f, categories: f.categories.includes(k) ? f.categories.filter((c) => c !== k) : [...f.categories, k] });
  return (
    <form className="tw:grid tw:gap-3 tw:border-b tw:border-prise-border tw:bg-prise-bg/60 tw:px-5 tw:py-4 tw:sm:grid-cols-12" onSubmit={(e) => { e.preventDefault(); onSubmit(f); }}>
      <div className="tw:sm:col-span-4">
        <label className={label} htmlFor="ar-email">Adresse e-mail</label>
        <input id="ar-email" type="email" className={input} value={f.email} onChange={(e) => setF({ ...f, email: e.target.value })} required maxLength={190} autoComplete="off" />
        <FieldError text={errors.email} />
      </div>
      <div className="tw:sm:col-span-4">
        <label className={label} htmlFor="ar-name">Nom</label>
        <input id="ar-name" className={input} value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} required maxLength={150} placeholder="ex. Logisticien de Kananga" />
      </div>
      <div className="tw:sm:col-span-4">
        <label className={label} htmlFor="ar-position">Poste occupé dans le projet</label>
        <select id="ar-position" className={input} value={f.position_id} onChange={(e) => setF({ ...f, position_id: e.target.value })} required>
          <option value="">Choisir le poste…</option>
          {data.positions.map((p) => <option key={p.id} value={p.id}>{p.name}{p.unit ? ` — ${p.unit}` : ''}</option>)}
        </select>
        <FieldError text={errors.position_id} />
      </div>
      <div className="tw:sm:col-span-12">
        <label className={label} htmlFor="ar-unit">Unité concernée</label>
        <select id="ar-unit" className={input} value={f.organizational_unit_id} onChange={(e) => setF({ ...f, organizational_unit_id: e.target.value })}>
          <option value="">Toutes les unités</option>
          {data.units.map((u) => <option key={u.id} value={u.id}>{u.name} seulement</option>)}
        </select>
      </div>
      <fieldset className="tw:m-0 tw:border-0 tw:p-0 tw:sm:col-span-12">
        <legend className={label}>Alertes reçues</legend>
        <div className="tw:flex tw:flex-wrap tw:gap-2">
          {forRecipients(data.categories).map((c) => (
            <label key={c.key} className={`tw:flex tw:cursor-pointer tw:items-center tw:gap-2 tw:rounded-lg tw:px-3 tw:py-1.5 tw:text-sm tw:ring-1 ${f.categories.includes(c.key) ? 'tw:bg-prise-green-soft tw:ring-prise-green-2' : 'tw:bg-white tw:ring-prise-border'}`}>
              <input type="checkbox" className="tw:size-4 tw:accent-prise-green-2" checked={f.categories.includes(c.key)} onChange={() => toggle(c.key)} />{c.label}
            </label>
          ))}
        </div>
        <FieldError text={errors.categories} />
      </fieldset>
      <div className="tw:flex tw:justify-end tw:gap-2 tw:sm:col-span-12">
        <button type="button" className={btnGhost} onClick={onCancel}>Annuler</button>
        <button type="submit" className={btnPrimary} disabled={busy}>{f.id ? 'Enregistrer' : 'Ajouter le destinataire'}</button>
      </div>
    </form>
  );
}

export default function AlertSettingsPage() {
  const { data, loading, error, reload } = useLoad('/alert-settings');
  const { flash, setFlash, errors, setErrors, busy, call } = useMutation(reload);
  const [editing, setEditing] = useState(null);
  const [rules, setRules] = useState(null);
  const [plan, setPlan] = useState(null);

  if (loading && !data) return <Loading />;
  if (!data) return <Notice>{error}</Notice>;

  const catLabel = Object.fromEntries(data.categories.map((c) => [c.key, c.label]));
  const unitName = Object.fromEntries(data.units.map((u) => [u.id, u.name]));
  const r = rules || data.rules;
  const setRule = (cat, k, v) => setRules(r.map((x) => (x.category === cat ? { ...x, [k]: v } : x)));
  const save = (f) => call(() => (f.id ? api.put(`/alert-settings/recipients/${f.id}`, f) : api.post('/alert-settings/recipients', f)), () => setEditing(null));
  const saveRules = () => call(() => api.put('/alert-settings/rules', { rules: r.map((x) => ({ category: x.category, is_enabled: x.isEnabled, interval_days: Number(x.intervalDays), max_sends: Number(x.maxSends) })) }), () => setRules(null));
  const runNow = (dry) => call(() => api.post('/alert-settings/run', { dry_run: dry }), (d) => setPlan({ dry, items: d.plan }));

  return (
    <>
      <ModuleHeader icon="envelope-paper-fill" title="Alertes e-mail"
        desc="Qui reçoit quelles alertes, et à quel rythme. L’envoi automatique a lieu chaque jour à 7 h ; une même alerte est relancée selon la séquence de sa catégorie, jusqu’au nombre maximal d’envois." />
      <Notice type={flash.type} onClose={() => setFlash({ ...flash, text: '' })}>{flash.text}</Notice>
      {!data.smtpReady && <Notice type="warning">Le serveur d’envoi n’est pas configuré : renseignez le SMTP dans Paramètres, sinon les envois échoueront.</Notice>}

      <section className={`${panel} tw:mb-5`}>
        <header className="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-3 tw:border-b tw:border-prise-border tw:px-5 tw:py-4">
          <h2 className="tw:m-0 tw:font-display tw:text-[17px] tw:font-bold tw:text-prise-blue">Destinataires <span className="tw:font-tech tw:text-sm tw:font-medium tw:text-prise-muted">{data.recipients.length}</span></h2>
          {editing !== 'new' && <button type="button" className={btnPrimary} onClick={() => { setErrors({}); setEditing('new'); }}><i className="bi bi-plus-lg" aria-hidden="true" />Ajouter une adresse</button>}
        </header>
        {editing === 'new' && <RecipientForm initial={{ email: '', name: '', organizational_unit_id: '', position_id: '', categories: forRecipients(data.categories).map((c) => c.key) }} data={data} onSubmit={save} onCancel={() => setEditing(null)} busy={busy} errors={errors} />}
        <ul className="tw:m-0 tw:list-none tw:p-0">
          {data.recipients.map((x) => (
            <li key={x.id} className="tw:border-b tw:border-prise-border tw:last:border-b-0">
              {editing === x.id ? (
                <RecipientForm initial={{ id: x.id, email: x.email, name: x.name, position_id: x.positionId ? String(x.positionId) : '', organizational_unit_id: x.unitId ? String(x.unitId) : '', categories: x.categories }} data={data} onSubmit={save} onCancel={() => setEditing(null)} busy={busy} errors={errors} />
              ) : (
                <div className={`tw:flex tw:flex-wrap tw:items-center tw:gap-x-4 tw:gap-y-2 tw:px-5 tw:py-3 ${x.isActive ? '' : 'tw:opacity-60'}`}>
                  <div className="tw:min-w-0 tw:flex-1">
                    <div className="tw:flex tw:flex-wrap tw:items-baseline tw:gap-x-2">
                      <span className="tw:font-semibold">{x.email}</span>
                      {x.name && <span className="tw:text-sm tw:text-prise-muted">{x.name}</span>}
                      {x.position ? <span className="tw:text-sm tw:text-prise-muted">· {x.position}</span> : <span className="tw:text-sm tw:font-semibold tw:text-amber-800">· Poste à renseigner</span>}
                      {!x.isActive && <span className="tw:rounded-full tw:bg-slate-100 tw:px-2 tw:py-0.5 tw:text-[11px] tw:font-bold tw:text-slate-600">Suspendu</span>}
                    </div>
                    <div className="tw:mt-1 tw:flex tw:flex-wrap tw:gap-1.5 tw:text-[11px]">
                      <span className="tw:rounded-full tw:bg-prise-bg tw:px-2 tw:py-0.5 tw:font-semibold tw:text-prise-text"><i className="bi bi-geo-alt tw:mr-1" aria-hidden="true" />{x.unitId ? unitName[x.unitId] : 'Toutes les unités'}</span>
                      {forRecipients(x.categories).map((c) => <span key={c} className="tw:rounded-full tw:bg-prise-green-soft tw:px-2 tw:py-0.5 tw:font-semibold tw:text-prise-green-2">{catLabel[c]}</span>)}
                    </div>
                  </div>
                  <div className="tw:flex tw:gap-1">
                    <button type="button" className={btnGhost} onClick={() => { setErrors({}); setEditing(x.id); }} aria-label={`Modifier ${x.email}`} title="Modifier"><i className="bi bi-pencil" aria-hidden="true" /></button>
                    <button type="button" className={btnGhost} disabled={busy} onClick={() => call(() => api.put(`/alert-settings/recipients/${x.id}/toggle`))}>{x.isActive ? 'Suspendre' : 'Réactiver'}</button>
                    <button type="button" className={btnDanger} disabled={busy} onClick={() => call(() => api.delete(`/alert-settings/recipients/${x.id}`))} aria-label={`Supprimer ${x.email}`} title="Supprimer"><i className="bi bi-trash" aria-hidden="true" /></button>
                  </div>
                </div>
              )}
            </li>
          ))}
        </ul>
        {!data.recipients.length && editing !== 'new' && <p className="tw:m-0 tw:px-5 tw:py-6 tw:text-center tw:text-sm tw:text-prise-muted">Aucun destinataire : aucune alerte ne peut partir. Ajoutez au moins une adresse.</p>}
      </section>

      <div className="tw:grid tw:items-start tw:gap-5 tw:xl:grid-cols-2">
        <section className={panel}>
          <header className="tw:flex tw:items-center tw:justify-between tw:gap-3 tw:border-b tw:border-prise-border tw:px-5 tw:py-4">
            <h2 className="tw:m-0 tw:font-display tw:text-[17px] tw:font-bold tw:text-prise-blue">Séquence d’envoi</h2>
            {rules && <button type="button" className={btnPrimary} disabled={busy} onClick={saveRules}>Enregistrer la séquence</button>}
          </header>
          <table className="tw:w-full tw:border-collapse">
            <thead><tr><th className={th} scope="col">Catégorie</th><th className={th} scope="col">Active</th><th className={th} scope="col">Relance tous les</th><th className={th} scope="col">Envois au plus</th></tr></thead>
            <tbody>
              {r.map((x) => (
                <tr key={x.category}>
                  <td className={`${td} tw:font-semibold`}>{catLabel[x.category]}</td>
                  <td className={td}><input type="checkbox" aria-label={`Activer ${catLabel[x.category]}`} className="tw:size-4 tw:accent-prise-green-2" checked={x.isEnabled} onChange={(e) => setRule(x.category, 'isEnabled', e.target.checked)} /></td>
                  {x.category === 'GARANTIE' ? (
                    <td className={`${td} tw:text-xs tw:text-prise-muted`} colSpan={2}>Entreprise : selon la part consommée de la garantie (1 à 4 envois par semaine), paliers réglés dans <Link to="/guarantees">Finance › Garanties</Link>. Personnel : une fois par semaine jusqu’à la prolongation ou au renouvellement.</td>
                  ) : x.category === 'DECOMPTE' ? (
                    <td className={`${td} tw:text-xs tw:text-prise-muted`} colSpan={2}>Un jour après la transmission puis chaque jour, à l’adresse du compte habilité à valider (le chef de cellule infrastructures), jamais au super-admin.</td>
                  ) : x.category === 'RETARD' ? (
                    <td className={`${td} tw:text-xs tw:text-prise-muted`} colSpan={2}>Entreprise et personnel : une fois par semaine, jusqu’à l’amélioration de l’avancement physique. Le bouton de la page <Link to="/alerts">Alertes</Link> envoie aussi à la demande.</td>
                  ) : (
                    <>
                      <td className={td}><span className="tw:flex tw:items-center tw:gap-2"><input type="number" min="1" max="365" aria-label="Jours entre deux relances" className={`${input} tw:w-20 tw:font-tech`} value={x.intervalDays} onChange={(e) => setRule(x.category, 'intervalDays', e.target.value)} />jours</span></td>
                      <td className={td}><input type="number" min="1" max="50" aria-label="Nombre maximal d’envois" className={`${input} tw:w-20 tw:font-tech`} value={x.maxSends} onChange={(e) => setRule(x.category, 'maxSends', e.target.value)} /></td>
                    </>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
          <p className="tw:m-0 tw:px-5 tw:py-3 tw:text-[11px] tw:text-prise-muted">Exemple : relance tous les 7 jours, 3 envois au plus. Une alerte qui disparaît (stock réapprovisionné, garantie prolongée…) n’est plus envoyée.</p>
        </section>

        <section className={panel}>
          <header className="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-3 tw:border-b tw:border-prise-border tw:px-5 tw:py-4">
            <h2 className="tw:m-0 tw:font-display tw:text-[17px] tw:font-bold tw:text-prise-blue">Simulation et envoi</h2>
            <div className="tw:flex tw:gap-2">
              <button type="button" className={btnGhost} disabled={busy} onClick={() => runNow(true)}><i className="bi bi-eye" aria-hidden="true" />Simuler</button>
              <button type="button" className={`${btn} tw:bg-prise-green-soft tw:text-prise-green-2 tw:hover:bg-prise-green-2 tw:hover:text-white`} disabled={busy} onClick={() => runNow(false)}><i className="bi bi-send" aria-hidden="true" />Envoyer maintenant</button>
            </div>
          </header>
          {!plan && <p className="tw:m-0 tw:px-5 tw:py-5 tw:text-sm tw:text-prise-muted">« Simuler » montre les messages qui partiraient maintenant, sans rien envoyer. « Envoyer maintenant » n’attend pas l’envoi automatique de 7 h ; la séquence reste respectée.</p>}
          {plan && (
            <ul className="tw:m-0 tw:list-none tw:p-0">
              {plan.items.map((m, i) => (
                <li key={i} className="tw:border-b tw:border-prise-border tw:px-5 tw:py-3 tw:last:border-b-0">
                  <div className="tw:flex tw:flex-wrap tw:items-center tw:gap-2"><Badge s={m.status} /><span className="tw:font-semibold">{m.subject}</span></div>
                  <p className="tw:m-0 tw:mt-1 tw:text-xs tw:text-prise-muted">À : {m.recipients.join(', ')}{m.message ? ` — ${m.message}` : ''}</p>
                  <ul className="tw:m-0 tw:mt-1 tw:pl-4 tw:text-xs">{m.alerts.slice(0, 6).map((a, j) => <li key={j}>{a.title} : {a.text}{a.sendNo > 1 ? ` (relance n° ${a.sendNo})` : ''}</li>)}{m.alerts.length > 6 && <li>… et {m.alerts.length - 6} autre(s)</li>}</ul>
                </li>
              ))}
              {!plan.items.length && <li className="tw:px-5 tw:py-5 tw:text-sm tw:text-prise-muted">Aucune alerte due pour le moment.</li>}
            </ul>
          )}
        </section>
      </div>

      <section className={`${panel} tw:mt-5`}>
        <header className="tw:border-b tw:border-prise-border tw:px-5 tw:py-4"><h2 className="tw:m-0 tw:font-display tw:text-[17px] tw:font-bold tw:text-prise-blue">Historique des envois</h2></header>
        <div className="tw:overflow-x-auto">
          <table className="tw:w-full tw:border-collapse">
            <thead><tr>{['Date', 'Catégorie', 'Alerte', 'Destinataires', 'Résultat'].map((h) => <th key={h} className={th} scope="col">{h}</th>)}</tr></thead>
            <tbody>
              {data.dispatches.map((d) => (
                <tr key={d.id}>
                  <td className={`${td} tw:font-tech tw:whitespace-nowrap`}>{d.date}<span className="tw:block tw:font-body tw:text-[11px] tw:text-prise-muted">{d.trigger === 'AUTO' ? 'envoi planifié' : `manuel${d.sentBy ? ` · ${d.sentBy}` : ''}`}</span></td>
                  <td className={`${td} tw:whitespace-nowrap`}>{catLabel[d.category] || d.category}</td>
                  <td className={td}>{d.subject}</td>
                  <td className={`${td} tw:text-xs`}>{d.recipients}</td>
                  <td className={td}><Badge s={d.status} />{d.status === 'ECHEC' && <span className="tw:block tw:text-[11px] tw:text-prise-danger">{d.message}</span>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {!data.dispatches.length && <p className="tw:m-0 tw:px-5 tw:py-6 tw:text-center tw:text-sm tw:text-prise-muted">Aucun envoi pour le moment.</p>}
      </section>
    </>
  );
}

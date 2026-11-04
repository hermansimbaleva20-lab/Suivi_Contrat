import React, { useState } from 'react';
import { api } from '../../api.js';
import { useLoad } from '../../lib/hooks.js';
import { fr, frDateTime, num } from '../../lib/format.js';
import { Modal, Notice } from '../../components/ui.jsx';
import { btn, btnGhost, btnPrimary, FieldError, input, label, panel } from '../shared/tw.jsx';
import { useMutation } from '../fleet/fleetHooks.js';

/*
 * Relances des garanties de bonne exécution adressées à l'entreprise (adresse de sa fiche), selon la part consommée de
 * la période en cours : vigilance à 75 / 85 / 95 % (1, 2, 3 envois par semaine), retard après l'échéance (4 par semaine).
 * Une prolongation, un renouvellement ou une modification des dates arrête la séquence. Un administrateur de la
 * coordination nationale (ou le super-admin) règle les paliers, interrompt les relances d'une garantie ou change leur
 * fréquence. L'envoi part chaque jour à 7 h avec les autres alertes (prise:send-alerts).
 */

const th = 'tw:border-b tw:border-prise-border tw:bg-prise-bg tw:px-3 tw:py-2.5 tw:text-left tw:font-tech tw:text-[11px] tw:font-semibold tw:uppercase tw:tracking-wide tw:text-prise-muted';
const td = 'tw:border-b tw:border-prise-border tw:px-3 tw:py-2.5 tw:text-sm tw:text-prise-text tw:align-top';
const pill = 'tw:inline-block tw:whitespace-nowrap tw:rounded-full tw:px-2 tw:py-0.5 tw:text-[11px] tw:font-bold';
// Champ en ligne : la classe commune impose toute la largeur.
const inlineInput = input.replace('tw:w-full ', '');
const perWeek = (n) => `${n} envoi${n > 1 ? 's' : ''} / semaine`;

const STATE = {
  SUSPENDUE: ['Interrompue', 'tw:bg-slate-100 tw:text-slate-700'],
  SANS_ADRESSE: ['Adresse manquante', 'tw:bg-amber-50 tw:text-amber-800'],
  DESACTIVEE: ['Relances arrêtées', 'tw:bg-slate-100 tw:text-slate-700'],
  RESILIE: ['Contrat résilié', 'tw:bg-slate-100 tw:text-slate-700'],
  RENOUVELE: ['Contrat renouvelé : garantie non exigée', 'tw:bg-slate-100 tw:text-slate-700'],
};

function LevelBadge({ row }) {
  const retard = row.kind === 'RETARD';
  return (
    <span className={`${pill} ${retard ? 'tw:bg-red-50 tw:text-prise-danger' : 'tw:bg-amber-50 tw:text-amber-800'}`}>
      <i className={`bi ${retard ? 'bi-exclamation-octagon-fill' : 'bi-hourglass-split'} tw:mr-1`} aria-hidden="true" />
      {retard ? 'Retard' : `Vigilance ≥ ${num(row.threshold)} %`}
    </span>
  );
}

function SettingsForm({ data, onSave, onCancel, busy, errors }) {
  const [f, setF] = useState({ is_enabled: data.enabled, vigilance: data.levels.vigilance.map((l) => ({ from: String(l.from), perWeek: String(l.perWeek) })), retard: String(data.levels.retard) });
  const setLevel = (i, k, v) => setF({ ...f, vigilance: f.vigilance.map((l, j) => (j === i ? { ...l, [k]: v } : l)) });
  const submit = (e) => {
    e.preventDefault();
    onSave({ is_enabled: f.is_enabled, retard: Number(f.retard), vigilance: f.vigilance.map((l) => ({ from: Number(String(l.from).replace(',', '.')), perWeek: Number(l.perWeek) })) });
  };
  const week = (id, value, onChange, aria) => (
    <select id={id} aria-label={aria} className={`${inlineInput} tw:w-auto`} value={value} onChange={(e) => onChange(e.target.value)}>
      {[1, 2, 3, 4, 5, 6, 7].map((n) => <option key={n} value={n}>{perWeek(n)}</option>)}
    </select>
  );
  return (
    <form className="tw:grid tw:gap-4 tw:border-b tw:border-prise-border tw:bg-prise-bg/60 tw:px-5 tw:py-4" onSubmit={submit}>
      <label className="tw:flex tw:items-center tw:gap-2 tw:text-sm tw:font-semibold">
        <input type="checkbox" className="tw:size-4 tw:accent-prise-green-2" checked={f.is_enabled} onChange={(e) => setF({ ...f, is_enabled: e.target.checked })} />
        Relances actives pour tous les contrats
      </label>
      <fieldset className="tw:m-0 tw:grid tw:gap-2 tw:border-0 tw:p-0">
        <legend className={label}>Alerte de vigilance : à partir de la part consommée de la période</legend>
        {f.vigilance.map((l, i) => (
          <div key={i} className="tw:flex tw:flex-wrap tw:items-center tw:gap-2">
            <span className="tw:text-sm">À partir de</span>
            <input aria-label={`Seuil du palier ${i + 1}`} inputMode="decimal" className={`${inlineInput} tw:w-20 tw:font-tech`} value={l.from} onChange={(e) => setLevel(i, 'from', e.target.value)} required />
            <span className="tw:text-sm">%</span>
            {week(`gl-week-${i}`, l.perWeek, (v) => setLevel(i, 'perWeek', v), `Envois par semaine du palier ${i + 1}`)}
            {f.vigilance.length > 1 && <button type="button" className={btnGhost} onClick={() => setF({ ...f, vigilance: f.vigilance.filter((_, j) => j !== i) })} aria-label={`Retirer le palier ${i + 1}`}><i className="bi bi-x-lg" aria-hidden="true" /></button>}
          </div>
        ))}
        {f.vigilance.length < 5 && <button type="button" className={`${btnGhost} tw:justify-self-start`} onClick={() => setF({ ...f, vigilance: [...f.vigilance, { from: '', perWeek: '1' }] })}><i className="bi bi-plus-lg" aria-hidden="true" />Ajouter un palier</button>}
        <FieldError text={Object.entries(errors).find(([k]) => k.startsWith('vigilance'))?.[1]} />
      </fieldset>
      <div className="tw:flex tw:flex-wrap tw:items-center tw:gap-2">
        <label className="tw:m-0 tw:text-sm" htmlFor="gl-retard">Alerte de retard, échéance dépassée :</label>
        {week('gl-retard', f.retard, (v) => setF({ ...f, retard: v }), 'Envois par semaine en retard')}
      </div>
      <div className="tw:flex tw:gap-2">
        <button type="submit" className={btnPrimary} disabled={busy}>Enregistrer la séquence</button>
        <button type="button" className={btnGhost} onClick={onCancel}>Annuler</button>
      </div>
    </form>
  );
}

export default function GuaranteeAlertsPanel({ rev = 0 }) {
  const { data, loading, error, reload } = useLoad('/guarantees/alerts', rev ? { rev } : {});
  const { flash, setFlash, errors, setErrors, busy, call } = useMutation(reload);
  const [editing, setEditing] = useState(false);
  const [dlg, setDlg] = useState(null); // { kind: 'message' | 'steer', row, form }
  const [showAll, setShowAll] = useState(false);

  if (!data) return loading ? null : <Notice>{error}</Notice>;
  const concerned = data.rows.filter((r) => r.kind || r.satisfactionDue);
  const rows = showAll ? data.rows : concerned;
  const lv = data.levels;
  const sequence = [...lv.vigilance.map((l) => `${num(l.from)} % → ${l.perWeek} par semaine`), `échue → ${lv.retard} par semaine`].join(' · ');

  const openSteer = (row) => { setErrors({}); setDlg({ kind: 'steer', row, form: { paused: row.state === 'SUSPENDUE', reason: '', per_week: row.perWeekOverride ? String(row.perWeekOverride) : '' } }); };
  const saveSteer = () => {
    const { row, form } = dlg;
    call(() => api.put(`/guarantees/${row.id}/alerts`, { paused: form.paused, reason: form.reason, per_week: form.per_week ? Number(form.per_week) : null }), () => setDlg(null));
  };
  const setForm = (k, v) => setDlg((s) => ({ ...s, form: { ...s.form, [k]: v } }));

  const next = (r) => {
    if (r.satisfactionDue) return <span className={`${pill} tw:bg-prise-green-soft tw:text-prise-green-2`}>Message de satisfaction au prochain envoi</span>;
    if (r.state === 'EN_COURS') return r.due ? <strong className="tw:text-prise-danger">Aujourd’hui</strong> : (r.nextDate ? fr(r.nextDate) : '—');
    if (STATE[r.state]) return <span className={`${pill} ${STATE[r.state][1]}`}>{STATE[r.state][0]}</span>;
    return <span className="tw:text-prise-muted">Aucune relance</span>;
  };

  return (
    <section className={`${panel} tw:mb-4`} aria-labelledby="ga-title">
      <header className="tw:flex tw:flex-wrap tw:items-start tw:justify-between tw:gap-3 tw:border-b tw:border-prise-border tw:px-5 tw:py-4">
        <div className="tw:min-w-0">
          <h2 id="ga-title" className="tw:m-0 tw:font-display tw:text-[17px] tw:font-bold tw:text-prise-blue">Relances aux entreprises</h2>
          <p className="tw:m-0 tw:mt-1 tw:text-xs tw:text-prise-muted">
            Message envoyé à l’adresse e-mail de l’entreprise du contrat, selon la part consommée de la garantie en cours : <span className="tw:font-tech tw:text-prise-text">{sequence}</span>.
            Une prolongation, un renouvellement ou une modification des dates arrête la séquence.
          </p>
          <p className="tw:m-0 tw:mt-1 tw:text-xs tw:text-prise-muted">
            {data.enabled ? <span className="tw:font-semibold tw:text-prise-green-2">Relances actives</span> : <span className="tw:font-semibold tw:text-prise-danger">Relances arrêtées pour tous les contrats</span>}
            {' · '}Personnel du projet (message distinct, une fois par semaine) : {data.staff.length ? data.staff.join(', ') : 'aucun destinataire'}
          </p>
        </div>
        {data.canManage && !editing && <button type="button" className={btnGhost} onClick={() => { setErrors({}); setEditing(true); }}><i className="bi bi-sliders" aria-hidden="true" />Régler la séquence</button>}
      </header>
      {flash.text && <div className="tw:px-5 tw:pt-3"><Notice type={flash.type} onClose={() => setFlash({ ...flash, text: '' })}>{flash.text}</Notice></div>}
      {!data.smtpReady && <p className="tw:m-0 tw:border-b tw:border-prise-border tw:bg-amber-50 tw:px-5 tw:py-2 tw:text-xs tw:text-amber-900"><i className="bi bi-exclamation-triangle-fill tw:mr-1" aria-hidden="true" />Le serveur d’envoi (SMTP) n’est pas configuré dans Paramètres : aucune relance ne peut partir.</p>}
      {editing && <SettingsForm data={data} busy={busy} errors={errors} onCancel={() => setEditing(false)} onSave={(f) => call(() => api.put('/guarantees/alerts/settings', f), () => setEditing(false))} />}

      <div className="tw:overflow-x-auto">
        <table className="tw:w-full tw:border-collapse">
          <thead>
            <tr>
              <th className={th} scope="col">Contrat et entreprise</th>
              <th className={th} scope="col">Période en cours</th>
              <th className={`${th} tw:text-right`} scope="col">Consommé</th>
              <th className={th} scope="col">Niveau</th>
              <th className={th} scope="col">Envois</th>
              <th className={th} scope="col">Prochain envoi</th>
              <th className={th} scope="col"><span className="tw:sr-only">Actions</span></th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.id} className={r.kind === 'RETARD' && r.state === 'EN_COURS' ? 'tw:bg-red-50/40' : ''}>
                <td className={td}>
                  <span className="tw:block tw:font-semibold">{r.contractNumber}</span>
                  <span className="tw:block tw:font-tech tw:text-[11px] tw:text-prise-muted">Garantie n° {r.guaranteeNumber || 'non renseigné'}</span>
                  <span className="tw:block tw:text-xs">{r.company}</span>
                  <span className={`tw:block tw:text-xs ${r.email ? 'tw:text-prise-muted' : 'tw:font-semibold tw:text-amber-800'}`}>{r.email || 'Pas d’adresse e-mail : complétez la fiche de l’entreprise'}</span>
                </td>
                <td className={`${td} tw:whitespace-nowrap tw:font-tech tw:text-xs`}>{fr(r.periodStart)} → <strong>{fr(r.periodEnd)}</strong></td>
                <td className={`${td} tw:text-right tw:font-tech`}><strong className={r.consumed > 100 ? 'tw:text-prise-danger' : ''}>{num(r.consumed, 1)} %</strong></td>
                <td className={td}>
                  {r.kind ? <LevelBadge row={r} /> : <span className="tw:text-xs tw:text-prise-muted">Sous {num(lv.vigilance[0]?.from)} %</span>}
                  {r.kind && <span className="tw:mt-1 tw:block tw:text-[11px] tw:text-prise-muted">{perWeek(r.perWeek)}{r.perWeekOverride ? ' (fréquence propre)' : ''}</span>}
                </td>
                <td className={`${td} tw:text-xs`}>{r.sentCount ? <>{r.sentCount} envoyé{r.sentCount > 1 ? 's' : ''}<span className="tw:block tw:text-prise-muted">dernier le {fr(r.lastSent)}</span></> : <span className="tw:text-prise-muted">Aucun</span>}</td>
                <td className={`${td} tw:text-xs`}>
                  {next(r)}
                  {r.state === 'SUSPENDUE' && <span className="tw:mt-1 tw:block tw:text-[11px] tw:text-prise-muted">{r.pauseReason}{r.pausedBy ? ` — ${r.pausedBy}, ${frDateTime(r.pausedAt)}` : ''}</span>}
                </td>
                <td className={`${td} tw:whitespace-nowrap tw:text-right`}>
                  {(r.text || r.satisfactionText) && <button type="button" className={btnGhost} onClick={() => setDlg({ kind: 'message', row: r.text ? r : { ...r, subject: r.satisfactionSubject, text: r.satisfactionText } })} title="Voir le message"><i className="bi bi-envelope-open" aria-hidden="true" /><span className="tw:sr-only">Voir le message du contrat {r.contractNumber}</span></button>}
                  {data.canManage && r.state !== 'RESILIE' && r.state !== 'RENOUVELE' && <button type="button" className={btnGhost} onClick={() => openSteer(r)} title="Interrompre ou modifier les relances"><i className="bi bi-pause-circle" aria-hidden="true" /><span className="tw:sr-only">Piloter les relances du contrat {r.contractNumber}</span></button>}
                </td>
              </tr>
            ))}
            {!rows.length && <tr><td colSpan={7} className="tw:px-5 tw:py-6 tw:text-center tw:text-sm tw:text-prise-muted">Aucune garantie n’a atteint le premier palier : aucune relance en cours.</td></tr>}
          </tbody>
        </table>
      </div>
      {data.rows.length > concerned.length && (
        <p className="tw:m-0 tw:px-5 tw:py-2.5 tw:text-xs tw:text-prise-muted">
          {data.rows.length - concerned.length} garantie(s) sous le premier palier.{' '}
          <button type="button" className={`${btn} tw:bg-transparent tw:p-0 tw:text-xs tw:text-prise-green-2 tw:underline`} onClick={() => setShowAll(!showAll)}>{showAll ? 'Ne montrer que les garanties relancées' : 'Les afficher'}</button>
        </p>
      )}

      {dlg?.kind === 'message' && (
        <Modal title="Message envoyé à l’entreprise" subtitle={`${dlg.row.contractNumber} · ${dlg.row.company}`} onClose={() => setDlg(null)}>
          <p className="tw:mb-1 tw:text-xs tw:text-prise-muted">À : {dlg.row.email || '— (adresse manquante)'}</p>
          <p className="tw:mb-3 tw:font-semibold">{dlg.row.subject}</p>
          <div className="tw:whitespace-pre-line tw:rounded-lg tw:border tw:border-prise-border tw:bg-prise-bg tw:p-4 tw:text-sm tw:leading-relaxed">{dlg.row.text}</div>
        </Modal>
      )}
      {dlg?.kind === 'steer' && (
        <Modal
          title={`Relances — ${dlg.row.contractNumber}`} subtitle={dlg.row.company} onClose={() => setDlg(null)}
          footer={<><button className="btn btn-light" onClick={() => setDlg(null)}>Annuler</button><button className="btn btn-primary" disabled={busy || (dlg.form.paused && dlg.row.state !== 'SUSPENDUE' && dlg.form.reason.trim().length < 5)} onClick={saveSteer}>Enregistrer</button></>}
        >
          <div className="tw:grid tw:gap-4">
            {flash.type === 'danger' && flash.text && <Notice type="danger" onClose={() => setFlash({ ...flash, text: '' })}>{flash.text}</Notice>}
            <label className="tw:flex tw:items-center tw:gap-2 tw:text-sm tw:font-semibold">
              <input type="checkbox" className="tw:size-4 tw:accent-prise-green-2" checked={dlg.form.paused} onChange={(e) => setForm('paused', e.target.checked)} />
              Interrompre les relances de cette garantie
            </label>
            <p className="tw:m-0 tw:-mt-2 tw:text-xs tw:text-prise-muted">L’interruption vise l’entreprise : le personnel du projet garde son rappel hebdomadaire jusqu’à la prolongation ou au renouvellement.</p>
            {dlg.form.paused && dlg.row.state !== 'SUSPENDUE' && (
              <div>
                <label className={label} htmlFor="ga-reason">Motif de l’interruption</label>
                <textarea id="ga-reason" rows={2} className={input} value={dlg.form.reason} onChange={(e) => setForm('reason', e.target.value)} placeholder="Ex. : prolongation annoncée par la banque, dossier en cours de régularisation…" />
              </div>
            )}
            {dlg.form.paused && dlg.row.state === 'SUSPENDUE' && <p className="tw:m-0 tw:text-xs tw:text-prise-muted">Interrompues : {dlg.row.pauseReason}. Décochez pour reprendre la séquence.</p>}
            <div>
              <label className={label} htmlFor="ga-week">Fréquence</label>
              <select id="ga-week" className={`${inlineInput} tw:w-auto`} value={dlg.form.per_week} onChange={(e) => setForm('per_week', e.target.value)}>
                <option value="">Celle du palier atteint{dlg.row.autoPerWeek ? ` (${perWeek(dlg.row.autoPerWeek)})` : ''}</option>
                {[1, 2, 3, 4, 5, 6, 7].map((n) => <option key={n} value={n}>{perWeek(n)}</option>)}
              </select>
              <FieldError text={errors.per_week} />
            </div>
          </div>
        </Modal>
      )}
    </section>
  );
}

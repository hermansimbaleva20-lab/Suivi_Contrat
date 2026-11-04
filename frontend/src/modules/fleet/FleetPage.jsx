import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../api.js';
import { useLoad } from '../../lib/hooks.js';
import { AlertBlink } from '../shared/AlertBlink.jsx';
import { assetAlertLevel } from '../shared/alertLevels.js';
import { fr } from '../../lib/format.js';
import { Loading, Notice } from '../../components/ui.jsx';
import { ModuleHeader } from '../shared/ModuleHeader.jsx';
import { ReadOnlyNote } from '../shared/ReadOnlyNote.jsx';
import { fleetNav, logbookPath } from '../shared/logisticsNav.js';
import { btn, btnDanger, btnGhost, btnPrimary, FieldError, input, label, panel } from '../shared/tw.jsx';
import { td, tdNum, th } from '../stock/stockUi.jsx';
import { Facts, InsurancePill, MaintenancePill, Pill, UnitPicker } from './fleetUi.jsx';
import { levelLabel, meterUnit, q } from './fleetFormat.js';
import { useMutation, useParam } from './fleetHooks.js';

/*
 * Charroi > Parc & alertes : situation de chaque véhicule et groupe (compteur, réservoir estimé, entretien, assurance,
 * carnet tenu ou non) et alertes à traiter. Le parc et ses seuils sont tenus par la coordination nationale.
 */

const DEFAULTS = {
  VEHICULE: { tank_capacity: '120', consumption_rate: '0,2', fuel_price: '1,37', fuel_alert_level: '30', maintenance_interval: '3000', maintenance_warning: '2500' },
  GROUPE: { tank_capacity: '', consumption_rate: '', fuel_price: '1,37', fuel_alert_level: '', maintenance_interval: '100', maintenance_warning: '75' },
};
const toInput = (v) => (v === null || v === undefined ? '' : String(v).replace('.', ','));

function AssetForm({ initial, units, onSubmit, onCancel, busy, errors }) {
  const [f, setF] = useState(initial);
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value });
  const isVehicle = f.kind === 'VEHICULE';
  const u = isVehicle ? 'km' : 'h';
  const field = (k, text, props = {}) => (
    <div className={props.span || 'tw:sm:col-span-3'}>
      <label className={label} htmlFor={`as-${k}`}>{text}</label>
      <input id={`as-${k}`} className={`${input} ${props.num ? 'tw:font-tech tw:text-right' : ''}`} value={f[k]} onChange={set(k)} inputMode={props.num ? 'decimal' : undefined} placeholder={props.placeholder} required={props.required} maxLength={props.max} />
      {props.hint && <p className="tw:m-0 tw:mt-1 tw:text-[11px] tw:text-prise-muted">{props.hint}</p>}
      <FieldError text={errors[k]} />
    </div>
  );
  return (
    <form className="tw:grid tw:gap-3 tw:border-b tw:border-prise-border tw:bg-prise-bg/60 tw:px-5 tw:py-4 tw:sm:grid-cols-12" onSubmit={(e) => { e.preventDefault(); onSubmit(f); }}>
      {field('name', 'Désignation', { span: 'tw:sm:col-span-4', required: true, max: 150, placeholder: isVehicle ? 'ex. Toyota Fortuner' : 'ex. Groupe 20 kVA bureau' })}
      {field('registration', isVehicle ? 'Immatriculation' : 'N° de série (facultatif)', { max: 40, placeholder: isVehicle ? 'ex. 1083 IT 318' : '' })}
      <div className="tw:sm:col-span-5">
        <label className={label} htmlFor="as-unit">Unité d’affectation</label>
        <select id="as-unit" className={input} value={f.organizational_unit_id} onChange={set('organizational_unit_id')} required>
          <option value="">Choisir…</option>
          {units.filter((x) => x.isActive || x.id === Number(f.organizational_unit_id)).map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
        </select>
        <FieldError text={errors.organizational_unit_id} />
      </div>
      {field('tank_capacity', 'Capacité du réservoir (L)', { num: true, required: true, placeholder: 'ex. 120' })}
      {field('fuel_alert_level', 'Alerte réservoir à (L)', { num: true, hint: isVehicle ? '¼ du réservoir : 30 L pour 120 L' : 'Facultatif' })}
      {field('consumption_rate', `Consommation estimée (L/${u})`, { num: true, hint: isVehicle ? 'Méthode retenue : 0,2 L/km' : 'Facultatif' })}
      {field('fuel_price', 'Prix du litre (USD)', { num: true, required: true, hint: 'Proposé à la saisie, modifiable' })}
      {field('maintenance_interval', `Entretien tous les (${u})`, { num: true, required: true })}
      {field('maintenance_warning', `Avertir à partir de (${u})`, { num: true, required: true, hint: 'Depuis le dernier entretien' })}
      {field('initial_meter', isVehicle ? 'Kilométrage à l’entrée dans le parc' : 'Compteur horaire à l’entrée', { num: true, span: 'tw:sm:col-span-4' })}
      <div className="tw:flex tw:items-end tw:justify-end tw:gap-2 tw:sm:col-span-8">
        <button type="button" className={btnGhost} onClick={onCancel}>Annuler</button>
        <button type="submit" className={btnPrimary} disabled={busy}>{f.id ? 'Enregistrer' : 'Ajouter au parc'}</button>
      </div>
    </form>
  );
}

function AssetActions({ a, busy, onEdit, onToggle, onDelete }) {
  const [confirm, setConfirm] = useState(false);
  if (confirm) {
    return (
      <div className="tw:flex tw:items-center tw:justify-end tw:gap-2">
        <span className="tw:text-xs tw:text-prise-danger">Supprimer ?</span>
        <button type="button" className={btnGhost} onClick={() => setConfirm(false)}>Non</button>
        <button type="button" className={`${btn} tw:bg-prise-danger tw:text-white tw:hover:bg-red-800`} disabled={busy} onClick={onDelete}>Supprimer</button>
      </div>
    );
  }
  return (
    <div className="tw:flex tw:items-center tw:justify-end tw:gap-1">
      <button type="button" className={btnGhost} onClick={onEdit} aria-label={`Modifier ${a.name}`} title="Modifier"><i className="bi bi-pencil" aria-hidden="true" /></button>
      <button type="button" className={btnGhost} disabled={busy} onClick={onToggle}>{a.isActive ? 'Désactiver' : 'Réactiver'}</button>
      {!a.lastLogDate && !a.maintenance.hasRecord && (
        <button type="button" className={btnDanger} onClick={() => setConfirm(true)} aria-label={`Supprimer ${a.name}`} title="Supprimer"><i className="bi bi-trash" aria-hidden="true" /></button>
      )}
    </div>
  );
}

export default function FleetPage({ kind }) {
  const nav = fleetNav(kind);
  const isVehicle = kind === 'VEHICULE';
  const [unitParam, setUnit] = useParam('unit');
  const { data, loading, error, reload } = useLoad('/fleet', unitParam ? { unit_id: unitParam } : {});
  const { flash, setFlash, errors, setErrors, busy, call } = useMutation(reload);
  const [editing, setEditing] = useState(null);

  if (loading && !data) return <Loading />;
  if (!data) return <Notice>{error}</Notice>;

  const logbookLink = (a) => `${logbookPath(a.kind)}?engin=${a.id}`;
  const alertLink = (al) => (al.code === 'MAINTENANCE' ? `${isVehicle ? '/fleet' : '/generators'}/maintenance` : al.code === 'INSURANCE' ? '/fleet/insurance' : logbookLink({ id: al.assetId, kind: al.kind }));
  const alerts = data.alerts.filter((a) => a.kind === kind);
  const pending = data.assets.filter((a) => a.kind === kind).reduce((s, a) => s + a.pending, 0);
  const open = (id) => { setErrors({}); setEditing(id); };
  const save = (f) => call(() => (f.id ? api.put(`/fleet/assets/${f.id}`, f) : api.post('/fleet/assets', f)), () => setEditing(null));
  const formFor = (a) => ({
    id: a.id, kind: a.kind, name: a.name, registration: a.registration || '', organizational_unit_id: String(a.unitId),
    tank_capacity: toInput(a.tankCapacity), consumption_rate: toInput(a.consumptionRate), fuel_price: toInput(a.fuelPrice), fuel_alert_level: toInput(a.fuelAlertLevel),
    maintenance_interval: String(a.maintenanceInterval), maintenance_warning: String(a.maintenanceWarning), initial_meter: toInput(a.initialMeter),
  });
  // Chaque unité tient son propre parc : seules ses unités modifiables sont proposées.
  const ownUnits = data.units.filter((u) => data.writableUnitIds.includes(u.id));
  const ownUnitId = ownUnits.length === 1 ? String(ownUnits[0].id) : '';
  const emptyAsset = { kind, name: '', registration: '', organizational_unit_id: (ownUnits.some((u) => String(u.id) === unitParam) && unitParam) || ownUnitId, initial_meter: '', ...DEFAULTS[kind] };
  const danger = alerts.filter((a) => a.level === 'danger').length;
  const unit = data.units.find((u) => u.id === data.unitId);

  const table = (kind, title) => {
    const rows = data.assets.filter((a) => a.kind === kind);
    const u = meterUnit(kind);
    return (
      <section className={panel}>
        <header className="tw:flex tw:items-center tw:justify-between tw:gap-3 tw:border-b tw:border-prise-border tw:px-5 tw:py-4">
          <h2 className="tw:m-0 tw:font-display tw:text-[17px] tw:text-prise-blue">{title} <span className="tw:font-tech tw:text-sm tw:font-medium tw:text-prise-muted">{rows.length}</span></h2>
        </header>
        <div className="tw:overflow-x-auto">
          <table className="tw:w-full tw:border-collapse">
            <thead>
              <tr>
                <th className={th} scope="col">{kind === 'GROUPE' ? 'Groupe' : 'Véhicule'}</th>
                {data.canChooseUnit && <th className={th} scope="col">Unité</th>}
                <th className={`${th} tw:text-right`} scope="col">Compteur</th>
                <th className={th} scope="col">Réservoir estimé</th>
                <th className={th} scope="col">Entretien</th>
                {kind === 'VEHICULE' && <th className={th} scope="col">Assurance</th>}
                <th className={th} scope="col">Dernière saisie</th>
                {data.canManageAssets && <th className={th} scope="col"><span className="tw:sr-only">Actions</span></th>}
              </tr>
            </thead>
            <tbody>
              {rows.map((a) => (editing === a.id ? (
                <tr key={a.id}><td colSpan={8} className="tw:p-0"><AssetForm initial={formFor(a)} units={ownUnits} onSubmit={save} onCancel={() => setEditing(null)} busy={busy} errors={errors} /></td></tr>
              ) : (
                <tr key={a.id} className={a.isActive ? 'tw:hover:bg-prise-green-soft/50' : 'tw:text-prise-muted'}>
                  <td className={td}>
                    <AlertBlink level={assetAlertLevel(alerts, a.id)}><Link to={logbookLink(a)} className="tw:font-semibold tw:text-prise-green-2 tw:no-underline tw:hover:underline">{a.name}</Link></AlertBlink>
                    {a.registration && <span className="tw:ml-2 tw:font-tech tw:text-xs tw:text-prise-muted">{a.registration}</span>}
                    {!a.isActive && <span className="tw:ml-2"><Pill>Désactivé</Pill></span>}
                    {a.pending > 0 && <span className="tw:block tw:text-[11px] tw:text-amber-800">{a.pending} saisie(s) en attente d’autorisation</span>}
                  </td>
                  {data.canChooseUnit && <td className={`${td} tw:whitespace-nowrap`}>{a.unitName}</td>}
                  <td className={tdNum}>{q(a.currentMeter)} {u}</td>
                  <td className={`${td} tw:whitespace-nowrap`}>
                    {a.fuelLevel === null ? <span className="tw:text-prise-muted">Non renseigné</span> : (
                      <>
                        <span className={`tw:font-tech tw:tabular-nums ${a.fuelAlertLevel !== null && a.fuelLevel <= a.fuelAlertLevel ? 'tw:font-semibold tw:text-prise-danger' : ''}`}>{levelLabel(a.fuelLevel, a.tankCapacity)}</span>
                        {a.autonomy !== null && <span className="tw:block tw:text-[11px] tw:text-prise-muted">autonomie ≈ {q(a.autonomy)} {u}</span>}
                      </>
                    )}
                  </td>
                  <td className={`${td} tw:whitespace-nowrap`}>
                    <MaintenancePill level={a.maintenance.level} />
                    <span className="tw:block tw:text-[11px] tw:text-prise-muted">{a.maintenance.remaining >= 0 ? `dans ${q(a.maintenance.remaining)} ${u}` : `dépassé de ${q(-a.maintenance.remaining)} ${u}`}</span>
                  </td>
                  {kind === 'VEHICULE' && (
                    <td className={`${td} tw:whitespace-nowrap`}>
                      <InsurancePill level={a.insurance?.level} />
                      {a.insurance?.endDate && <span className="tw:block tw:text-[11px] tw:text-prise-muted">au {fr(a.insurance.endDate)}</span>}
                    </td>
                  )}
                  <td className={`${td} tw:whitespace-nowrap tw:text-prise-muted`}>{a.lastLogDate ? fr(a.lastLogDate) : '—'}</td>
                  {data.canManageAssets && (
                    <td className={td}>
                      {data.writableUnitIds.includes(a.unitId) && (
                        <AssetActions a={a} busy={busy} onEdit={() => open(a.id)}
                          onToggle={() => call(() => api.put(`/fleet/assets/${a.id}/toggle`))}
                          onDelete={() => call(() => api.delete(`/fleet/assets/${a.id}`))} />
                      )}
                    </td>
                  )}
                </tr>
              )))}
            </tbody>
          </table>
        </div>
        {!rows.length && <p className="tw:m-0 tw:px-5 tw:py-6 tw:text-center tw:text-sm tw:text-prise-muted">{kind === 'GROUPE' ? 'Aucun groupe électrogène dans ce périmètre.' : 'Aucun véhicule dans ce périmètre.'}{data.canManageAssets ? ' Ajoutez-le avec « Ajouter un engin ».' : ''}</p>}
      </section>
    );
  };

  return (
    <>
      <ModuleHeader icon={nav.icon} title={nav.title} tabs={nav.tabs.map((t) => (t.end ? { ...t, badge: danger } : t))} unit={unit ? unit.name : 'Toutes les unités'}
        desc={isVehicle ? 'Situation de chaque véhicule : réservoir estimé, entretien, assurance et tenue du carnet de bord.' : 'Situation de chaque groupe : compteur horaire, réservoir estimé, entretien et tenue des relevés.'}
        actions={(
          <>
            {data.canChooseUnit && <UnitPicker data={data} value={unitParam} onChange={setUnit} hideLabel />}
            {data.canManageAssets && editing !== 'new' && (
              <button type="button" className={btnPrimary} onClick={() => open('new')}><i className="bi bi-plus-lg" aria-hidden="true" />{isVehicle ? 'Ajouter un véhicule' : 'Ajouter un groupe'}</button>
            )}
          </>
        )} />
      <Notice type={flash.type} onClose={() => setFlash({ ...flash, text: '' })}>{flash.text}</Notice>
      {data.unitId && data.writableUnitIds.length > 0 && !data.writableUnitIds.includes(data.unitId) && <ReadOnlyNote unitName={unit?.name} />}

      {editing === 'new' && (
        <section className={`${panel} tw:mb-6 tw:overflow-hidden`}>
          <h2 className="tw:m-0 tw:border-b tw:border-prise-border tw:px-5 tw:py-4 tw:font-display tw:text-[17px] tw:text-prise-blue">{isVehicle ? 'Nouveau véhicule' : 'Nouveau groupe électrogène'}</h2>
          <AssetForm initial={emptyAsset} units={ownUnits} onSubmit={save} onCancel={() => setEditing(null)} busy={busy} errors={errors} />
        </section>
      )}

      <section className={`${panel} tw:mb-6`}>
        <header className="tw:flex tw:flex-wrap tw:items-center tw:gap-x-6 tw:gap-y-2 tw:border-b tw:border-prise-border tw:px-5 tw:py-4">
          <h2 className="tw:m-0 tw:font-display tw:text-[17px] tw:text-prise-blue">Alertes à traiter</h2>
          <Facts items={[
            ['Urgentes', danger, danger ? 'tw:text-prise-danger' : ''],
            ['À surveiller', alerts.length - danger],
            pending > 0 && ['Saisies à autoriser', pending, 'tw:text-amber-800'],
          ]} />
        </header>
        {alerts.length ? (
          <ul className="tw:m-0 tw:list-none tw:p-0">
            {alerts.map((al, i) => (
              <li key={`${al.assetId}-${al.code}-${i}`} className="tw:flex tw:flex-wrap tw:items-center tw:gap-x-3 tw:gap-y-1 tw:border-b tw:border-prise-border tw:px-5 tw:py-3 tw:last:border-b-0">
                <i className={`bi ${al.level === 'danger' ? 'bi-exclamation-octagon-fill tw:text-prise-danger' : 'bi-exclamation-triangle-fill tw:text-prise-warning'}`} aria-hidden="true" />
                <span className="tw:shrink-0 tw:whitespace-nowrap tw:text-sm tw:font-semibold tw:text-prise-text"><AlertBlink level={al.level}>{al.assetName}</AlertBlink></span>
                {al.registration && <span className="tw:font-tech tw:text-xs tw:text-prise-muted">{al.registration}</span>}
                {data.canChooseUnit && <span className="tw:text-xs tw:text-prise-muted">· {al.unitName}</span>}
                <span className="tw:min-w-0 tw:flex-1 tw:basis-64 tw:text-sm tw:text-prise-text">{al.text}</span>
                <Link to={alertLink(al)} className="tw:text-sm tw:font-semibold tw:text-prise-green-2 tw:no-underline tw:hover:underline">Ouvrir</Link>
              </li>
            ))}
          </ul>
        ) : (
          <p className="tw:m-0 tw:flex tw:items-center tw:gap-2 tw:px-5 tw:py-5 tw:text-sm tw:text-prise-green-2"><i className="bi bi-check-circle-fill" aria-hidden="true" />{isVehicle ? 'Aucune alerte : réservoirs, entretiens, assurances et carnets sont à jour.' : 'Aucune alerte : réservoirs, entretiens et relevés sont à jour.'}</p>
        )}
      </section>

      {table(kind, isVehicle ? 'Véhicules' : 'Groupes électrogènes')}
    </>
  );
}

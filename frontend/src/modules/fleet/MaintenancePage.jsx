import React, { useState } from 'react';
import { api } from '../../api.js';
import { useLoad } from '../../lib/hooks.js';
import { fr, money, todayISO } from '../../lib/format.js';
import { Loading, Notice } from '../../components/ui.jsx';
import { ModuleHeader } from '../shared/ModuleHeader.jsx';
import { fleetNav } from '../shared/logisticsNav.js';
import { btnPrimary, panel } from '../shared/tw.jsx';
import { td, tdNum, th } from '../stock/stockUi.jsx';
import { Facts, MaintenancePill, UnitPicker } from './fleetUi.jsx';
import { meterUnit, q, writableAssets } from './fleetFormat.js';
import { useMutation, useParam } from './fleetHooks.js';
import { DeleteDialog, RecordForm, RowEdit } from './FleetRecords.jsx';

/*
 * Charroi > Entretiens : échéance de chaque engin (véhicules en km, groupes en heures) et historique des entretiens.
 * Un entretien enregistré remet le compteur d'échéance à zéro.
 */

const TYPES = ['Vidange et filtres', 'Révision générale', 'Pneumatiques', 'Freinage', 'Batterie', 'Réparation', 'Vidange du groupe'];
const toInput = (v) => (v === null || v === undefined ? '' : String(v).replace('.', ','));

export default function MaintenancePage({ kind }) {
  const [unitParam, setUnit] = useParam('unit');
  const { data: raw, loading, error, reload } = useLoad('/fleet/maintenances', unitParam ? { unit_id: unitParam } : {});
  const { flash, setFlash, errors, setErrors, busy, call } = useMutation(reload);
  const [editing, setEditing] = useState(null);
  const [deleting, setDeleting] = useState(null);

  if (loading && !raw) return <Loading />;
  if (!raw) return <Notice>{error}</Notice>;

  // Sous-module : véhicules (km) ou groupes électrogènes (heures).
  const nav = fleetNav(kind);
  const isVehicle = kind === 'VEHICULE';
  const history = raw.history.filter((h) => h.kind === kind);
  const data = { ...raw, assets: raw.assets.filter((a) => a.kind === kind), history, totalCost: history.reduce((s, h) => s + (h.cost || 0), 0) };
  const unit = data.units.find((u) => u.id === data.unitId);

  const writable = writableAssets(data);
  const byId = Object.fromEntries(data.assets.map((a) => [a.id, a]));
  const save = (f) => call(() => (f.id ? api.put(`/fleet/maintenances/${f.id}`, f) : api.post('/fleet/maintenances', f)), () => setEditing(null));
  const open = (v) => { setErrors({}); setEditing(v); };
  const meterOf = (assetId) => ({ meter_reading: byId[assetId] ? toInput(byId[assetId].currentMeter) : '' });
  const unitOf = () => meterUnit(kind);
  const fields = (assetId) => [
    ['maintenance_date', 'Date', { type: 'date', required: true, max: todayISO(), span: 'tw:sm:col-span-2' }],
    ['meter_reading', `Compteur (${unitOf(assetId)})`, { num: true, required: true, span: 'tw:sm:col-span-2' }],
    ['designation', 'Nature de l’entretien', { required: true, list: 'mt-types', maxLength: 255 }],
    ['cost', 'Coût (USD)', { num: true, span: 'tw:sm:col-span-2' }],
    ['provider', 'Garage ou prestataire', { maxLength: 190 }],
    ['observation', 'Observation', { maxLength: 500, span: 'tw:sm:col-span-6' }],
  ];
  const form = (initial) => (
    <RecordForm initial={initial} assets={initial.id ? [byId[initial.fleet_asset_id]] : writable} fields={fields(initial.fleet_asset_id)} onSubmit={save}
      onCancel={() => setEditing(null)} busy={busy} errors={errors} submitLabel="Enregistrer l’entretien" onAssetChange={meterOf} lists={{ 'mt-types': TYPES }} />
  );
  const order = { DUE: 0, SOON: 1, OK: 2 };
  const situation = [...data.assets].filter((a) => a.isActive).sort((a, b) => order[a.maintenance.level] - order[b.maintenance.level] || a.maintenance.remaining - b.maintenance.remaining);

  return (
    <>
      <ModuleHeader icon={nav.icon} title={nav.title} tabs={nav.tabs} unit={unit ? unit.name : 'Toutes les unités'}
        desc={isVehicle ? 'Entretien tous les 3 000 km, avertissement à 2 500 km (réglables par véhicule). Date, coût et prestataire de chaque intervention.' : 'Entretien toutes les 100 heures, avertissement à 75 heures (réglables par groupe). Date, coût et prestataire de chaque intervention.'}
        actions={(
          <>
            {data.canChooseUnit && <UnitPicker data={data} value={unitParam} onChange={setUnit} hideLabel />}
            {writable.length > 0 && editing !== 'new' && (
              <button type="button" className={btnPrimary} onClick={() => open('new')}><i className="bi bi-plus-lg" aria-hidden="true" />Enregistrer un entretien</button>
            )}
          </>
        )} />
      <Notice type={flash.type} onClose={() => setFlash({ ...flash, text: '' })}>{flash.text}</Notice>

      <section className={`${panel} tw:mb-6`}>
        <h2 className="tw:m-0 tw:border-b tw:border-prise-border tw:px-5 tw:py-4 tw:font-display tw:text-[17px] tw:text-prise-blue">Échéances</h2>
        <div className="tw:overflow-x-auto">
          <table className="tw:w-full tw:border-collapse">
            <thead>
              <tr>
                <th className={th} scope="col">Engin</th>
                {data.canChooseUnit && <th className={th} scope="col">Unité</th>}
                <th className={`${th} tw:text-right`} scope="col">Compteur actuel</th>
                <th className={th} scope="col">Dernier entretien</th>
                <th className={`${th} tw:text-right`} scope="col">Depuis</th>
                <th className={`${th} tw:text-right`} scope="col">Prochain à</th>
                <th className={th} scope="col">État</th>
              </tr>
            </thead>
            <tbody>
              {situation.map((a) => {
                const u = meterUnit(a.kind);
                const m = a.maintenance;
                return (
                  <tr key={a.id}>
                    <td className={td}><span className="tw:font-semibold">{a.name}</span>{a.registration && <span className="tw:ml-2 tw:font-tech tw:text-xs tw:text-prise-muted">{a.registration}</span>}</td>
                    {data.canChooseUnit && <td className={`${td} tw:whitespace-nowrap`}>{a.unitName}</td>}
                    <td className={tdNum}>{q(a.currentMeter)} {u}</td>
                    <td className={`${td} tw:whitespace-nowrap`}>{m.lastDate ? `${fr(m.lastDate)} · ${q(m.lastMeter)} ${u}` : <span className="tw:text-prise-muted">Aucun (entrée au parc : {q(m.lastMeter)} {u})</span>}</td>
                    <td className={tdNum}>{q(m.since)} {u}</td>
                    <td className={tdNum}>{q(m.nextMeter)} {u}</td>
                    <td className={`${td} tw:whitespace-nowrap`}>
                      <MaintenancePill level={m.level} />
                      <span className="tw:block tw:text-[11px] tw:text-prise-muted">{m.remaining >= 0 ? `reste ${q(m.remaining)} ${u}` : `dépassé de ${q(-m.remaining)} ${u}`}</span>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
        {!situation.length && <p className="tw:m-0 tw:px-5 tw:py-6 tw:text-center tw:text-sm tw:text-prise-muted">Aucun engin actif dans ce périmètre.</p>}
      </section>

      <section className={panel}>
        <header className="tw:flex tw:flex-wrap tw:items-center tw:gap-x-6 tw:gap-y-2 tw:border-b tw:border-prise-border tw:px-5 tw:py-4">
          <h2 className="tw:m-0 tw:font-display tw:text-[17px] tw:text-prise-blue">Historique</h2>
          <Facts items={[['Interventions', data.history.length], ['Coût total', `${money(data.totalCost)} USD`]]} />
        </header>
        {editing === 'new' && form({ fleet_asset_id: '', maintenance_date: todayISO(), meter_reading: '', designation: '', cost: '', provider: '', observation: '' })}
        <div className="tw:overflow-x-auto">
          <table className="tw:w-full tw:border-collapse">
            <thead>
              <tr>
                <th className={th} scope="col">Date</th>
                <th className={th} scope="col">Engin</th>
                <th className={`${th} tw:text-right`} scope="col">Compteur</th>
                <th className={th} scope="col">Nature</th>
                <th className={`${th} tw:text-right`} scope="col">Coût (USD)</th>
                <th className={th} scope="col">Prestataire</th>
                <th className={th} scope="col">Observation</th>
                <th className={th} scope="col"><span className="tw:sr-only">Actions</span></th>
              </tr>
            </thead>
            <tbody>
              {data.history.map((h) => (editing === h.id ? (
                <tr key={h.id}><td colSpan={8} className="tw:p-0">{form({ id: h.id, fleet_asset_id: String(h.assetId), maintenance_date: h.date, meter_reading: toInput(h.meterReading), designation: h.designation, cost: toInput(h.cost), provider: h.provider || '', observation: h.observation })}</td></tr>
              ) : (
                <tr key={h.id} className="tw:hover:bg-prise-green-soft/50">
                  <td className={`${td} tw:font-tech tw:whitespace-nowrap`}>{fr(h.date)}</td>
                  <td className={td}>{h.assetName}{data.canChooseUnit && <span className="tw:block tw:text-[11px] tw:text-prise-muted">{h.unitName}</span>}</td>
                  <td className={tdNum}>{q(h.meterReading)} {meterUnit(h.kind)}</td>
                  <td className={td}>{h.designation}</td>
                  <td className={tdNum}>{h.cost === null ? '—' : money(h.cost)}</td>
                  <td className={td}>{h.provider}</td>
                  <td className={`${td} tw:text-xs`}>{h.observation}</td>
                  <td className={td}>{h.canEdit && <RowEdit name={h.designation} onEdit={() => open(h.id)} onDelete={() => setDeleting(h)} />}</td>
                </tr>
              )))}
            </tbody>
          </table>
        </div>
        {!data.history.length && editing !== 'new' && <p className="tw:m-0 tw:px-5 tw:py-6 tw:text-center tw:text-sm tw:text-prise-muted">Aucun entretien enregistré.</p>}
      </section>

      {deleting && (
        <DeleteDialog title="Supprimer cet entretien" url={`/fleet/maintenances/${deleting.id}`}
          text={`« ${deleting.designation} » du ${fr(deleting.date)} sur ${deleting.assetName}. L’échéance du prochain entretien sera recalculée.`}
          onClose={() => setDeleting(null)} onDone={async (m) => { setDeleting(null); setFlash({ type: 'success', text: m }); await reload(); }} />
      )}
    </>
  );
}

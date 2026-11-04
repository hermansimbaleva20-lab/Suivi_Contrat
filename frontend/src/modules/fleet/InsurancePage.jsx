import React, { useState } from 'react';
import { api } from '../../api.js';
import { useLoad } from '../../lib/hooks.js';
import { fr, money, todayISO } from '../../lib/format.js';
import { Loading, Notice } from '../../components/ui.jsx';
import { ModuleHeader } from '../shared/ModuleHeader.jsx';
import { FLEET } from '../shared/logisticsNav.js';
import { btnPrimary, panel } from '../shared/tw.jsx';
import { td, tdNum, th } from '../stock/stockUi.jsx';
import { Facts, InsurancePill, UnitPicker } from './fleetUi.jsx';
import { useMutation, useParam } from './fleetHooks.js';
import { DeleteDialog, RecordForm, RowEdit } from './FleetRecords.jsx';
import { writableAssets } from './fleetFormat.js';

/*
 * Charroi > Assurances : police en cours de chaque véhicule (alerte 30 jours avant l'échéance) et historique des polices
 * (date de début et de fin, désignation, coût, observation).
 */

const toInput = (v) => (v === null || v === undefined ? '' : String(v).replace('.', ','));
const FIELDS = [
  ['start_date', 'Date de début', { type: 'date', required: true, span: 'tw:sm:col-span-2' }],
  ['end_date', 'Date de fin', { type: 'date', required: true, span: 'tw:sm:col-span-2' }],
  ['designation', 'Désignation (assureur, n° de police)', { required: true, maxLength: 255 }],
  ['cost', 'Coût (USD)', { num: true, span: 'tw:sm:col-span-2' }],
  ['observation', 'Observation', { maxLength: 500, span: 'tw:sm:col-span-8' }],
];

export default function InsurancePage() {
  const [unitParam, setUnit] = useParam('unit');
  const { data, loading, error, reload } = useLoad('/fleet/insurances', unitParam ? { unit_id: unitParam } : {});
  const { flash, setFlash, errors, setErrors, busy, call } = useMutation(reload);
  const [editing, setEditing] = useState(null);
  const [deleting, setDeleting] = useState(null);

  if (loading && !data) return <Loading />;
  if (!data) return <Notice>{error}</Notice>;

  // Charroi : l'assurance concerne les véhicules.
  const vehicles = data.assets.filter((a) => a.kind === 'VEHICULE');
  const writable = writableAssets({ ...data, assets: vehicles });
  const unit = data.units.find((u) => u.id === data.unitId);
  const byId = Object.fromEntries(data.assets.map((a) => [a.id, a]));
  const save = (f) => call(() => (f.id ? api.put(`/fleet/insurances/${f.id}`, f) : api.post('/fleet/insurances', f)), () => setEditing(null));
  const open = (v) => { setErrors({}); setEditing(v); };
  const form = (initial) => (
    <RecordForm initial={initial} assets={initial.id ? [byId[initial.fleet_asset_id]] : writable} fields={FIELDS} onSubmit={save}
      onCancel={() => setEditing(null)} busy={busy} errors={errors} submitLabel="Enregistrer l’assurance" />
  );
  const order = { EXPIRED: 0, NONE: 1, SOON: 2, OK: 3 };
  const situation = vehicles.filter((a) => a.isActive && a.insurance).sort((a, b) => order[a.insurance.level] - order[b.insurance.level]);

  return (
    <>
      <ModuleHeader icon={FLEET.icon} title={FLEET.title} tabs={FLEET.tabs} unit={unit ? unit.name : 'Toutes les unités'}
        desc="Polices d’assurance des véhicules : date de début et de fin, désignation, coût. Alerte 30 jours avant l’échéance."
        actions={(
          <>
            {data.canChooseUnit && <UnitPicker data={data} value={unitParam} onChange={setUnit} hideLabel />}
            {writable.length > 0 && editing !== 'new' && (
              <button type="button" className={btnPrimary} onClick={() => open('new')}><i className="bi bi-plus-lg" aria-hidden="true" />Enregistrer une assurance</button>
            )}
          </>
        )} />
      <Notice type={flash.type} onClose={() => setFlash({ ...flash, text: '' })}>{flash.text}</Notice>

      <section className={`${panel} tw:mb-6`}>
        <h2 className="tw:m-0 tw:border-b tw:border-prise-border tw:px-5 tw:py-4 tw:font-display tw:text-[17px] tw:text-prise-blue">Situation</h2>
        <div className="tw:overflow-x-auto">
          <table className="tw:w-full tw:border-collapse">
            <thead>
              <tr>
                <th className={th} scope="col">Véhicule</th>
                {data.canChooseUnit && <th className={th} scope="col">Unité</th>}
                <th className={th} scope="col">Police en cours</th>
                <th className={th} scope="col">Échéance</th>
                <th className={th} scope="col">État</th>
              </tr>
            </thead>
            <tbody>
              {situation.map((a) => (
                <tr key={a.id}>
                  <td className={td}><span className="tw:font-semibold">{a.name}</span>{a.registration && <span className="tw:ml-2 tw:font-tech tw:text-xs tw:text-prise-muted">{a.registration}</span>}</td>
                  {data.canChooseUnit && <td className={`${td} tw:whitespace-nowrap`}>{a.unitName}</td>}
                  <td className={td}>{a.insurance.designation || <span className="tw:text-prise-muted">—</span>}</td>
                  <td className={`${td} tw:font-tech tw:whitespace-nowrap`}>
                    {a.insurance.endDate ? fr(a.insurance.endDate) : '—'}
                    {a.insurance.daysLeft !== null && <span className="tw:block tw:font-body tw:text-[11px] tw:text-prise-muted">{a.insurance.daysLeft >= 0 ? `dans ${a.insurance.daysLeft} jour(s)` : `depuis ${-a.insurance.daysLeft} jour(s)`}</span>}
                  </td>
                  <td className={td}><InsurancePill level={a.insurance.level} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {!situation.length && <p className="tw:m-0 tw:px-5 tw:py-6 tw:text-center tw:text-sm tw:text-prise-muted">Aucun véhicule actif dans ce périmètre.</p>}
      </section>

      <section className={panel}>
        <header className="tw:flex tw:flex-wrap tw:items-center tw:gap-x-6 tw:gap-y-2 tw:border-b tw:border-prise-border tw:px-5 tw:py-4">
          <h2 className="tw:m-0 tw:font-display tw:text-[17px] tw:text-prise-blue">Historique des polices</h2>
          <Facts items={[['Polices', data.history.length], ['Coût total', `${money(data.totalCost)} USD`]]} />
        </header>
        {editing === 'new' && form({ fleet_asset_id: '', start_date: todayISO(), end_date: '', designation: '', cost: '', observation: '' })}
        <div className="tw:overflow-x-auto">
          <table className="tw:w-full tw:border-collapse">
            <thead>
              <tr>
                <th className={th} scope="col">Engin</th>
                <th className={th} scope="col">Début</th>
                <th className={th} scope="col">Fin</th>
                <th className={th} scope="col">Désignation</th>
                <th className={`${th} tw:text-right`} scope="col">Coût (USD)</th>
                <th className={th} scope="col">Observation</th>
                <th className={th} scope="col"><span className="tw:sr-only">Actions</span></th>
              </tr>
            </thead>
            <tbody>
              {data.history.map((h) => (editing === h.id ? (
                <tr key={h.id}><td colSpan={7} className="tw:p-0">{form({ id: h.id, fleet_asset_id: String(h.assetId), start_date: h.startDate, end_date: h.endDate, designation: h.designation, cost: toInput(h.cost), observation: h.observation })}</td></tr>
              ) : (
                <tr key={h.id} className="tw:hover:bg-prise-green-soft/50">
                  <td className={td}>{h.assetName}{h.registration && <span className="tw:ml-2 tw:font-tech tw:text-xs tw:text-prise-muted">{h.registration}</span>}</td>
                  <td className={`${td} tw:font-tech tw:whitespace-nowrap`}>{fr(h.startDate)}</td>
                  <td className={`${td} tw:font-tech tw:whitespace-nowrap`}>{fr(h.endDate)}</td>
                  <td className={td}>{h.designation}</td>
                  <td className={tdNum}>{h.cost === null ? '—' : money(h.cost)}</td>
                  <td className={`${td} tw:text-xs`}>{h.observation}</td>
                  <td className={td}>{h.canEdit && <RowEdit name={h.designation} onEdit={() => open(h.id)} onDelete={() => setDeleting(h)} />}</td>
                </tr>
              )))}
            </tbody>
          </table>
        </div>
        {!data.history.length && editing !== 'new' && <p className="tw:m-0 tw:px-5 tw:py-6 tw:text-center tw:text-sm tw:text-prise-muted">Aucune assurance enregistrée.</p>}
      </section>

      {deleting && (
        <DeleteDialog title="Supprimer cette assurance" url={`/fleet/insurances/${deleting.id}`}
          text={`« ${deleting.designation} » (${fr(deleting.startDate)} – ${fr(deleting.endDate)}) sur ${deleting.assetName}.`}
          onClose={() => setDeleting(null)} onDone={async (m) => { setDeleting(null); setFlash({ type: 'success', text: m }); await reload(); }} />
      )}
    </>
  );
}

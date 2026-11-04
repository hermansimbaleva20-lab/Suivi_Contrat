import React from 'react';
import { input, label } from '../shared/tw.jsx';

/** Pastille d'état : danger (rouge), warning (ambre), ok (vert), muted (gris). */
const TONES = {
  danger: 'tw:bg-red-50 tw:text-prise-danger',
  warning: 'tw:bg-amber-50 tw:text-amber-800',
  ok: 'tw:bg-prise-green-soft tw:text-prise-green-2',
  muted: 'tw:bg-slate-100 tw:text-slate-600',
};

export function Pill({ tone = 'muted', children, title }) {
  return <span title={title} className={`tw:inline-block tw:whitespace-nowrap tw:rounded-full tw:px-2 tw:py-0.5 tw:text-[11px] tw:font-bold ${TONES[tone]}`}>{children}</span>;
}

const MAINTENANCE = { DUE: ['danger', 'Entretien dû'], SOON: ['warning', 'Entretien proche'], OK: ['ok', 'À jour'] };
export function MaintenancePill({ level }) {
  const [tone, text] = MAINTENANCE[level] || MAINTENANCE.OK;
  return <Pill tone={tone}>{text}</Pill>;
}

const INSURANCE = { EXPIRED: ['danger', 'Expirée'], SOON: ['warning', 'À renouveler'], OK: ['ok', 'Valide'], NONE: ['warning', 'Non enregistrée'] };
export function InsurancePill({ level }) {
  if (!level) return null;
  const [tone, text] = INSURANCE[level];
  return <Pill tone={tone}>{text}</Pill>;
}

/** Sélecteur d'unité : vue nationale = toutes les unités ; agent de pool = son unité, affichée sans choix. */
export function UnitPicker({ data, value, onChange, id = 'fleet-unit', hideLabel = false }) {
  const unit = data.units.find((u) => u.id === data.unitId);
  return (
    <div className="tw:w-full tw:sm:w-72">
      <label className={hideLabel ? 'tw:sr-only' : label} htmlFor={id}>Unité</label>
      {data.canChooseUnit ? (
        <select id={id} className={input} value={value || ''} onChange={(e) => onChange(e.target.value)}>
          <option value="">Toutes les unités (vue nationale)</option>
          {data.units.map((u) => <option key={u.id} value={u.id}>{u.name}{u.isActive ? '' : ' (désactivée)'}</option>)}
        </select>
      ) : (
        <div id={id} className={`${input} tw:bg-prise-bg`}>{unit?.name}</div>
      )}
    </div>
  );
}

/** Ligne de faits « libellé valeur » sous un titre de panneau. */
export function Facts({ items }) {
  return (
    <dl className="tw:m-0 tw:flex tw:flex-wrap tw:gap-x-6 tw:gap-y-1 tw:text-sm">
      {items.filter(Boolean).map(([k, v, cls = '']) => (
        <div key={k} className="tw:flex tw:gap-1.5">
          <dt className="tw:text-prise-muted">{k}</dt>
          <dd className={`tw:m-0 tw:font-tech tw:font-semibold tw:tabular-nums tw:text-prise-text ${cls}`}>{v}</dd>
        </div>
      ))}
    </dl>
  );
}

import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { api, apiError } from '../../api.js';
import { useLoad } from '../../lib/hooks.js';
import { fr, frDateTime, money, todayISO } from '../../lib/format.js';
import { Loading, Modal, Notice, ReasonDialog } from '../../components/ui.jsx';
import { ModuleHeader } from '../shared/ModuleHeader.jsx';
import { ReadOnlyNote } from '../shared/ReadOnlyNote.jsx';
import { PrintLetterhead } from '../shared/PrintLetterhead.jsx';
import { fleetNav } from '../shared/logisticsNav.js';
import { btn, btnGhost, btnPrimary, FieldError, input, label, panel } from '../shared/tw.jsx';
import { td, tdNum, th } from '../stock/stockUi.jsx';
import { Facts, InsurancePill, MaintenancePill, Pill } from './fleetUi.jsx';
import { dec, FUEL_PRESETS, levelLabel, meterUnit, monthLabel, q } from './fleetFormat.js';
import { useMutation, useParam } from './fleetHooks.js';

/*
 * Carnet de bord (véhicules) et relevés (groupes électrogènes), repris du carnet papier du projet :
 * une page par engin et par mois, totaux mensuels (km, carburant, consommation moyenne) et visas à l'impression.
 * Chaque unité gère sa logistique de manière autonome : la ligne est adressée à un valideur de l'unité de l'engin (le C.A
 * dans une antenne, le HRAF ou le comptable à la coordination nationale), qui l'autorise ou y fait objection (jamais sur sa
 * propre saisie) ; une ligne erronée est annulée avec motif.
 */

const LUBRICANTS = ['PLEIN', '½ L', '1 L', '2 L', '5 L'];
const personLabel = (p) => `${p.name}${p.position ? ` — ${p.position}` : ''}${p.unit ? ` (${p.unit})` : ''}`;

/** Personne de l'unité à qui la saisie est adressée pour autorisation (C.A en antenne, HRAF ou comptable à la coordination). */
function AuthorizerSelect({ id, authorizers, value, onChange, error }) {
  if (!authorizers.length) {
    return <p className="tw:m-0 tw:text-sm tw:text-prise-danger" role="alert">Personne n’est habilité à autoriser le carnet de bord dans cette unité : demandez au super-administrateur d’accorder l’habilitation « Autoriser » au C.A de l’antenne (ou, à la coordination nationale, au HRAF et au comptable).</p>;
  }
  return (
    <>
      <select id={id} className={input} value={value} onChange={(e) => onChange(e.target.value)} required>
        <option value="">Choisissez la personne disponible…</option>
        {authorizers.map((p) => <option key={p.id} value={p.id}>{personLabel(p)}</option>)}
      </select>
      <FieldError text={error} />
    </>
  );
}

function EntryForm({ book, onSubmit, onCancel, busy, errors }) {
  const { asset, status, suggestions } = book;
  const isVehicle = asset.kind === 'VEHICULE';
  const u = meterUnit(asset.kind);
  const [f, setF] = useState({
    log_date: todayISO(), requester: '', destination: '', purpose: '', departure_time: '', arrival_time: '',
    meter_start: String(status.currentMeter).replace('.', ','), meter_end: '', fuel_level_before: '', fuel_purchased: '',
    fuel_price: String(asset.fuelPrice).replace('.', ','), lubricant: 'PLEIN', observation: '',
    assigned_to: book.authorizers.length === 1 ? String(book.authorizers[0].id) : '',
  });
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value });
  const distance = dec(f.meter_end) - dec(f.meter_start);
  const estimated = asset.consumptionRate && distance >= 0 ? distance * asset.consumptionRate : NaN;
  const price = dec(f.fuel_price);
  const purchased = dec(f.fuel_purchased);

  const text = (k, title, props = {}) => (
    <div className={props.span || 'tw:sm:col-span-3'}>
      <label className={label} htmlFor={`lg-${k}`}>{title}</label>
      <input id={`lg-${k}`} className={`${input} ${props.num ? 'tw:font-tech tw:text-right' : ''}`} value={f[k]} onChange={set(k)} type={props.type || 'text'}
        inputMode={props.num ? 'decimal' : undefined} list={props.list} placeholder={props.placeholder} required={props.required} max={props.max} maxLength={props.maxLength} autoComplete="off" />
      <FieldError text={errors[k]} />
    </div>
  );
  const datalist = (id, items) => <datalist id={id}>{items.map((x) => <option key={x} value={x} />)}</datalist>;

  return (
    <form className="tw:grid tw:gap-3 tw:border-b tw:border-prise-border tw:bg-prise-bg/60 tw:px-5 tw:py-4 tw:sm:grid-cols-12 tw:print:hidden"
      onSubmit={(e) => { e.preventDefault(); onSubmit({ ...f, fleet_asset_id: asset.id }); }}>
      {text('log_date', 'Date', { type: 'date', max: todayISO(), required: true, span: 'tw:sm:col-span-2' })}
      {isVehicle && text('requester', 'Demandeur', { list: 'lg-requesters', required: true, maxLength: 150, placeholder: 'ex. RAF' })}
      {isVehicle && text('destination', 'Destination', { list: 'lg-destinations', required: true, maxLength: 190 })}
      {text('purpose', isVehicle ? 'Motif de la course' : 'Usage (facultatif)', { list: 'lg-purposes', maxLength: 255, span: isVehicle ? 'tw:sm:col-span-4' : 'tw:sm:col-span-6' })}
      {isVehicle && text('departure_time', 'Heure de départ', { type: 'time', span: 'tw:sm:col-span-2' })}
      {isVehicle && text('arrival_time', 'Heure d’arrivée', { type: 'time', span: 'tw:sm:col-span-2' })}
      {text('meter_start', `${isVehicle ? 'Départ' : 'Compteur au départ'} (${u})`, { num: true, required: true, span: 'tw:sm:col-span-2' })}
      {text('meter_end', `${isVehicle ? 'Arrivée' : 'Compteur à l’arrêt'} (${u})`, { num: true, required: true, span: 'tw:sm:col-span-2' })}

      <div className="tw:sm:col-span-12">
        <span className={label} id="lg-level-label">{isVehicle ? 'Quantité avant départ' : 'Quantité au démarrage'}</span>
        <div className="tw:flex tw:flex-wrap tw:items-center tw:gap-2" role="group" aria-labelledby="lg-level-label">
          {FUEL_PRESETS.map(([t, frac]) => {
            const v = String(Math.round(asset.tankCapacity * frac * 100) / 100).replace('.', ',');
            return (
              <button key={t} type="button" aria-pressed={f.fuel_level_before === v}
                className={`${btn} ${f.fuel_level_before === v ? 'tw:bg-prise-green-2 tw:text-white' : 'tw:bg-white tw:text-prise-text tw:ring-1 tw:ring-prise-border tw:hover:bg-prise-green-soft'}`}
                onClick={() => setF({ ...f, fuel_level_before: f.fuel_level_before === v ? '' : v })}>{t}</button>
            );
          })}
          <input aria-label="Quantité dans le réservoir, en litres" className={`${input} tw:w-28 tw:font-tech tw:text-right`} inputMode="decimal" value={f.fuel_level_before} onChange={set('fuel_level_before')} placeholder="Litres" />
          <span className="tw:text-xs tw:text-prise-muted">sur {q(asset.tankCapacity)} L{status.fuelLevel !== null ? ` · estimé actuellement : ${q(status.fuelLevel)} L` : ''}</span>
        </div>
        <FieldError text={errors.fuel_level_before} />
      </div>

      {text('fuel_purchased', 'Carburant acheté (L)', { num: true, placeholder: 'Seulement en cas d’achat' })}
      {text('fuel_price', 'Prix du litre (USD)', { num: true, span: 'tw:sm:col-span-2' })}
      {text('lubricant', 'Lubrifiant', { list: 'lg-lubricants', maxLength: 50, span: 'tw:sm:col-span-2' })}
      {text('observation', 'Observation (facultatif)', { maxLength: 500, span: 'tw:sm:col-span-5' })}
      <div className="tw:sm:col-span-6">
        <label className={label} htmlFor="lg-assigned_to">Adresser pour autorisation à</label>
        <AuthorizerSelect id="lg-assigned_to" authorizers={book.authorizers} value={f.assigned_to} onChange={(v) => setF({ ...f, assigned_to: v })} error={errors.assigned_to} />
      </div>
      <p className="tw:m-0 tw:self-end tw:text-xs tw:text-prise-muted tw:sm:col-span-6">La personne choisie reçoit la saisie dans ses notifications, puis l’autorise ou y fait objection. Si elle n’est pas disponible, la saisie peut être adressée à une autre personne.</p>

      <div className="tw:flex tw:flex-wrap tw:items-center tw:gap-x-5 tw:gap-y-2 tw:sm:col-span-12">
        <p className="tw:m-0 tw:flex-1 tw:text-sm tw:text-prise-muted" aria-live="polite">
          {Number.isFinite(distance) && distance >= 0 ? (
            <>
              <strong className="tw:font-tech tw:text-prise-text">{q(distance)} {u}</strong>
              {Number.isFinite(estimated) && <> · ≈ <strong className="tw:font-tech tw:text-prise-text">{q(Math.round(estimated * 100) / 100)} L</strong> estimés ({q(asset.consumptionRate)} L/{u}){Number.isFinite(price) && <>, soit {money(estimated * price)} USD</>}</>}
              {Number.isFinite(purchased) && Number.isFinite(price) && <> · achat : <strong className="tw:font-tech tw:text-prise-text">{money(purchased * price)} USD</strong></>}
            </>
          ) : 'Saisissez les compteurs de départ et d’arrivée.'}
        </p>
        <button type="button" className={btnGhost} onClick={onCancel}>Annuler</button>
        <button type="submit" className={btnPrimary} disabled={busy || !book.authorizers.length}>{isVehicle ? 'Enregistrer la course' : 'Enregistrer le relevé'}</button>
      </div>
      {datalist('lg-requesters', suggestions.requesters)}
      {datalist('lg-destinations', suggestions.destinations)}
      {datalist('lg-purposes', suggestions.purposes)}
      {datalist('lg-lubricants', LUBRICANTS)}
    </form>
  );
}

function Logbook({ assetId, kind, month, setMonth, setAsset, assets }) {
  const { data: book, loading, error, reload } = useLoad(`/fleet/assets/${assetId}/logbook`, { month });
  const { flash, setFlash, errors, setErrors, busy, call } = useMutation(reload);
  const [adding, setAdding] = useState(false);
  const [cancelling, setCancelling] = useState(null);
  const [objecting, setObjecting] = useState(null);
  const [reassigning, setReassigning] = useState(null);
  const [onlyPending, setOnlyPending] = useState(false);

  if (loading && !book) return <Loading />;
  if (!book) return <Notice>{error}</Notice>;

  const { asset, status, totals } = book;
  const isVehicle = kind === 'VEHICULE';
  const u = meterUnit(kind);
  const isPending = (l) => !l.isAuthorized && !l.isCancelled && !l.isObjected;
  const lines = onlyPending ? book.lines.filter(isPending) : book.lines;

  const save = (f) => call(() => api.post('/fleet/logs', f), () => setAdding(false));
  const cancel = async (reason) => {
    try {
      const r = await api.post(`/fleet/logs/${cancelling.id}/cancel`, { reason });
      setCancelling(null);
      setFlash({ type: 'success', text: r.data.message });
      await reload();
    } catch (e) { throw new Error(apiError(e)); }
  };
  const object = async (reason) => {
    try {
      const r = await api.post(`/fleet/logs/${objecting.id}/objection`, { reason });
      setObjecting(null);
      setFlash({ type: 'success', text: r.data.message });
      await reload();
    } catch (e) { throw new Error(apiError(e)); }
  };
  const reassign = () => call(() => api.put(`/fleet/logs/${reassigning.id}/assignee`, { assigned_to: reassigning.to }), () => setReassigning(null));
  const strike = (l) => (l.isCancelled ? 'tw:line-through' : '');
  const small = `${btn} tw:bg-transparent tw:px-0 tw:py-0 tw:text-[11px] tw:font-medium tw:text-prise-muted`;

  const authCell = (l) => {
    if (l.isCancelled) return <span className="tw:text-xs"><strong className="tw:text-prise-danger">Annulé</strong> : {l.cancelReason}</span>;
    if (l.isAuthorized) return <span title={`${l.authorizedBy || ''} · ${frDateTime(l.authorizedAt)}`}><Pill tone="ok">Autorisé</Pill><span className="tw:block tw:text-[11px] tw:text-prise-muted">{l.authorizedBy}</span></span>;
    if (l.isObjected) {
      return (
        <span className="tw:block tw:max-w-56 tw:whitespace-normal" title={`${l.objectedBy || ''} · ${frDateTime(l.objectedAt)}`}>
          <Pill tone="danger">Objection</Pill>
          <span className="tw:block tw:text-[11px] tw:text-prise-muted">{l.objectedBy} : {l.objectionReason}</span>
        </span>
      );
    }
    if (l.canDecide) {
      return (
        <span className="tw:flex tw:gap-1.5">
          <button type="button" className={`${btn} tw:bg-prise-green-soft tw:text-prise-green-2 tw:hover:bg-prise-green-2 tw:hover:text-white`} disabled={busy} onClick={() => call(() => api.post(`/fleet/logs/${l.id}/authorize`))}>Autoriser</button>
          <button type="button" className={`${btn} tw:bg-white tw:text-prise-danger tw:ring-1 tw:ring-prise-border tw:hover:bg-red-50`} disabled={busy} onClick={() => setObjecting(l)}>Objection</button>
        </span>
      );
    }
    return (
      <span className="tw:block">
        <Pill tone="warning">En attente</Pill>
        {l.assignedToName && <span className="tw:block tw:text-[11px] tw:text-prise-muted">chez {l.assignedToName}</span>}
        {l.canReassign && book.authorizers.length > 1 && (
          <button type="button" className={`${small} tw:hover:text-prise-green-2 tw:print:hidden`} onClick={() => { setErrors({}); setReassigning({ ...l, to: '' }); }}>Adresser à une autre personne</button>
        )}
      </span>
    );
  };

  return (
    <>
      {/* En-tête imprimé du projet, puis le titre du carnet */}
      <PrintLetterhead
        orientation="paysage"
        title={`${isVehicle ? 'Carnet de bord véhicule' : 'Relevés du groupe électrogène'} — ${asset.name}${asset.registration ? ` ${asset.registration}` : ''}`}
        subtitle={`${asset.unitName} · mois de ${monthLabel(book.month)}`}
      />

      <Notice type={flash.type} onClose={() => setFlash({ ...flash, text: '' })}>{flash.text}</Notice>
      {book.otherUnit && <ReadOnlyNote unitName={asset.unitName} />}

      <div className="tw:mb-4 tw:flex tw:flex-wrap tw:items-end tw:gap-3 tw:print:hidden">
        <div className="tw:w-full tw:sm:w-80">
          <label className={label} htmlFor="lb-asset">{isVehicle ? 'Véhicule' : 'Groupe électrogène'}</label>
          <select id="lb-asset" className={input} value={assetId} onChange={(e) => setAsset(e.target.value)}>
            {assets.map((a) => <option key={a.id} value={a.id}>{a.name}{a.registration ? ` — ${a.registration}` : ''}{a.unitName ? ` · ${a.unitName}` : ''}{a.isActive ? '' : ' (désactivé)'}</option>)}
          </select>
        </div>
        <div>
          <label className={label} htmlFor="lb-month">Mois</label>
          <input id="lb-month" type="month" className={`${input} tw:font-tech`} value={book.month} max={todayISO().slice(0, 7)} onChange={(e) => e.target.value && setMonth(e.target.value)} />
        </div>
        <div className="tw:ml-auto tw:flex tw:flex-wrap tw:gap-2">
          {book.canWrite && !adding && (
            <button type="button" className={btnPrimary} onClick={() => { setErrors({}); setAdding(true); }}><i className="bi bi-plus-lg" aria-hidden="true" />{isVehicle ? 'Nouvelle course' : 'Nouveau relevé'}</button>
          )}
          <button type="button" className={btnGhost} onClick={() => window.print()}><i className="bi bi-printer" aria-hidden="true" />Imprimer le carnet</button>
        </div>
      </div>

      <section className={`${panel} tw:print:border-0 tw:print:shadow-none`}>
        <header className="tw:grid tw:gap-3 tw:border-b tw:border-prise-border tw:px-5 tw:py-4">
          <div className="tw:flex tw:flex-wrap tw:items-baseline tw:gap-x-3">
            <h2 className="tw:m-0 tw:font-display tw:text-[19px] tw:text-prise-blue tw:print:hidden">{asset.name}</h2>
            {asset.registration && <span className="tw:font-tech tw:text-sm tw:font-semibold tw:text-prise-muted tw:print:hidden">{asset.registration}</span>}
            <span className="tw:text-sm tw:text-prise-muted tw:print:hidden">· {asset.unitName} · {monthLabel(book.month)}</span>
          </div>
          <Facts items={[
            ['Compteur', `${q(status.currentMeter)} ${u}`],
            ['Réservoir estimé', status.fuelLevel === null ? 'non renseigné' : levelLabel(status.fuelLevel, asset.tankCapacity), status.fuelLevel !== null && asset.fuelAlertLevel !== null && status.fuelLevel <= asset.fuelAlertLevel ? 'tw:text-prise-danger' : ''],
            status.autonomy !== null && ['Autonomie', `≈ ${q(status.autonomy)} ${u}`],
            ['Dernier entretien', status.maintenance.lastDate ? `${fr(status.maintenance.lastDate)} · ${q(status.maintenance.lastMeter)} ${u}` : 'aucun'],
            ['Prochain entretien', `${q(status.maintenance.nextMeter)} ${u}`],
            isVehicle && ['Assurance', status.insurance?.endDate ? `au ${fr(status.insurance.endDate)}` : 'non enregistrée'],
          ]} />
          <div className="tw:flex tw:flex-wrap tw:items-center tw:gap-2 tw:print:hidden">
            <MaintenancePill level={status.maintenance.level} />
            {isVehicle && <InsurancePill level={status.insurance?.level} />}
            {status.alerts.filter((a) => a.code === 'FUEL' || a.code === 'STALE').map((a) => <Pill key={a.code} tone={a.level}>{a.text}</Pill>)}
          </div>
        </header>

        {adding && <EntryForm book={book} onSubmit={save} onCancel={() => setAdding(false)} busy={busy} errors={errors} />}

        {totals.pending > 0 && (
          <div className="tw:flex tw:flex-wrap tw:items-center tw:gap-3 tw:border-b tw:border-prise-border tw:bg-amber-50/60 tw:px-5 tw:py-2.5 tw:text-sm tw:print:hidden">
            <span className="tw:text-amber-900">{totals.pending} saisie(s) en attente d’autorisation par la personne désignée dans l’unité.</span>
            <label className="tw:ml-auto tw:flex tw:cursor-pointer tw:items-center tw:gap-2">
              <input type="checkbox" className="tw:size-4 tw:accent-prise-green-2" checked={onlyPending} onChange={(e) => setOnlyPending(e.target.checked)} />
              Afficher seulement celles-ci
            </label>
          </div>
        )}

        <div className="tw:overflow-x-auto">
          <table className="tw:w-full tw:border-collapse">
            <thead>
              <tr>
                <th className={th} scope="col">Date</th>
                {isVehicle && <th className={th} scope="col">Destination</th>}
                <th className={`${th} tw:text-right`} scope="col">{isVehicle ? 'Départ' : 'Début'} ({u})</th>
                <th className={`${th} tw:text-right`} scope="col">{isVehicle ? 'Arrivée' : 'Fin'} ({u})</th>
                <th className={`${th} tw:text-right`} scope="col">{isVehicle ? 'Total km' : 'Heures'}</th>
                <th className={`${th} tw:text-right`} scope="col" title="Méthode : distance × taux de consommation">Estimé (L)</th>
                <th className={th} scope="col">{isVehicle ? 'Qté avant départ' : 'Qté au démarrage'}</th>
                <th className={`${th} tw:text-right`} scope="col">Carburant acheté (L)</th>
                <th className={th} scope="col">Lubrifiant</th>
                {isVehicle && <th className={th} scope="col">H. départ</th>}
                {isVehicle && <th className={th} scope="col">H. arrivée</th>}
                <th className={th} scope="col">{isVehicle ? 'Motif de la course' : 'Usage'}</th>
                {isVehicle && <th className={th} scope="col">Demandeur</th>}
                <th className={th} scope="col">Autorisation</th>
                <th className={th} scope="col">Observation</th>
              </tr>
            </thead>
            <tbody>
              {lines.map((l) => (
                <tr key={l.id} className={l.isCancelled ? 'tw:text-prise-muted' : 'tw:hover:bg-prise-green-soft/50'}>
                  <td className={`${td} tw:font-tech tw:whitespace-nowrap ${strike(l)}`}>{fr(l.date)}</td>
                  {isVehicle && <td className={`${td} ${strike(l)}`}>{l.destination}</td>}
                  <td className={`${tdNum} ${strike(l)}`}>{q(l.meterStart)}</td>
                  <td className={`${tdNum} ${strike(l)}`}>{q(l.meterEnd)}</td>
                  <td className={`${tdNum} tw:font-semibold ${strike(l)}`}>{q(l.distance)}</td>
                  <td className={`${tdNum} tw:text-prise-muted ${strike(l)}`}>{q(l.estimatedLitres)}</td>
                  <td className={`${td} tw:whitespace-nowrap tw:font-tech ${strike(l)}`}>{l.fuelLevelBefore === null ? '' : levelLabel(l.fuelLevelBefore, asset.tankCapacity)}</td>
                  <td className={`${tdNum} ${strike(l)}`}>{l.fuelPurchased === null ? '' : q(l.fuelPurchased)}{l.purchaseCost !== null && <span className="tw:block tw:text-[11px] tw:font-normal tw:text-prise-muted">{money(l.purchaseCost)} USD</span>}</td>
                  <td className={`${td} tw:whitespace-nowrap tw:font-tech ${strike(l)}`}>{l.lubricant || ''}</td>
                  {isVehicle && <td className={`${td} tw:font-tech ${strike(l)}`}>{l.departureTime}</td>}
                  {isVehicle && <td className={`${td} tw:font-tech ${strike(l)}`}>{l.arrivalTime}</td>}
                  <td className={`${td} tw:min-w-40 ${strike(l)}`}>{l.purpose}</td>
                  {isVehicle && <td className={`${td} ${strike(l)}`}>{l.requester}</td>}
                  <td className={`${td} tw:whitespace-nowrap`}>{authCell(l)}</td>
                  <td className={`${td} tw:min-w-48 tw:text-xs`}>
                    {l.observation}
                    {l.createdBy && <span className="tw:block tw:text-[11px] tw:text-prise-muted tw:print:hidden">Saisi par {l.createdBy}</span>}
                    {book.canWrite && !l.isCancelled && (
                      <button type="button" className={`${small} tw:mt-1 tw:hover:text-prise-danger`} onClick={() => setCancelling(l)}>Annuler la saisie</button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {!lines.length && (
          <p className="tw:m-0 tw:px-5 tw:py-8 tw:text-center tw:text-sm tw:text-prise-muted">
            {onlyPending ? 'Aucune saisie en attente.' : `Aucune saisie en ${monthLabel(book.month)}.`}{book.canWrite && !onlyPending ? ` Ajoutez ${isVehicle ? 'la première course' : 'le premier relevé'} du mois.` : ''}
          </p>
        )}

        {/* Pied du carnet : totaux mensuels du modèle papier */}
        <footer className="tw:grid tw:gap-2 tw:border-t-2 tw:border-prise-border tw:bg-prise-bg/60 tw:px-5 tw:py-4 tw:print:bg-transparent" aria-label="Totaux du mois">
          <Facts items={[
            [isVehicle ? 'Total km mensuel' : 'Total heures du mois', `${q(totals.distance)} ${u}`],
            ['Total carburant du mois', `${q(totals.litresPurchased)} L · ${money(totals.purchaseCost)} USD`],
            [isVehicle ? 'Consommation moyenne' : 'Consommation moyenne', isVehicle ? (totals.per100Km === null ? '—' : `${q(totals.per100Km)} L/100 km`) : (totals.perHour === null ? '—' : `${q(totals.perHour)} L/h`)],
          ]} />
          <Facts items={[
            ['Estimation (méthode)', `${q(totals.estimatedLitres)} L · ${money(totals.estimatedCost)} USD`],
            ['Lubrifiant', `${totals.lubricantEntries} appoint(s)`],
            ['Saisies autorisées', totals.entries],
            totals.pending > 0 && ['Hors totaux (à autoriser)', `${totals.pending} · ${q(totals.pendingDistance)} ${u} · ${q(totals.pendingLitres)} L`, 'tw:text-amber-800'],
            totals.objected > 0 && ['Objections (hors totaux)', totals.objected, 'tw:text-prise-danger'],
          ]} />
          <p className="tw:m-0 tw:text-[11px] tw:text-prise-muted">Seules les saisies autorisées dans l’unité (par le C.A en antenne, le HRAF ou le comptable à la coordination) entrent dans les totaux.
 Une saisie objectée est annulée, puis ressaisie corrigée.</p>
        </footer>
      </section>

      {/* Visas imprimés, comme au bas du carnet papier */}
      <div className="tw:hidden tw:print:mt-8 tw:print:grid tw:print:grid-cols-3 tw:print:gap-6 tw:print:text-center tw:print:text-sm tw:print:font-semibold">
        <div>Contrôle hiérarchique / Logistique<div className="tw:mt-14 tw:border-t tw:border-prise-text" /></div>
        <div>Visa RAF<div className="tw:mt-14 tw:border-t tw:border-prise-text" /></div>
        <div>Approbation du Coordonnateur<div className="tw:mt-14 tw:border-t tw:border-prise-text" /></div>
      </div>

      {objecting && (
        <ReasonDialog
          title="Faire objection"
          text={`Saisie du ${fr(objecting.date)}${objecting.destination ? ` (${objecting.destination})` : ''} : ${q(objecting.distance)} ${u}, saisie par ${objecting.createdBy}. Elle n’entrera pas dans les totaux ; la logistique sera prévenue avec votre motif.`}
          confirmLabel="Faire objection"
          onConfirm={object}
          onClose={() => setObjecting(null)}
        />
      )}
      {reassigning && (
        <Modal title="Adresser à une autre personne" subtitle={`Saisie du ${fr(reassigning.date)}, actuellement chez ${reassigning.assignedToName || 'personne'}`} size="md" onClose={() => setReassigning(null)}
          footer={<><button type="button" className="btn btn-light" onClick={() => setReassigning(null)}>Annuler</button><button type="button" className="btn btn-primary" disabled={busy || !reassigning.to} onClick={reassign}>Adresser la saisie</button></>}>
          <label className={label} htmlFor="lg-reassign">Personne disponible</label>
          <AuthorizerSelect id="lg-reassign" authorizers={book.authorizers.filter((p) => p.id !== reassigning.assignedTo)} value={reassigning.to} onChange={(v) => setReassigning({ ...reassigning, to: v })} error={errors.assigned_to} />
        </Modal>
      )}
      {cancelling && (
        <ReasonDialog
          title="Annuler cette saisie"
          text={`Saisie du ${fr(cancelling.date)}${cancelling.destination ? ` (${cancelling.destination})` : ''} : ${q(cancelling.distance)} ${u}. Elle restera visible, barrée, et ne comptera plus dans les totaux.`}
          confirmLabel="Annuler la saisie"
          onConfirm={cancel}
          onClose={() => setCancelling(null)}
        />
      )}
    </>
  );
}

export default function LogbookPage({ kind }) {
  const [assetParam, setAsset] = useParam('engin');
  const [monthParam, setMonth] = useParam('mois');
  const { data, loading, error } = useLoad('/fleet');
  const isVehicle = kind === 'VEHICULE';
  const nav = fleetNav(kind);
  const desc = isVehicle
    ? 'Une page par véhicule et par mois : courses, carburant, lubrifiant et autorisations, avec les totaux du modèle papier.'
    : 'Relevés du compteur horaire et du carburant, par groupe et par mois. Une saisie par semaine suffit.';

  if (loading && !data) return <Loading />;
  if (!data) return <Notice>{error}</Notice>;

  const assets = data.assets.filter((a) => a.kind === kind);
  const current = assets.find((a) => String(a.id) === assetParam) || assets.find((a) => a.isActive) || assets[0];

  return (
    <>
      <ModuleHeader icon={nav.icon} title={nav.title} tabs={nav.tabs} desc={desc} unit={current?.unitName} />
      {current ? (
        <Logbook key={`${current.id}-${kind}`} assetId={current.id} kind={kind} month={monthParam || todayISO().slice(0, 7)} setMonth={setMonth} setAsset={setAsset} assets={assets} />
      ) : (
        <section className={`${panel} tw:px-5 tw:py-8 tw:text-center tw:text-sm tw:text-prise-muted`}>
          {isVehicle ? 'Aucun véhicule dans votre périmètre.' : 'Aucun groupe électrogène dans votre périmètre.'}{' '}
          {data.canManageAssets ? <Link to={isVehicle ? '/fleet' : '/generators'} className="tw:font-semibold tw:text-prise-green-2">Ajoutez-le dans « {nav.tabs[0].label} ».</Link> : 'La logistique de votre unité doit d’abord l’enregistrer dans le parc.'}

        </section>
      )}
    </>
  );
}

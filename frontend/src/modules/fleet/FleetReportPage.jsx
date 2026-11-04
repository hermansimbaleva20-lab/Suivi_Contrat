import React, { useState } from 'react';
import { apiError, download } from '../../api.js';
import { useLoad } from '../../lib/hooks.js';
import { fr, money, todayISO } from '../../lib/format.js';
import { Loading, Notice } from '../../components/ui.jsx';
import { ModuleHeader } from '../shared/ModuleHeader.jsx';
import { PrintLetterhead } from '../shared/PrintLetterhead.jsx';
import { fleetNav } from '../shared/logisticsNav.js';
import { btn, btnGhost, input, label, panel } from '../shared/tw.jsx';
import { td, tdNum, th } from '../stock/stockUi.jsx';
import { Facts, UnitPicker } from './fleetUi.jsx';
import { meterUnit, monthLabel, q } from './fleetFormat.js';
import { useParam } from './fleetHooks.js';

/*
 * Charroi > Rapports carburant : sur la période choisie, par engin et par mois, total km (ou heures), carburant acheté
 * et montant, estimation (km × taux), consommation moyenne ; totaux par unité (pool / coordination) et total général.
 * Export Excel et impression.
 */

const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
function presets() {
  const now = new Date();
  const y = now.getFullYear(), m = now.getMonth();
  const qStart = Math.floor(m / 3) * 3;
  return [
    ['Ce mois', iso(new Date(y, m, 1)), todayISO()],
    ['Mois dernier', iso(new Date(y, m - 1, 1)), iso(new Date(y, m, 0))],
    ['Ce trimestre', iso(new Date(y, qStart, 1)), todayISO()],
    ['Cette année', iso(new Date(y, 0, 1)), todayISO()],
  ];
}

export default function FleetReportPage({ kind }) {
  const [fromP, setFrom] = useParam('du');
  const [toP, setTo] = useParam('au');
  const [unitP, setUnit] = useParam('unit');
  const nav = fleetNav(kind);
  const params = { kind, ...(fromP ? { from: fromP } : {}), ...(toP ? { to: toP } : {}), ...(unitP ? { unit_id: unitP } : {}) };
  const { data, loading, error } = useLoad('/fleet/report', params);
  const [msg, setMsg] = useState('');

  if (loading && !data) return <Loading />;
  if (!data) return <Notice>{error}</Notice>;

  const isVehicle = kind === 'VEHICULE';
  const u = meterUnit(kind);
  const avg = (t) => (isVehicle ? (t.per100Km === null ? '—' : q(t.per100Km)) : (t.perHour === null ? '—' : q(t.perHour)));
  const unitName = data.units.find((x) => x.id === data.unitId)?.name || 'toutes les unités';
  const exportXlsx = async () => {
    setMsg('');
    try { await download('/fleet/report/export', params, `rapport_carburant_${data.from}_${data.to}.xlsx`); } catch (e) { setMsg(apiError(e)); }
  };
  const setPeriod = (f, t) => { setFrom(f); setTo(t); };

  const cells = (t, bold = false) => (
    <>
      <td className={`${tdNum} ${bold ? 'tw:font-semibold' : ''}`}>{q(t.distance)}</td>
      <td className={`${tdNum} ${bold ? 'tw:font-semibold' : ''}`}>{q(t.litresPurchased)}</td>
      <td className={tdNum}>{money(t.purchaseCost)}</td>
      <td className={`${tdNum} tw:text-prise-muted`}>{q(t.estimatedLitres)}</td>
      <td className={`${tdNum} tw:text-prise-muted`}>{money(t.estimatedCost)}</td>
      <td className={`${tdNum} ${bold ? 'tw:font-semibold' : ''}`}>{avg(t)}</td>
      <td className={tdNum}>{t.lubricantEntries}</td>
      <td className={tdNum}>{t.entries}{t.pending > 0 && <span className="tw:block tw:text-[11px] tw:text-amber-800">{t.pending} en attente</span>}</td>
    </>
  );
  const head = (
    <tr>
      <th className={th} scope="col">{isVehicle ? 'Véhicule' : 'Groupe'} / mois</th>
      <th className={`${th} tw:text-right`} scope="col">{isVehicle ? 'Km parcourus' : 'Heures'}</th>
      <th className={`${th} tw:text-right`} scope="col">Carburant acheté (L)</th>
      <th className={`${th} tw:text-right`} scope="col">Montant (USD)</th>
      <th className={`${th} tw:text-right`} scope="col">Estimé (L)</th>
      <th className={`${th} tw:text-right`} scope="col">Estimé (USD)</th>
      <th className={`${th} tw:text-right`} scope="col">{isVehicle ? 'L/100 km' : 'L/h'}</th>
      <th className={`${th} tw:text-right`} scope="col">Appoints de lubrifiant</th>
      <th className={`${th} tw:text-right`} scope="col">Saisies</th>
    </tr>
  );

  return (
    <>
      <ModuleHeader icon={nav.icon} title={nav.title} tabs={nav.tabs} unit={unitName}
        desc={isVehicle ? 'Total km, carburant utilisé et consommation moyenne aux 100 km, par véhicule, par mois et par unité, sur la période choisie.' : 'Heures de fonctionnement, carburant et consommation horaire, par groupe, par mois et par unité, sur la période choisie.'} />
      <Notice onClose={() => setMsg('')}>{msg}</Notice>

      <section className={`${panel} tw:mb-4 tw:px-5 tw:py-4 tw:print:hidden`}>
        <div className="tw:flex tw:flex-wrap tw:items-end tw:gap-3">
          {data.canChooseUnit && <UnitPicker data={data} value={unitP} onChange={setUnit} id="rep-unit" />}
          <div>
            <label className={label} htmlFor="rep-from">Du</label>
            <input id="rep-from" type="date" className={`${input} tw:font-tech`} value={data.from} onChange={(e) => e.target.value && setFrom(e.target.value)} />
          </div>
          <div>
            <label className={label} htmlFor="rep-to">Au</label>
            <input id="rep-to" type="date" className={`${input} tw:font-tech`} value={data.to} onChange={(e) => e.target.value && setTo(e.target.value)} />
          </div>
          <div className="tw:ml-auto tw:flex tw:gap-2">
            <button type="button" className={btnGhost} onClick={() => window.print()}><i className="bi bi-printer" aria-hidden="true" />Imprimer</button>
            <button type="button" className={`${btn} tw:bg-prise-green-soft tw:text-prise-green-2 tw:hover:bg-prise-green-2 tw:hover:text-white`} onClick={exportXlsx}><i className="bi bi-file-earmark-excel" aria-hidden="true" />Exporter en Excel</button>
          </div>
        </div>
        <div className="tw:mt-3 tw:flex tw:flex-wrap tw:gap-2">
          {presets().map(([t, f, to]) => (
            <button key={t} type="button" className={`${btn} ${data.from === f && data.to === to ? 'tw:bg-prise-green-2 tw:text-white' : 'tw:bg-white tw:text-prise-text tw:ring-1 tw:ring-prise-border tw:hover:bg-prise-green-soft'}`} onClick={() => setPeriod(f, to)}>{t}</button>
          ))}
        </div>
      </section>

      <PrintLetterhead orientation="paysage" title={isVehicle ? 'Rapport carburant des véhicules' : 'Rapport des groupes électrogènes'} subtitle={`${unitName} · du ${fr(data.from)} au ${fr(data.to)}`} />
      <section className={`${panel} tw:print:border-0 tw:print:shadow-none`}>
        <header className="tw:grid tw:gap-2 tw:border-b tw:border-prise-border tw:px-5 tw:py-4">
          <h2 className="tw:m-0 tw:font-display tw:text-[17px] tw:text-prise-blue tw:print:hidden">
            {isVehicle ? 'Carburant des véhicules' : 'Groupes électrogènes'} — {unitName}
          </h2>
          <p className="tw:m-0 tw:text-sm tw:text-prise-muted">Du {fr(data.from)} au {fr(data.to)} · estimation : {isVehicle ? 'km × taux du véhicule (0,2 L/km par défaut)' : 'heures × taux du groupe'}, au prix de chaque saisie.</p>
          <Facts items={[
            [isVehicle ? 'Total km' : 'Total heures', `${q(data.grand.distance)} ${u}`],
            ['Carburant acheté', `${q(data.grand.litresPurchased)} L · ${money(data.grand.purchaseCost)} USD`],
            ['Consommation moyenne', `${avg(data.grand)} ${isVehicle ? 'L/100 km' : 'L/h'}`],
            data.grand.pending > 0 && ['En attente d’autorisation', data.grand.pending, 'tw:text-amber-800'],
          ]} />
        </header>
        <div className="tw:overflow-x-auto">
          <table className="tw:w-full tw:border-collapse">
            <thead>{head}</thead>
            <tbody>
              {data.assets.map((a) => (
                <React.Fragment key={a.id}>
                  <tr className="tw:bg-prise-bg/60">
                    <th scope="rowgroup" colSpan={9} className={`${td} tw:text-left tw:font-semibold tw:text-prise-blue`}>
                      {a.name} {a.registration && <span className="tw:font-tech tw:text-xs tw:font-medium tw:text-prise-muted">{a.registration}</span>}
                      {data.canChooseUnit && <span className="tw:ml-2 tw:text-xs tw:font-normal tw:text-prise-muted">· {a.unitName}</span>}
                    </th>
                  </tr>
                  {a.months.map((m) => (
                    <tr key={m.month}>
                      <td className={`${td} tw:pl-6 tw:capitalize`}>{monthLabel(m.month)}</td>
                      {cells(m)}
                    </tr>
                  ))}
                  {a.months.length !== 1 && (
                    <tr>
                      <td className={`${td} tw:pl-6 tw:whitespace-nowrap ${a.months.length ? 'tw:font-semibold' : 'tw:text-prise-muted'}`}>{a.months.length ? 'Total de la période' : 'Aucune saisie'}</td>
                      {cells(a.total, true)}
                    </tr>
                  )}
                </React.Fragment>
              ))}
            </tbody>
            {data.assets.length > 0 && (
              <tfoot>
                {data.unitTotals.length > 1 && data.unitTotals.map((x) => (
                  <tr key={x.unitId} className="tw:bg-prise-bg/60">
                    <th scope="row" className={`${td} tw:text-left tw:font-semibold`}>Total {x.unitName}</th>
                    {cells(x, true)}
                  </tr>
                ))}
                <tr className="tw:bg-prise-green-soft">
                  <th scope="row" className={`${td} tw:text-left tw:font-bold tw:text-prise-green`}>Total général</th>
                  {cells(data.grand, true)}
                </tr>
              </tfoot>
            )}
          </table>
        </div>
        {!data.assets.length && <p className="tw:m-0 tw:px-5 tw:py-8 tw:text-center tw:text-sm tw:text-prise-muted">Aucun {isVehicle ? 'véhicule' : 'groupe'} dans ce périmètre.</p>}
      </section>
    </>
  );
}

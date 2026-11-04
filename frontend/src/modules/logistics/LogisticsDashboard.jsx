import React from 'react';
import { Link } from 'react-router-dom';
import { useLoad } from '../../lib/hooks.js';
import { fr, money } from '../../lib/format.js';
import { Loading, Notice } from '../../components/ui.jsx';
import { input, label, panel } from '../shared/tw.jsx';
import { ModuleHeader } from '../shared/ModuleHeader.jsx';
import { FLEET, GENERATORS, STOCK, logbookPath } from '../shared/logisticsNav.js';
import { AlertBlink } from '../shared/AlertBlink.jsx';
import { monthLabel, q } from '../fleet/fleetFormat.js';
import { useParam } from '../fleet/fleetHooks.js';

/*
 * Logistique > Tableau de bord : une colonne par sous-module (stock, charroi, groupes électrogènes), chacune avec ses
 * chiffres du mois et ce qui demande une action, puis les derniers mouvements de stock. Lecture en un coup d'œil, un clic
 * pour agir. Les blocs suivent les habilitations : un compte qui ne voit que le stock ne voit que ce bloc.
 */

/** Ligne « libellé ……… valeur » d'un relevé chiffré. */
function Row({ k, v, tone, sub }) {
  const tones = { danger: 'tw:text-prise-danger', warning: 'tw:text-amber-800', ok: 'tw:text-prise-green-2' };
  return (
    // Sans preflight, « border-dashed » s'applique aux quatre côtés : les épaisseurs sont remises à zéro sauf en bas.
    <div className="tw:flex tw:items-baseline tw:justify-between tw:gap-4 tw:border-0 tw:border-b tw:border-dashed tw:border-prise-border tw:py-2 tw:last:border-b-0">
      <dt className="tw:text-sm tw:text-prise-muted">{k}</dt>
      <dd className={`tw:m-0 tw:text-right tw:font-tech tw:text-[15px] tw:font-semibold tw:tabular-nums tw:text-prise-text ${tones[tone] || ''}`}>
        {v}{sub && <span className="tw:ml-1.5 tw:text-xs tw:font-medium tw:text-prise-muted">{sub}</span>}
      </dd>
    </div>
  );
}

function Attention({ items, empty, renderItem }) {
  if (!items.length) {
    return (
      <p className="tw:m-0 tw:flex tw:items-center tw:gap-2 tw:text-sm tw:text-prise-green-2">
        <i className="bi bi-check-circle-fill" aria-hidden="true" />{empty}
      </p>
    );
  }
  return <ul className="tw:m-0 tw:grid tw:list-none tw:gap-1 tw:p-0">{items.map(renderItem)}</ul>;
}

function Dot({ level }) {
  return <span className={`tw:mt-1.5 tw:size-2 tw:shrink-0 tw:rounded-full ${level === 'danger' || level === 'OUT' ? 'tw:bg-prise-danger' : 'tw:bg-prise-warning'}`} aria-hidden="true" />;
}

function ModulePanel({ nav, to, summary, figures, attentionTitle, children }) {
  return (
    <section className={`${panel} tw:flex tw:flex-col`} aria-labelledby={`lg-${to.slice(1)}`}>
      <header className="tw:flex tw:items-center tw:gap-3 tw:border-b tw:border-prise-border tw:px-5 tw:py-4">
        <span className="tw:grid tw:size-9 tw:place-items-center tw:rounded-lg tw:bg-prise-green-soft tw:text-prise-green-2" aria-hidden="true"><i className={`bi bi-${nav.icon}`} /></span>
        <div className="tw:min-w-0 tw:flex-1">
          <h2 id={`lg-${to.slice(1)}`} className="tw:m-0 tw:font-display tw:text-[17px] tw:font-bold tw:text-prise-blue">{nav.title}</h2>
          <p className="tw:m-0 tw:text-xs tw:text-prise-muted">{summary}</p>
        </div>
        <Link to={to} className="tw:rounded-lg tw:px-2.5 tw:py-1.5 tw:text-sm tw:font-semibold tw:text-prise-green-2 tw:no-underline tw:transition-colors tw:hover:bg-prise-green-soft">
          Ouvrir<i className="bi bi-arrow-right tw:ml-1" aria-hidden="true" />
        </Link>
      </header>
      <dl className="tw:m-0 tw:px-5 tw:py-2">{figures}</dl>
      <div className="tw:mt-auto tw:border-t tw:border-prise-border tw:bg-prise-bg/50 tw:px-5 tw:py-4">
        <h3 className="tw:m-0 tw:mb-2 tw:text-xs tw:font-bold tw:text-prise-text">{attentionTitle}</h3>
        {children}
      </div>
    </section>
  );
}

function FleetPanel({ data, kind }) {
  const nav = kind === 'GROUPE' ? GENERATORS : FLEET;
  const isVehicle = kind === 'VEHICULE';
  const m = data.month;
  const u = isVehicle ? 'km' : 'h';
  const avg = isVehicle ? m.per100Km : m.perHour;
  const urgent = data.danger + data.warning;
  return (
    <ModulePanel
      nav={nav}
      to={isVehicle ? '/fleet' : '/generators'}
      summary={`${data.count} ${isVehicle ? 'véhicule(s)' : 'groupe(s)'} en service`}
      attentionTitle={urgent ? `À traiter (${urgent})` : 'À traiter'}
      figures={(
        <>
          <Row k={isVehicle ? 'Kilomètres du mois' : 'Heures du mois'} v={`${q(m.distance)} ${u}`} />
          <Row k="Carburant du mois" v={`${q(m.litresPurchased)} L`} sub={`${money(m.purchaseCost)} USD`} />
          <Row k="Consommation moyenne" v={avg === null ? '—' : q(avg)} sub={isVehicle ? 'L/100 km' : 'L/h'} />
          <Row k="Entretiens dus ou proches" v={data.maintenanceDue} tone={data.maintenanceDue ? 'warning' : 'ok'} />
          {isVehicle && <Row k="Assurances à régulariser" v={data.insurance} tone={data.insurance ? 'warning' : 'ok'} />}
          <Row k="Saisies à autoriser" v={m.pending} tone={m.pending ? 'warning' : undefined} sub={m.pending ? `${q(m.pendingDistance)} ${u} hors totaux` : ''} />
        </>
      )}
    >
      <Attention
        items={data.alerts}
        empty={`Aucune alerte sur les ${isVehicle ? 'véhicules' : 'groupes'}.`}
        renderItem={(a, i) => (
          <li key={`${a.assetId}-${a.code}-${i}`}>
            <Link to={`${a.code === 'MAINTENANCE' ? (isVehicle ? '/fleet/maintenance' : '/generators/maintenance') : a.code === 'INSURANCE' ? '/fleet/insurance' : logbookPath(kind)}?engin=${a.assetId}`}
              className="tw:-mx-2 tw:flex tw:gap-2 tw:rounded-md tw:px-2 tw:py-1 tw:text-sm tw:text-prise-text tw:no-underline tw:transition-colors tw:hover:bg-white">
              <Dot level={a.level} />
              <span className="tw:min-w-0"><strong className="tw:font-semibold">{a.assetName}</strong> — {a.text}</span>
            </Link>
          </li>
        )}
      />
    </ModulePanel>
  );
}

export default function LogisticsDashboard() {
  const [unitParam, setUnit] = useParam('unit');
  const { data, loading, error } = useLoad('/logistics/dashboard', unitParam ? { unit_id: unitParam } : {});

  if (loading && !data) return <Loading />;
  if (!data) return <Notice>{error}</Notice>;

  const unit = data.units.find((u) => u.id === data.unitId);
  const s = data.stock;
  // Alertes des trois sous-modules, les plus graves d'abord.
  const fleetAlerts = (d, kind) => (d ? d.alerts.map((a, i) => ({
    key: `${kind}-${a.assetId}-${a.code}-${i}`, level: a.level, module: kind === 'GROUPE' ? 'Groupes' : 'Charroi', title: a.assetName, text: a.text,
    to: `${a.code === 'MAINTENANCE' ? (kind === 'GROUPE' ? '/generators/maintenance' : '/fleet/maintenance') : a.code === 'INSURANCE' ? '/fleet/insurance' : logbookPath(kind)}?engin=${a.assetId}`,
  })) : []);
  const alerts = [
    ...(s ? s.alerts.map((a) => ({ key: `s-${a.id}`, level: a.level === 'OUT' ? 'danger' : 'warning', module: 'Stock', title: a.designation, text: a.level === 'OUT' ? 'en rupture' : `${q(a.stock)} ${a.unit}, niveau minimum ${q(a.alertThreshold)}`, to: `/stock/articles/${a.id}${data.unitId ? `?unit=${data.unitId}` : ''}` })) : []),
    ...fleetAlerts(data.vehicles, 'VEHICULE'),
    ...fleetAlerts(data.generators, 'GROUPE'),
  ].sort((x, y) => (x.level === 'danger' ? 0 : 1) - (y.level === 'danger' ? 0 : 1));
  const picker = data.canChooseUnit && (
    <div className="tw:w-72">
      <label className={`${label} tw:sr-only`} htmlFor="lg-unit">Unité</label>
      <select id="lg-unit" className={input} value={unitParam} onChange={(e) => setUnit(e.target.value)}>
        <option value="">Toutes les unités (vue nationale)</option>
        {data.units.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
      </select>
    </div>
  );

  return (
    <>
      <ModuleHeader
        icon="boxes"
        title="Logistique"
        unit={unit ? unit.name : 'Vue nationale'}
        desc={`Situation de ${monthLabel(data.period.from.slice(0, 7))} au ${fr(data.period.to)} : stock, charroi et groupes électrogènes. Seules les saisies autorisées entrent dans les totaux.`}
        actions={picker}
      />

      {alerts.length > 0 && (
        <section className={`${panel} tw:mb-5`} aria-labelledby="lg-alerts">
          <header className="tw:flex tw:flex-wrap tw:items-center tw:gap-x-4 tw:gap-y-1 tw:border-b tw:border-prise-border tw:px-5 tw:py-3.5">
            <h2 id="lg-alerts" className="tw:m-0 tw:flex tw:items-center tw:gap-2 tw:font-display tw:text-[15px] tw:font-bold tw:text-prise-blue">
              <i className="bi bi-bell-fill tw:text-prise-danger" aria-hidden="true" />Alertes logistiques
            </h2>
            <span className="tw:text-sm tw:text-prise-muted">{alerts.filter((a) => a.level === 'danger').length} urgente(s) · {alerts.length} au total</span>
          </header>
          <ul className="tw:m-0 tw:grid tw:list-none tw:gap-x-6 tw:p-0 tw:px-5 tw:py-2 tw:lg:grid-cols-2">
            {alerts.slice(0, 10).map((a) => (
              <li key={a.key}>
                <Link to={a.to} className="tw:-mx-2 tw:flex tw:gap-2 tw:rounded-md tw:px-2 tw:py-1.5 tw:text-sm tw:text-prise-text tw:no-underline tw:transition-colors tw:hover:bg-prise-green-soft/60">
                  <Dot level={a.level} />
                  <span className="tw:w-24 tw:shrink-0 tw:text-xs tw:font-bold tw:text-prise-muted">{a.module}</span>
                  <span className="tw:min-w-0"><strong className="tw:font-semibold"><AlertBlink level={a.level}>{a.title}</AlertBlink></strong> — {a.text}</span>
                </Link>
              </li>
            ))}
          </ul>
        </section>
      )}

      <div className="tw:grid tw:items-stretch tw:gap-5 tw:lg:grid-cols-3">
        {s && (
          <ModulePanel
            nav={STOCK}
            to="/stock"
            summary={`${s.articles} article(s) au catalogue`}
            attentionTitle={s.low + s.out ? `À réapprovisionner (${s.low + s.out})` : 'À réapprovisionner'}
            figures={(
              <>
                <Row k="Valeur du stock" v={money(s.value)} sub="USD" />
                <Row k="Entrées du mois" v={s.monthIn.count} sub={`${money(s.monthIn.amount)} USD`} />
                <Row k="Sorties du mois" v={s.monthOut.count} sub={`${money(s.monthOut.amount)} USD`} />
                <Row k="Sous le niveau minimum" v={s.low} tone={s.low ? 'warning' : 'ok'} />
                <Row k="En rupture" v={s.out} tone={s.out ? 'danger' : 'ok'} />
              </>
            )}
          >
            <Attention
              items={s.alerts}
              empty="Tous les articles sont au-dessus de leur niveau minimum."
              renderItem={(a) => (
                <li key={a.id}>
                  <Link to={`/stock/articles/${a.id}${data.unitId ? `?unit=${data.unitId}` : ''}`}
                    className="tw:-mx-2 tw:flex tw:gap-2 tw:rounded-md tw:px-2 tw:py-1 tw:text-sm tw:text-prise-text tw:no-underline tw:transition-colors tw:hover:bg-white">
                    <Dot level={a.level} />
                    <span className="tw:min-w-0 tw:flex-1">{a.designation}</span>
                    <span className="tw:font-tech tw:tabular-nums tw:text-prise-muted">{q(a.stock)} {a.unit}{a.alertThreshold !== null && ` / min. ${q(a.alertThreshold)}`}</span>
                  </Link>
                </li>
              )}
            />
          </ModulePanel>
        )}
        {data.vehicles && <FleetPanel data={data.vehicles} kind="VEHICULE" />}
        {data.generators && <FleetPanel data={data.generators} kind="GROUPE" />}
      </div>

      {s && s.recent.length > 0 && (
        <section className={`${panel} tw:mt-5`}>
          <header className="tw:flex tw:items-center tw:justify-between tw:gap-3 tw:border-b tw:border-prise-border tw:px-5 tw:py-3.5">
            <h2 className="tw:m-0 tw:font-display tw:text-[15px] tw:font-bold tw:text-prise-blue">Derniers mouvements de stock</h2>
            <Link to="/stock" className="tw:text-sm tw:font-semibold tw:text-prise-green-2 tw:no-underline tw:hover:underline">Tout le stock</Link>
          </header>
          <ul className="tw:m-0 tw:list-none tw:p-0">
            {s.recent.map((m) => (
              <li key={m.id} className="tw:border-b tw:border-prise-border tw:last:border-b-0">
                <Link to={`/stock/articles/${m.articleId}?unit=${m.unitId}`} className="tw:flex tw:flex-wrap tw:items-center tw:gap-x-4 tw:gap-y-1 tw:px-5 tw:py-2.5 tw:text-sm tw:text-prise-text tw:no-underline tw:transition-colors tw:hover:bg-prise-green-soft/50">
                  <span className="tw:w-20 tw:font-tech tw:text-prise-muted tw:tabular-nums">{fr(m.date)}</span>
                  <span className={`tw:inline-flex tw:w-16 tw:items-center tw:gap-1 tw:text-xs tw:font-bold ${m.type === 'ENTREE' ? 'tw:text-prise-success' : 'tw:text-prise-blue'}`}>
                    <i className={`bi ${m.type === 'ENTREE' ? 'bi-arrow-down-left' : 'bi-arrow-up-right'}`} aria-hidden="true" />{m.type === 'ENTREE' ? 'Entrée' : 'Sortie'}
                  </span>
                  <span className="tw:min-w-0 tw:flex-1 tw:font-semibold">{m.designation}</span>
                  <span className="tw:font-tech tw:tabular-nums">{q(m.quantity)} {m.unit}</span>
                  <span className="tw:w-full tw:text-xs tw:text-prise-muted tw:sm:w-56 tw:sm:text-right">{m.originDestination}{data.canChooseUnit ? ` · ${m.unitName}` : ''}</span>
                </Link>
              </li>
            ))}
          </ul>
        </section>
      )}

      {!s && !data.vehicles && <Notice type="info">Aucun module logistique n’est ouvert pour votre compte.</Notice>}
    </>
  );
}

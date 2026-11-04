import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { api, apiError } from '../../api.js';
import { useLoad } from '../../lib/hooks.js';
import { fr, frDateTime, money } from '../../lib/format.js';
import { Loading, Notice, ReasonDialog } from '../../components/ui.jsx';
import { ModuleHeader } from '../shared/ModuleHeader.jsx';
import { btn, panel } from '../shared/tw.jsx';
import { td, tdNum, th } from '../stock/stockUi.jsx';
import { Pill } from './fleetUi.jsx';
import { kindLabel, meterUnit, q } from './fleetFormat.js';
import { logbookPath } from '../shared/logisticsNav.js';
import { useMutation } from './fleetHooks.js';

/*
 * Saisies du carnet de bord adressées au compte connecté (C.A en antenne, HRAF ou comptable à la coordination) :
 * il les autorise ou y fait objection avec un motif. Les saisies autorisées entrent dans les totaux du carnet.
 */

function Course({ l }) {
  const u = meterUnit(l.kind);
  return (
    <>
      <span className="tw:block tw:font-semibold">
        {l.kind === 'GROUPE' ? 'Relevé' : `Course${l.destination ? ` vers ${l.destination}` : ''}`}
        <span className="tw:font-normal tw:text-prise-muted"> · {q(l.distance)} {u}</span>
      </span>
      <span className="tw:block tw:text-xs tw:text-prise-muted">
        {[l.requester && `Demandeur : ${l.requester}`, l.purpose, `compteur ${q(l.meterStart)} → ${q(l.meterEnd)} ${u}`].filter(Boolean).join(' · ')}
      </span>
      {l.observation && <span className="tw:block tw:text-xs tw:italic tw:text-prise-muted">{l.observation}</span>}
    </>
  );
}

function Asset({ l }) {
  return (
    <>
      {/* Le carnet d'un pool ne s'ouvre que dans son périmètre de lecture ; la saisie, elle, est entièrement décrite ici. */}
      {l.canOpenLogbook
        ? <Link to={`${logbookPath(l.kind)}?engin=${l.assetId}&mois=${l.date.slice(0, 7)}`} className="tw:font-semibold tw:text-prise-green-2 tw:no-underline tw:hover:underline">{l.assetName}</Link>
        : <span className="tw:font-semibold">{l.assetName}</span>}
      <span className="tw:block tw:text-xs tw:text-prise-muted">{[kindLabel(l.kind), l.registration, l.unitName].filter(Boolean).join(' · ')}</span>
    </>
  );
}

export default function AuthorizationsPage() {
  const { data, loading, error, reload } = useLoad('/fleet/authorizations');
  const { flash, setFlash, busy, call } = useMutation(reload);
  const [objecting, setObjecting] = useState(null);

  if (loading && !data) return <Loading />;
  if (!data) return <Notice>{error}</Notice>;

  const object = async (reason) => {
    try {
      const r = await api.post(`/fleet/logs/${objecting.id}/objection`, { reason });
      setObjecting(null);
      setFlash({ type: 'success', text: r.data.message });
      await reload();
    } catch (e) { throw new Error(apiError(e)); }
  };

  return (
    <>
      <ModuleHeader icon="journal-check" title="Autorisations du carnet de bord"
        desc="Les courses et relevés que la logistique vous a adressés. Autorisez-les, ou faites objection avec un motif : la saisie objectée n’entre pas dans les totaux et son auteur en est prévenu." />
      <Notice type={flash.type} onClose={() => setFlash({ ...flash, text: '' })}>{flash.text}</Notice>

      <section className={`${panel} tw:mb-5 tw:overflow-hidden`} aria-labelledby="auth-pending">
        <header className="tw:flex tw:items-baseline tw:gap-3 tw:border-b tw:border-prise-border tw:px-5 tw:py-4">
          <h2 id="auth-pending" className="tw:m-0 tw:font-display tw:text-[19px] tw:text-prise-blue">À autoriser</h2>
          <span className="tw:font-tech tw:text-sm tw:text-prise-muted">{data.pending.length} saisie(s)</span>
        </header>
        {data.pending.length ? (
          <div className="tw:overflow-x-auto">
            <table className="tw:w-full tw:border-collapse">
              <thead>
                <tr>
                  <th className={th} scope="col">Date</th>
                  <th className={th} scope="col">Engin</th>
                  <th className={th} scope="col">Saisie</th>
                  <th className={`${th} tw:text-right`} scope="col">Carburant acheté</th>
                  <th className={th} scope="col">Lubrifiant</th>
                  <th className={th} scope="col"><span className="tw:sr-only">Décision</span></th>
                </tr>
              </thead>
              <tbody>
                {data.pending.map((l) => (
                  <tr key={l.id} className="tw:hover:bg-prise-green-soft/50">
                    <td className={`${td} tw:font-tech tw:whitespace-nowrap`}>{fr(l.date)}</td>
                    <td className={`${td} tw:min-w-44`}><Asset l={l} /></td>
                    <td className={`${td} tw:min-w-64`}>
                      <Course l={l} />
                      <span className="tw:mt-1 tw:block tw:text-[11px] tw:text-prise-muted">Saisie par {l.createdBy} le {frDateTime(l.createdAt)}</span>
                    </td>
                    <td className={tdNum}>{l.fuelPurchased === null ? '—' : `${q(l.fuelPurchased)} L`}{l.purchaseCost !== null && <span className="tw:block tw:text-[11px] tw:font-normal tw:text-prise-muted">{money(l.purchaseCost)} USD</span>}</td>
                    <td className={`${td} tw:font-tech tw:whitespace-nowrap`}>{l.lubricant || '—'}</td>
                    <td className={`${td} tw:w-px tw:whitespace-nowrap`}>
                      <span className="tw:flex tw:flex-col tw:items-stretch tw:gap-1.5">
                        <button type="button" className={`${btn} tw:bg-prise-green-2 tw:text-white tw:hover:bg-prise-green`} disabled={busy} onClick={() => call(() => api.post(`/fleet/logs/${l.id}/authorize`))}>
                          <i className="bi bi-check-lg" aria-hidden="true" />Autoriser
                        </button>
                        <button type="button" className={`${btn} tw:bg-white tw:text-prise-danger tw:ring-1 tw:ring-prise-border tw:hover:bg-red-50`} disabled={busy} onClick={() => setObjecting(l)}>
                          <i className="bi bi-x-lg" aria-hidden="true" />Objection
                        </button>
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <p className="tw:m-0 tw:px-5 tw:py-8 tw:text-center tw:text-sm tw:text-prise-muted">
            <i className="bi bi-check2-circle tw:mr-1.5 tw:text-prise-green-2" aria-hidden="true" />Aucune saisie ne vous attend. Les nouvelles saisies apparaîtront aussi dans vos notifications.
          </p>
        )}
      </section>

      {data.recent.length > 0 && (
        <section className={`${panel} tw:overflow-hidden`} aria-labelledby="auth-recent">
          <header className="tw:border-b tw:border-prise-border tw:px-5 tw:py-4">
            <h2 id="auth-recent" className="tw:m-0 tw:font-display tw:text-[17px] tw:text-prise-blue">Vos dernières décisions</h2>
          </header>
          <div className="tw:overflow-x-auto">
            <table className="tw:w-full tw:border-collapse">
              <thead>
                <tr>
                  <th className={th} scope="col">Date</th>
                  <th className={th} scope="col">Engin</th>
                  <th className={th} scope="col">Saisie</th>
                  <th className={th} scope="col">Décision</th>
                </tr>
              </thead>
              <tbody>
                {data.recent.map((l) => (
                  <tr key={l.id}>
                    <td className={`${td} tw:font-tech tw:whitespace-nowrap`}>{fr(l.date)}</td>
                    <td className={`${td} tw:min-w-44`}><Asset l={l} /></td>
                    <td className={`${td} tw:min-w-64`}><Course l={l} /></td>
                    <td className={`${td} tw:min-w-48`}>
                      {l.decision === 'AUTORISEE' ? <Pill tone="ok">Autorisée</Pill> : <Pill tone="danger">Objection</Pill>}
                      <span className="tw:block tw:text-[11px] tw:text-prise-muted">{frDateTime(l.decidedAt)}{l.objectionReason ? ` · ${l.objectionReason}` : ''}</span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}

      {objecting && (
        <ReasonDialog
          title="Faire objection"
          text={`${objecting.assetName}, saisie du ${fr(objecting.date)} par ${objecting.createdBy}. Elle n’entrera pas dans les totaux ; la logistique sera prévenue avec votre motif.`}
          confirmLabel="Faire objection"
          onConfirm={object}
          onClose={() => setObjecting(null)}
        />
      )}
    </>
  );
}

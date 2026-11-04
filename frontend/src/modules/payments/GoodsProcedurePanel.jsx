import React from 'react';
import { money, num } from '../../lib/format.js';
import { panel } from '../shared/tw.jsx';
import { advanceSituation } from './advanceSituation.js';

const th = 'tw:border-b tw:border-prise-border tw:bg-prise-bg tw:px-3 tw:py-2.5 tw:text-left tw:font-tech tw:text-[11px] tw:font-semibold tw:uppercase tw:tracking-wide tw:text-prise-muted';
const td = 'tw:border-b tw:border-prise-border tw:px-3 tw:py-2.5 tw:text-sm tw:align-top';
const num2 = 'tw:text-right tw:font-tech tw:tabular-nums tw:whitespace-nowrap';
const h3 = 'tw:m-0 tw:mb-2 tw:font-display tw:text-sm tw:font-bold tw:text-prise-blue';
const list = 'tw:m-0 tw:grid tw:gap-1.5 tw:pl-4 tw:text-sm tw:text-prise-text';

/* Fiche d'un marché de biens en procédure BAD ou nationale : conditions du marché, échéancier indicatif et documents à fournir. */
export default function GoodsProcedurePanel({ c, rules }) {
  if (!rules) return null;
  const steps = [
    { key: 'AVANCE', label: 'Avance de démarrage', rate: c.initialFinancingRate, condition: rules.advanceCondition },
    ...c.paymentSchedule,
  ];
  return (
    <section className={`${panel} tw:mb-3`} aria-labelledby="goods-title">
      <header className="tw:flex tw:flex-wrap tw:items-baseline tw:justify-between tw:gap-x-4 tw:gap-y-1 tw:border-b tw:border-prise-border tw:px-5 tw:py-3.5">
        <h2 id="goods-title" className="tw:m-0 tw:font-display tw:text-[17px] tw:font-bold tw:text-prise-blue">{c.goodsProcedureLabel}</h2>
        <p className="tw:m-0 tw:text-sm tw:text-prise-muted">{c.goodsOriginLabel}</p>
      </header>
      <div className="tw:overflow-x-auto">
        <table className="tw:w-full tw:border-collapse">
          <thead>
            <tr>
              <th className={th} scope="col">Étape</th>
              <th className={`${th} tw:text-right`} scope="col">Taux</th>
              <th className={`${th} tw:text-right`} scope="col">Montant USD</th>
              <th className={th} scope="col">Conditions</th>
            </tr>
          </thead>
          <tbody>
            {steps.map((s) => (
              <tr key={s.key}>
                <th scope="row" className={`${td} tw:whitespace-nowrap tw:text-left tw:font-semibold`}>{s.label}</th>
                <td className={`${td} ${num2}`}>{num(s.rate, 2)} %</td>
                <td className={`${td} ${num2}`}>{money(c.amount * s.rate / 100)}</td>
                <td className={`${td} tw:min-w-72 tw:text-prise-muted`}>{s.condition}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="tw:m-0 tw:border-b tw:border-prise-border tw:px-5 tw:py-2 tw:text-xs tw:text-prise-muted">
        Échéancier indicatif : les paiements suivent les décomptes et leurs retenues. <strong className="tw:font-semibold tw:text-prise-text">Avance :</strong> {advanceSituation(c)}
      </p>
      <div className="tw:grid tw:gap-5 tw:px-5 tw:py-4 tw:lg:grid-cols-2">
        <div>
          <h3 className={h3}>Conditions du marché</h3>
          <ul className={list}>
            {rules.clauses.map((x) => <li key={x}>{x}</li>)}
            {c.delayPenaltyRate > 0 && <li>Pénalité de retard : {num(c.delayPenaltyRate, 3)} % par semaine, plafonnée à {num(c.delayPenaltyCap, 2)} % du montant du marché, déduite du dernier décompte (CCAG 25.1).{c.penalties > 0 && <> Pénalité validée : <strong className="tw:font-tech">{money(c.penalties)} USD</strong>.</>}</li>}
          </ul>
        </div>
        <div>
          <h3 className={h3}>Documents à fournir</h3>
          {rules.documents.length > 0 && <ul className={`${list} tw:mb-2`}>{rules.documents.map((x) => <li key={x}>{x}</li>)}</ul>}
          <p className="tw:m-0 tw:text-sm tw:text-prise-muted">{rules.documentsNote}</p>
        </div>
      </div>
    </section>
  );
}

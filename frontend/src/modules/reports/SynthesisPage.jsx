import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useLoad } from '../../lib/hooks.js';
import { fr, money, num, todayISO } from '../../lib/format.js';
import { Loading, Notice } from '../../components/ui.jsx';
import { btn, btnGhost, btnPrimary, input, label, panel } from '../shared/tw.jsx';
import { ModuleHeader } from '../shared/ModuleHeader.jsx';
import { PrintDocument } from '../shared/PrintDocument.jsx';
import { PrintLetterhead } from '../shared/PrintLetterhead.jsx';
import '../../styles/synthesis.css';

/*
 * Rapports & suivi › Synthèse des contrats : note de synthèse d'une période (trimestre, mois, année ou dates libres), à lire
 * à l'écran et à imprimer (A4 paysage). La première page donne le résumé, les statuts, les chiffres clés et les constats ;
 * la suivante, le tableau de performance contrat par contrat. Les statuts sont comptés, jamais moyennés.
 * Le même contenu sert à l'écran et à l'impression (PrintDocument) ; les graphiques sont en HTML pour s'imprimer nets.
 */

const STATUS = {
  Retard: ['x-octagon-fill', '#c62828'], Vigilance: ['exclamation-triangle-fill', '#d98200'], Normal: ['check-circle-fill', '#16844a'],
  'Terminé': ['flag-fill', '#2f6db3'], 'Résilié': ['slash-circle-fill', '#6b7c8a'],
};
const LEVEL = { critical: ['x-octagon-fill', 'Critique', '#c62828'], warning: ['exclamation-triangle-fill', 'À surveiller', '#d98200'], info: ['info-circle-fill', 'À traiter', '#2f6db3'], good: ['check-circle-fill', 'Favorable', '#16844a'] };
const GUARANTEE = { 'Expirée': 'x-octagon-fill', 'Alerte >80%': 'exclamation-triangle-fill', Valide: 'check-circle-fill', 'À venir': 'clock', 'Non constituée': 'dash-circle', 'Sans objet': 'dash' };
const GUARANTEE_ORDER = ['Expirée', 'Alerte >80%', 'Valide', 'À venir', 'Non constituée', 'Sans objet'];
const MONTHS = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
const TYPES = [['TRIMESTRE', 'Trimestre'], ['MOIS', 'Mois'], ['ANNEE', 'Année'], ['LIBRE', 'Dates libres']];
const GUARANTEE_COLOR = { 'Expirée': '#c62828', 'Alerte >80%': '#d98200', Valide: '#16844a' };

const pct = (v) => `${num(v, 1)} %`;
const signed = (v) => `${Number(v) > 0 ? '+' : ''}${num(v, 1)}`.replace('-', '−');
const clamp = (v) => Math.max(0, Math.min(100, Number(v) || 0));

function StatusLabel({ status }) {
  const [icon, color] = STATUS[status] || ['circle', '#6b7c8a'];
  return <span className="syn-status"><i className={`bi bi-${icon}`} style={{ color }} aria-hidden="true" />{status}</span>;
}

/** Secteur des statuts : le nombre de contrats par statut, au centre le total, chaque part nommée et chiffrée à côté. */
function StatusDonut({ statuses, total }) {
  const R = 42;
  const C = 2 * Math.PI * R;
  const GAP = 1.6; // filet blanc entre deux parts
  const parts = Object.entries(statuses).filter(([, n]) => n > 0);
  let offset = 0;
  return (
    <div className="syn-donut-wrap">
      <svg className="syn-donut" viewBox="0 0 120 120" role="img" aria-label={`Statut des ${total} contrats : ${parts.map(([st, n]) => `${st} ${n}`).join(', ')}`}>
        <g transform="rotate(-90 60 60)">
          {parts.map(([st, n]) => {
            const len = (n / total) * C;
            const dash = parts.length > 1 ? Math.max(len - GAP, 0.5) : C;
            const arc = (
              <circle key={st} cx="60" cy="60" r={R} fill="none" stroke={STATUS[st]?.[1]} strokeWidth="17" strokeDasharray={`${dash} ${C - dash}`} strokeDashoffset={-offset} style={{ '--syn-c': C }}>
                <title>{`${st} : ${n} contrat${n > 1 ? 's' : ''} sur ${total} (${num((n / total) * 100)} %)`}</title>
              </circle>
            );
            offset += len;
            return arc;
          })}
        </g>
        <text x="60" y="58" textAnchor="middle" className="syn-donut-total">{total}</text>
        <text x="60" y="72" textAnchor="middle" className="syn-donut-label">contrat{total > 1 ? 's' : ''}</text>
      </svg>
      <ul className="syn-donut-legend">
        {parts.map(([st, n]) => (
          <li key={st}><StatusLabel status={st} /><strong>{n}</strong><span>{num((n / total) * 100)} %</span></li>
        ))}
      </ul>
    </div>
  );
}

/** Barre d'avancement physique, repère au délai consommé : à gauche du repère, le contrat est en retard sur son délai. */
function ProgressBullet({ progress, elapsed }) {
  return (
    <div className="syn-bullet" title={`Avancement physique ${pct(progress)} pour ${pct(elapsed)} de délai consommé`}>
      <span className="syn-bullet-fill" style={{ width: `${clamp(progress)}%` }} />
      <span className="syn-bullet-tick" style={{ left: `${clamp(elapsed)}%` }} />
    </div>
  );
}

function PaymentBar({ rate, overpaid }) {
  return (
    <div className="syn-bullet" title={`Taux de paiement ${pct(rate)}${overpaid ? ', supérieur à l’avancement physique' : ''}`}>
      <span className={`syn-bullet-fill ${overpaid ? 'is-over' : 'is-pay'}`} style={{ width: `${clamp(rate)}%` }} />
    </div>
  );
}

function Figure({ name, value, unit, sub }) {
  return (
    <div className="syn-figure">
      <dt>{name}</dt>
      <dd>
        <span className="syn-figure-value">{value}{unit && <small> {unit}</small>}</span>
        {sub && <span className="syn-figure-sub">{sub}</span>}
      </dd>
    </div>
  );
}

function PerformanceTable({ rows, s }) {
  return (
    <table className="syn-table">
      <thead>
        <tr>
          <th scope="col" className="is-left">Contrat</th>
          <th scope="col" className="is-left">Statut</th>
          <th scope="col">Montant USD</th>
          <th scope="col" className="is-left syn-col-bar">Avancement physique</th>
          <th scope="col" title="Points d’avancement gagnés sur la période">Gain période</th>
          <th scope="col">Délai consommé</th>
          <th scope="col" title="Avancement physique moins délai consommé, en points">Écart</th>
          <th scope="col" title="Indice de performance des délais : avancement ÷ délai consommé">SPI</th>
          <th scope="col" className="is-left syn-col-bar">Taux de paiement</th>
          <th scope="col">Payé période USD</th>
          <th scope="col">Reste USD</th>
          <th scope="col" className="is-left">Garantie</th>
          <th scope="col" className="is-left">Décomptes</th>
        </tr>
      </thead>
      <tbody>
        {rows.map((r) => (
          <tr key={r.id}>
            <th scope="row" className="is-left">
              <Link to={`/overview/${r.id}`} className="syn-contract">{r.number}</Link>
              <span className="syn-note">{r.company}</span>
              <span className="syn-note">{r.category} · {r.sitesDone}/{r.sitesTotal} site{r.sitesTotal > 1 ? 's' : ''} achevé{r.sitesDone > 1 ? 's' : ''}{r.amendments ? ` · ${r.amendments} avenant${r.amendments > 1 ? 's' : ''}` : ''}{r.renewals ? ` · ${r.renewals} renouvellement${r.renewals > 1 ? 's' : ''}` : ''}</span>
            </th>
            <td className="is-left">
              <StatusLabel status={r.status} />
              {r.daysLate > 0 && <span className="syn-note">{num(r.daysLate)} j après la fin</span>}
            </td>
            <td>{money(r.amount)}</td>
            <td className="is-left syn-col-bar"><span className="syn-bar-value">{pct(r.progress)}</span><ProgressBullet progress={r.progress} elapsed={r.elapsed} /></td>
            <td>{signed(r.progressGain)}</td>
            <td>{pct(r.elapsed)}</td>
            <td className="syn-strong">{signed(r.gap)}</td>
            <td>{r.spi === null ? '—' : num(r.spi, 2)}</td>
            <td className="is-left syn-col-bar">
              <span className="syn-bar-value">{pct(r.paymentRate)}{r.overpaid && <i className="bi bi-exclamation-triangle-fill syn-over" title="Supérieur à l’avancement physique" aria-label="Supérieur à l’avancement physique" />}</span>
              <PaymentBar rate={r.paymentRate} overpaid={r.overpaid} />
            </td>
            <td>{money(r.paidInPeriod)}</td>
            <td>{money(r.remaining)}</td>
            <td className="is-left">
              <span className="syn-status"><i className={`bi bi-${GUARANTEE[r.guarantee] || 'circle'}`} style={{ color: GUARANTEE_COLOR[r.guarantee] || '#6b7c8a' }} aria-hidden="true" />{r.guarantee}</span>
              {r.guaranteeEnd && <span className="syn-note">{r.guaranteeNumber ? `N° ${r.guaranteeNumber} · ` : ''}au {fr(r.guaranteeEnd)}</span>}
            </td>
            <td className="is-left">
              {!r.decomptesPending && !r.toPay && <span className="syn-note">—</span>}
              {r.decomptesPending > 0 && <span className="syn-note">{r.decomptesPending} en attente</span>}
              {r.toPay > 0 && <span className="syn-note">{money(r.toPay)} à payer</span>}
            </td>
          </tr>
        ))}
      </tbody>
      <tfoot>
        <tr>
          <th scope="row" className="is-left" colSpan={2}>Ensemble ({s.contracts} contrat{s.contracts > 1 ? 's' : ''})</th>
          <td>{money(s.amount)}</td>
          <td className="is-left syn-col-bar">{pct(s.progress)} <span className="syn-note-inline">moyenne</span></td>
          <td>{signed(s.progressGain)}</td>
          <td>{pct(s.elapsed)}</td>
          <td>{signed(s.progress - s.elapsed)}</td>
          <td />
          <td className="is-left syn-col-bar">{pct(s.paymentRate)}</td>
          <td>{money(s.paidInPeriod)}</td>
          <td>{money(s.remaining)}</td>
          <td />
          <td className="is-left">{s.toPay > 0 ? `${money(s.toPay)} à payer` : ''}</td>
        </tr>
      </tfoot>
    </table>
  );
}

const DEFINITIONS = [
  ['Avancement physique', 'Moyenne des sites du contrat, cumul des saisies plafonné à 100 % par site, à la date d’arrêt.'],
  ['Repère noir', 'Délai consommé : la barre verte doit l’atteindre pour que le contrat soit au rythme prévu.'],
  ['Écart', 'Avancement physique moins délai consommé, en points. Négatif : en retard sur le délai.'],
  ['SPI', 'Indice de performance des délais : avancement physique ÷ délai consommé. 1,00 correspond au rythme prévu.'],
  ['Taux de paiement', 'Avance de démarrage et paiements enregistrés, rapportés au montant du contrat. En rouge s’il dépasse l’avancement physique.'],
  ['Statut', 'Normal, Vigilance (écart de −20 à −25 points), Retard (au-delà, ou date de fin dépassée), Terminé, Résilié.'],
];

/** Contenu de la synthèse, partagé par l'écran et l'impression. */
function SynthesisBody({ d, printing }) {
  const { summary: s, rows, period: p } = d;
  const notes = rows.filter((r) => r.observation);
  const guarantees = GUARANTEE_ORDER.filter((g) => s.guarantees[g]);
  const isFree = p.type === 'LIBRE';
  return (
    <article className="syn">
      <div className="syn-page-1">
        {printing
          ? <PrintLetterhead always title="SYNTHÈSE DES CONTRATS" subtitle={`${isFree ? p.label : `${p.label} — du ${fr(p.from)} au ${fr(p.to)}`}${p.isOpen ? ` · situation arrêtée au ${fr(p.asOf)}` : ''}`} />
          : (
            <header className="syn-head">
              <h2>{p.label}</h2>
              <p>{isFree ? '' : `Du ${fr(p.from)} au ${fr(p.to)}. `}{p.isOpen ? `Période en cours : situation arrêtée au ${fr(p.asOf)}.` : `Situation arrêtée au ${fr(p.asOf)}.`}</p>
            </header>
          )}
        <div className="syn-overview">
          <p className="syn-lead">{d.lead}</p>
          <StatusDonut statuses={s.statuses} total={s.contracts} />
        </div>
        <dl className="syn-figures">
          <Figure name="Montant contractuel" value={money(s.amount)} unit="USD" sub={`${s.contracts} contrat${s.contracts > 1 ? 's' : ''}`} />
          <Figure name="Avancement physique moyen" value={pct(s.progress)} sub={`${signed(s.progressGain)} points sur la période`} />
          <Figure name="Délai consommé moyen" value={pct(s.elapsed)} sub={`Écart : ${signed(s.progress - s.elapsed)} points`} />
          <Figure name="Taux de paiement" value={pct(s.paymentRate)} sub={`${money(s.paid)} USD payés`} />
          <Figure name="Payé sur la période" value={money(s.paidInPeriod)} unit="USD" sub={s.toPay > 0 ? `${money(s.toPay)} USD validés à payer` : 'Aucun décompte validé à payer'} />
          <Figure name="Reste à payer" value={money(s.remaining)} unit="USD" sub={s.decomptesPending ? `${s.decomptesPending} décompte${s.decomptesPending > 1 ? 's' : ''} en attente` : 'Aucun décompte en attente'} />
        </dl>
        <div className="syn-cols">
          <section className="syn-section">
            <h3>Constats de la période</h3>
            {d.findings.length ? (
              <ul className="syn-findings">
                {d.findings.map((f) => {
                  const [icon, name, color] = LEVEL[f.level];
                  return <li key={f.text}><i className={`bi bi-${icon}`} style={{ color }} role="img" aria-label={name} /><span>{f.text}</span></li>;
                })}
              </ul>
            ) : <p className="syn-empty">Aucun constat particulier sur cette période.</p>}
          </section>
          <div className="syn-side">
            <section className="syn-section">
              <h3>Par catégorie</h3>
              <table className="syn-mini">
                <thead><tr><th scope="col" className="is-left">Catégorie</th><th scope="col">Contrats</th><th scope="col">Montant USD</th><th scope="col">Avancement</th><th scope="col">Paiement</th></tr></thead>
                <tbody>
                  {s.categories.map((c) => <tr key={c.category}><th scope="row" className="is-left">{c.category}</th><td>{c.contracts}</td><td>{money(c.amount)}</td><td>{pct(c.progress)}</td><td>{pct(c.paymentRate)}</td></tr>)}
                </tbody>
              </table>
            </section>
            <section className="syn-section">
              <h3>Garanties</h3>
              <ul className="syn-guarantees">
                {guarantees.map((g) => <li key={g}><span className="syn-status"><i className={`bi bi-${GUARANTEE[g]}`} style={{ color: GUARANTEE_COLOR[g] || '#6b7c8a' }} aria-hidden="true" />{g}</span><strong>{s.guarantees[g]}</strong></li>)}
              </ul>
            </section>
          </div>
        </div>
      </div>

      <div className="syn-page-2">
        <section className="syn-section">
          <h3>Performance par contrat</h3>
          <p className="syn-legend">
            <span><i className="syn-key syn-key-progress" aria-hidden="true" />Avancement physique</span>
            <span><i className="syn-key syn-key-tick" aria-hidden="true" />Délai consommé</span>
            <span><i className="syn-key syn-key-pay" aria-hidden="true" />Taux de paiement</span>
            <span><i className="syn-key syn-key-over" aria-hidden="true" />Paiement supérieur à l’avancement</span>
          </p>
          <div className="syn-table-wrap" tabIndex={printing ? undefined : 0} role={printing ? undefined : 'region'} aria-label={printing ? undefined : 'Tableau de performance par contrat'}>
            <PerformanceTable rows={rows} s={s} />
          </div>
        </section>
        {notes.length > 0 && (
          <section className="syn-section syn-notes">
            <h3>Dernières observations de suivi</h3>
            <dl>
              {notes.map((r) => <div key={r.id}><dt>{r.number}</dt><dd>{r.observation}</dd></div>)}
            </dl>
          </section>
        )}
        <section className="syn-section syn-defs">
          <h3>Définitions</h3>
          <dl>
            {DEFINITIONS.map(([k, v]) => <div key={k}><dt>{k}</dt><dd>{v}</dd></div>)}
          </dl>
        </section>
        <p className="syn-signoff">Établi le {fr(d.issuedBy.date)} par {d.issuedBy.name}{d.issuedBy.title ? `, ${d.issuedBy.title}` : ''}. Montants en USD.</p>
      </div>
    </article>
  );
}

export default function SynthesisPage() {
  const today = todayISO();
  const thisYear = Number(today.slice(0, 4));
  const thisMonth = Number(today.slice(5, 7));
  const [f, setF] = useState({ type: 'TRIMESTRE', year: thisYear, month: thisMonth, quarter: Math.ceil(thisMonth / 3), from: '', to: today, contract_id: '' });
  const set = (k) => (e) => setF((s) => ({ ...s, [k]: e.target.value }));
  const freeReady = f.type !== 'LIBRE' || (f.from && f.to);
  const params = {
    type: f.type, contract_id: f.contract_id || undefined,
    ...(f.type === 'LIBRE' ? { from: f.from, to: f.to } : { year: f.year, index: f.type === 'MOIS' ? f.month : f.type === 'TRIMESTRE' ? f.quarter : 1 }),
  };
  const { data: d, loading, error } = useLoad('/reports/synthesis', params, !!freeReady);

  // Le nom du document est celui que le navigateur propose à l'enregistrement en PDF.
  const docName = d ? `Synthèse des contrats — ${d.period.label}` : null;
  useEffect(() => {
    if (!docName) return undefined;
    const before = document.title;
    document.title = docName;
    return () => { document.title = before; };
  }, [docName]);

  const years = Array.from({ length: 6 }, (_, i) => thisYear - i);
  const inlineField = `${input.replace('tw:w-full ', '')} tw:w-full tw:sm:w-auto`;
  const hasRows = d && d.rows.length > 0;

  return (
    <>
      <ModuleHeader
        icon="file-earmark-bar-graph" title="Synthèse des contrats"
        desc="Résumé des indicateurs de performance des contrats sur une période, à lire ici ou à imprimer."
        actions={(
          <>
            <Link to="/reports" className={btnGhost}><i className="bi bi-arrow-left" aria-hidden="true" />Rapport de suivi</Link>
            <button type="button" className={btnPrimary} disabled={!hasRows} onClick={() => window.print()}><i className="bi bi-printer" aria-hidden="true" />Imprimer la synthèse</button>
          </>
        )}
      />

      <form className={`${panel} tw:mb-4 tw:flex tw:flex-wrap tw:items-end tw:gap-x-4 tw:gap-y-3 tw:px-5 tw:py-3.5 tw:print:hidden`} onSubmit={(e) => e.preventDefault()} aria-label="Période de la synthèse">
        <fieldset className="tw:m-0 tw:border-0 tw:p-0">
          <legend className={label}>Période</legend>
          <div className="tw:flex tw:rounded-lg tw:border tw:border-prise-border tw:bg-white tw:p-0.5">
            {TYPES.map(([v, l]) => (
              <button key={v} type="button" aria-pressed={f.type === v} onClick={() => setF((s) => ({ ...s, type: v }))}
                className={`${btn} ${f.type === v ? 'tw:bg-prise-green-2 tw:text-white' : 'tw:bg-transparent tw:text-prise-text tw:hover:bg-prise-green-soft'}`}>{l}</button>
            ))}
          </div>
        </fieldset>
        {f.type === 'TRIMESTRE' && (
          <div>
            <label className={label} htmlFor="sy-q">Trimestre</label>
            <select id="sy-q" className={inlineField} value={f.quarter} onChange={set('quarter')}>
              {[1, 2, 3, 4].map((q) => <option key={q} value={q}>T{q} — {MONTHS[(q - 1) * 3]} à {MONTHS[q * 3 - 1].toLowerCase()}</option>)}
            </select>
          </div>
        )}
        {f.type === 'MOIS' && (
          <div>
            <label className={label} htmlFor="sy-m">Mois</label>
            <select id="sy-m" className={inlineField} value={f.month} onChange={set('month')}>
              {MONTHS.map((m, i) => <option key={m} value={i + 1}>{m}</option>)}
            </select>
          </div>
        )}
        {f.type !== 'LIBRE' && (
          <div>
            <label className={label} htmlFor="sy-y">Année</label>
            <select id="sy-y" className={inlineField} value={f.year} onChange={set('year')}>
              {years.map((y) => <option key={y} value={y}>{y}</option>)}
            </select>
          </div>
        )}
        {f.type === 'LIBRE' && (
          <>
            <div><label className={label} htmlFor="sy-from">Du</label><input id="sy-from" type="date" className={inlineField} value={f.from} max={f.to || undefined} onChange={set('from')} /></div>
            <div><label className={label} htmlFor="sy-to">Au</label><input id="sy-to" type="date" className={inlineField} value={f.to} min={f.from || undefined} onChange={set('to')} /></div>
          </>
        )}
        <div className="tw:w-full tw:sm:w-auto tw:sm:min-w-72">
          <label className={label} htmlFor="sy-c">Contrats</label>
          <select id="sy-c" className={input} value={f.contract_id} onChange={set('contract_id')}>
            <option value="">Tous les contrats</option>
            {(d?.contracts || []).map((c) => <option key={c.id} value={c.id}>{c.number} — {c.company}</option>)}
          </select>
        </div>
      </form>

      {!freeReady && <Notice type="info">Choisissez la date de début de la période.</Notice>}
      {freeReady && error && <Notice>{error}</Notice>}
      {freeReady && loading && !d && <Loading />}
      {freeReady && d && !hasRows && (
        <div className={`${panel} tw:px-5 tw:py-10 tw:text-center`}>
          <p className="tw:m-0 tw:font-display tw:text-lg tw:font-bold tw:text-prise-blue">Aucun contrat actif sur {d.period.type === 'LIBRE' ? 'cette période' : d.period.label}</p>
          <p className="tw:m-0 tw:mt-1 tw:text-sm tw:text-prise-muted">Élargissez la période ou choisissez « Tous les contrats ».</p>
        </div>
      )}
      {freeReady && hasRows && (
        <div className={loading ? 'syn-loading' : ''} aria-busy={loading}>
          <SynthesisBody d={d} />
          <PrintDocument orientation="paysage"><SynthesisBody d={d} printing /></PrintDocument>
        </div>
      )}
    </>
  );
}

import React, { useState } from 'react';
import { api, apiError, fieldErrors } from '../../api.js';
import { useAuth } from '../../context/AuthContext.jsx';
import { useLoad } from '../../lib/hooks.js';
import { fr, money } from '../../lib/format.js';
import { Loading, Notice } from '../../components/ui.jsx';
import { btnPrimary, FieldError, input, label, panel } from '../shared/tw.jsx';
import { ModuleHeader } from '../shared/ModuleHeader.jsx';

/*
 * Contrats > Repérage par l'IA : l'agent colle un extrait de contrat ; l'IA en extrait l'entreprise, le numéro, le montant
 * et les contacts, et le rapproche d'une entreprise du projet pour compléter ses coordonnées (destinataires d'alertes).
 */
export default function ContractExtractPage() {
  const { can } = useAuth();
  const { data: status, loading } = useLoad('/ai/status');
  const [text, setText] = useState('');
  const [result, setResult] = useState(null);
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState({});
  const [flash, setFlash] = useState({ type: 'success', text: '' });

  if (loading && !status) return <Loading />;
  const header = <ModuleHeader icon="stars" title="Repérage par l’IA" desc="Collez la première page et la page des signatures d’un contrat : l’IA repère l’entreprise titulaire, le numéro, le montant et les contacts. Vérifiez toujours le résultat." />;
  if (!status?.extraction) return <>{header}<Notice type="info">Cette fonction n’est pas autorisée. Le super-administrateur l’active dans Administration › Intelligence artificielle.</Notice></>;

  const run = async (e) => {
    e.preventDefault();
    setBusy(true); setErrors({}); setResult(null);
    try { setResult((await api.post('/ai/extract', { text })).data); } catch (err) {
      const fe = fieldErrors(err); setErrors(fe);
      if (!Object.keys(fe).length) setFlash({ type: 'danger', text: apiError(err) });
    } finally { setBusy(false); }
  };
  const apply = async () => {
    try {
      const r = await api.post('/ai/extract/apply', { company_id: result.match.id, email: result.fields.emails[0] || null, phone: result.fields.phones[0] || null });
      setFlash({ type: 'success', text: r.data.message });
    } catch (err) { setFlash({ type: 'danger', text: apiError(err) }); }
  };
  const f = result?.fields;

  return (
    <>
      {header}
      <Notice type={flash.type} onClose={() => setFlash({ ...flash, text: '' })}>{flash.text}</Notice>
      <div className="tw:grid tw:items-start tw:gap-5 tw:xl:grid-cols-2">
        <form onSubmit={run} className={`${panel} tw:grid tw:gap-3 tw:px-5 tw:py-4`}>
          <label className={label} htmlFor="ex-text">Texte du contrat</label>
          <textarea id="ex-text" rows="14" className={`${input} tw:font-body`} value={text} onChange={(e) => setText(e.target.value)} maxLength={30000} required
            placeholder="Collez ici le texte (copié depuis le PDF ou le document Word)…" />
          <FieldError text={errors.text} />
          <div className="tw:flex tw:items-center tw:justify-between tw:gap-3">
            <span className="tw:text-xs tw:text-prise-muted">{text.length.toLocaleString('fr-FR')} / 30 000 caractères</span>
            <button type="submit" className={btnPrimary} disabled={busy || text.trim().length < 50}><i className="bi bi-stars" aria-hidden="true" />{busy ? 'Analyse en cours…' : 'Analyser'}</button>
          </div>
        </form>
        {f && (
          <section className={panel}>
            <h2 className="tw:m-0 tw:border-b tw:border-prise-border tw:px-5 tw:py-4 tw:font-display tw:text-[17px] tw:font-bold tw:text-prise-blue">Informations repérées</h2>
            <dl className="tw:m-0 tw:grid tw:grid-cols-[auto_1fr] tw:gap-x-4 tw:gap-y-2 tw:px-5 tw:py-4 tw:text-sm">
              {[['Entreprise', f.company], ['N° du contrat', f.contractNumber], ['Objet', f.subject], ['Montant', f.amount !== null ? `${money(f.amount)} ${f.currency || ''}` : null],
                ['Date de signature', f.signatureDate ? fr(f.signatureDate) : null], ['Représentant', f.representative], ['Adresses e-mail', f.emails.join(', ')], ['Téléphones', f.phones.join(', ')]]
                .map(([k, val]) => <React.Fragment key={k}><dt className="tw:text-prise-muted">{k}</dt><dd className="tw:m-0 tw:font-semibold">{val || <span className="tw:font-normal tw:text-prise-muted">non trouvé</span>}</dd></React.Fragment>)}
            </dl>
            <div className="tw:border-t tw:border-prise-border tw:bg-prise-bg/60 tw:px-5 tw:py-4 tw:text-sm">
              {result.match ? (
                <div className="tw:flex tw:flex-wrap tw:items-center tw:gap-3">
                  <span>Correspond à l’entreprise <strong>{result.match.name}</strong>{result.match.email ? ` (adresse : ${result.match.email})` : ' (aucune adresse enregistrée)'}.</span>
                  {can('canManageCompanies') && (f.emails.length > 0 || f.phones.length > 0) && <button type="button" className={btnPrimary} onClick={apply}>Compléter ses coordonnées</button>}
                </div>
              ) : 'Aucune entreprise du projet ne correspond : créez-la dans Administration › Entreprises si besoin.'}
            </div>
          </section>
        )}
      </div>
    </>
  );
}

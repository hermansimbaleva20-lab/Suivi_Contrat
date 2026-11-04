import React, { useState } from 'react';
import { api } from '../../api.js';
import { useLoad } from '../../lib/hooks.js';
import { Loading, Notice } from '../../components/ui.jsx';
import { btnGhost, btnPrimary, FieldError, input, label, panel } from '../shared/tw.jsx';
import { ModuleHeader } from '../shared/ModuleHeader.jsx';
import { useMutation } from '../fleet/fleetHooks.js';

/*
 * Administration > Intelligence artificielle (super-administrateur) : fournisseur, modèle, clé d'API (chiffrée, jamais
 * réaffichée), fonctions autorisées. Tant que l'IA n'est pas activée, aucune donnée ne quitte le serveur.
 */

const FEATURES = [
  ['comments', 'Rédaction des commentaires de rapport', 'Propose le commentaire d’une période (États figés & comparaisons) à partir des indicateurs des contrats. À relire avant de figer.'],
  ['alerts', 'Synthèse des alertes par e-mail', 'Ajoute en tête de chaque message d’alerte une synthèse de ce qui est le plus urgent.'],
  ['extraction', 'Repérage des entreprises dans les contrats', 'Extrait du texte d’un contrat l’entreprise, le numéro, le montant et les contacts, pour compléter la fiche de l’entreprise.'],
];

export default function AiSettingsPage() {
  const { data, loading, error, reload } = useLoad('/ai/settings');
  const { flash, setFlash, errors, busy, call } = useMutation(reload);
  const [f, setF] = useState(null);

  if (loading && !data) return <Loading />;
  if (!data) return <Notice>{error}</Notice>;
  const v = f || { is_enabled: data.isEnabled, provider: data.provider, model: data.model, endpoint: data.endpoint, api_key: '', features: data.features };
  const set = (k, val) => setF({ ...v, [k]: val });
  const save = (e) => { e.preventDefault(); call(() => api.put('/ai/settings', v), () => setF(null)); };

  return (
    <>
      <ModuleHeader icon="stars" title="Intelligence artificielle"
        desc="L’IA est désactivée tant que vous ne l’activez pas. Activée, elle reçoit seulement les données utiles à la fonction appelée (indicateurs des contrats, texte des alertes, extrait de contrat collé par l’agent) ; chaque appel est inscrit au journal d’audit." />
      <Notice type={flash.type} onClose={() => setFlash({ ...flash, text: '' })}>{flash.text}</Notice>

      <form onSubmit={save} className={`${panel} tw:max-w-4xl`}>
        <div className="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-3 tw:border-b tw:border-prise-border tw:px-5 tw:py-4">
          <label className="tw:flex tw:cursor-pointer tw:items-center tw:gap-3 tw:font-display tw:text-[17px] tw:font-bold tw:text-prise-blue">
            <input type="checkbox" className="tw:size-5 tw:accent-prise-green-2" checked={v.is_enabled} onChange={(e) => set('is_enabled', e.target.checked)} />
            Activer l’IA pour ce projet
          </label>
          <span className={`tw:rounded-full tw:px-2.5 tw:py-0.5 tw:text-xs tw:font-bold ${data.isEnabled ? 'tw:bg-prise-green-soft tw:text-prise-green-2' : 'tw:bg-slate-100 tw:text-slate-600'}`}>{data.isEnabled ? 'Active' : 'Désactivée'}</span>
        </div>
        <div className="tw:grid tw:gap-4 tw:px-5 tw:py-4 tw:sm:grid-cols-2">
          <div>
            <label className={label} htmlFor="ai-provider">Fournisseur</label>
            <select id="ai-provider" className={input} value={v.provider} onChange={(e) => setF({ ...v, provider: e.target.value, model: e.target.value === 'ANTHROPIC' ? 'claude-opus-5-5' : v.model })}>
              <option value="ANTHROPIC">Anthropic (Claude)</option>
              <option value="OPENAI_COMPATIBLE">Service compatible OpenAI (autre fournisseur ou serveur interne)</option>
            </select>
          </div>
          <div>
            <label className={label} htmlFor="ai-model">Modèle</label>
            <input id="ai-model" className={`${input} tw:font-tech`} value={v.model} onChange={(e) => set('model', e.target.value)} maxLength={100} required list="ai-models" />
            <datalist id="ai-models">{['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5'].map((m) => <option key={m} value={m} />)}</datalist>
            <p className="tw:m-0 tw:mt-1 tw:text-[11px] tw:text-prise-muted">Recommandé : claude-opus-5-5. Sonnet 5.5 ou Haiku 5.5 coûtent moins cher.</p>
            <FieldError text={errors.model} />
          </div>
          {v.provider === 'OPENAI_COMPATIBLE' && (
            <div className="tw:sm:col-span-2">
              <label className={label} htmlFor="ai-endpoint">Adresse du service</label>
              <input id="ai-endpoint" className={`${input} tw:font-tech`} value={v.endpoint} onChange={(e) => set('endpoint', e.target.value)} placeholder="ex. https://serveur-interne/v1" />
              <FieldError text={errors.endpoint} />
            </div>
          )}
          <div className="tw:sm:col-span-2">
            <label className={label} htmlFor="ai-key">Clé d’API</label>
            <input id="ai-key" type="password" autoComplete="new-password" className={`${input} tw:font-tech`} value={v.api_key} onChange={(e) => set('api_key', e.target.value)}
              placeholder={data.hasKey ? 'Clé enregistrée (chiffrée) — laissez vide pour la conserver' : 'Collez la clé fournie par le fournisseur'} />
            <FieldError text={errors.api_key} />
          </div>
        </div>
        <fieldset className="tw:m-0 tw:border-0 tw:border-t tw:border-prise-border tw:px-5 tw:py-4">
          <legend className="tw:float-left tw:mb-2 tw:w-full tw:p-0 tw:text-sm tw:font-bold tw:text-prise-text">Fonctions autorisées</legend>
          <div className="tw:clear-both tw:grid tw:gap-2">
            {FEATURES.map(([k, t, d]) => (
              <label key={k} className={`tw:flex tw:cursor-pointer tw:gap-3 tw:rounded-lg tw:px-3 tw:py-2.5 tw:ring-1 ${v.features[k] ? 'tw:bg-prise-green-soft tw:ring-prise-green-2' : 'tw:bg-white tw:ring-prise-border'}`}>
                <input type="checkbox" className="tw:mt-0.5 tw:size-4 tw:accent-prise-green-2" checked={v.features[k]} onChange={(e) => set('features', { ...v.features, [k]: e.target.checked })} />
                <span><span className="tw:block tw:text-sm tw:font-semibold">{t}</span><span className="tw:block tw:text-xs tw:text-prise-muted">{d}</span></span>
              </label>
            ))}
          </div>
        </fieldset>
        <div className="tw:flex tw:flex-wrap tw:justify-end tw:gap-2 tw:border-t tw:border-prise-border tw:px-5 tw:py-4">
          {data.isEnabled && <button type="button" className={btnGhost} disabled={busy} onClick={() => call(() => api.post('/ai/test'))}><i className="bi bi-plug" aria-hidden="true" />Tester la connexion</button>}
          <button type="submit" className={btnPrimary} disabled={busy}>Enregistrer</button>
        </div>
      </form>
    </>
  );
}

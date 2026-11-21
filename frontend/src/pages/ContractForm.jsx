import React, { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { api, apiError, fieldErrors } from '../api.js';
import { useAuth } from '../context/AuthContext.jsx';
import { useLoad } from '../lib/hooks.js';
import { addMonths, fr, money, parseDecimal } from '../lib/format.js';
import { DecimalInput, Field, Loading, Notice, PageHead } from '../components/ui.jsx';

const AEP = 'Système d’AEP';
const blankSite = () => ({ id: 0, name: '', province: '', work_type: '', description: '', quantity: '1', amount: '' });
const filled = (v) => String(v ?? '').trim() !== '';
const toInput = (v) => (v === null || v === undefined ? '' : String(v).replace('.', ','));

export default function ContractForm() {
  const { id } = useParams();
  const { can } = useAuth();
  const edit = !!id;
  // La logistique d'une unité ne crée que des contrats de fournitures de bureau, pour son unité.
  const logisticOnly = !can('canManageContracts') && can('canManageStock');
  const nav = useNavigate();
  const ref = useLoad('/reference-data');
  const current = useLoad(`/contracts/${id}`, {}, edit);
  const [f, setF] = useState({
    reference: '', company_id: '', category: 'TRAVAUX', province: '', selected_provinces: [], signature_date: '', start_date: '', duration_months: '12', amount: '',
    initial_financing_rate: '0', activities: '', work_mode: 'Lourd', selected_work_types: [], guarantee_rate: '5', guarantee_bank_name: '', guarantee_account_number: '',
    guarantee_observation: '', goods_procedure: '', goods_origin: '', payment_schedule: [], delay_penalty_rate: '', delay_penalty_cap: '', goods_min_amount: '', goods_max_amount: '',
    installation_cost: '', component_id: '', activity_id: '', guarantee_start_date: '', guarantee_end_date: '',
  });
  const [sites, setSites] = useState([blankSite()]);
  const [errors, setErrors] = useState({});
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [customType, setCustomType] = useState('');
  const [documentFile, setDocumentFile] = useState(null);

  const set = (k) => (v) => setF((s) => ({ ...s, [k]: v }));
  const isWorks = f.category === 'TRAVAUX';
  const isMulti = f.category === 'BIENS' || f.category === 'SERVICE';
  // Contrat de SERVICE : ni avance de démarrage, ni garantie de bonne exécution.
  const isService = f.category === 'SERVICE';
  // Marché de biens : une seule procédure ; choisir la procédure et la provenance reprend les taux du document, qui restent modifiables.
  const isGoods = f.category === 'BIENS';
  const goods = ref.data?.goodsProcedures;
  const governed = isGoods && ['BAD', 'NATIONALE'].includes(f.goods_procedure);
  const isOffice = isGoods && f.goods_procedure === 'FOURNITURES_BUREAU';
  // Contrat renouvelé : l'écran ne montre que la période en cours ; la date de démarrage d'origine ne se modifie plus ici.
  const renewed = edit && current.data?.contract?.renewalNo > 0 ? current.data.contract : null;
  const chooseGoods = (procedure, origin) => setF((s) => {
    const rules = goods?.rules?.[procedure];
    if (!rules) return { ...s, initial_financing_rate: '0', goods_min_amount: s.goods_min_amount || String(goods.officeAmounts.min), goods_max_amount: s.goods_max_amount || String(goods.officeAmounts.max), goods_procedure: procedure, goods_origin: '', payment_schedule: [], delay_penalty_rate: '', delay_penalty_cap: '' };
    const byOrigin = origin ? rules[origin] : null;
    return {
      ...s, goods_procedure: procedure, goods_origin: origin, guarantee_rate: String(rules.guaranteeRate),
      delay_penalty_rate: rules.penaltyRate === null ? '' : String(rules.penaltyRate), delay_penalty_cap: rules.penaltyCap === null ? '' : String(rules.penaltyCap),
      ...(byOrigin ? { initial_financing_rate: String(byOrigin.advanceRate), payment_schedule: byOrigin.tranches.map((t) => ({ ...t, rate: String(t.rate) })) } : { payment_schedule: [] }),
    };
  });
  useEffect(() => {
    if (edit || !logisticOnly || !goods) return;
    setF((s) => ({ ...s, category: 'BIENS' }));
    chooseGoods('FOURNITURES_BUREAU', '');
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [edit, logisticOnly, goods]);
  const shownProcedures = (goods?.procedures || []).filter((p) => (p.code === 'FOURNITURES_BUREAU' ? can('canManageStock') : can('canManageContracts')));
  const setTranche = (key, rate) => setF((s) => ({ ...s, payment_schedule: s.payment_schedule.map((t) => (t.key === key ? { ...t, rate } : t)) }));
  const scheduleTotal = f.payment_schedule.reduce((a, t) => a + (parseDecimal(t.rate) || 0), parseDecimal(f.initial_financing_rate) || 0);
  // Changer de catégorie retire une entreprise d'une autre catégorie (décision du 18/11/2026) ; une entreprise sans catégorie reste.
  const setCategory = (v) => setF((s) => {
    const chosen = (ref.data?.companies || []).find((c) => String(c.id) === String(s.company_id));
    const keep = !chosen || !chosen.category || chosen.category === v;
    return { ...s, category: v, ...(keep ? {} : { company_id: '' }), ...(v === 'SERVICE' ? { initial_financing_rate: '0' } : {}) };
  });

  useEffect(() => {
    const c = current.data?.contract;
    if (!c) return;
    const types = (c.workTypeText || '').split(';').map((x) => x.trim()).filter(Boolean);
    setF((s) => ({
      ...s, reference: c.number, company_id: String(c.companyId), category: c.category || 'TRAVAUX', province: c.province || '',
      selected_provinces: c.provinces || [], signature_date: c.signatureDate || '', start_date: c.startDate || '', duration_months: String(c.durationMonths || 12),
      amount: String(c.amount ?? ''), initial_financing_rate: String(c.initialFinancingRate ?? 0), activities: c.activity || '',
      work_mode: types.length === 1 && types[0] === AEP ? 'Lourd' : 'Autres', selected_work_types: types,
      goods_procedure: c.goodsProcedure || '', goods_origin: c.goodsOrigin || '', payment_schedule: (c.paymentSchedule || []).map((t) => ({ ...t, rate: String(t.rate) })),
      delay_penalty_rate: c.delayPenaltyRate === null || c.delayPenaltyRate === undefined ? '' : String(c.delayPenaltyRate),
      delay_penalty_cap: c.delayPenaltyCap === null || c.delayPenaltyCap === undefined ? '' : String(c.delayPenaltyCap),
      guarantee_rate: current.data?.guarantee ? String(current.data.guarantee.rate) : s.guarantee_rate,
      goods_min_amount: c.goodsMinAmount === null || c.goodsMinAmount === undefined ? '' : String(c.goodsMinAmount),
      goods_max_amount: c.goodsMaxAmount === null || c.goodsMaxAmount === undefined ? '' : String(c.goodsMaxAmount),
      installation_cost: toInput(c.installationCost), component_id: c.componentId ? String(c.componentId) : '', activity_id: c.activityId ? String(c.activityId) : '',
    }));
    setSites((c.sites || []).map((x) => ({ id: x.id, name: x.name, province: x.province, work_type: x.workType, description: x.description, quantity: String(x.quantity), amount: toInput(x.amount) })));
  }, [current.data]);

  const workTypes = useMemo(() => {
    const base = (ref.data?.workTypes || []).filter((w) => w.category === f.category || w.category === 'TRAVAUX').map((w) => w.name);
    return [...new Set([...base, ...f.selected_work_types])].filter((n) => n !== AEP);
  }, [ref.data, f.category, f.selected_work_types]);

  // Montant du contrat (décision du 10/11/2026) = installation de chantier et travaux préparatoires + montants prévus des sites,
  // plus ce qu'ont ajouté les renouvellements. Un ancien contrat, sans montant par site, garde son montant saisi tant qu'aucun
  // montant n'est renseigné ; dès qu'il l'est, chaque site doit avoir le sien (contrôlé aussi par l'API).
  const loaded = current.data?.contract;
  const legacy = edit && loaded && !loaded.progressWeighted && (loaded.installationCost === null || loaded.installationCost === undefined);
  const computed = !isOffice && (!legacy || (isWorks && filled(f.installation_cost)) || sites.some((s) => filled(s.amount)));
  // Coût d'installation de chantier : contrats de TRAVAUX seulement.
  const installation = isWorks ? parseDecimal(f.installation_cost) || 0 : 0;
  const renewedAmount = edit ? Number(loaded?.renewedAmount) || 0 : 0;
  const sitesTotal = sites.filter((s) => s.name.trim()).reduce((a, s) => a + (parseDecimal(s.amount) || 0), 0);
  const total = installation + sitesTotal + renewedAmount;
  // Fin du contrat : borne de la période de garantie (démarrage + durée).
  const contractEnd = f.start_date ? addMonths(f.start_date, Number(f.duration_months) || 1) : '';
  // Composantes enregistrées au préalable (Administration › Composantes) : le contrat choisit une activité d'une catégorie
  // d'une composante, facultativement (décision du 16/11/2026) ; la composante et la catégorie en découlent.
  const components = ref.data?.components || [];
  const activitiesReady = !!ref.data?.activitiesReady;
  const activityChoices = components.flatMap((c) => c.categories.map((k) => ({
    component: c, category: k,
    // Activités proposées : actives d'une composante active, plus celle que le contrat a déjà.
    activities: k.activities.filter((a) => String(a.id) === String(f.activity_id) || (a.isActive && c.isActive)),
  }))).filter((g) => g.activities.length);
  const chosen = activityChoices.flatMap((g) => g.activities.map((a) => ({ ...g, activity: a }))).find((x) => String(x.activity.id) === String(f.activity_id)) || null;
  const chosenComponent = chosen ? chosen.component : components.find((x) => String(x.id) === String(f.component_id)) || null;
  const categoryTitle = (name) => (/^cat[ée]gorie\b/i.test(name) ? name : `Catégorie ${name}`);
  const ucText = (v) => (v === null || v === undefined ? '—' : `${money(v).replace(/,00$/, '')} UC`);

  const toggle = (key, value) => setF((s) => ({ ...s, [key]: s[key].includes(value) ? s[key].filter((x) => x !== value) : [...s[key], value] }));
  const setSite = (i, k, v) => setSites((list) => list.map((s, j) => (j === i ? { ...s, [k]: v } : s)));
  const addType = () => {
    const v = customType.trim();
    if (!v) return;
    setF((s) => ({ ...s, selected_work_types: [...new Set([...s.selected_work_types, v])] }));
    setCustomType('');
  };

  const submit = async (e) => {
    e.preventDefault();
    if (!edit) {
      if (!documentFile) {
        setError('Joignez le document du contrat signé avant son enregistrement.');
        window.scrollTo({ top: 0, behavior: 'smooth' });
        return;
      }
      const ext = documentFile.name.split('.').pop()?.toLowerCase();
      if (!['pdf', 'doc', 'docx'].includes(ext) || documentFile.size > 20 * 1024 * 1024) {
        setError('Le document doit être au format PDF, DOC ou DOCX et ne pas dépasser 20 Mo.');
        window.scrollTo({ top: 0, behavior: 'smooth' });
        return;
      }
    }
    setBusy(true);
    setError('');
    setErrors({});
    // Le montant calculé est recalculé par l'API ; il n'est transmis que pour un ancien contrat (montant saisi).
    const payload = {
      ...f, installation_cost: isWorks ? f.installation_cost : '', amount: computed ? String(total) : f.amount, sites: sites.filter((s) => s.name.trim()),
      guarantee_start_date: f.guarantee_start_date || f.start_date, guarantee_end_date: f.guarantee_end_date || contractEnd,
    };
    try {
      const res = edit ? await api.put(`/contracts/${id}`, payload) : await api.post('/contracts', payload);
      const contractId = edit ? id : res.data.id;
      if (!edit && documentFile) {
        const fd = new FormData();
        fd.append('file', documentFile);
        try {
          await api.post(`/contracts/${contractId}/documents`, fd);
        } catch (uploadErr) {
          nav(`/contracts/${contractId}`, { replace: true, state: { documentUploadWarning: `Le contrat a été créé, mais le document n’a pas pu être joint : ${apiError(uploadErr)}. Ajoutez-le depuis la fiche du contrat.` } });
          return;
        }
      }
      nav(`/contracts/${contractId}`, { replace: true });
    } catch (err) {
      setErrors(fieldErrors(err));
      setError(apiError(err));
      setBusy(false);
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
  };

  if (ref.loading || (edit && current.loading)) return <Loading />;
  const companies = ref.data?.companies || [];
  const provinces = ref.data?.provinces || [];
  // Entreprises de la catégorie du contrat ; celles sans catégorie (antérieures au 18/11/2026) à part ; l'entreprise actuelle
  // du contrat reste proposée même si sa catégorie diffère.
  const CATEGORY_LABEL = { TRAVAUX: 'Travaux', BIENS: 'Biens', SERVICE: 'Services' };
  const sameCategory = companies.filter((c) => c.category === f.category);
  const uncategorized = companies.filter((c) => !c.category);
  const keptCompany = companies.find((c) => String(c.id) === String(f.company_id) && c.category && c.category !== f.category);

  return (
    <>
      <PageHead kicker="GESTION CONTRACTUELLE" title={edit ? `Modifier le contrat ${f.reference}` : 'Nouveau contrat'} desc={isService ? 'Renseignez le contrat et ses sites d’exécution. Un contrat de service n’a ni avance de démarrage ni garantie de bonne exécution.' : 'Renseignez le contrat, ses sites d’exécution et sa garantie de bonne exécution.'}>
        <Link to={edit ? `/contracts/${id}` : '/contracts'} className="btn btn-light"><i className="bi bi-arrow-left me-1" /> Retour</Link>
      </PageHead>
      <Notice>{error}</Notice>
      <form onSubmit={submit}>
        <div className="cardx form-card p-3 mb-3">
          <div className="section-title mb-3">Informations du contrat</div>
          <div className="row g-3">
            <Field className="col-md-4" label="N° du contrat" error={errors.reference}><input className="form-control" value={f.reference} onChange={(e) => set('reference')(e.target.value)} required /></Field>
            {/* La catégorie d'abord : elle limite la liste des entreprises à celles de cette catégorie (18/11/2026). */}
            <Field className="col-md-4" label="Catégorie" error={errors.category}>
              <select className="form-select" value={f.category} onChange={(e) => setCategory(e.target.value)} disabled={logisticOnly}>
                {['TRAVAUX', 'BIENS', 'SERVICE'].map((c) => <option key={c} value={c}>{CATEGORY_LABEL[c]}</option>)}
              </select>
            </Field>
            <Field className="col-md-4" label="Entreprise" error={errors.company_id}>
              <select className="form-select" value={f.company_id} onChange={(e) => set('company_id')(e.target.value)} required>
                <option value="">{sameCategory.length || uncategorized.length ? `— Entreprise de la catégorie ${CATEGORY_LABEL[f.category]} —` : `— Aucune entreprise de la catégorie ${CATEGORY_LABEL[f.category]} —`}</option>
                {keptCompany && <option value={keptCompany.id}>{keptCompany.name} (catégorie {CATEGORY_LABEL[keptCompany.category]})</option>}
                {sameCategory.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                {uncategorized.length > 0 && (
                  <optgroup label="Catégorie non renseignée">
                    {uncategorized.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                  </optgroup>
                )}
              </select>
              {!sameCategory.length && (
                <div className="form-text">
                  Aucune entreprise enregistrée dans la catégorie {CATEGORY_LABEL[f.category]}.
                  {can('canManageCompanies') && <> <Link to="/companies">Ajouter une entreprise</Link></>}
                </div>
              )}
            </Field>
            <Field className="col-md-3" label="Date de signature"><input type="date" className="form-control" value={f.signature_date} onChange={(e) => set('signature_date')(e.target.value)} /></Field>
            {renewed ? (
              <>
                <Field className="col-md-3" label="Début de la période en cours"><input className="form-control" value={fr(renewed.periodStart)} readOnly /></Field>
                <Field className="col-md-3" label="Durée de la période (mois)"><input className="form-control" value={renewed.periodMonths} readOnly /></Field>
              </>
            ) : (
              <>
                <Field className="col-md-3" label="Date de démarrage" error={errors.start_date}><input type="date" className="form-control" value={f.start_date} onChange={(e) => set('start_date')(e.target.value)} required /></Field>
                <Field className="col-md-3" label="Durée (mois)"><input type="number" min="1" className="form-control" value={f.duration_months} onChange={(e) => set('duration_months')(e.target.value)} required /></Field>
              </>
            )}
            {/* Installation de chantier et travaux préparatoires : un coût de tout le contrat, pas d'un site ; TRAVAUX seulement. */}
            {isWorks && (
              <Field className="col-md-3" label="Coût d’installation de chantier et travaux préparatoires (USD)" error={errors.installation_cost}>
                <DecimalInput id="installation-cost" value={f.installation_cost} onChange={set('installation_cost')} placeholder="0,00" />
              </Field>
            )}
            {/* Montant calculé (installation + sites + renouvellements) ; saisi pour un ancien contrat sans montant par site. */}
            {!isOffice && computed && (
              <Field className="col-md-3" label="Montant du contrat (USD)" error={errors.amount}>
                <input className="form-control text-money fw-semibold amount-computed" value={money(total)} readOnly tabIndex={-1} />
              </Field>
            )}
            {!isOffice && !computed && (
              <Field className="col-md-3" label="Montant du contrat (USD)" error={errors.amount}>
                <DecimalInput value={f.amount} onChange={set('amount')} placeholder="Ex. : 1500000,50" required />
              </Field>
            )}
            {!(governed && f.goods_origin) && !isOffice && (
              <Field className="col-md-3" label="Avance de démarrage (%)" error={errors.initial_financing_rate}>
                <DecimalInput value={isService ? '0' : f.initial_financing_rate} onChange={set('initial_financing_rate')} disabled={isService} aria-describedby={isService ? 'svc-financing' : undefined} />
                {isService && <div id="svc-financing" className="form-text">Aucune avance de démarrage pour un contrat de service.</div>}
              </Field>
            )}
            {!isMulti && (
              <Field className="col-md-4" label="Province" error={errors.province}>
                <select className="form-select" value={f.province} onChange={(e) => set('province')(e.target.value)} required>
                  <option value="">— Sélectionner —</option>
                  {provinces.map((p) => <option key={p}>{p}</option>)}
                </select>
              </Field>
            )}
            <Field className="col-12" label="Activité / objet du contrat"><textarea className="form-control" rows="2" value={f.activities} onChange={(e) => set('activities')(e.target.value)} /></Field>
            {/* Rattachement à la matrice d'exécution : activité d'une catégorie d'une composante (Administration › Composantes). */}
            {!isOffice && activitiesReady && (
              <>
                <Field className="col-md-8" label="Activité de la composante (facultatif)" error={errors.activity_id}>
                  {/* « Aucune » détache aussi la composante ; une activité choisie impose la sienne (déduite par l'API). */}
                  <select className="form-select" value={f.activity_id} onChange={(e) => setF((s) => ({ ...s, activity_id: e.target.value, component_id: e.target.value ? s.component_id : '' }))}>
                    <option value="">{activityChoices.length ? '— Aucune —' : '— Aucune activité enregistrée (Administration › Composantes) —'}</option>
                    {activityChoices.map((g) => (
                      <optgroup key={g.category.id} label={`${g.component.name} — ${categoryTitle(g.category.name)}`}>
                        {g.activities.map((a) => <option key={a.id} value={a.id}>{a.name}{a.budget !== null ? ` — ${ucText(a.budget)}` : ''}{a.isActive && g.component.isActive ? '' : ' (désactivée)'}</option>)}
                      </optgroup>
                    ))}
                  </select>
                  {!f.activity_id && f.component_id && chosenComponent && (
                    <div className="form-text">Rattaché à la composante « {chosenComponent.name} » sans activité : choisissez son activité.</div>
                  )}
                </Field>
                <Field className="col-md-4" label="Coût de l’activité (UC)">
                  <input className="form-control text-money amount-computed" readOnly tabIndex={-1} value={chosen ? ucText(chosen.activity.budget) : '—'} />
                </Field>
                {chosen && (
                  <div className="col-12">
                    <dl className="progress-context mb-0">
                      <div><dt>Composante</dt><dd>{chosen.component.name} <span>· {ucText(chosen.component.total)}</span></dd></div>
                      <div><dt>Catégorie</dt><dd>{categoryTitle(chosen.category.name)} <span>· {ucText(chosen.category.cost)}</span></dd></div>
                    </dl>
                  </div>
                )}
              </>
            )}
            {/* Base pas encore mise à jour : composante seule, comme avant le 16/11/2026. */}
            {!isOffice && !activitiesReady && (
              <>
                <Field className="col-md-8" label="Composante du projet (facultatif)" error={errors.component_id}>
                  <select className="form-select" value={f.component_id} onChange={(e) => set('component_id')(e.target.value)}>
                    <option value="">— Aucune —</option>
                    {components.filter((x) => x.isActive || String(x.id) === String(f.component_id)).map((x) => <option key={x.id} value={x.id}>{x.name}{x.isActive ? '' : ' (désactivée)'}</option>)}
                  </select>
                </Field>
                <Field className="col-md-4" label="Budget de la composante (UC)">
                  <input className="form-control text-money amount-computed" readOnly tabIndex={-1} value={chosenComponent ? ucText(chosenComponent.total) : '—'} />
                </Field>
              </>
            )}
          </div>

          {isMulti && (
            <div className="mt-3">
              <label className="form-label">Provinces d’exécution</label>
              {errors.province && <div className="text-danger small mb-1">{errors.province}</div>}
              <div className="d-flex flex-wrap gap-2">
                {provinces.map((p) => (
                  <label key={p} className="check-pill"><input type="checkbox" checked={f.selected_provinces.includes(p)} onChange={() => toggle('selected_provinces', p)} />{p}</label>
                ))}
              </div>
            </div>
          )}

          {isWorks && (
            <div className="mt-3 work-mode">
              <label className="form-label">Nature des travaux</label>
              <div className="d-flex gap-2 flex-wrap mb-2">
                <label className="check-pill"><input type="radio" checked={f.work_mode === 'Lourd'} onChange={() => set('work_mode')('Lourd')} /> Travaux lourds : Système d’AEP</label>
                <label className="check-pill"><input type="radio" checked={f.work_mode === 'Autres'} onChange={() => set('work_mode')('Autres')} /> Autres ouvrages</label>
              </div>
              {f.work_mode === 'Autres' && (
                <>
                  <div className="d-flex flex-wrap gap-2 mb-2">
                    {workTypes.map((w) => <label key={w} className="check-pill"><input type="checkbox" checked={f.selected_work_types.includes(w)} onChange={() => toggle('selected_work_types', w)} />{w}</label>)}
                  </div>
                  <div className="input-group" style={{ maxWidth: 420 }}>
                    <input className="form-control" placeholder="Ajouter un autre type d’ouvrage…" value={customType} onChange={(e) => setCustomType(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && (e.preventDefault(), addType())} />
                    <button type="button" className="btn btn-outline-primary" onClick={addType}>Ajouter</button>
                  </div>
                </>
              )}
            </div>
          )}
        </div>

        {isGoods && goods && (
          <div className="cardx form-card p-3 mb-3">
            <div className="section-title mb-3">Procédure du marché</div>
            {errors.goods_procedure && <div className="text-danger small mb-2">{errors.goods_procedure}</div>}
            <div className="d-flex flex-wrap gap-2 mb-3" role="radiogroup" aria-label="Procédure du marché">
              {shownProcedures.map((p) => <label key={p.code} className="check-pill"><input type="radio" name="goods-procedure" checked={f.goods_procedure === p.code} onChange={() => chooseGoods(p.code, f.goods_origin)} />{p.label}</label>)}
            </div>
            {isOffice && (
              <>
                <div className="row g-3">
                  <Field className="col-md-3" label="Montant minimum d’une commande (USD)" error={errors.goods_min_amount}><DecimalInput value={f.goods_min_amount} onChange={set('goods_min_amount')} required /></Field>
                  <Field className="col-md-3" label="Montant maximum d’une commande (USD)" error={errors.goods_max_amount}><DecimalInput value={f.goods_max_amount} onChange={set('goods_max_amount')} required /></Field>
                </div>
              </>
            )}
            {governed && (
              <>
                <label className="form-label">Provenance des biens</label>
                {errors.goods_origin && <div className="text-danger small mb-1">{errors.goods_origin}</div>}
                <div className="d-flex flex-wrap gap-2 mb-3" role="radiogroup" aria-label="Provenance des biens">
                  {goods.origins.map((o) => <label key={o.code} className="check-pill"><input type="radio" name="goods-origin" checked={f.goods_origin === o.code} onChange={() => chooseGoods(f.goods_procedure, o.code)} />{o.label}</label>)}
                </div>
                {f.goods_origin && (
                  <div className="row g-3">
                    <Field className="col-md-3" label="Avance de démarrage (%)" error={errors.initial_financing_rate}><DecimalInput value={f.initial_financing_rate} onChange={set('initial_financing_rate')} /></Field>
                    {f.payment_schedule.map((t) => <Field key={t.key} className="col-md-3" label={`${t.label} (%)`}><DecimalInput value={t.rate} onChange={(v) => setTranche(t.key, v)} /></Field>)}
                    <div className="col-md-3 d-flex flex-column justify-content-end">
                      <span className={`small fw-semibold ${Math.abs(scheduleTotal - 100) > 0.001 ? 'text-danger' : 'text-secondary'}`} role="status">Total : {Math.round(scheduleTotal * 100) / 100} % sur 100 %</span>
                    </div>
                    {errors.payment_schedule && <div className="col-12 text-danger small">{errors.payment_schedule}</div>}
                    {edit && <Field className="col-md-3" label="Garantie de bonne exécution (%)" error={errors.guarantee_rate}><DecimalInput value={f.guarantee_rate} onChange={set('guarantee_rate')} /></Field>}
                    {edit && !current.data?.guarantee && (
                      <>
                        <Field className="col-md-3" label="Banque émettrice de la garantie" error={errors.guarantee_bank_name}><input className="form-control" value={f.guarantee_bank_name} onChange={(e) => set('guarantee_bank_name')(e.target.value)} required /></Field>
                        <Field className="col-md-3" label="N° garantie"><input className="form-control" value={f.guarantee_account_number} onChange={(e) => set('guarantee_account_number')(e.target.value)} /></Field>
                      </>
                    )}
                    {f.goods_procedure === 'NATIONALE' && (
                      <>
                        <Field className="col-md-3" label="Pénalité de retard (% par semaine)" error={errors.delay_penalty_rate}><DecimalInput value={f.delay_penalty_rate} onChange={set('delay_penalty_rate')} /></Field>
                        <Field className="col-md-3" label="Plafond de la pénalité (%)" error={errors.delay_penalty_cap}><DecimalInput value={f.delay_penalty_cap} onChange={set('delay_penalty_cap')} /></Field>
                      </>
                    )}
                  </div>
                )}
              </>
            )}
          </div>
        )}

        <div className="cardx form-card p-3 mb-3">
          <div className="d-flex justify-content-between align-items-center mb-2">
            <div className="section-title">{isOffice ? 'Sites d’exécution' : 'Sites d’exécution et montants'}</div>
            <button type="button" className="btn btn-outline-primary btn-sm" onClick={() => setSites((l) => [...l, blankSite()])}><i className="bi bi-plus-lg me-1" />Ajouter un site</button>
          </div>
          {errors.sites && <div className="text-danger small mb-2">{errors.sites}</div>}
          <div className="d-grid gap-2">
            {sites.map((s, i) => (
              <div className="site-row" key={i}>
                <div className="row g-2 align-items-end">
                  <div className={isMulti ? 'col-md-2' : 'col-md-3'}><label className="form-label small">Nom du site</label><input className="form-control" value={s.name} onChange={(e) => setSite(i, 'name', e.target.value)} /></div>
                  {isMulti && (
                    <div className="col-md-2">
                      <label className="form-label small">Province</label>
                      <select className="form-select" value={s.province} onChange={(e) => setSite(i, 'province', e.target.value)}>
                        <option value="">—</option>
                        {(f.selected_provinces.length ? f.selected_provinces : provinces).map((p) => <option key={p}>{p}</option>)}
                      </select>
                    </div>
                  )}
                  <div className="col-md-2">
                    <label className="form-label small">{isWorks ? 'Type d’ouvrage' : 'Nature'}</label>
                    <input className="form-control" list="work-types" value={s.work_type} onChange={(e) => setSite(i, 'work_type', e.target.value)} placeholder={isWorks && f.work_mode === 'Lourd' ? AEP : ''} />
                  </div>
                  <div className={isOffice ? (isMulti ? 'col-md-4' : 'col-md-5') : (isMulti ? 'col-md-2' : 'col-md-3')}><label className="form-label small">Description</label><input className="form-control" value={s.description} onChange={(e) => setSite(i, 'description', e.target.value)} /></div>
                  <div className="col-md-1"><label className="form-label small">Qté</label><DecimalInput value={s.quantity} onChange={(v) => setSite(i, 'quantity', v)} /></div>
                  {!isOffice && (
                    <div className="col-md-2">
                      <label className="form-label small">Montant prévu (USD)</label>
                      <DecimalInput value={s.amount} onChange={(v) => setSite(i, 'amount', v)} placeholder="0,00" required={computed && !!s.name.trim()} aria-label={`Montant prévu du site ${i + 1} (USD)`} />
                    </div>
                  )}
                  {/* Un site enregistré ne se retire pas d'ici : il porte peut-être déjà des situations d'avancement. */}
                  <div className="col-md-1 text-end">{sites.length > 1 && (!edit || !s.id) && <button type="button" className="btn btn-outline-danger btn-icon" onClick={() => setSites((l) => l.filter((_, j) => j !== i))} title="Retirer" aria-label={`Retirer le site ${s.name || i + 1}`}><i className="bi bi-trash" /></button>}</div>
                </div>
              </div>
            ))}
          </div>
          {computed && (
            <dl className="contract-amount-sum" aria-live="polite">
              {isWorks && <div><dt>Installation de chantier</dt><dd>{money(installation)}</dd></div>}
              <div><dt>Sites ({sites.filter((s) => s.name.trim()).length})</dt><dd>{money(sitesTotal)}</dd></div>
              {renewedAmount > 0 && <div><dt>Renouvellements</dt><dd>{money(renewedAmount)}</dd></div>}
              <div className="is-total"><dt>Montant du contrat</dt><dd>{money(total)} <span>USD</span></dd></div>
            </dl>
          )}
          <datalist id="work-types">{(ref.data?.workTypes || []).map((w) => <option key={w.id} value={w.name} />)}</datalist>
        </div>

        {!edit && isService && (
          <div className="alert alert-info d-flex gap-2 align-items-start mb-3">
            <i className="bi bi-info-circle-fill mt-1" aria-hidden="true" />
            <div><strong>Pas de garantie de bonne exécution.</strong> Un contrat de service ne reçoit ni avance de démarrage ni garantie de bonne exécution : ses décomptes n’auront aucune retenue.</div>
          </div>
        )}

        {!edit && !isService && !isOffice && (
          <div className="cardx form-card p-3 mb-3">
            <div className="section-title mb-3">Garantie de bonne exécution</div>
            {/* Période de la garantie : comprise dans celle du contrat, jamais au-delà de sa date de fin. */}
            <div className="row g-3">
              <Field className="col-md-3" label="Taux (%)" error={errors.guarantee_rate}><DecimalInput value={f.guarantee_rate} onChange={set('guarantee_rate')} /></Field>
              <Field className="col-md-5" label="Banque émettrice" error={errors.guarantee_bank_name}><input className="form-control" value={f.guarantee_bank_name} onChange={(e) => set('guarantee_bank_name')(e.target.value)} required /></Field>
              <Field className="col-md-4" label="N° garantie"><input className="form-control" value={f.guarantee_account_number} onChange={(e) => set('guarantee_account_number')(e.target.value)} /></Field>
              <Field className="col-md-3" label="Début de la garantie" error={errors.guarantee_start_date}>
                <input type="date" className="form-control" value={f.guarantee_start_date || f.start_date} min={f.start_date || undefined} max={contractEnd || undefined} onChange={(e) => set('guarantee_start_date')(e.target.value)} required />
              </Field>
              <Field className="col-md-3" label="Fin de la garantie" error={errors.guarantee_end_date}>
                <input type="date" className="form-control" value={f.guarantee_end_date || contractEnd} min={f.guarantee_start_date || f.start_date || undefined} max={contractEnd || undefined} onChange={(e) => set('guarantee_end_date')(e.target.value)} required />
              </Field>
              <Field className="col-md-6" label="Observation"><input className="form-control" value={f.guarantee_observation} onChange={(e) => set('guarantee_observation')(e.target.value)} /></Field>
            </div>
          </div>
        )}

        {!edit && (
          <div className="cardx form-card p-3 mb-3">
            <div className="section-title mb-1">Document du contrat signé</div>
            <div className="section-subtitle mb-3">PDF, DOC ou DOCX, 20 Mo maximum.</div>
            <Field className="col-12" label="Fichier du contrat signé">
              <input type="file" className="form-control" accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document" onChange={(e) => setDocumentFile(e.target.files?.[0] || null)} required />
            </Field>
            {documentFile && <div className="small text-secondary mt-2">Fichier sélectionné : {documentFile.name} ({(documentFile.size / 1048576).toFixed(2)} Mo)</div>}
          </div>
        )}

        <div className="d-flex gap-2 justify-content-end mb-4">
          <Link to={edit ? `/contracts/${id}` : '/contracts'} className="btn btn-light">Annuler</Link>
          <button className="btn btn-primary" disabled={busy}><i className="bi bi-check-lg me-1" />{edit ? 'Enregistrer les modifications' : 'Créer le contrat'}</button>
        </div>
      </form>
    </>
  );
}

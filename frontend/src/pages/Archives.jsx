import React, { useState } from 'react';
import { api, apiError } from '../api.js';
import { useLoad } from '../lib/hooks.js';
import { Empty, Loading, Notice, PageHead } from '../components/ui.jsx';
import { useAuth } from '../context/AuthContext.jsx';

/** Phases archivées : réservées au super-administrateur et aux comptes qu'il autorise ; les autres en demandent l'accès. */
export default function Archives() {
  const { me } = useAuth();
  return me?.user?.permissions?.canViewArchives ? <ArchiveList /> : <ArchiveRequest />;
}

function ArchiveList() {
  const { data, loading, error } = useLoad('/archives');
  const { selectPhase } = useAuth();
  if (loading && !data) return <Loading />;
  if (!data) return <Notice>{error}</Notice>;
  return (
    <>
      <PageHead title="Archives des phases" desc="Phases archivées du projet, réservées au super-administrateur et aux comptes qu’il autorise. Ouvrez une phase pour consulter ses contrats, suivis, paiements et garanties." />
      <div className="cardx p-3">
        <div className="table-wrap">
          <table className="table align-middle">
            <thead><tr><th>Phase</th><th>Description</th><th>Contrats</th><th /></tr></thead>
            <tbody>
              {data.map((p) => (
                <tr key={p.id}><td><strong>Phase {p.number} — {p.name}</strong></td><td>{p.description || '—'}</td><td>{p.contracts}</td>
                  <td className="text-end"><button className="btn btn-sm btn-outline-primary" onClick={() => selectPhase(p.id)}><i className="bi bi-box-arrow-in-right me-1" />Consulter cette phase</button></td></tr>
              ))}
              {!data.length && <tr><td colSpan="4"><Empty text="Aucune phase archivée." /></td></tr>}
            </tbody>
          </table>
        </div>
      </div>
    </>
  );
}

function ArchiveRequest() {
  const { data, loading, error, reload } = useLoad('/archives/access');
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [flash, setFlash] = useState({ type: 'success', text: '' });
  if (loading && !data) return <Loading />;
  if (!data) return <Notice>{error}</Notice>;

  const send = async (e) => {
    e.preventDefault();
    if (busy) return;
    setBusy(true);
    try {
      const r = await api.post('/archives/request', { reason });
      setFlash({ type: 'success', text: r.data.message });
      setReason('');
      await reload();
    } catch (err) {
      setFlash({ type: 'danger', text: apiError(err) });
    }
    setBusy(false);
  };

  return (
    <>
      <PageHead title="Archives des phases" desc="Les phases archivées ne sont consultables que par le super-administrateur et les comptes qu’il autorise." />
      <Notice type={flash.type} onClose={() => setFlash({ ...flash, text: '' })}>{flash.text}</Notice>
      <div className="cardx p-4" style={{ maxWidth: 720 }}>
        {data.pending ? (
          <div className="d-flex gap-3 align-items-start">
            <i className="bi bi-hourglass-split fs-3 text-warning" aria-hidden="true" />
            <div>
              <div className="section-title mb-1">Demande en cours</div>
              <p className="mb-0 text-secondary">Votre demande d’accès a été transmise au super-administrateur. Vous recevrez une notification dès qu’il l’aura accordée.</p>
            </div>
          </div>
        ) : (
          <form onSubmit={send}>
            <div className="d-flex gap-3 align-items-start mb-3">
              <i className="bi bi-lock fs-3 text-secondary" aria-hidden="true" />
              <div>
                <div className="section-title mb-1">Accès réservé</div>
                <p className="mb-0 text-secondary">
                  {data.archivedPhases ? `Le projet compte ${data.archivedPhases} phase(s) archivée(s). ` : 'Aucune phase n’est archivée pour le moment. '}
                  Pour les consulter, demandez l’accès au super-administrateur : il est prévenu dans son espace et par e-mail.
                </p>
              </div>
            </div>
            <label className="form-label" htmlFor="archive-reason">Motif de la demande (facultatif)</label>
            <textarea id="archive-reason" className="form-control mb-3" rows="3" maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Exemple : comparer les contrats de la phase 1 pour le rapport annuel." />
            <button type="submit" className="btn btn-primary" disabled={busy}><i className="bi bi-send me-1" />{busy ? 'Envoi…' : 'Demander l’accès'}</button>
          </form>
        )}
      </div>
    </>
  );
}

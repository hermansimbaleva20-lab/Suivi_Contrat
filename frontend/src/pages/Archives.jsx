import React from 'react';
import { useLoad } from '../lib/hooks.js';
import { Empty, Loading, Notice, PageHead } from '../components/ui.jsx';
import { useAuth } from '../context/AuthContext.jsx';

export default function Archives() {
  const { data, loading, error } = useLoad('/archives');
  const { selectPhase } = useAuth();
  if (loading && !data) return <Loading />;
  if (!data) return <Notice>{error}</Notice>;
  return (
    <>
      <PageHead kicker="HISTORIQUE" title="Archives des phases" desc="Phases archivées du projet. Ouvrez une phase pour consulter ses contrats, suivis, paiements et garanties." />
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

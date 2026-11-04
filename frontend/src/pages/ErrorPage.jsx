import React from 'react';
import { Link, useSearchParams } from 'react-router-dom';

export default function ErrorPage() {
  const [params] = useSearchParams();
  const type = params.get('type') || 'general';
  const connection = type === 'connection';
  const server = type === 'server';
  const title = connection ? 'Connexion impossible' : server ? 'Service momentanément indisponible' : 'Une erreur est survenue';
  const description = connection
    ? 'L’application ne parvient pas à joindre le serveur. Vérifiez que Laravel, MySQL et votre connexion réseau sont actifs.'
    : server
      ? 'Le serveur a rencontré une erreur pendant le traitement de la demande. Réessayez dans quelques instants.'
      : 'La page demandée n’a pas pu être affichée correctement.';
  const technical = sessionStorage.getItem('prise_last_error') || '';
  return (
    <main className="app-error-page">
      <section className="app-error-card">
        <div className="app-error-mark"><i className={`bi ${connection ? 'bi-wifi-off' : 'bi-exclamation-triangle'}`} /></div>
        <div className="page-kicker">PRISE / PREDIRE · CENTRE D’ASSISTANCE</div>
        <h1>{title}</h1>
        <p>{description}</p>
        {technical && <details className="app-error-details"><summary>Détails techniques</summary><pre>{technical}</pre></details>}
        <div className="d-flex justify-content-center flex-wrap gap-2 mt-4">
          <button className="btn btn-primary" onClick={() => {
            const target = sessionStorage.getItem('prise_return_path') || (localStorage.getItem('prise_token') ? '/dashboard' : '/login');
            sessionStorage.removeItem('prise_last_error');
            sessionStorage.removeItem('prise_return_path');
            window.location.href = target.startsWith('/error') ? '/dashboard' : target;
          }}><i className="bi bi-arrow-clockwise me-2" />Réessayer</button>
          <Link className="btn btn-outline-secondary" to="/login"><i className="bi bi-box-arrow-in-right me-2" />Retour à la connexion</Link>
        </div>
        <div className="app-error-foot">Si le problème persiste, contactez l’administrateur de la plateforme.</div>
      </section>
    </main>
  );
}

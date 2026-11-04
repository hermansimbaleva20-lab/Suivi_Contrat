/** Sous-navigation des trois sous-modules de la Logistique (onglets de l'en-tête de module). */
export const STOCK = {
  icon: 'box-seam-fill',
  title: 'Stock',
  tabs: [
    { to: '/stock', label: 'État du stock', icon: 'clipboard-data', end: true },
    { to: '/stock/catalogue', label: 'Catalogue & prix unitaires', icon: 'tags' },
  ],
};

export const FLEET = {
  icon: 'truck-front-fill',
  title: 'Charroi automobile',
  tabs: [
    { to: '/fleet', label: 'Véhicules & alertes', icon: 'speedometer2', end: true },
    { to: '/fleet/logbook', label: 'Carnets de bord', icon: 'journal-bookmark' },
    { to: '/fleet/maintenance', label: 'Entretiens', icon: 'wrench-adjustable' },
    { to: '/fleet/insurance', label: 'Assurances', icon: 'shield-check' },
    { to: '/fleet/reports', label: 'Rapports carburant', icon: 'fuel-pump' },
  ],
};

export const GENERATORS = {
  icon: 'lightning-charge-fill',
  title: 'Groupes électrogènes',
  tabs: [
    { to: '/generators', label: 'Groupes & alertes', icon: 'speedometer2', end: true },
    { to: '/generators/logbook', label: 'Relevés & carburant', icon: 'journal-bookmark' },
    { to: '/generators/maintenance', label: 'Entretiens', icon: 'wrench-adjustable' },
    { to: '/generators/reports', label: 'Rapports', icon: 'fuel-pump' },
  ],
};

/** Sous-module d'un type d'engin. */
export const fleetNav = (kind) => (kind === 'GROUPE' ? GENERATORS : FLEET);
export const logbookPath = (kind) => (kind === 'GROUPE' ? '/generators/logbook' : '/fleet/logbook');

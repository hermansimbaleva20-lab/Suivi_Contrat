import React, { useEffect, useRef } from 'react';
import L from 'leaflet';

const COORDS = {
  kinshasa: [-4.33, 15.31], kongocentral: [-5.82, 13.48], kwango: [-6.95, 17.9], kwilu: [-5.04, 18.82], maindombe: [-2.95, 18.28], equateur: [0.04, 18.26],
  mongala: [2.03, 21.88], nordubangi: [4.24, 21.01], sudubangi: [3.27, 19.82], tshuapa: [-0.95, 19.62], tshopo: [0.52, 25.2], basuele: [2.78, 24.8],
  hautuele: [3.52, 27.52], ituri: [1.88, 30.25], nordkivu: [-0.72, 29.27], sudkivu: [-2.52, 28.86], maniema: [-3.1, 26.15], tanganyika: [-6.3, 28.55],
  hautlomami: [-7.06, 24.48], lualaba: [-10.7, 25.3], hautkatanga: [-11.67, 27.48], lomami: [-6.15, 24.55], sankuru: [-3.45, 23.4], kasai: [-5.7, 20.8],
  kasaicentral: [-5.95, 22.42], kasaioriental: [-5.9, 24.9],
};
const norm = (v) => String(v || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]/g, '');
const GEOJSON_URL = 'https://raw.githubusercontent.com/skywalker282/drcMapsGeojson/master/26provinces_features.json';
const escapeHtml = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

export default function ProvinceMap({ rows = [] }) {
  const el = useRef(null);
  useEffect(() => {
    if (!el.current) return undefined;
    let alive = true;
    let boundaryLayer = null;
    const map = L.map(el.current, {
  scrollWheelZoom: true,
  zoomControl: true,
}).setView([-2.9, 23.6], 5.8);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    minZoom: 5,
    maxZoom: 12,
    attribution: '© OpenStreetMap contributors',
   }).addTo(map);
    const byName = new Map(rows.map((r) => [norm(r.name), r]));
    const colorFor = (r) => !r ? '#DCE4EC' : r.retard ? '#C62828' : r.vigilance ? '#D98200' : '#16844A';
    const popupFor = (r, name) => r
      ? `<strong>${escapeHtml(name)}</strong><br>${r.contracts} contrat(s) · ${r.siteCount} site(s)<br>Avancement moyen : ${r.progress} %<br>${r.retard} retard · ${r.vigilance} vigilance · ${r.termines} terminé(s)`
      : `<strong>${escapeHtml(name)}</strong><br>Aucun contrat enregistré dans cette province.`;

    const fallback = () => {
      rows.forEach((p) => {
        const c = COORDS[norm(p.name)];
        if (!c) return;
        const color = colorFor(p);
        L.circleMarker(c, { radius: 8 + Math.min(10, p.contracts * 2), color: '#fff', weight: 2, fillColor: color, fillOpacity: 0.9 })
          .bindPopup(popupFor(p, p.name)).addTo(map);
      });
    };

    fetch(GEOJSON_URL)
      .then((response) => { if (!response.ok) throw new Error('Carte administrative indisponible'); return response.json(); })
      .then((geo) => {
        if (!alive) return;
        boundaryLayer = L.geoJSON(geo, {
          style: (feature) => {
            const props = feature?.properties || {};
            const names = Object.values(props).filter((v) => typeof v === 'string').map(norm);
            const matched = rows.find((r) => names.includes(norm(r.name)));
            const color = colorFor(matched);
            return {
              color: '#FFFFFF',
              weight: matched ? 2 : 1.4,
              opacity: 1,
              fillColor: color,
              fillOpacity: matched ? 0.88 : 0.92,
              lineCap: 'round',
              lineJoin: 'round',
            };
          },
          onEachFeature: (feature, layer) => {
            const props = feature?.properties || {};
            const names = Object.values(props).filter((v) => typeof v === 'string');
            const name = names.find((v) => rows.some((r) => norm(r.name) === norm(v))) || names.find((v) => /province|kinshasa|kasai|kivu|kwango|kwilu|tshopo|ituri|lomami|ubangi|katanga|equateur|mongala|tanganyika|maniema|sankuru|tshuapa|uele|maindombe/i.test(v)) || names[0] || 'Province';
            const matched = rows.find((r) => names.some((v) => norm(r.name) === norm(v)));
            layer.bindPopup(popupFor(matched, matched?.name || name));
            layer.on({ mouseover: (e) => e.target.setStyle({ weight: 3, fillOpacity: matched ? 0.78 : 0.32 }), mouseout: (e) => boundaryLayer?.resetStyle(e.target) });
          },
       }).addTo(map);

// Afficher le nom de chaque province directement sur la carte.
boundaryLayer.eachLayer((layer) => {
  const props = layer.feature?.properties || {};
  const names = Object.values(props).filter(
    (value) => typeof value === 'string' && value.trim()
  );

  const provinceName =
    names.find((value) =>
      rows.some((row) => norm(row.name) === norm(value))
    ) ||
    names.find((value) =>
      /province|kinshasa|kasai|kivu|kwango|kwilu|tshopo|ituri|lomami|ubangi|katanga|equateur|mongala|tanganyika|maniema|sankuru|tshuapa|uele|maindombe/i.test(value)
    );

  if (!provinceName || !layer.getBounds().isValid()) return;

  const center = layer.getBounds().getCenter();

  L.marker(center, {
    interactive: false,
    keyboard: false,
    icon: L.divIcon({
      className: 'province-name-label',
      html: `<span>${escapeHtml(provinceName)}</span>`,
      iconSize: [120, 24],
      iconAnchor: [60, 12],
    }),
  }).addTo(map);
});

const bounds = boundaryLayer.getBounds();
        if (bounds.isValid()) {
          map.fitBounds(bounds, {
            padding: [20, 20],
            maxZoom: 6,
            animate: true,
          });
        }
      })
      .catch(() => { if (alive) fallback(); });

    setTimeout(() => { if (alive) map.invalidateSize(); }, 250);
    return () => { alive = false; map.remove(); };
  }, [JSON.stringify(rows)]);
  return (
    <>
      <div ref={el} className="prise-map" style={{ height: 500, borderRadius: 14, overflow: 'hidden' }} />
      <div className="province-legend mt-2 d-flex gap-3 flex-wrap small text-secondary">
        <span><i className="province-dot" style={{ background: '#16844a' }} /> Province d’intervention — normal</span>
        <span><i className="province-dot" style={{ background: '#d98200' }} /> Vigilance</span>
        <span><i className="province-dot" style={{ background: '#c62828' }} /> Retard</span>
        <span><i className="province-dot" style={{ background: '#d9e2dc' }} /> Sans contrat</span>
      </div>
      <div className="small text-secondary mt-1">Limites provinciales indicatives — données géographiques ouvertes ; fond cartographique © OpenStreetMap.</div>
    </>
  );
}

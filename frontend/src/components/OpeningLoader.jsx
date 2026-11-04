import React from 'react';

/*
 * Chargeur d'ouverture (6 s, piloté par App.jsx) : coupe géologique « holographique » d'un forage.
 * La foreuse s'implante, le train de tiges traverse les couches jusqu'à la nappe, puis l'eau remonte et jaillit.
 * Les trois étapes, le compteur de profondeur et la barre de progression suivent la même chronologie (prise-design.css).
 */
const STEPS = ['Implantation de la foreuse…', 'Foration des couches géologiques…', 'Nappe atteinte : l’eau remonte.'];

export default function OpeningLoader() {
  return (
    <div className="opening-loader" role="status" aria-label="Ouverture de PRISE / PREDIRE : préparation des outils de suivi">
      <div className="ol-panel">
        <div className="ol-scene" aria-hidden="true">
          <svg viewBox="0 0 360 230" className="ol-svg">
            <defs>
              <linearGradient id="ol-holo" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0" stopColor="#a7f3d0" />
                <stop offset=".45" stopColor="#5eead4" />
                <stop offset=".75" stopColor="#e8c873" />
                <stop offset="1" stopColor="#86efac" />
              </linearGradient>
              <linearGradient id="ol-water" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0" stopColor="#67e8f9" />
                <stop offset="1" stopColor="#0e9fb3" />
              </linearGradient>
              <pattern id="ol-hatch" width="6" height="6" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
                <line x1="0" y1="0" x2="0" y2="6" stroke="#a7f3d0" strokeOpacity=".16" strokeWidth="1" />
              </pattern>
              <pattern id="ol-dots" width="9" height="7" patternUnits="userSpaceOnUse">
                <circle cx="2" cy="2" r=".9" fill="#e8c873" fillOpacity=".3" />
              </pattern>
              <clipPath id="ol-bore"><rect x="176" y="98" width="8" height="132" /></clipPath>
              <linearGradient id="ol-fade-grad" x1="0" y1="0" x2="1" y2="0">
                <stop offset="0" stopColor="#fff" stopOpacity="0" />
                <stop offset=".14" stopColor="#fff" />
                <stop offset=".86" stopColor="#fff" />
                <stop offset="1" stopColor="#fff" stopOpacity="0" />
              </linearGradient>
              <mask id="ol-fade" maskUnits="userSpaceOnUse" x="0" y="0" width="360" height="230"><rect x="0" y="0" width="360" height="230" fill="url(#ol-fade-grad)" /></mask>
            </defs>

            {/* Couches géologiques */}
            <g className="ol-strata" mask="url(#ol-fade)">
              <rect x="0" y="98" width="360" height="24" className="ol-layer ol-soil" />
              <rect x="0" y="122" width="360" height="34" className="ol-layer ol-laterite" />
              <rect x="0" y="122" width="360" height="34" fill="url(#ol-dots)" />
              <rect x="0" y="156" width="360" height="30" className="ol-layer ol-clay" />
              <rect x="0" y="156" width="360" height="30" fill="url(#ol-hatch)" />
              <rect x="0" y="186" width="360" height="44" className="ol-layer ol-aquifer" />
              <path className="ol-wave" d="M0 198 q15 -5 30 0 t30 0 t30 0 t30 0 t30 0 t30 0 t30 0 t30 0 t30 0 t30 0 t30 0 t30 0 t30 0" />
              <path className="ol-wave ol-wave-2" d="M0 212 q15 -5 30 0 t30 0 t30 0 t30 0 t30 0 t30 0 t30 0 t30 0 t30 0 t30 0 t30 0 t30 0 t30 0" />
              {[122, 156, 186].map((y) => <line key={y} x1="0" x2="360" y1={y} y2={y} className="ol-boundary" />)}
              <line x1="0" x2="360" y1="98" y2="98" className="ol-ground" />
            </g>

            {/* Cotes de profondeur, comme sur une coupe de forage */}
            <g className="ol-scale">
              {[[122, '10 m'], [156, '35 m'], [186, '60 m'], [222, '85 m']].map(([y, t]) => (
                <g key={t}><line x1="300" x2="308" y1={y} y2={y} /><text x="312" y={y + 3}>{t}</text></g>
              ))}
            </g>

            {/* Trou foré, puis tubage rempli d'eau */}
            <g clipPath="url(#ol-bore)">
              <rect x="176" y="98" width="8" height="124" className="ol-borehole" />
              <rect x="177.5" y="98" width="5" height="124" className="ol-water-column" fill="url(#ol-water)" />
            </g>

            {/* Foreuse : mât, poulie de tête, plate-forme et moteur */}
            <g className="ol-rig">
              <path d="M163 96 L180 14 L197 96" className="ol-line" />
              <path d="M167 78 L193 78 M171 58 L189 58 M175 38 L185 38 M167 78 L189 58 M193 78 L171 58 M171 58 L185 38 M189 58 L175 38" className="ol-line ol-thin" />
              <rect x="174" y="9" width="12" height="7" rx="1.5" className="ol-line" />
              <rect x="140" y="90" width="80" height="7" rx="1.5" className="ol-line ol-deck" />
              <rect x="206" y="74" width="26" height="16" rx="2" className="ol-line" />
              <path d="M211 74 v-7 M228 74 v-4" className="ol-line ol-thin" />
              <circle cx="219" cy="82" r="4" className="ol-line ol-thin ol-engine" />
            </g>

            {/* Train de tiges et outil de forage */}
            <g className="ol-drill">
              <rect x="179" y="16" width="2" height="82" className="ol-pipe" />
              <g className="ol-bit">
                <path d="M175 98 h10 l-5 9 z" className="ol-bit-body" />
                <path d="M176.5 100 l7 0 M177.5 102.5 l5 0 M178.6 105 l2.8 0" className="ol-bit-teeth" />
              </g>
            </g>

            {/* Déblais remontés pendant la foration */}
            <g className="ol-cuttings">
              {[0, 1, 2, 3, 4, 5].map((i) => <circle key={i} cx={168 + (i % 3) * 12} cy="96" r="1.3" style={{ animationDelay: `${1.13 + i * 0.41}s` }} />)}
            </g>

            {/* Jaillissement de l'eau en surface */}
            <g className="ol-spring">
              <path d="M180 92 C176 66 156 50 134 66 C130 70 128 78 127 90" />
              <path d="M180 92 C184 66 204 50 226 66 C230 70 232 78 233 90" />
              <path d="M180 92 C178 70 166 60 152 72" className="ol-jet-inner" />
              <path d="M180 92 C182 70 194 60 208 72" className="ol-jet-inner" />
              {[[128, 94], [232, 94], [140, 80], [220, 80]].map(([x, y]) => <circle key={`${x}-${y}`} cx={x} cy={y} r="1.6" className="ol-drop" />)}
              <ellipse cx="180" cy="97" rx="26" ry="3" className="ol-ripple" />
            </g>

            {/* Balayage du relevé */}
            <rect x="0" y="98" width="360" height="1.5" className="ol-scan" mask="url(#ol-fade)" />

          </svg>
          <div className="ol-depth"><span>Profondeur</span><strong className="ol-depth-value" /><span>m</span></div>
        </div>

        <div className="ol-brand">PRISE <span>/</span> PREDIRE</div>
        <div className="ol-caption">Suivi des contrats et des infrastructures</div>
        <div className="ol-track"><span /></div>
        <div className="ol-steps">
          {STEPS.map((s, i) => <span key={s} className={`ol-step ol-step-${i + 1}`}>{s}</span>)}
        </div>
      </div>
    </div>
  );
}

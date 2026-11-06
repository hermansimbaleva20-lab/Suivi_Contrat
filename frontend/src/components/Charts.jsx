import React, { useEffect, useRef } from 'react';
import { Chart, BarController, BarElement, CategoryScale, LinearScale, Legend, Tooltip } from 'chart.js';
import { Empty } from './ui.jsx';
import { COLORS, paymentColor } from '../lib/chartColors.js';
import { THEME_EVENT, chartInk } from '../lib/theme.js';

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Legend, Tooltip);
Chart.defaults.font.family = '"Public Sans", "Segoe UI", Arial, sans-serif';


// Graphiques affichés : recolorés sans être reconstruits quand le thème change, et en couleurs claires pour l'impression.
const live = new Set();
const paint = (chart, ink) => {
  const o = chart.options;
  o.priseValueColor = ink.value;
  o.scales.x.grid.color = ink.grid;
  o.scales.x.ticks.color = ink.tick;
  o.scales.y.border.color = ink.axis;
  o.scales.y.ticks.color = ink.label;
  o.plugins.legend.labels.color = ink.legend;
  o.plugins.tooltip.backgroundColor = ink.tooltip;
  chart.update('none');
};
if (typeof window !== 'undefined') {
  window.addEventListener(THEME_EVENT, () => live.forEach((c) => paint(c, chartInk())));
  window.addEventListener('beforeprint', () => live.forEach((c) => paint(c, chartInk(true))));
  window.addEventListener('afterprint', () => live.forEach((c) => paint(c, chartInk())));
}

const reducedMotion = () => typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

// Valeur au bout de chaque barre : elle suit la barre pendant sa croissance et apparaît en fondu ; en rouge si la barre l'est.
const valueLabels = {
  id: 'priseValueLabels',
  afterDatasetsDraw(chart) {
    const ctx = chart.ctx;
    ctx.save();
    ctx.font = '600 11px "Public Sans", Arial, sans-serif';
    ctx.textBaseline = 'middle';
    chart.data.datasets.forEach((ds, i) => {
      const meta = chart.getDatasetMeta(i);
      if (meta.hidden) return;
      meta.data.forEach((bar, j) => {
        const v = ds.data[j];
        if (v === null || v === undefined) return;
        const color = Array.isArray(ds.backgroundColor) ? ds.backgroundColor[j] : ds.backgroundColor;
        const target = Math.abs(chart.scales.x.getPixelForValue(v) - bar.base);
        // Fondu pendant la croissance ; une valeur nulle (barre invisible) reste lisible.
        ctx.globalAlpha = target < 2 ? 1 : Math.min(1, Math.max(0, (Math.abs(bar.x - bar.base) / target) * 1.4 - 0.4));
        const text = `${Number(v).toFixed(0)}%`;
        // Barre presque pleine : l'étiquette passe dans la barre, en blanc.
        const inside = bar.x + ctx.measureText(text).width + 10 > chart.chartArea.right;
        ctx.textAlign = inside ? 'right' : 'left';
        ctx.fillStyle = inside ? '#fff' : color === COLORS.overpaid ? COLORS.overpaid : chart.options.priseValueColor;
        ctx.fillText(text, inside ? bar.x - 7 : bar.x + 6, bar.y);
      });
    });
    ctx.restore();
  },
};

/**
 * Histogramme horizontal : labels = périodes ou indicateurs ; datasets = [{ label, data, color, legendColor }] en pourcentages
 * (0–100). « color » peut être une couleur par barre. Le graphique se construit quand il entre à l'écran : les barres
 * poussent l'une après l'autre (catégorie puis série), sans animation si l'utilisateur la refuse.
 */
export function HBarChart({ labels, datasets, legend = true }) {
  const ref = useRef(null);
  const box = useRef(null);
  useEffect(() => {
    if (!ref.current || !labels?.length) return undefined;
    const ink = chartInk();
    const perLabel = Math.max(26, datasets.length * 17 + 16);
    if (box.current) box.current.style.height = `${Math.max(legend ? 250 : 200, labels.length * perLabel + (legend ? 74 : 40))}px`;
    let chart = null;
    const build = () => {
      if (chart || !ref.current) return;
      chart = new Chart(ref.current, {
        type: 'bar',
        data: {
          labels,
          datasets: datasets.map((d) => ({
            label: d.label, data: d.data, backgroundColor: d.color, hoverBackgroundColor: d.color, legendColor: d.legendColor,
            borderRadius: 5, borderSkipped: false, barPercentage: 0.86, categoryPercentage: 0.8,
          })),
        },
        options: {
          priseValueColor: ink.value,
          animation: reducedMotion() ? false : {
            duration: 900,
            easing: 'easeOutQuart',
            delay: (c) => (c.type === 'data' && c.mode === 'default' ? c.dataIndex * 90 + c.datasetIndex * 140 : 0),
          },
          animations: reducedMotion() ? undefined : { x: { from: (c) => (c.chart?.scales?.x ? c.chart.scales.x.getPixelForValue(0) : 0) } },
          transitions: { active: { animation: { duration: 180 } } },
          indexAxis: 'y',
          responsive: true,
          maintainAspectRatio: false,
          layout: { padding: { right: 34 } },
          scales: {
            x: { min: 0, max: 100, grid: { color: ink.grid }, border: { display: false }, ticks: { color: ink.tick, callback: (v) => `${v}%` } },
            y: { grid: { display: false }, border: { color: ink.axis }, ticks: { color: ink.label, font: { weight: '600' } } },
          },
          plugins: {
            legend: {
              display: legend,
              position: 'top',
              labels: {
                usePointStyle: true, pointStyle: 'rectRounded', boxWidth: 10, color: ink.legend,
                generateLabels: (c) => Chart.defaults.plugins.legend.labels.generateLabels(c).map((l) => {
                  const ds = c.data.datasets[l.datasetIndex];
                  const fill = ds.legendColor || (Array.isArray(ds.backgroundColor) ? ds.backgroundColor[0] : ds.backgroundColor);
                  return { ...l, fillStyle: fill, strokeStyle: fill };
                }),
              },
            },
            tooltip: {
              backgroundColor: ink.tooltip, padding: 10, cornerRadius: 8, titleFont: { weight: '600' },
              callbacks: { label: (c) => ` ${c.dataset.label} : ${Number(c.parsed.x).toFixed(1)} %` },
            },
          },
        },
        plugins: [valueLabels],
      });
      live.add(chart);
    };
    // Construit à l'entrée dans la page visible : l'animation est vue au lieu de se jouer hors de l'écran.
    const io = typeof IntersectionObserver !== 'undefined' ? new IntersectionObserver((entries) => {
      if (entries.some((e) => e.isIntersecting)) { build(); io.disconnect(); }
    }, { threshold: 0, rootMargin: '0px 0px -12% 0px' }) : null;

    if (io) io.observe(box.current); else build();
    return () => { io?.disconnect(); if (chart) { live.delete(chart); chart.destroy(); } };
  }, [JSON.stringify(labels), JSON.stringify(datasets), legend]);

  if (!labels?.length) return <Empty text="Aucune donnée à afficher pour le moment." />;
  return (
    <div className="hist-scroll">
      <div className="chart-wrap hist-chart" ref={box}><canvas ref={ref} role="img" aria-label={`Histogramme : ${datasets.map((d) => d.label).join(', ')}`} /></div>
    </div>
  );
}

/** Légende de la règle « paiement supérieur à l'avancement », affichée seulement quand elle s'applique. */
export function OverpaidNote({ show }) {
  if (!show) return null;
  return (
    <div className="chart-note"><span className="chart-note-swatch" aria-hidden="true" />En rouge : taux de paiement supérieur à l’avancement physique.</div>
  );
}

/** Évolution d'un contrat : une barre par indicateur et par période. rows = [{ date, progress, days, payment, guarantee }] */
export function EvolutionChart({ rows, withGuarantee = false }) {
  const ds = [
    { label: 'Avancement physique', data: rows.map((r) => r.progress), color: COLORS.progress },
    { label: 'Jours consommés', data: rows.map((r) => r.days), color: COLORS.days },
    { label: 'Taux de paiement', data: rows.map((r) => r.payment), color: rows.map((r) => paymentColor(r.payment, r.progress)), legendColor: COLORS.payment },
  ];
  if (withGuarantee) ds.push({ label: 'Consommation garantie', data: rows.map((r) => r.guarantee), color: COLORS.guarantee });
  return (
    <>
      <HBarChart labels={rows.map((r) => r.date)} datasets={ds} />
      <OverpaidNote show={rows.some((r) => Number(r.payment) > Number(r.progress))} />
    </>
  );
}


/** Évolution de chaque site entre deux dates. sites = [{ name, previous, current }] */
export function SiteChart({ sites, prevLabel, curLabel }) {
  return (
    <HBarChart
      labels={sites.map((s) => s.name)}
      datasets={[
        { label: `Au ${prevLabel}`, data: sites.map((s) => s.previous), color: COLORS.before },
        { label: `Au ${curLabel}`, data: sites.map((s) => s.current), color: COLORS.after },
      ]}
    />
  );
}


const PIE_COLORS = ['#087a3f', '#d98900', '#c62828', '#2f6db3', '#7a5bb5'];

function sectorPath(cx, cy, rx, ry, start, end) {
  const point = (a) => [cx + rx * Math.cos(a), cy + ry * Math.sin(a)];
  const [x1, y1] = point(start);
  const [x2, y2] = point(end);
  const large = end - start > Math.PI ? 1 : 0;
  return `M ${cx} ${cy} L ${x1} ${y1} A ${rx} ${ry} 0 ${large} 1 ${x2} ${y2} Z`;
}

/** Synthèse par statut en secteurs SVG avec extrusion visuelle pour un rendu 3D léger. */
export function Pie3DChart({ items = [] }) {
  const total = items.reduce((sum, item) => sum + Math.max(0, Number(item.value) || 0), 0);
  if (!total) return <Empty text="Aucune donnée contractuelle à représenter." />;
  let angle = -Math.PI / 2;
  const slices = items.map((item, index) => {
    const value = Math.max(0, Number(item.value) || 0);
    const start = angle;
    angle += (value / total) * Math.PI * 2;
    return { ...item, value, start, end: angle, color: item.color || PIE_COLORS[index % PIE_COLORS.length] };
  }).filter((s) => s.value > 0);
  return (
    <div className="pie3d-layout">
      <div className="pie3d-visual">
        <svg viewBox="0 0 320 220" role="img" aria-label="Répartition des contrats par statut en secteurs 3D">
          <ellipse cx="160" cy="174" rx="92" ry="22" style={{ fill: 'var(--pie-shadow, #163e2d)' }} opacity=".10" />
          {slices.map((slice, i) => <path key={`side-${i}`} d={sectorPath(160, 94 + 16, 92, 59, slice.start, slice.end)} fill={slice.color} opacity=".72" />)}
          {slices.map((slice, i) => <path key={`top-${i}`} d={sectorPath(160, 94, 92, 59, slice.start, slice.end)} fill={slice.color} style={{ stroke: 'var(--pie-gap, #fff)' }} strokeWidth="2" />)}
          <circle cx="160" cy="94" r="2" style={{ fill: 'var(--pie-gap, #fff)' }} />
          <text x="160" y="194" textAnchor="middle" fontSize="11" style={{ fill: 'var(--chart-tick, #62756b)' }}>Total : {total}</text>
        </svg>
      </div>
      <div className="pie3d-legend">
        {slices.map((slice, i) => (
          <div className="pie3d-legend-item" key={`${slice.label}-${i}`}>
            <span className="pie3d-swatch" style={{ background: slice.color }} />
            <span className="pie3d-label">{slice.label}</span>
            <strong>{slice.value}</strong>
            <small>{Math.round((slice.value / total) * 100)} %</small>
          </div>
        ))}
      </div>
    </div>
  );
}

// Renders every <canvas data-chart="..."> with the locally vendored Chart.js (no CDN, no inline script).
(function () {
  'use strict';
  if (typeof Chart === 'undefined') { return; }
  var PALETTE = ['#173b77', '#3b6fc4', '#7aa5e6', '#a9c4ee', '#0f2a57', '#5c7fb8', '#c9dbf5'];
  document.querySelectorAll('canvas[data-chart]').forEach(function (cv) {
    var cfg;
    try { cfg = JSON.parse(cv.getAttribute('data-chart')); } catch (e) { return; }
    var pie = cfg.type === 'doughnut';
    var datasets = cfg.datasets.map(function (d, i) {
      var color = d.color || PALETTE[i % PALETTE.length];
      var o = { label: d.label, data: d.data };
      if (pie) { o.backgroundColor = d.colors || PALETTE; o.borderColor = '#fff'; }
      else if (cfg.type === 'line') { o.borderColor = color; o.backgroundColor = color + '22'; o.fill = true; o.tension = 0.25; o.pointRadius = d.data.length > 40 ? 0 : 3; }
      else { o.backgroundColor = d.colors || color; }
      return o;
    });
    var scales = pie ? {} : {
      x: { stacked: !!cfg.stacked, ticks: { autoSkip: true, maxRotation: 45 } },
      y: { stacked: !!cfg.stacked, beginAtZero: true, ticks: { precision: 0 } }
    };
    if (cfg.horizontal && !pie) { scales.x.beginAtZero = true; scales.x.ticks = { precision: 0 }; scales.y.ticks = {}; }
    new Chart(cv, {
      type: pie ? 'doughnut' : cfg.type,
      data: { labels: cfg.labels, datasets: datasets },
      options: {
        responsive: true, maintainAspectRatio: false,
        indexAxis: cfg.horizontal ? 'y' : 'x',
        plugins: { legend: { display: pie || datasets.length > 1, position: 'bottom' } },
        scales: scales
      }
    });
  });
})();

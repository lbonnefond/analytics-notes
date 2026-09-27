/* Vue globale multi-parcours — phase 2 (v4) */
(function () {
  'use strict';

  const TOKEN = window.APO_TOKEN;
  let DATA = null;
  const charts = [];
  const PALETTE = ['#2563eb', '#16a34a', '#d97706', '#7c3aed', '#dc2626', '#0891b2', '#db2777', '#65a30d'];
  const GLOBAL_GRAY = '#6b7280'; // couleur neutre pour les graphiques « global »
  const ANOM_LABELS = {
    ue_note_vide: 'Case(s) d\'épreuve(s) vide(s) — donnée manquante',
    ue_single_note: 'Une seule note notée (DEF + moyenne UE attendue non calculée)',
    sem_def_incoherent: 'DEF en UE mais incohérent au semestre'
  };

  /* ---------- couleurs par parcours (modifiables) ---------- */

  let COLORS = PALETTE.slice();
  try {
    const savedC = JSON.parse(sessionStorage.getItem('apoColors') || 'null');
    if (Array.isArray(savedC) && savedC.length) COLORS = savedC;
  } catch (e) { /* ignoré */ }
  function colorOf(g) { return COLORS[g % COLORS.length] || PALETTE[g % PALETTE.length]; }
  function nameColored(label, g) {
    return '<span style="color:' + colorOf(g) + ';font-weight:600">' + esc(label) + '</span>';
  }

  /* ---------- seuils de coloration ---------- */

  let THRESH = { acqLow: 40, acqHigh: 80, sigma: 3 };
  try {
    const saved = JSON.parse(sessionStorage.getItem('apoThresh') || 'null');
    if (saved && [saved.acqLow, saved.acqHigh, saved.sigma].every((x) => typeof x === 'number')) THRESH = saved;
  } catch (e) { /* ignoré */ }

  const acqCls = (p) => (p === null || p === undefined ? '' : p < THRESH.acqLow ? 'bad' : p > THRESH.acqHigh ? 'good' : '');
  const sigmaCls = (v) => (v === null || v === undefined || isNaN(v) ? '' : v > THRESH.sigma ? 'bad' : '');

  function recolor() {
    const num = (el) => { const m = String(el.textContent || '').match(/-?\d+(?:[.,]\d+)?/); return m ? parseFloat(m[0].replace(',', '.')) : NaN; };
    document.querySelectorAll('.acq-cell').forEach((el) => { const p = num(el); el.classList.remove('bad', 'good'); const c = acqCls(p); if (!isNaN(p) && c) el.classList.add(c); });
    document.querySelectorAll('.sigma-cell').forEach((el) => { const v = num(el); el.classList.remove('bad', 'good'); const c = sigmaCls(v); if (!isNaN(v) && c) el.classList.add(c); });
  }

  /* ---------- utilitaires ---------- */

  const esc = (s) => String(s === null || s === undefined ? '' : s)
    .replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  const fmt = (v, d) => {
    if (v === null || v === undefined || isNaN(v)) return '—';
    return Number(v).toFixed(d === undefined ? 2 : d).replace('.', ',');
  };

  function destroyAllCharts() {
    const seen = new Set();
    for (const c of charts) { if (!seen.has(c)) { seen.add(c); c.destroy(); } }
    charts.length = 0;
  }

  function badge(code) {
    if (!code) return '<span class="badge b-NA">—</span>';
    const up = String(code).toUpperCase();
    const known = ['ADM', 'ACQ', 'AJ', 'NACQ', 'DEF', 'DIS', 'ABJ', 'ABI'];
    const cls = known.indexOf(up) !== -1 ? 'b-' + up : 'b-NA';
    return '<span class="badge ' + cls + '">' + esc(code) + '</span>';
  }

  function examValue(ex) {
    if (ex.note !== null && ex.note !== undefined) return fmt(ex.note, 3);
    if (ex.raw) return badge(ex.raw);
    return '—';
  }

  function kpiCards(items) {
    return items.map((it) =>
      '<div class="card"><div class="t">' + it[0] + '</div>' +
      '<div class="v' + (it[3] ? ' ' + it[3] : '') + '">' + it[1] + '</div>' +
      (it[2] ? '<div class="s">' + it[2] + '</div>' : '') + '</div>'
    ).join('');
  }

  function niceCohort(niveau, semestre) {
    const mN = String(niveau || '').match(/(\d+)/);
    const mS = String(semestre || '').match(/(\d+)/);
    const an = mN ? mN[1] : '';
    const sem = mS ? mS[1] : '';
    if (!an && !sem) return '';
    return 'Licence ' + (an || '…') + ' — Semestre ' + (sem || '…');
  }

  function codesColorHtml(groups, isSpecific) {
    if (isSpecific) {
      return '<span class="mono" style="font-size:12px;color:' + colorOf(groups[0] ? groups[0].g : 0) + ';font-weight:600">' +
        esc(groups[0] ? (groups[0].fullCode || '') : '') + '</span>';
    }
    return '<span class="muted">codes : ' + groups.map((g) =>
      '<span class="mono" style="color:' + colorOf(g.g) + ';font-weight:600">' + esc(g.fullCode) + '</span>'
    ).join(' · ') + '</span>';
  }

  /* ---------- plugin lignes verticales ---------- */

  const vlines = {
    id: 'vlines',
    afterDatasetsDraw(chart) {
      const lines = (chart.options.plugins.vlines && chart.options.plugins.vlines.lines) || [];
      if (!lines.length) return;
      const x = chart.scales.x;
      const chartArea = chart.chartArea;
      if (!x || !chartArea) return;
      const ctx = chart.ctx;
      ctx.save();
      for (const l of lines) {
        const clamped = Math.max(0, Math.min(20, l.x));
        const px = x.left + (clamped / 20) * (x.right - x.left);
        ctx.strokeStyle = l.color;
        ctx.lineWidth = 1.6;
        ctx.setLineDash(l.dash || [6, 4]);
        ctx.beginPath();
        ctx.moveTo(px, chartArea.top);
        ctx.lineTo(px, chartArea.bottom);
        ctx.stroke();
        if (l.label) {
          ctx.setLineDash([]);
          ctx.fillStyle = l.color;
          ctx.font = '600 13px system-ui, sans-serif';
          const w = ctx.measureText(l.label).width;
          const lx = (px + 4 + w > chartArea.right) ? px - w - 6 : px + 4;
          const ly = chartArea.top + 14 + (l.offset || 0);
          ctx.fillText(l.label, lx, ly);
        }
      }
      ctx.restore();
    }
  };

  function vlinesFor(s) {
    if (!s) return [];
    const out = [];
    if (s.mean !== null && s.mean !== undefined) {
      out.push({ x: s.mean, color: '#111827', label: 'Moy. ' + fmt(s.mean) });
      if (s.std !== null && s.std !== undefined) {
        out.push({ x: s.mean - s.std, color: '#2563eb' });
        out.push({ x: s.mean + s.std, color: '#2563eb', label: '±σ', offset: 26 });
      }
    }
    if (s.median !== null && s.median !== undefined) {
      out.push({ x: s.median, color: '#7c3aed', label: 'Méd. ' + fmt(s.median), offset: 14 });
    }
    return out;
  }

  function hist(canvas, d, opts) {
    opts = opts || {};
    if (canvas.__chart) {
      const idx = charts.indexOf(canvas.__chart);
      if (idx !== -1) charts.splice(idx, 1);
      canvas.__chart.destroy();
    }
    const c = new Chart(canvas, {
      type: 'bar',
      data: {
        labels: (d.labels || []).map((l) => String(l)),
        datasets: [{
          label: opts.label || 'Effectif',
          data: d.counts || [],
          backgroundColor: opts.color || 'rgba(37,99,235,0.5)',
          borderColor: opts.color || 'rgba(37,99,235,0.95)',
          borderWidth: 1, barPercentage: 1, categoryPercentage: 1
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        animation: { duration: 250 },
        scales: {
          x: {
            grid: { display: false }, ticks: { autoSkip: false, font: { size: 11 } },
            title: { display: true, text: 'Note /20 — [n ; n+1[', font: { size: 13 } }
          },
          y: {
            beginAtZero: true, ticks: { precision: 0, font: { size: 11 } },
            title: { display: true, text: 'Effectif', font: { size: 13 } }
          }
        },
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              title: (items) => 'Note [' + items[0].label + ' ; ' + (Number(items[0].label) + 1) + '[',
              label: (item) => 'Effectif : ' + item.raw
            }
          },
          vlines: { lines: opts.lines || [] }
        }
      }
    });
    canvas.__chart = c;
    charts.push(c);
    return c;
  }

  /* ---------- rendu ---------- */

  let semDistMode = 'abs'; // défaut : effectifs bruts
  let semDistAdmis = false;

  function render() {
    destroyAllCharts();
    const meta = DATA.meta;
    const cohortTitle = (meta.niveau || meta.semestre) ? niceCohort(meta.niveau, meta.semestre) : '';
    document.getElementById('fileTitle').textContent = cohortTitle
      ? 'Vue globale — ' + cohortTitle + ' (' + meta.filesCount + ' parcours)'
      : 'Vue globale — ' + meta.filesCount + ' parcours';
    const bits = [meta.filesCount + ' fichiers'];
    const firstObj = (DATA.groups[0] && DATA.groups[0].meta && DATA.groups[0].meta.objetParsed) || null;
    if (firstObj && firstObj.annee) bits.push('Année ' + firstObj.annee);
    if (meta.redoublantSource) bits.push('Redoublants : ' + meta.redoublantSource);
    document.getElementById('fileMeta').textContent = bits.join('  ·  ');
    document.getElementById('exportAll').href = 'api/export.php?token=' + encodeURIComponent(TOKEN) + '&type=zip';
    const warns = meta.warnings || [];
    document.getElementById('warnings').innerHTML = warns.length
      ? '<details class="warn-box">' +
      '<summary>⚠ ' + warns.length + ' avertissement' + (warns.length > 1 ? 's' : '') + ' — cliquer pour dérouler</summary>' +
      warns.map((x) => '<div class="warn">⚠ ' + esc(x) + '</div>').join('') +
      '</details>'
      : '';

    document.getElementById('main').innerHTML =
      kpisHtml() + semDistHtml() + byParcoursHtml() + groupsHtml() +
      sharedHtml() + sharedCardsHtml() + specificHtml() + specificCardsHtml() +
      redoublantsHtml() + semBorderlineHtml() + borderlineHtml() +
      absencesHtml() + examAbsencesHtml() + anomaliesHtml() +
      '<div class="footer">Vue globale générée le ' + esc(meta.generatedAt || '') + ' · données conservées 24 h</div>';

    drawParcours();
    initSemToggle();
    drawShared();
    drawRedou();
    initListTabs();
    initAnomToggle();
    bindSubjectClicks();
    document.querySelectorAll('.student-row').forEach((tr) => {
      tr.addEventListener('click', () => openStudent(Number(tr.dataset.g), tr.dataset.id, tr.dataset.nom, tr.dataset.prenom));
    });
    // Tableau « Réussite par parcours » : clic sur une ligne → analyse du parcours
    document.querySelectorAll('#parcoursTable tbody tr').forEach((tr) => {
      tr.addEventListener('click', () => {
        location.href = 'analyse.php?token=' + encodeURIComponent(TOKEN) + '&g=' + tr.dataset.g;
      });
    });
    initSearch();
  }

  function kpisHtml() {
    const k = DATA.global.kpis;
    const R = DATA.global.redoublants || null;
    const rep = k.repartition || {};
    const pct = (n) => k.effectif ? (n / k.effectif * 100).toFixed(1).replace('.', ',') + ' %' : '—';
    const items = [
      ['Effectif total', k.effectif, 'étudiants'],
    ];
    if (R) {
      items.push(['Redoublants', R.matched, 'présent(s) dans les fichiers']);
    }
    items.push(
      ['ADM', rep.ADM || 0, pct(rep.ADM || 0)],
      ['AJ', rep.AJ || 0, pct(rep.AJ || 0)],
      ['DEF', rep.DEF || 0, pct(rep.DEF || 0)],
      ['Moyenne sem.', fmt(k.semNote.mean), 'n = ' + (k.semNote.n || 0)],
      ['Médiane sem.', fmt(k.semNote.median)],
      ['Écart-type sem.', fmt(k.semNote.std)],
      ['Moyenne (admis)', fmt(k.semNoteAdmis.mean), 'n = ' + (k.semNoteAdmis.n || 0)]
    );
    return '<section><div class="kpis-row">' + kpiCards(items) + '</div></section>';
  }

  function byParcoursHtml() {
    const P = DATA.global.byParcours;
    const rows = P.map((p, i) =>
      '<tr class="clickable" data-g="' + i + '"><td>' + nameColored(p.label, i) + '</td><td class="muted mono">' + esc(p.fileName) + '</td>' +
      '<td>' + p.effectif + '</td><td>' + p.ADM + '</td><td>' + p.AJ + '</td><td>' + p.DEF + '</td>' +
      '<td>' + fmt(p.tauxAdm, 1) + ' %</td><td>' + fmt(p.mean) + '</td><td>' + fmt(p.median) + '</td>' +
      '<td class="sigma-cell ' + sigmaCls(p.std) + '">' + fmt(p.std) + '</td></tr>'
    ).join('');
    return '<section><h2>Réussite par parcours</h2>' +
      '<div class="table-wrap"><table id="parcoursTable"><thead>…</thead><tbody>' + rows + '</tbody></table></div>' +
      '<div class="chart-box" style="height:240px;margin-top:16px"><canvas id="parcoursCanvas"></canvas></div>' +
      '<p class="muted">Clic sur une ligne : analyse détaillée du parcours.</p></section>';
  }

  function drawParcours() {
    const P = DATA.global.byParcours;
    const cv = document.getElementById('parcoursCanvas');
    if (!cv) return;
    cv.__chart = new Chart(cv, {
      type: 'bar',
      data: {
        labels: P.map((p) => p.label),
        datasets: [{
          data: P.map((p) => p.tauxAdm),
          backgroundColor: P.map((_, i) => colorOf(i)), barPercentage: 0.5, categoryPercentage: 0.9
        }]
      },
      options: {
        indexAxis: 'y', responsive: true, maintainAspectRatio: false,
        scales: { x: { beginAtZero: true, max: 100, title: { display: true, text: '% ADM', font: { size: 13 } }, ticks: { font: { size: 11 } } } },
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: (it) => it.raw + ' % ADM' } } }
      }
    });
    charts.push(cv.__chart);
  }

  function semDistHtml() {
    return '<section><div class="section-head"><h2 style="margin:0">Distribution des notes du semestre</h2>' +
      '<div class="actions">' +
      '<div class="toggle"><button id="semNorm">Normalisé (%)</button><button id="semAbs" class="active">Effectifs bruts</button></div>' +
      '<div class="toggle"><button id="semAll" class="active">Tous</button><button id="semAdmis">Admis</button></div>' +
      '</div></div>' +
      '<div class="chart-box tall"><canvas id="semDistCanvas"></canvas></div>' +
      '<p class="muted">Effectifs bruts : barres empilées (effectif total par case). Normalisé (%) : chaque parcours rapporté à 100 % — comparable malgré des effectifs très différents.</p></section>';
  }

  function initSemToggle() {
    const bNorm = document.getElementById('semNorm');
    const bAbs = document.getElementById('semAbs');
    const bAll = document.getElementById('semAll');
    const bAdmis = document.getElementById('semAdmis');
    bNorm.addEventListener('click', () => { semDistMode = 'norm'; bNorm.classList.add('active'); bAbs.classList.remove('active'); drawSemDist(); });
    bAbs.addEventListener('click', () => { semDistMode = 'abs'; bAbs.classList.add('active'); bNorm.classList.remove('active'); drawSemDist(); });
    bAll.addEventListener('click', () => { semDistAdmis = false; bAll.classList.add('active'); bAdmis.classList.remove('active'); drawSemDist(); });
    bAdmis.addEventListener('click', () => { semDistAdmis = true; bAdmis.classList.add('active'); bAll.classList.remove('active'); drawSemDist(); });
    drawSemDist();
  }

  function drawSemDist() {
    const cv = document.getElementById('semDistCanvas');
    if (!cv) return;
    if (cv.__chart) { const idx = charts.indexOf(cv.__chart); if (idx !== -1) charts.splice(idx, 1); cv.__chart.destroy(); }
    const labels = [...Array(20).keys()].map(String);
    if (semDistMode === 'norm') {
      const datasets = DATA.groups.map((g, i) => {
        const k = g.analysis.kpis || {};
        const d = semDistAdmis ? k.histSemAdmis : k.histSem;
        const counts = d.counts || [];
        const total = counts.reduce((a, b) => a + b, 0);
        return {
          label: g.label, data: counts.map((c) => total > 0 ? c / total * 100 : 0),
          backgroundColor: colorOf(i) + 'cc', borderColor: colorOf(i), borderWidth: 1, barPercentage: 0.8, categoryPercentage: 0.9
        };
      });
      cv.__chart = new Chart(cv, {
        type: 'bar', data: { labels, datasets },
        options: {
          responsive: true, maintainAspectRatio: false,
          scales: {
            x: { ticks: { autoSkip: false, font: { size: 11 } }, title: { display: true, text: 'Note /20 — [n ; n+1[', font: { size: 13 } } },
            y: { beginAtZero: true, title: { display: true, text: '% des étudiants du parcours', font: { size: 13 } } }
          },
          plugins: {
            legend: { position: 'top', labels: { boxWidth: 12 } },
            tooltip: { callbacks: { title: (it) => 'Note [' + it.label + ' ; ' + (Number(it.label) + 1) + '[', label: (it) => it.dataset.label + ' : ' + it.raw.toFixed(1) + ' %' } }
          }
        }
      });
    } else {
      const datasets = DATA.groups.map((g, i) => {
        const k = g.analysis.kpis || {};
        const d = semDistAdmis ? k.histSemAdmis : k.histSem;
        return { label: g.label, data: (d.counts || []).slice(), backgroundColor: colorOf(i), stack: 's', barPercentage: 1, categoryPercentage: 1 };
      });
      cv.__chart = new Chart(cv, {
        type: 'bar', data: { labels, datasets },
        options: {
          responsive: true, maintainAspectRatio: false,
          scales: {
            x: { stacked: true, ticks: { autoSkip: false, font: { size: 11 } }, title: { display: true, text: 'Note /20', font: { size: 13 } } },
            y: { stacked: true, beginAtZero: true, ticks: { precision: 0, font: { size: 11 } }, title: { display: true, text: 'Effectif', font: { size: 13 } } }
          },
          plugins: {
            legend: { position: 'top', labels: { boxWidth: 12 } },
            tooltip: { callbacks: { title: (it) => 'Note [' + it.label + ' ; ' + (Number(it.label) + 1) + '[', label: (it) => it.dataset.label + ' : ' + it.raw } }
          }
        }
      });
    }
    charts.push(cv.__chart);
  }

  function sharedHtml() {
    const S = DATA.global.sharedSubjects;
    const mode = DATA.global.matchMode || 'code4';
    const modeLabel = { code4: 'les 4 derniers caractères du code + le 1er mot du nom de la matière', code2: 'les 2 derniers caractères du code', name: 'le nom de la matière', full: 'le code complet' }[mode] || mode;
    if (!S.length) {
      return '<section><h2>Matières partagées</h2><p class="muted">Aucune matière commune détectée entre les parcours.</p></section>';
    }
    const body = S.map((s, idx) => {
      const c = s.combined;
      const namesTxt = (s.names && s.names.length) ? s.names.join(' / ') : (s.name || s.code);
      return '<tr class="clickable" data-kind="shared" data-idx="' + idx + '">' +
        '<td>' + esc(namesTxt) + '<br>' + codesColorHtml(s.groups, false) + '</td>' +
        '<td>' + c.effectif + '</td>' +
        '<td class="acq-cell ' + acqCls(c.acqPct) + '">' + (c.acqPct === null ? '—' : c.acqPct + ' %') + '</td>' +
        '<td>' + fmt(c.mean) + '</td><td>' + fmt(c.median) + '</td>' +
        '<td class="sigma-cell ' + sigmaCls(c.std) + '">' + fmt(c.std) + '</td>' +
        '</tr>';
    }).join('');
    return '<section><h2>Matières communes (présentes dans au moins 2 parcours)</h2>' +
      '<p class="muted">Identification par ' + modeLabel + '. Statistiques combinées (tous les étudiants de tous les parcours qui suivent la matière). Les codes sont colorés par parcours. Clic sur une ligne : analyse détaillée.</p>' +
      '<div class="table-wrap"><table><thead><tr><th>Matière</th><th>Eff. total</th><th>ACQ %</th><th>Moy.</th><th>Méd.</th><th>σ</th></tr></thead><tbody>' + body + '</tbody></table></div>' +
      '<div class="chart-box" style="height:' + Math.max(320, S.length * 44 + 80) + 'px;margin-top:16px"><canvas id="sharedCanvas"></canvas></div>' +
      '<p class="muted">Comparatif des moyennes par parcours.</p></section>';
  }

  function drawShared() {
    const S = DATA.global.sharedSubjects;
    const cv = document.getElementById('sharedCanvas');
    if (!cv || !S.length) return;
    cv.__chart = new Chart(cv, {
      type: 'bar',
      data: {
        labels: S.map((s) => (s.name || s.code)),
        datasets: DATA.groups.map((g, i) => ({
          label: g.label,
          data: S.map((s) => { const gr = s.groups.find((x) => x.g === i); return gr ? gr.mean : null; }),
          backgroundColor: colorOf(i), barPercentage: 0.95, categoryPercentage: 0.9
        }))
      },
      options: {
        indexAxis: 'y', responsive: true, maintainAspectRatio: false,
        scales: {
          x: { min: 0, max: 20, title: { display: true, text: 'Moyenne /20', font: { size: 13 } }, ticks: { font: { size: 11 } } },
          y: { ticks: { font: { size: 11 } } }
        },
        plugins: { legend: { position: 'top', labels: { boxWidth: 12 } } }
      }
    });
    charts.push(cv.__chart);
  }

  function sharedCardsHtml() {
    const S = DATA.global.sharedSubjects;
    if (!S.length) return '';
    const cards = S.map((s, idx) => {
      const parcoursNames = (s.groups || []).map((g) => nameColored(g.label, g.g)).join(', ');
      return '<a class="ue-card" data-kind="shared" data-idx="' + idx + '">' +
        '<div class="name">' + esc(s.name || s.code) + '</div>' +
        '<div class="code">' + (s.groups || []).length + ' parcours · ' + parcoursNames + '</div>' +
        '</a>';
    }).join('');
    return '<section><h2>Matières communes — analyse détaillée</h2>' +
      '<p class="muted">Cliquer pour ouvrir l\'analyse (histogramme global puis par parcours).</p>' +
      '<div class="ue-grid">' + cards + '</div></section>';
  }

  function specificHtml() {
    const L = DATA.global.specificSubjects;
    if (!L.length) return '';
    const rows = L.map((s, idx) =>
      '<tr class="clickable" data-kind="specific" data-idx="' + idx + '">' +
      '<td class="mono" style="color:' + colorOf(s.g) + ';font-weight:600">' + esc(s.fullCode || s.code) + '</td>' +
      '<td>' + esc(s.name || '—') + '</td><td>' + nameColored(s.label, s.g) + '</td>' +
      '<td>' + s.effectif + '</td>' +
      '<td class="acq-cell ' + acqCls(s.acqPct) + '">' + (s.acqPct === null ? '—' : s.acqPct + ' %') + '</td>' +
      '<td>' + fmt(s.mean) + '</td>' +
      '</tr>'
    ).join('');
    return '<section><h2>Matières spécifiques à un seul parcours</h2>' +
      '<p class="muted">Clic sur une ligne : analyse détaillée (notes de l\'UE et de ses épreuves).</p>' +
      '<div class="table-wrap"><table><thead><tr><th>Code</th><th>Matière</th><th>Parcours</th><th>Eff.</th><th>ACQ %</th><th>Moy.</th></tr></thead><tbody>' + rows + '</tbody></table></div></section>';
  }

  function specificCardsHtml() {
    const L = DATA.global.specificSubjects;
    if (!L.length) return '';
    const cards = L.map((s, idx) =>
      '<a class="ue-card" data-kind="specific" data-idx="' + idx + '">' +
      '<div class="name">' + esc(s.name || s.code) + '</div>' +
      '<div class="code">' + nameColored(s.label, s.g) + '</div>' +
      '</a>'
    ).join('');
    return '<section><h2>Matières spécifiques — analyse détaillée</h2>' +
      '<p class="muted">Cliquer pour ouvrir l\'analyse (notes de l\'UE et de ses épreuves).</p>' +
      '<div class="ue-grid">' + cards + '</div></section>';
  }

  function bindSubjectClicks() {
    document.querySelectorAll('[data-kind]').forEach((el) => {
      el.addEventListener('click', () => openSubjectDetail(el.dataset.kind, Number(el.dataset.idx)));
    });
  }

  /* ---------- analyse détaillée d'une matière ---------- */

  function openSubjectDetail(kind, idx) {
    let title, codesHtml, combined, perProgram, examsList, subLine;
    if (kind === 'shared') {
      const s = DATA.global.sharedSubjects[idx];
      title = (s.names && s.names.length) ? s.names.join(' / ') : (s.name || s.code);
      combined = s.combined;
      perProgram = s.groups;
      examsList = null;
      codesHtml = codesColorHtml(s.groups, false);
      subLine = (s.groups || []).length + ' parcours · matching ' + esc(DATA.global.matchMode || 'code4');
    } else {
      const s = DATA.global.specificSubjects[idx];
      title = s.name || s.code;
      combined = { effectif: s.effectif, admis: s.admis, acqPct: s.acqPct, mean: s.mean, median: s.median, std: s.std, hist: s.hist };
      perProgram = null;
      examsList = s.exams || [];
      codesHtml = codesColorHtml([{ g: s.g, fullCode: s.fullCode || s.code }], true);
      subLine = nameColored(s.label, s.g) + ' · matière spécifique à ce parcours';
    }

    let body =
      '<h2>' + esc(title) + ' <span class="mono" style="font-weight:normal;font-size:13px">' + codesHtml + '</span></h2>' +
      '<div class="muted" style="margin:0 0 12px">' + subLine + '</div>' +
      '<h3>' + (perProgram ? 'Global (tous les étudiants de la matière)' : 'Notes de l\'UE') + '</h3>' +
      '<div class="cards">' + kpiCards([
        ['Effectif', combined.effectif],
        ['Admis (≥10)', combined.admis, combined.effectif ? (combined.admis / combined.effectif * 100).toFixed(1).replace('.', ',') + ' %' : ''],
        ['ACQ %', combined.acqPct === null ? '—' : combined.acqPct + ' %'],
        ['Moyenne /20', fmt(combined.mean)],
        ['Médiane', fmt(combined.median)],
        ['Écart-type', fmt(combined.std), '', (combined.std !== null && combined.std !== undefined) ? 'class="sigma-cell ' + sigmaCls(combined.std) + '"' : 'class="sigma-cell"']
      ]) + '</div>' +
      '<div class="chart-box" style="margin-top:14px"><canvas id="subjGlobalCanvas"></canvas></div>';

    if (perProgram) {
      // Tableau comparatif : un parcours par ligne (sous l'histogramme global)
      const pRows = perProgram.map((p) =>
        '<tr><td>' + nameColored(p.label, p.g) + '</td>' +
        '<td>' + p.effectif + '</td>' +
        '<td class="acq-cell ' + acqCls(p.acqPct) + '">' + (p.acqPct === null ? '—' : p.acqPct + ' %') + '</td>' +
        '<td>' + fmt(p.mean) + '</td><td>' + fmt(p.median) + '</td>' +
        '<td class="sigma-cell ' + sigmaCls(p.std) + '">' + fmt(p.std) + '</td></tr>'
      ).join('');
      body += '<div class="table-wrap" style="margin:14px 0">' +
        '<table><thead><tr><th>Parcours</th><th data-numeric="1">Eff.</th><th data-numeric="1">ACQ %</th><th data-numeric="1">Moy.</th><th data-numeric="1">Méd.</th><th data-numeric="1">σ</th></tr></thead><tbody>' + pRows + '</tbody></table></div>';

      body += '<h3>Parcours</h3><div class="exams-grid">' + perProgram.map((p) =>
        '<div class="exam-card">' +
        '<div class="name">' + nameColored(p.label, p.g) + '</div>' +
        '<div class="row"><span>Eff. <b>' + p.effectif + '</b></span><span class="acq-cell ' + acqCls(p.acqPct) + '">ACQ <b>' + (p.acqPct === null ? '—' : p.acqPct + ' %') + '</b></span></div>' +
        '<div class="row"><span>Moy. <b>' + fmt(p.mean) + '</b></span><span class="sigma-cell ' + sigmaCls(p.std) + '">σ <b>' + fmt(p.std) + '</b></span></div>' +
        '<div class="exam-mini"><canvas></canvas></div>' +
        '</div>'
      ).join('') + '</div>';
    } else {
      body += '<h3>Épreuves</h3><div class="exams-grid">' + examsList.map((ex) =>
        '<div class="exam-card">' +
        '<div class="code">' + esc(ex.code || '—') + '</div>' +
        '<div class="name">' + esc(ex.name || 'Épreuve') + '</div>' +
        '<div class="row"><span>n = <b>' + ((ex.stats && ex.stats.n) || 0) + '</b></span><span>moy. <b>' + fmt(ex.stats ? ex.stats.mean : null) + '</b></span></div>' +
        '<div class="exam-mini"><canvas></canvas></div>' +
        (ex.rawCounts && Object.keys(ex.rawCounts).length
          ? '<div class="muted raws">' + Object.entries(ex.rawCounts).map(([k, v]) => esc(k) + ' × ' + v).join(' · ') + '</div>'
          : '') +
        '</div>'
      ).join('') + '</div>';
    }

    document.getElementById('modalContent').innerHTML = body;
    document.getElementById('modal').classList.remove('hidden');

    // Le graphique global est en gris neutre (pas une couleur de parcours)
    hist(document.getElementById('subjGlobalCanvas'), combined.hist, { lines: vlinesFor(combined), color: GLOBAL_GRAY + 'aa', borderColor: GLOBAL_GRAY });
    const grid = document.querySelector('#modalContent .exams-grid');
    if (grid) {
      grid.querySelectorAll('.exam-card canvas').forEach((cv, i) => {
        const sData = perProgram ? perProgram[i] : (examsList[i] || {});
        hist(cv, (sData && sData.hist) || { labels: [], counts: [] },
          { lines: vlinesFor(sData && sData.stats ? sData.stats : null), color: colorOf((perProgram ? sData.g : 0) % COLORS.length) + '66', borderColor: colorOf((perProgram ? sData.g : 0) % COLORS.length) });
      });
    }
  }

  function redoublantsHtml() {
    const R = DATA.global.redoublants;
    if (!R) return '';
    const rows = R.byGroup.map((r) =>
      '<tr><td>' + nameColored(r.label, r.g) + '</td><td>' + r.matched + '</td><td>' + r.ADM + '</td><td>' + r.AJ + '</td><td>' + r.DEF + '</td>' +
      '<td>' + fmt(r.tauxAdm, 1) + ' %</td><td>' + fmt(r.mean) + '</td><td>' + fmt(r.median) + '</td>' +
      '<td class="sigma-cell ' + sigmaCls(r.std) + '">' + fmt(r.std) + '</td></tr>'
    ).join('');
    return '<section><h2>Redoublants par parcours — ' + R.matched + ' présent(s) sur ' + R.requested + ' identifiant(s) fournis</h2>' +
      '<div class="table-wrap"><table><thead><tr><th>Parcours</th><th>Red. présents</th><th>ADM</th><th>AJ</th><th>DEF</th><th>Taux ADM (red.)</th><th>Moy.</th><th>Méd.</th><th>σ</th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
      '<div class="chart-box" style="height:240px;margin-top:16px"><canvas id="redouCanvas"></canvas></div>' +
      '<p class="muted">Comparaison du taux ADM : redoublants vs tous les étudiants, par parcours.</p></section>';
  }

  function drawRedou() {
    const R = DATA.global.redoublants;
    const cv = document.getElementById('redouCanvas');
    if (!R || !cv) return;
    const idx = R.byGroup.map((r) => DATA.global.byParcours.findIndex((p) => p.label === r.label));
    cv.__chart = new Chart(cv, {
      type: 'bar',
      data: {
        labels: R.byGroup.map((r) => r.label),
        datasets: [
          { label: 'Tous les étudiants', data: idx.map((i) => (i >= 0 ? DATA.global.byParcours[i].tauxAdm : null)), backgroundColor: '#94a3b8' },
          { label: 'Redoublants', data: R.byGroup.map((r) => r.tauxAdm), backgroundColor: '#7c3aed' }
        ]
      },
      options: {
        indexAxis: 'y', responsive: true, maintainAspectRatio: false,
        scales: { x: { beginAtZero: true, max: 100, title: { display: true, text: '% ADM', font: { size: 13 } }, ticks: { font: { size: 11 } } } },
        plugins: { legend: { position: 'top', labels: { boxWidth: 12 } } }
      }
    });
    charts.push(cv.__chart);
  }

  /* ---------- listes jury (onglets par parcours) ---------- */

  function listTabsHtml(tableId, counts, total) {
    return '<div class="tabs" data-table="' + tableId + '">' +
      '<button class="tab active" data-g="-1">Tous les parcours (' + total + ')</button>' +
      DATA.groups.map((g, i) => '<button class="tab" data-g="' + i + '">' + esc(g.label) + ' (' + counts[i] + ')</button>').join('') +
      '</div>';
  }

  function absencesHtml() {
    const list = DATA.global.absences || [];
    const counts = DATA.groups.map((g, i) => list.filter((r) => r.g === i).length);
    const cnt = (v) => '<td' + (Number(v) === 1 ? ' style="color:#dc2626;font-weight:700"' : '') + '>' + v + '</td>';
    const rows = list.map((r) =>
      '<tr class="clickable student-row" data-g="' + r.g + '" data-id="' + esc(r.id) + '" data-nom="' + esc(r.nom) + '" data-prenom="' + esc(r.prenom) + '">' +
      '<td>' + nameColored(r.label, r.g) + '</td><td class="mono">' + esc(r.id) + '</td><td>' + esc(r.nom) + '</td><td>' + esc(r.prenom) + '</td>' +
      cnt(r.DIS) + cnt(r.ABJ) + cnt(r.ABI) + '</tr>'
    ).join('');
    return '<section><div class="section-head"><h2 style="margin:0">Défaillants avec absences</h2>' +
      '<a class="btn small" href="api/export.php?token=' + encodeURIComponent(TOKEN) + '&type=csvg:absences">CSV</a></div>' +
      listTabsHtml('absTable', counts, list.length) +
      (list.length
        ? '<div class="table-wrap"><table id="absTable"><thead><tr><th>Parcours</th><th>N°Etu</th><th>Nom</th><th>Prénom</th><th>DIS</th><th>ABJ</th><th>ABI</th></tr></thead><tbody>' + rows + '</tbody></table></div>'
        : '<p class="muted">Aucun étudiant.</p>') +
      '<p class="muted">Clic sur une ligne : fiche de l\'étudiant.</p></section>';
  }

  /* ---------- DIS / ABI / ABJ par épreuve (une matière par ligne) ---------- */
  function examAbsencesHtml() {
    // Réunir les matières (partagées + spécifiques) avec leurs épreuves
    const subjects = [];
    (DATA.global.sharedSubjects || []).forEach((s) => {
      const name = (s.names && s.names.length) ? s.names.join(' / ') : (s.name || s.code);
      subjects.push({
        name,
        groups: (s.groups || []).map((p) => ({ label: p.label, g: p.g, exams: p.exams || [] }))
      });
    });
    (DATA.global.specificSubjects || []).forEach((s) => {
      subjects.push({
        name: s.name || s.code,
        groups: [{ label: s.label, g: s.g, exams: s.exams || [] }]
      });
    });
    if (!subjects.length) return '';

    const rows = subjects.map((subj) => {
      // Aggréger DIS/ABI/ABJ par épreuve (clé = nom d'épreuve), tous parcours confondus
      const examMap = {};
      subj.groups.forEach((p) => {
        (p.exams || []).forEach((ex, idx) => {
          const key = ex.name || ex.code || ('Épreuve ' + (idx + 1));
          if (!examMap[key]) examMap[key] = { display: ex.name || ex.code || ('Épreuve ' + (idx + 1)), DIS: 0, ABI: 0, ABJ: 0 };
          Object.entries(ex.rawCounts || {}).forEach(([code, n]) => {
            const c = Number(n) || 0;
            if (code === 'DIS') examMap[key].DIS += c;
            else if (code === 'ABI') examMap[key].ABI += c;
            else if (code === 'ABJ') examMap[key].ABJ += c;
          });
        });
      });

      const parcours = subj.groups.map((p) => nameColored(p.label, p.g)).join(', ');
      const examBlocks = Object.values(examMap).map((c) => {
        const parts = [];
        if (c.DIS) parts.push('DIS ' + c.DIS);
        if (c.ABI) parts.push('ABI ' + c.ABI);
        if (c.ABJ) parts.push('ABJ ' + c.ABJ);
        const has = parts.length > 0;
        return '<div class="exam-abs-block">' +
          '<div class="exam-abs-name">' + esc(c.display) + '</div>' +
          '<div class="' + (has ? 'exam-abs-yes' : 'exam-abs-no') + '">' +
          (has ? parts.join(' · ') : 'aucune absence') + '</div>' +
          '</div>';
      }).join('');

      return '<tr><td>' + esc(subj.name) + '</td><td>' + parcours + '</td>' +
        '<td class="exam-abs-cell">' + (examBlocks || '<span class="muted">Aucune épreuve</span>') + '</td></tr>';
    }).join('');

    return '<section><div class="section-head"><h2 style="margin:0">Absences par épreuve (DIS / ABI / ABJ)</h2></div>' +
      '<p class="muted">Une matière par ligne ; les épreuves sont listées en dessous. Pour les matières communes, les mentions sont additionnées sur tous les parcours. Rouge quand il y a des absences.</p>' +
      '<div class="table-wrap"><table id="examAbsTable"><thead><tr><th>Matière</th><th>Parcours</th><th>Épreuves</th></tr></thead><tbody>' + rows + '</tbody></table></div></section>';
  }

  function semBorderlineHtml() {
    const list = DATA.global.semBorderline || [];
    const counts = DATA.groups.map((g, i) => list.filter((r) => r.g === i).length);
    const rows = list.map((r) =>
      '<tr class="clickable student-row" data-g="' + r.g + '" data-id="' + esc(r.id) + '" data-nom="' + esc(r.nom) + '" data-prenom="' + esc(r.prenom) + '">' +
      '<td>' + nameColored(r.label, r.g) + '</td><td class="mono">' + esc(r.id) + '</td><td>' + esc(r.nom) + '</td><td>' + esc(r.prenom) + '</td>' +
      '<td>' + fmt(r.note, 3) + '</td><td>' + badge(r.resultat) + '</td></tr>'
    ).join('');
    return '<section><div class="section-head"><h2 style="margin:0">Notes de semestre entre 9,95 et 10</h2>' +
      '<a class="btn small" href="api/export.php?token=' + encodeURIComponent(TOKEN) + '&type=csvg:sem_borderline">CSV</a></div>' +
      listTabsHtml('semBlTable', counts, list.length) +
      (list.length
        ? '<div class="table-wrap"><table id="semBlTable"><thead><tr><th>Parcours</th><th>N°Etu</th><th>Nom</th><th>Prénom</th><th>Note sem.</th><th>Résultat</th></tr></thead><tbody>' + rows + '</tbody></table></div>'
        : '<p class="muted">Aucun étudiant.</p>') +
      '<p class="muted">Clic sur une ligne : fiche de l\'étudiant.</p></section>';
  }

  function borderlineHtml() {
    const list = DATA.global.borderline || [];
    const counts = DATA.groups.map((g, i) => list.filter((r) => r.g === i).length);
    const rows = list.map((r) =>
      '<tr class="clickable student-row" data-g="' + r.g + '" data-id="' + esc(r.id) + '" data-nom="' + esc(r.nom) + '" data-prenom="' + esc(r.prenom) + '">' +
      '<td>' + nameColored(r.label, r.g) + '</td><td class="mono">' + esc(r.id) + '</td><td>' + esc(r.nom) + '</td><td>' + esc(r.prenom) + '</td>' +
      '<td>' + fmt(r.note, 3) + '</td><td class="mono">' + esc(r.ueCode) + '</td><td>' + esc(r.ueName) + '</td></tr>'
    ).join('');
    return '<section><div class="section-head"><h2 style="margin:0">Notes de l\'UE entre 9,95 et 10</h2>' +
      '<a class="btn small" href="api/export.php?token=' + encodeURIComponent(TOKEN) + '&type=csvg:borderline">CSV</a></div>' +
      listTabsHtml('blTable', counts, list.length) +
      (list.length
        ? '<div class="table-wrap"><table id="blTable"><thead><tr><th>Parcours</th><th>N°Etu</th><th>Nom</th><th>Prénom</th><th>Note</th><th>CODE UE</th><th>Matière</th></tr></thead><tbody>' + rows + '</tbody></table></div>'
        : '<p class="muted">Aucun étudiant.</p>') +
      '<p class="muted">Clic sur une ligne : fiche de l\'étudiant.</p></section>';
  }

  /* ---------- contrôles PV provisoire (masquables) ---------- */

  /* ---------- contrôles PV provisoire (masquables, onglets, regroupés) ---------- */

  function anomaliesHtml() {
    const A = DATA.global.anomalies || [];
    const counts = DATA.global.anomalyCounts || {};
    if (!A.length) return '';

    // Regrouper par étudiant (clé: g|id)
    const byStudent = {};
    for (const a of A) {
      const key = a.g + '|' + a.id;
      if (!byStudent[key]) {
        byStudent[key] = { g: a.g, label: a.label, id: a.id, nom: a.nom, prenom: a.prenom, issues: [] };
      }
      byStudent[key].issues.push(a);
    }
    const students = Object.values(byStudent);

    const rows = students.map((s) =>
      '<tr class="clickable student-row" data-g="' + s.g + '" data-id="' + esc(s.id) + '" data-nom="' + esc(s.nom) + '" data-prenom="' + esc(s.prenom) + '">' +
      '<td>' + nameColored(s.label, s.g) + '</td>' +
      '<td class="mono">' + esc(s.id) + '</td><td>' + esc(s.nom) + '</td><td>' + esc(s.prenom) + '</td>' +
      '<td>' + s.issues.map((a) =>
        (a.ueName ? '<div>' + esc(a.ueName) + '</div>' : '') +
        '<div class="muted">' + esc(a.message) + '</div>'
      ).join('') + '</td>' +
      '</tr>'
    ).join('');

    const tabCounts = DATA.groups.map((g, i) => students.filter((s) => s.g === i).length);

    return '<section class="anom-section">' +
      '<div class="section-head"><h2 style="margin:0">Contrôles PV provisoire <span class="muted" style="font-weight:normal">(à masquer pour un PV définitif)</span></h2>' +
      '<button class="btn small ghost" id="anomToggle">Masquer</button></div>' +
      '<p class="muted">' +
      esc(ANOM_LABELS.ue_note_vide) + ' : ' + (counts.ue_note_vide || 0) + ' · ' +
      esc(ANOM_LABELS.ue_single_note) + ' : ' + (counts.ue_single_note || 0) + ' · ' +
      esc(ANOM_LABELS.sem_def_incoherent) + ' : ' + (counts.sem_def_incoherent || 0) +
      '</p>' +
      '<div class="tabs" data-table="anomTable">' +
      '<button class="tab active" data-g="-1">Tous (' + students.length + ')</button>' +
      DATA.groups.map((g, i) => '<button class="tab" data-g="' + i + '">' + esc(g.label) + ' (' + tabCounts[i] + ')</button>').join('') +
      '</div>' +
      '<div class="table-wrap" id="anomTableWrap"><table id="anomTable"><thead><tr><th>Parcours</th><th>N°Etu</th><th>Nom</th><th>Prénom</th><th>Problème(s)</th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
      '</section>';
  }

  function initAnomToggle() {
    const btn = document.getElementById('anomToggle');
    if (!btn) return;
    const wrap = document.getElementById('anomTableWrap');
    if (!wrap) return;
    btn.addEventListener('click', () => {
      const hidden = wrap.style.display === 'none';
      wrap.style.display = hidden ? '' : 'none';
      btn.textContent = hidden ? 'Masquer' : 'Afficher';
    });
  }

  function initListTabs() {
    document.querySelectorAll('.tabs').forEach((tabsEl) => {
      const table = document.getElementById(tabsEl.dataset.table);
      if (!table) return;
      tabsEl.querySelectorAll('.tab').forEach((tab) => {
        tab.addEventListener('click', () => {
          tabsEl.querySelectorAll('.tab').forEach((t) => t.classList.remove('active'));
          tab.classList.add('active');
          const v = tab.dataset.g;
          table.querySelectorAll('tbody tr').forEach((tr) => {
            tr.style.display = (v === '-1' || String(tr.dataset.g) === v) ? '' : 'none';
          });
        });
      });
    });
  }

  function groupsHtml() {
    return '<section><h2>Parcours — ouvrir l\'analyse détaillée</h2><div class="ue-grid">' +
      DATA.groups.map((g, i) => {
        const k = g.analysis.kpis || {};
        const rep = k.repartition || {};
        const eff = k.effectif || 0;
        const adm = eff ? Math.round(rep.ADM / eff * 100) : 0;
        return '<a class="ue-card" href="analyse.php?token=' + encodeURIComponent(TOKEN) + '&g=' + i + '">' +
          '<div class="code">' + esc(g.fileName) + '</div>' +
          '<div class="name">' + nameColored(g.label, i) + '</div>' +
          '<div class="row"><span>Eff. <b>' + eff + '</b></span><span>ADM <b>' + adm + ' %</b></span></div>' +
          '<div class="row"><span>Moy. sem. <b>' + fmt(k.semNote.mean) + '</b></span><span>' + ((g.analysis.ues || []).length) + ' UE</span></div>' +
          '</a>';
      }).join('') + '</div></section>';
  }

  /* ---------- fiche étudiant ---------- */

  function problemUeCodes(gi, studentId) {
    const codes = new Set();
    for (const a of (DATA.global.anomalies || [])) {
      if (a.g === gi && a.id === studentId && a.ueCode) codes.add(a.ueCode);
    }
    return codes;
  }

  function openStudent(gi, id, nom, prenom) {
    const analysis = (DATA.groups[gi] || {}).analysis || {};
    const students = analysis.students || [];
    const s = students.find((x) => x.id === id) || students.find((x) => x.nom === nom && x.prenom === prenom);
    if (!s) { alert('Étudiant introuvable dans les données du parcours « ' + DATA.groups[gi].label + ' ».'); return; }

    const sem = s.sem || {};
    const info = Object.keys(s.info || {}).map((k) =>
      '<span>' + esc(k) + ' : <b>' + esc(s.info[k] || '—') + '</b></span>').join('');

    const badCodes = problemUeCodes(gi, s.id);
    const ueRows = (analysis.ues || []).map((ue, i) => {
      const u = (s.ues || [])[i];
      if (!u) return '';
      const prev = u.sessionPrecedente ? ' <span class="badge b-PREV">session préc.</span>' : '';
      const note = (u.note !== null && u.note !== undefined) ? fmt(u.note, 3) : badge(u.noteRaw);
      const name = (ue.name && ue.name.trim() !== '' && ue.name !== ue.code) ? ue.name : ue.code;
      const codeLine = (name !== ue.code) ? '<br><span class="muted mono">' + esc(ue.code) + '</span>' : '';
      const exams = (u.exams || []).map((ex) => {
        const v = examValue(ex);
        return '<span title="' + esc(ex.code) + '">' + esc(ex.name || ex.code || 'Épreuve') + ' : ' + v + '</span>';
      }).join('<br>');
      const bad = badCodes.has(ue.code);
      return '<tr' + (bad ? ' class="ue-problem"' : '') + '>' +
        '<td>' + esc(name) + prev + codeLine + '</td><td>' + note + '</td><td>' + badge(u.resultat) + '</td>' +
        '<td>' + ((u.credits !== null && u.credits !== undefined) ? fmt(u.credits) : '—') + '</td>' +
        '<td>' + (u.anSession ? esc(u.anSession) : '—') + '</td>' +
        '<td class="exams-cell">' + (exams || '<span class="muted">—</span>') + '</td></tr>';
    }).join('');

    document.getElementById('modalContent').innerHTML =
      '<h2>' + esc(s.prenom) + ' ' + esc(s.nom) + ' ' + badge(sem.resultat) +
      (s.isRedoublant ? ' <span class="badge b-RED">Redoublant</span>' : '') + '</h2>' +
      '<div class="muted" style="margin:0 0 10px">Parcours : ' + nameColored(DATA.groups[gi].label, gi) + '</div>' +
      '<div class="student-info"><span>N°Etu : <b class="mono">' + esc(s.id) + '</b></span>' + info +
      '<span>Note sem. : <b>' + fmt(sem.note, 3) + '</b></span>' +
      ((sem.credits !== null && sem.credits !== undefined) ? '<span>Crédits : <b>' + fmt(sem.credits) + '</b></span>' : '') +
      '</div>' +
      '<table class="student-table"><thead><tr><th>Matière</th><th>Note</th><th>Résultat</th><th>Crédits</th><th>An/Session</th><th>Épreuves</th></tr></thead><tbody>' + ueRows + '</tbody></table>' +
      '<p class="muted">Les éléments marqués « session préc. » ont été obtenus à une session antérieure ' +
      '(colonne An/Session remplie ou note/résultat souligné) — cf. la légende du fichier.</p>';
    document.getElementById('modal').classList.remove('hidden');
  }

  /* ---------- recherche étudiant (tous parcours) ---------- */

  function initSearch() {
    const input = document.getElementById('studentSearch');
    const box = document.getElementById('searchResults');
    if (!input || !box) return;

    const norm = (s) => String(s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');

    function close() { box.classList.add('hidden'); box.innerHTML = ''; }

    function render() {
      if (!DATA) return;
      const q = norm(input.value.trim());
      if (!q) { close(); return; }
      const matches = [];
      for (let gi = 0; gi < DATA.groups.length && matches.length < 20; gi++) {
        for (const s of (DATA.groups[gi].analysis.students || [])) {
          if (norm(s.nom).includes(q) || norm(s.prenom).includes(q) || norm(s.id).includes(q)) {
            matches.push({ s, gi });
            if (matches.length >= 20) break;
          }
        }
      }
      if (!matches.length) {
        box.innerHTML = '<div class="empty">Aucun étudiant trouvé.</div>';
      } else {
        box.innerHTML = matches.map((m, i) =>
          '<div class="item" data-i="' + i + '"><span>' + esc(m.s.prenom) + ' ' + esc(m.s.nom) + ' — ' + esc(DATA.groups[m.gi].label) + '</span><span class="id">' + esc(m.s.id) + '</span></div>'
        ).join('');
        box.querySelectorAll('.item').forEach((el) => {
          el.addEventListener('click', () => {
            const m = matches[Number(el.dataset.i)];
            openStudent(m.gi, m.s.id, m.s.nom, m.s.prenom);
            input.value = '';
            close();
          });
        });
      }
      box.classList.remove('hidden');
    }

    input.addEventListener('input', render);
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') close();
      if (e.key === 'Enter') { const first = box.querySelector('.item'); if (first) first.click(); }
    });
    document.addEventListener('click', (e) => { if (!e.target.closest('.search')) close(); });
  }

  /* ---------- réglages (seuils + couleurs par parcours) ---------- */

  function initThresholds() {
    const btn = document.getElementById('thresholdBtn');
    const modal = document.getElementById('thresholdModal');
    if (!btn || !modal) return;
    const fLow = document.getElementById('thAcqLow');
    const fHigh = document.getElementById('thAcqHigh');
    const fSigma = document.getElementById('thSigma');

    function fill() {
      fLow.value = THRESH.acqLow;
      fHigh.value = THRESH.acqHigh;
      fSigma.value = THRESH.sigma;
      const box = document.getElementById('thColors');
      if (box && DATA) {
        box.innerHTML = DATA.groups.map((g, i) =>
          '<div class="th-color-row"><span style="color:' + colorOf(i) + ';font-weight:600">' + esc(g.label) + '</span>' +
          '<input type="color" data-i="' + i + '" value="' + (COLORS[i] || PALETTE[i % PALETTE.length]) + '"></div>'
        ).join('');
      }
    }

    btn.addEventListener('click', () => { fill(); modal.classList.remove('hidden'); });
    document.getElementById('thresholdClose').addEventListener('click', () => modal.classList.add('hidden'));
    modal.addEventListener('click', (e) => { if (e.target === modal) modal.classList.add('hidden'); });

    document.getElementById('thApply').addEventListener('click', () => {
      const a = parseFloat(fLow.value), b = parseFloat(fHigh.value), s = parseFloat(fSigma.value);
      if (!isNaN(a) && a >= 0 && a <= 100) THRESH.acqLow = a;
      if (!isNaN(b) && b >= 0 && b <= 100) THRESH.acqHigh = b;
      if (!isNaN(s) && s >= 0) THRESH.sigma = s;
      try { sessionStorage.setItem('apoThresh', JSON.stringify(THRESH)); } catch (e) { /* ignoré */ }
      const colorInputs = document.querySelectorAll('#thColors input[type=color]');
      let colorsChanged = false;
      colorInputs.forEach((inp) => { COLORS[Number(inp.dataset.i)] = inp.value; colorsChanged = true; });
      if (colorsChanged) { try { sessionStorage.setItem('apoColors', JSON.stringify(COLORS)); } catch (e) { /* ignoré */ } }
      modal.classList.add('hidden');
      if (colorsChanged) { render(); } else { recolor(); }
    });

    document.getElementById('thReset').addEventListener('click', () => {
      THRESH = { acqLow: 40, acqHigh: 80, sigma: 3 };
      COLORS = PALETTE.slice();
      fill();
    });
  }

  /* ---------- démarrage ---------- */

  function init() {
    if (!window.Chart) {
      document.getElementById('main').innerHTML =
        '<div class="warn" style="border-radius:10px;margin:0">⚠ <b>Chart.js introuvable</b> : placez <b>chart.umd.js</b> dans <b>public/assets/libs/</b>.</div>';
      return;
    }
    Chart.register(vlines);
    fetch('api/analyse.php?token=' + encodeURIComponent(TOKEN) + '&all=1')
      .then((r) => r.json())
      .then((j) => {
        if (!j.ok) throw new Error(j.error || 'Erreur inattendue du serveur.');
        DATA = j.data;
        render();
      })
      .catch((e) => {
        document.getElementById('main').innerHTML =
          '<div class="warn" style="border-radius:10px;margin:0">⚠ ' + esc(e.message) +
          '<br><br><a class="btn" href="index.php">← Nouvelle analyse</a></div>';
      });
    initThresholds();
  }

  document.getElementById('modalClose').addEventListener('click', () => document.getElementById('modal').classList.add('hidden'));
  document.getElementById('modal').addEventListener('click', (e) => { if (e.target.id === 'modal') document.getElementById('modal').classList.add('hidden'); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') document.getElementById('modal').classList.add('hidden'); });

  init();
})();
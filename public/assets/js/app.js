/* Analyse interactive des relevés APO — phase 1 (v4, version finale) */
(function () {
  'use strict';

  const TOKEN = window.APO_TOKEN;
  const GROUP = (window.APO_GROUP === undefined || window.APO_GROUP === null) ? null : Number(window.APO_GROUP);
  let DATA = null;
  let MULTI = null;
  let currentUe = 0;
  let ueMode = 'all';
  let semMode = 'all';
  const charts = [];

  /* ---------- seuils de coloration (ACQ %, σ) ---------- */

  let THRESH = { acqLow: 40, acqHigh: 80, sigma: 3 };
  try {
    const saved = JSON.parse(sessionStorage.getItem('apoThresh') || 'null');
    if (saved && [saved.acqLow, saved.acqHigh, saved.sigma].every((x) => typeof x === 'number')) {
      THRESH = saved;
    }
  } catch (e) { /* ignoré */ }

  const acqCls = (p) => (p === null ? '' : p < THRESH.acqLow ? 'bad' : p > THRESH.acqHigh ? 'good' : '');
  const sigmaCls = (v) => (v !== null && v !== undefined && !isNaN(v) && v > THRESH.sigma) ? 'bad' : '';

  function recolor() {
    const num = (el) => {
      const m = String(el.textContent || '').match(/-?\d+(?:[.,]\d+)?/);
      return m ? parseFloat(m[0].replace(',', '.')) : NaN;
    };
    document.querySelectorAll('.acq-cell').forEach((el) => {
      const p = num(el);
      el.classList.remove('bad', 'good');
      const cls = acqCls(p);
      if (!isNaN(p) && cls) el.classList.add(cls);
    });
    document.querySelectorAll('.sigma-cell').forEach((el) => {
      const v = num(el);
      el.classList.remove('bad', 'good');
      const cls = sigmaCls(v);
      if (!isNaN(v) && cls) el.classList.add(cls);
    });
    const hint = document.getElementById('thHint');
    if (hint) {
      hint.innerHTML = 'ACQ &lt; ' + THRESH.acqLow + ' % en rouge, &gt; ' + THRESH.acqHigh +
        ' % en bleu · σ &gt; ' + THRESH.sigma +
        ' en rouge (modifiables via ⚙). Clic sur un en-tête : trier · clic sur une ligne : détail de la matière.';
    }
  }

  /* ---------- utilitaires ---------- */

  const esc = (s) => String(s === null || s === undefined ? '' : s)
    .replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  const fmt = (v, d) => {
    if (v === null || v === undefined || isNaN(v)) return '—';
    return Number(v).toFixed(d === undefined ? 2 : d).replace('.', ',');
  };

  const sumObj = (o) => Object.values(o || {}).reduce((a, b) => a + Number(b || 0), 0);

  // Valeur d'une épreuve : note numérique, ou mention (badge coloré) si pas de note
  function examValue(ex) {
    if (ex.note !== null && ex.note !== undefined) return fmt(ex.note, 3);
    if (ex.raw) return badge(ex.raw);
    return '—';
  }

  function acqPct(ue) {
    const tot = sumObj(ue.repartition);
    const acq = (ue.repartition || {}).ACQ || 0;
    return tot ? Math.round(acq / tot * 100) : null;
  }

  function destroyAllCharts() {
    const seen = new Set();
    for (const c of charts) {
      if (!seen.has(c)) { seen.add(c); c.destroy(); }
    }
    charts.length = 0;
  }

  function badge(code) {
    if (!code) return '<span class="badge b-NA">—</span>';
    const known = ['ADM', 'ACQ', 'AJ', 'NACQ', 'DEF', 'DIS', 'ABJ', 'ABI'];
    const cls = known.indexOf(code) !== -1 ? 'b-' + code : 'b-NA';
    return '<span class="badge ' + cls + '">' + esc(code) + '</span>';
  }

  /* it = [titre, valeur, sous-texte, attributsHTML sur .v (optionnel)] */
  function kpiCards(items) {
    return items.map((it) =>
      '<div class="card"><div class="t">' + it[0] + '</div>' +
      '<div class="v' + (it[3] ? ' ' + it[3] : '') + '">' + it[1] + '</div>' +
      (it[2] ? '<div class="s">' + it[2] + '</div>' : '') + '</div>'
    ).join('');
  }

  /* ---------- plugin : lignes verticales (moyenne / médiane / ±σ) ---------- */

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

  // Moyenne = noir, médiane = violet, σ = bleu
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

  /* ---------- histogramme ---------- */

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
          borderWidth: 1,
          barPercentage: 1,
          categoryPercentage: 1
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 250 },
        scales: {
          x: {
            grid: { display: false },
            ticks: opts.mini ? { display: false } : { autoSkip: false, font: { size: 10 } },
            title: opts.mini
              ? { display: false }
              : { display: true, text: 'Note /20 — chaque barre couvre [n ; n+1[' }
          },
          y: {
            beginAtZero: true,
            ticks: { display: !opts.mini, precision: 0 },
            title: opts.mini ? { display: false } : { display: true, text: 'Effectif' }
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

  /* ---------- vue d'ensemble ---------- */

  function renderOverview() {
    destroyAllCharts();
    const m = DATA.meta;
    const R = DATA.redoublants;

    document.getElementById('fileTitle').textContent = m.fileName || 'Analyse';
    const bits = [];
    if (m.fileNameParsed) {
      bits.push(m.fileNameParsed.niveau + ' · ' + m.fileNameParsed.parcours +
        (m.fileNameParsed.semestre ? ' · ' + m.fileNameParsed.semestre : ''));
    }
    if (m.objetParsed) {
      if (m.objetParsed.annee) bits.push('Année ' + m.objetParsed.annee);
      if (m.objetParsed.session) bits.push('Session ' + m.objetParsed.session);
    }
    bits.push('Effectif ' + m.effectif);
    bits.push(m.nbUe + ' UE');
    document.getElementById('fileMeta').textContent = bits.join('  ·  ');
    document.getElementById('exportAll').href = 'api/export.php?token=' + TOKEN + '&type=zip';

    document.getElementById('warnings').innerHTML = (DATA.warnings || [])
      .map((x) => '<div class="warn">⚠ ' + esc(x) + '</div>').join('');

    const main = document.getElementById('main');
    const backLink = MULTI ? ('<a class="back" href="global.php?token=' + encodeURIComponent(TOKEN) + '">' +
      '← Retour à la vue globale (' + esc(MULTI.groupLabel) + ')</a>') : '';
    main.innerHTML =
      backLink +
      overviewKpisHtml() +
      '<section><div class="section-head"><h2 style="margin:0">Distribution des notes du semestre</h2>' +
      '<div class="toggle">' +
      '<button id="semAll" class="active">Tous les étudiants</button>' +
      '<button id="semAdmis">Admis uniquement</button>' +
      ((R && R.matched > 0) ? '<button id="semRed">Redoublants</button>' : '') +
      '</div></div>' +
      '<div class="chart-box tall"><canvas id="semCanvas"></canvas></div></section>' +
      '<section><h2>Matières (UE) — cliquer sur une carte pour le détail</h2>' +
      '<div class="ue-grid">' + DATA.ues.map((ue, i) => ueCardHtml(ue, i)).join('') + '</div></section>' +
      ueStatsTableHtml() +
      redoublantsHtml() +
      absencesHtml() +
      borderlineHtml() +
      '<div class="footer">Analyse générée le ' + esc(m.generatedAt || '') +
      ' · données conservées 24 h · token ' + esc(TOKEN.slice(0, 8)) + '…</div>';

    drawSemester();

    main.querySelectorAll('canvas[data-mini]').forEach((cv) => {
      hist(cv, DATA.ues[Number(cv.dataset.mini)].hist, { mini: true });
    });

    main.querySelectorAll('.ue-card').forEach((card) => {
      card.addEventListener('click', () => renderUeDetail(Number(card.dataset.ue)));
    });

    const semAll = document.getElementById('semAll');
    const semAdmis = document.getElementById('semAdmis');
    const semRed = document.getElementById('semRed');
    semAll.addEventListener('click', () => { semMode = 'all'; semAll.classList.add('active'); semAdmis.classList.remove('active'); if (semRed) semRed.classList.remove('active'); drawSemester(); });
    semAdmis.addEventListener('click', () => { semMode = 'admis'; semAdmis.classList.add('active'); semAll.classList.remove('active'); if (semRed) semRed.classList.remove('active'); drawSemester(); });
    if (semRed) {
      semRed.addEventListener('click', () => { semMode = 'red'; semRed.classList.add('active'); semAll.classList.remove('active'); semAdmis.classList.remove('active'); drawSemester(); });
    }

    bindTableSort(document.getElementById('ueStatsTable'));
    document.querySelectorAll('#ueStatsTable tbody tr').forEach((tr) => {
      tr.addEventListener('click', () => renderUeDetail(Number(tr.dataset.i)));
    });
    bindTableSort(document.querySelector('.redou-table'));
    document.querySelectorAll('.redou-table tbody tr').forEach((tr) => {
      tr.addEventListener('click', () => renderUeDetail(Number(tr.dataset.i)));
    });
    bindStudentLinks();
  }

  function overviewKpisHtml() {
    const k = DATA.kpis;
    const R = DATA.redoublants || null;
    const rep = k.repartition || {};
    const pct = (n) => k.effectif ? (n / k.effectif * 100).toFixed(1).replace('.', ',') + ' %' : '—';
    const items = [
      ['Effectif', k.effectif, 'étudiants'],
    ];
    if (R) {
      items.push(['Redoublants', R.matched, R.requested !== R.matched
        ? 'sur ' + R.requested + ' identifiant(s) fourni(s)'
        : 'tous identifiés']);
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

  function drawSemester() {
    const k = DATA.kpis;
    const R = DATA.redoublants;
    let d, s;
    if (semMode === 'red' && R && R.matched > 0) {
      d = R.kpis.histSem;
      s = R.kpis.semNote;
    } else if (semMode === 'admis') {
      d = k.histSemAdmis;
      s = k.semNoteAdmis;
    } else {
      d = k.histSem;
      s = k.semNote;
    }
    hist(document.getElementById('semCanvas'), d, { lines: vlinesFor(s) });
  }

  function ueCardHtml(ue, i) {
    const s = ue.stats;
    const p = acqPct(ue);
    const acqSpan = p === null
      ? '<span class="acq-cell">ACQ <b>—</b></span>'
      : '<span class="acq-cell ' + acqCls(p) + '">ACQ <b>' + p + ' %</b></span>';
    const sigSpan = (s.std === null || s.std === undefined)
      ? '<span class="sigma-cell">σ <b>—</b></span>'
      : '<span class="sigma-cell ' + sigmaCls(s.std) + '">σ <b>' + fmt(s.std) + '</b></span>';
    return '<div class="ue-card" data-ue="' + i + '">' +
      '<div class="code">' + esc(ue.code) + '</div>' +
      '<div class="name">' + esc(ue.name || '—') + '</div>' +
      '<div class="ue-mini"><canvas data-mini="' + i + '"></canvas></div>' +
      '<div class="row"><span>Eff. <b>' + s.effectif + '</b></span><span>Admis <b>' + s.acquis + '</b></span></div>' +
      '<div class="row"><span>Moy. <b>' + fmt(s.mean) + '</b></span>' + acqSpan + '</div>' +
      '<div class="row">' +
      (ue.nbSessionPrecedente ? '<span>Sess. préc. <b>' + ue.nbSessionPrecedente + '</b></span>' : '<span></span>') +
      sigSpan + '</div>' +
      '</div>';
  }

  function acqTd(p) {
    return p === null
      ? '<td class="acq-cell">—</td>'
      : '<td class="acq-cell ' + acqCls(p) + '">' + p + ' %</td>';
  }
  function sigmaTd(v) {
    return '<td class="sigma-cell' + (v !== null && v !== undefined ? ' ' + sigmaCls(v) : '') + '">' + fmt(v) + '</td>';
  }

  function ueStatsTableHtml() {
    const rows = DATA.ues.map((ue, i) => {
      const s = ue.stats;
      const c = ue.statsSessionCourante;
      return '<tr data-i="' + i + '">' +
        '<td class="mono">' + esc(ue.code) + '</td>' +
        '<td>' + esc(ue.name || '—') + '</td>' +
        '<td>' + s.effectif + '</td><td>' + s.acquis + '</td>' +
        acqTd(acqPct(ue)) +
        '<td>' + fmt(s.mean) + '</td><td>' + fmt(s.median) + '</td>' +
        sigmaTd(s.std) +
        '<td>' + fmt(s.max, 3) + '</td><td>' + fmt(s.min, 3) + '</td>' +
        '<td>' + ue.nbSessionPrecedente + '</td>' +
        '<td class="cur">' + c.effectif + '</td><td class="cur">' + fmt(c.mean) + '</td>' +
        '</tr>';
    }).join('');
    return '<section>' +
      '<div class="section-head"><h2 style="margin:0">Statistiques par UE</h2>' +
      '<div class="actions">' +
      '<a class="btn small" href="api/export.php?token=' + TOKEN + '&type=csv:stats_ue">CSV — toutes notes</a>' +
      '<a class="btn small ghost" href="api/export.php?token=' + TOKEN + '&type=csv:stats_ue_cur">CSV — session courante</a>' +
      '</div></div>' +
      '<div class="table-wrap"><table id="ueStatsTable"><thead><tr>' +
      '<th>CODE</th><th>Matière</th>' +
      '<th data-numeric="1">Eff.</th><th data-numeric="1">Admis (≥10)</th>' +
      '<th data-numeric="1">ACQ %</th><th data-numeric="1">Moy.</th><th data-numeric="1">Méd.</th>' +
      '<th data-numeric="1">σ</th><th data-numeric="1">Max</th><th data-numeric="1">Min</th>' +
      '<th data-numeric="1" title="Acquis obtenus en session précédente">Sess. préc.</th>' +
      '<th data-numeric="1" title="Variante : session courante uniquement">Eff. (cur.)</th>' +
      '<th data-numeric="1" title="Variante : session courante uniquement">Moy. (cur.)</th>' +
      '</tr></thead><tbody>' + rows + '</tbody></table></div>' +
      '<p class="muted" id="thHint">ACQ &lt; ' + THRESH.acqLow + ' % en rouge, &gt; ' + THRESH.acqHigh + ' % en bleu · σ &gt; ' + THRESH.sigma + ' en rouge (modifiables via ⚙). Clic sur un en-tête : trier · clic sur une ligne : détail de la matière.</p></section>';
  }

  function bindTableSort(table) {
    if (!table) return;
    let state = { col: null, dir: 1 };
    table.querySelectorAll('thead th').forEach((th, idx) => {
      const col = idx + 1;
      const isNum = th.dataset.numeric === '1';
      th.addEventListener('click', () => {
        state = state.col === col ? { col: col, dir: -state.dir } : { col: col, dir: 1 };
        const tbody = table.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr'));
        rows.sort((a, b) => {
          const va = a.children[col - 1].textContent.trim();
          const vb = b.children[col - 1].textContent.trim();
          if (isNum) {
            const na = parseFloat(String(va).replace(',', '.'));
            const nb = parseFloat(String(vb).replace(',', '.'));
            if (!isNaN(na) && !isNaN(nb)) return (na - nb) * state.dir;
            if (!isNaN(na)) return -state.dir;
            if (!isNaN(nb)) return state.dir;
            return 0;
          }
          return String(va).localeCompare(String(vb), 'fr') * state.dir;
        });
        rows.forEach((r) => tbody.appendChild(r));
      });
    });
  }

  /* ---------- redoublants ---------- */

  function redoublantsHtml() {
    const R = DATA.redoublants;
    if (!R) return '';
    const src = DATA.meta.redoublantSource ? ' — liste : ' + esc(DATA.meta.redoublantSource) : '';
    if (R.matched === 0) {
      return '<section><h2>Redoublants</h2><p class="muted">' + R.requested +
        ' identifiant(s) fourni(s), mais aucun ne figure dans ce fichier ' +
        '(la liste contient probablement des redoublants d\'autres parcours, ou des identifiants mal lus).</p></section>';
    }
    const k = R.kpis;
    const rep = k.repartition || {};
    const pct = (n) => k.effectif ? (n / k.effectif * 100).toFixed(1).replace('.', ',') + ' %' : '—';
    const rows = R.ues.map((s, i) =>
      '<tr data-i="' + i + '">' +
      '<td class="mono">' + esc(s.code) + '</td>' +
      '<td>' + esc(s.name || '—') + '</td>' +
      '<td>' + s.effectif + '</td><td>' + s.acquis + '</td>' +
      '<td>' + fmt(s.mean) + '</td><td>' + fmt(s.median) + '</td>' +
      sigmaTd(s.std) +
      '<td>' + fmt(s.max, 3) + '</td><td>' + fmt(s.min, 3) + '</td>' +
      '</tr>'
    ).join('');
    return '<section>' +
      '<h2>Redoublants — ' + R.matched + ' étudiant(s) du fichier sur ' + R.requested + ' identifiant(s) fournis' + src + '</h2>' +
      '<div class="kpis-row">' + kpiCards([
        ['Effectif', k.effectif, 'redoublants présents'],
        ['ADM', rep.ADM || 0, pct(rep.ADM || 0)],
        ['AJ', rep.AJ || 0, pct(rep.AJ || 0)],
        ['DEF', rep.DEF || 0, pct(rep.DEF || 0)],
        ['Moyenne sem.', fmt(k.semNote.mean)],
        ['Médiane sem.', fmt(k.semNote.median)],
        ['Écart-type sem.', fmt(k.semNote.std)],
        ['Moyenne (admis)', fmt(k.semNoteAdmis.mean), 'n = ' + (k.semNoteAdmis.n || 0)]
      ]) + '</div>' +
      '<h3>Par matière</h3>' +
      '<div class="table-wrap"><table class="redou-table"><thead><tr>' +
      '<th>CODE</th><th>Matière</th>' +
      '<th data-numeric="1">Eff.</th><th data-numeric="1">Admis (≥10)</th>' +
      '<th data-numeric="1">Moy.</th><th data-numeric="1">Méd.</th>' +
      '<th data-numeric="1">σ</th><th data-numeric="1">Max</th><th data-numeric="1">Min</th>' +
      '</tr></thead><tbody>' + rows + '</tbody></table></div>' +
      '<p class="muted">Clic sur une ligne : détail de la matière (bascule « Redoublants »).</p></section>';
  }

  /* ---------- listes jury ---------- */

  function absencesHtml() {
    const list = DATA.absences || [];
    // Bleu + gras quand la valeur est 1 (mention isolée à examiner au jury)
    const cnt = (v) => '<td' + (Number(v) === 1 ? ' style="color:#dc2626;font-weight:700"' : '') + '>' + v + '</td>';
    const rows = list.map((r) =>
      '<tr class="clickable student-row" data-id="' + esc(r.id) + '" data-nom="' + esc(r.nom) + '" data-prenom="' + esc(r.prenom) + '">' +
      '<td class="mono">' + esc(r.id) + '</td><td>' + esc(r.nom) + '</td><td>' + esc(r.prenom) + '</td>' +
      cnt(r.DIS) + cnt(r.ABJ) + cnt(r.ABI) + '</tr>'
    ).join('');
    return '<section>' +
      '<div class="section-head"><h2 style="margin:0">Défaillants au semestre avec absences (' + list.length + ') — liste jury</h2>' +
      '<a class="btn small" href="api/export.php?token=' + TOKEN + '&type=csv:absences">CSV</a></div>' +
      (list.length
        ? '<div class="table-wrap"><table><thead><tr><th>N°Etu</th><th>Nom</th><th>Prénom</th><th>DIS</th><th>ABJ</th><th>ABI</th></tr></thead><tbody>' + rows + '</tbody></table></div'
        : '<p class="muted">Aucun étudiant.</p>') +
      '<p class="muted">Clic sur une ligne : fiche de l\'étudiant.</p></section>';
  }

  function borderlineHtml() {
    const list = DATA.borderline || [];
    const rows = list.map((r) =>
      '<tr class="clickable student-row" data-id="' + esc(r.id) + '" data-nom="' + esc(r.nom) + '" data-prenom="' + esc(r.prenom) + '">' +
      '<td class="mono">' + esc(r.id) + '</td><td>' + esc(r.nom) + '</td><td>' + esc(r.prenom) + '</td>' +
      '<td>' + fmt(r.note, 3) + '</td><td class="mono">' + esc(r.ueCode) + '</td><td>' + esc(r.ueName) + '</td></tr>'
    ).join('');
    return '<section>' +
      '<div class="section-head"><h2 style="margin:0">Notes de matières entre 9,95 et 10 (' + list.length + ')</h2>' +
      '<a class="btn small" href="api/export.php?token=' + TOKEN + '&type=csv:borderline">CSV</a></div>' +
      (list.length
        ? '<div class="table-wrap"><table><thead><tr><th>N°Etu</th><th>Nom</th><th>Prénom</th><th>Note</th><th>CODE UE</th><th>Matière</th></tr></thead><tbody>' + rows + '</tbody></table></div>'
        : '<p class="muted">Aucun étudiant.</p>') +
      '<p class="muted">Clic sur une ligne : fiche de l\'étudiant.</p></section>';
  }

  function bindStudentLinks() {
    document.querySelectorAll('.student-row').forEach((tr) => {
      tr.addEventListener('click', () => openStudent(tr.dataset.id, tr.dataset.nom, tr.dataset.prenom));
    });
  }

  /* ---------- détail UE ---------- */

  function examSource(ue, R) {
    if (ueMode === 'red' && R && R.ues && R.ues[currentUe] && R.ues[currentUe].effectif > 0) {
      return { exams: R.ues[currentUe].exams || [], label: 'redoublants uniquement' };
    }
    if (ueMode === 'cur') {
      return { exams: ue.examsSessionCourante || [], label: 'session courante uniquement' };
    }
    return { exams: ue.exams || [], label: 'toutes notes' };
  }

  function drawExams(ue) {
    const src = examSource(ue, DATA.redoublants);
    const cap = document.getElementById('examsCaption');
    if (cap) cap.textContent = ' — ' + src.label;
    const grid = document.getElementById('examsGrid');
    if (!grid) return;
    grid.innerHTML = src.exams.map((ex) =>
      '<div class="exam-card">' +
      '<div class="code">' + esc(ex.code || '—') + '</div>' +
      '<div class="name">' + esc(ex.name || 'Épreuve') + '</div>' +
      '<div class="row"><span>n = <b>' + ((ex.stats && ex.stats.n) || 0) + '</b></span><span>moy. <b>' + fmt(ex.stats ? ex.stats.mean : null) + '</b></span></div>' +
      '<div class="exam-mini"><canvas></canvas></div>' +
      (ex.rawCounts && Object.keys(ex.rawCounts).length
        ? '<div class="muted raws">' + Object.entries(ex.rawCounts).map(([k, v]) => esc(k) + ' × ' + v).join(' · ') + '</div>'
        : '') +
      '</div>'
    ).join('');
    grid.querySelectorAll('.exam-card canvas').forEach((cv, k) => {
      hist(cv, (src.exams[k] && src.exams[k].hist) || { labels: [], counts: [] },
        { lines: vlinesFor(src.exams[k] ? src.exams[k].stats : null), color: 'rgba(22,163,74,0.45)' });
    });
  }

  function renderUeDetail(i) {
    destroyAllCharts();
    currentUe = i;
    const ue = DATA.ues[i];
    const R = DATA.redoublants;
    const red = (R && R.ues && R.ues[i]) ? R.ues[i] : null;
    if (ueMode === 'red' && !(red && red.effectif > 0)) ueMode = 'all';

    const main = document.getElementById('main');
    main.innerHTML =
      '<a class="back" href="#" id="backOv">← Retour aux matières</a>' +
      '<section>' +
      '<h2><span class="mono">' + esc(ue.code) + '</span> — ' + esc(ue.name || 'Matière') + '</h2>' +
      '<div class="toggle"><button id="ueAll" class="' + (ueMode === 'all' ? 'active' : '') + '">Toutes notes</button>' +
      '<button id="ueCur" class="' + (ueMode === 'cur' ? 'active' : '') + '">Session courante uniquement</button>' +
      (red && red.effectif > 0
        ? '<button id="ueRed" class="' + (ueMode === 'red' ? 'active' : '') + '">Redoublants (' + red.effectif + ')</button>'
        : '') +
      '</div>' +
      '<div class="cards" id="ueKpis"></div>' +
      '<div class="chart-grid" style="margin-top:16px">' +
      '<div class="chart-box"><canvas id="ueCanvas"></canvas></div>' +
      '<div class="chart-box">' +
      '<canvas id="ueResCanvas"></canvas>' +
      '<div id="ueResNote" class="muted hidden">Répartition par mention non calculée pour le sous-groupe redoublants.</div>' +
      '</div>' +
      '</div>' +
      '<h3>Épreuves <span class="muted" id="examsCaption"></span></h3>' +
      '<div class="exams-grid" id="examsGrid"></div>' +
      '</section>';

    document.getElementById('backOv').addEventListener('click', (e) => { e.preventDefault(); renderOverview(); });
    document.getElementById('ueAll').addEventListener('click', () => { ueMode = 'all'; updateUeParts(); });
    document.getElementById('ueCur').addEventListener('click', () => { ueMode = 'cur'; updateUeParts(); });
    const redBtn = document.getElementById('ueRed');
    if (redBtn) redBtn.addEventListener('click', () => { ueMode = 'red'; updateUeParts(); });

    updateUeParts();
  }

  function updateUeParts() {
    const ue = DATA.ues[currentUe];
    const R = DATA.redoublants;
    const red = (R && R.ues && R.ues[currentUe]) ? R.ues[currentUe] : null;
    const isRed = ueMode === 'red' && red && red.effectif > 0;

    let s, d, sub;
    if (isRed) {
      s = red;
      d = red.hist;
      sub = 'redoublants uniquement (n = ' + s.effectif + ')';
    } else if (ueMode === 'cur') {
      s = ue.statsSessionCourante;
      d = ue.histSessionCourante;
      sub = 'session courante uniquement';
    } else {
      s = ue.stats;
      d = ue.hist;
      sub = 'toutes notes';
    }

    const stdCls = (s.std !== null && s.std !== undefined)
      ? 'sigma-cell ' + sigmaCls(s.std) : 'sigma-cell';
    document.getElementById('ueKpis').innerHTML = kpiCards([
      ['Effectif', s.effectif, sub],
      ['Admis (note ≥ 10)', s.acquis, s.effectif ? (s.acquis / s.effectif * 100).toFixed(1).replace('.', ',') + ' %' : ''],
      ['Défaillants (DEF)', s.def],
      ['Moyenne /20', fmt(s.mean)],
      ['Médiane', fmt(s.median)],
      ['Écart-type', fmt(s.std), '', stdCls],
      ['Note la plus haute', fmt(s.max, 3)],
      ['Note la plus basse', fmt(s.min, 3)]
    ]);
    document.getElementById('ueAll').classList.toggle('active', ueMode === 'all');
    document.getElementById('ueCur').classList.toggle('active', ueMode === 'cur');
    const redBtn = document.getElementById('ueRed');
    if (redBtn) redBtn.classList.toggle('active', isRed);

    hist(document.getElementById('ueCanvas'), d, { lines: vlinesFor(s) });

    const cv = document.getElementById('ueResCanvas');
    const note = document.getElementById('ueResNote');
    if (cv.__chart) {
      const idx = charts.indexOf(cv.__chart);
      if (idx !== -1) charts.splice(idx, 1);
      cv.__chart.destroy();
    }
    if (isRed) {
      cv.classList.add('hidden');
      note.classList.remove('hidden');
    } else {
      cv.classList.remove('hidden');
      note.classList.add('hidden');
      const rp = ue.repartition || {};
      const order = ['ACQ', 'NACQ', 'DEF', 'ABJ', 'ABI', 'DIS'];
      const keys = order.filter((k) => rp[k] > 0);
      const colors = { ACQ: '#16a34a', NACQ: '#d97706', DEF: '#dc2626', ABJ: '#2563eb', ABI: '#7c3aed', DIS: '#991b1b' };
      cv.__chart = new Chart(cv, {
        type: 'bar',
        data: {
          labels: keys,
          datasets: [{ data: keys.map((k) => rp[k]), backgroundColor: keys.map((k) => colors[k] || '#64748b'), borderWidth: 0, barPercentage: 0.8 }]
        },
        options: {
          indexAxis: 'y',
          responsive: true,
          maintainAspectRatio: false,
          scales: { x: { beginAtZero: true, ticks: { precision: 0 } } },
          plugins: {
            legend: { display: false },
            title: { display: true, text: 'Résultats par mention', font: { size: 13 } },
            tooltip: { callbacks: { label: (item) => item.raw + ' étudiant(s)' } }
          }
        }
      });
      charts.push(cv.__chart);
    }

    drawExams(ue);
  }

  /* ---------- fiche étudiant (usage jury) ---------- */

  function problemUeCodes(studentId) {
    const codes = new Set();
    for (const a of (DATA.anomalies || [])) {
      if (a.id === studentId && a.ueCode) codes.add(a.ueCode);
    }
    return codes;
  }

  function openStudent(id, nom, prenom) {
    const students = DATA.students || [];
    const s = students.find((x) => x.id === id) ||
      students.find((x) => x.nom === nom && x.prenom === prenom);
    if (!s) { alert('Étudiant introuvable dans les données de l\'analyse.'); return; }

    const sem = s.sem || {};
    const info = Object.keys(s.info || {}).map((k) =>
      '<span>' + esc(k) + ' : <b>' + esc(s.info[k] || '—') + '</b></span>').join('');

    const ueRows = DATA.ues.map((ue, i) => {
      const u = (s.ues || [])[i];
      if (!u) return '';
      const bad = problemUeCodes(s.id).has(ue.code);
      const prev = u.sessionPrecedente ? ' <span class="badge b-PREV">session préc.</span>' : '';
      const note = u.note !== null && u.note !== undefined ? fmt(u.note, 3) : badge(u.noteRaw);
      const name = (ue.name && ue.name.trim() !== '' && ue.name !== ue.code) ? ue.name : ue.code;
      const codeLine = (name !== ue.code) ? '<br><span class="muted mono">' + esc(ue.code) + '</span>' : '';
      const exams = (u.exams || []).map((ex) => {
        const v = examValue(ex);
        return '<span title="' + esc(ex.code) + '">' + esc(ex.name || ex.code || 'Épreuve') + ' : ' + v + '</span>';
      }).join('<br>');
      return '<tr' + (bad ? ' class="ue-problem"' : '') + '>' +
        '<td>' + esc(name) + prev + codeLine + '</td>' +
        '<td>' + note + '</td>' +
        '<td>' + badge(u.resultat) + '</td>' +
        '<td>' + (u.credits !== null && u.credits !== undefined ? fmt(u.credits) : '—') + '</td>' +
        '<td>' + (u.anSession ? esc(u.anSession) : '—') + '</td>' +
        '<td class="exams-cell">' + (exams || '<span class="muted">—</span>') + '</td>' +
        '</tr>';
    }).join('');

    document.getElementById('modalContent').innerHTML =
      '<h2>' + esc(s.prenom) + ' ' + esc(s.nom) + ' ' + badge(sem.resultat) +
      (s.isRedoublant ? ' <span class="badge b-RED">Redoublant</span>' : '') + '</h2>' +
      '<div class="student-info">' +
      '<span>N°Etu : <b class="mono">' + esc(s.id) + '</b></span>' +
      info +
      '<span>Note sem. : <b>' + fmt(sem.note, 3) + '</b></span>' +
      (sem.ptjury !== null && sem.ptjury !== undefined ? '<span>PtJury/20 : <b>' + fmt(sem.ptjury) + '</b></span>' : '') +
      (sem.credits !== null && sem.credits !== undefined ? '<span>Crédits : <b>' + fmt(sem.credits) + '</b></span>' : '') +
      '</div>' +
      '<table class="student-table"><thead><tr><th>Matière</th><th>Note</th><th>Résultat</th><th>Crédits</th><th>An/Session</th><th>Épreuves</th></tr></thead><tbody>' + ueRows + '</tbody></table>' +
      '<p class="muted">Les éléments marqués « session préc. » ont été obtenus à une session antérieure ' +
      '(colonne An/Session remplie ou note/résultat souligné) — cf. la légende du fichier.</p>';
    document.getElementById('modal').classList.remove('hidden');
  }

  /* ---------- recherche étudiant (top bar) ---------- */

  function initSearch() {
    const input = document.getElementById('studentSearch');
    const box = document.getElementById('searchResults');
    if (!input || !box) return;

    const norm = (s) => String(s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');

    function close() {
      box.classList.add('hidden');
      box.innerHTML = '';
    }

    function render() {
      if (!DATA) return;
      const q = norm(input.value.trim());
      if (!q) { close(); return; }
      const students = DATA.students || [];
      const matches = [];
      for (const s of students) {
        if (norm(s.nom).includes(q) || norm(s.prenom).includes(q) || norm(s.id).includes(q)) {
          matches.push(s);
          if (matches.length >= 20) break;
        }
      }
      if (!matches.length) {
        box.innerHTML = '<div class="empty">Aucun étudiant trouvé.</div>';
      } else {
        box.innerHTML = matches.map((s, i) =>
          '<div class="item" data-i="' + i + '"><span>' + esc(s.prenom) + ' ' + esc(s.nom) + '</span><span class="id">' + esc(s.id) + '</span></div>'
        ).join('');
        box.querySelectorAll('.item').forEach((el) => {
          el.addEventListener('click', () => {
            const s = matches[Number(el.dataset.i)];
            openStudent(s.id, s.nom, s.prenom);
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
      if (e.key === 'Enter') {
        const first = box.querySelector('.item');
        if (first) first.click();
      }
    });
    document.addEventListener('click', (e) => {
      if (!e.target.closest('.search')) close();
    });
  }

  /* ---------- seuils de coloration ---------- */

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
      recolor();
      modal.classList.add('hidden');
    });

    document.getElementById('thReset').addEventListener('click', () => {
      THRESH = { acqLow: 40, acqHigh: 80, sigma: 3 };
      fill();
    });
  }

  /* ---------- démarrage ---------- */

  function init() {
    if (!window.Chart) {
      document.getElementById('main').innerHTML =
        '<div class="warn" style="border-radius:10px;margin:0">⚠ <b>Chart.js introuvable</b> : ' +
        'placez le fichier <b>chart.umd.js</b> dans <b>public/assets/libs/</b> puis rechargez.</div>';
      return;
    }
    Chart.register(vlines);

    fetch('api/analyse.php?token=' + encodeURIComponent(TOKEN) + (GROUP !== null ? '&g=' + GROUP : ''))
      .then((r) => r.json())
      .then((j) => {
        if (!j.ok) throw new Error(j.error || 'Erreur inattendue du serveur.');
        DATA = j.data;
        MULTI = j.multi || null;
        renderOverview();
        initSearch();
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
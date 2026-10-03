<?php

declare(strict_types=1);

$token = (string) ($_GET['token'] ?? '');
$storage = dirname(__DIR__) . '/storage';
$path = preg_match('/^[a-f0-9]{32}$/', $token) ? $storage . '/' . $token . '.json' : null;

if ($path === null || !is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Vue globale</title></head>'
        . '<body style="font-family:system-ui"><h1>Analyse introuvable ou expirée (24 h)</h1>'
        . '<p>Token : <code>' . htmlspecialchars($token) . '</code></p>'
        . '<p><a href="index.php">← Nouvelle analyse</a></p></body></html>';
    exit;
}

$analysis = json_decode((string) file_get_contents($path), true);
if (!is_array($analysis) || !isset($analysis['groups']) || !is_array($analysis['groups'])) {
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Vue globale</title></head>'
        . '<body style="font-family:system-ui"><h1>Cette analyse n\'est pas multi-parcours.</h1>'
        . '<p><a href="analyse.php?token=' . htmlspecialchars($token) . '">Ouvrir l\'analyse unitaire</a> · '
        . '<a href="index.php">← Nouvelle analyse</a></p></body></html>';
    exit;
}
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Vue globale — multi-parcours</title>
<link rel="stylesheet" href="assets/css/app.css?v=<?= sha1_file(__DIR__ . '/assets/css/app.css') ?>">
</head>
<body>
<header class="topbar">
  <div class="topbar-left">
    <span class="logo">📊</span>
    <div>
      <div id="fileTitle" class="topbar-title">Vue globale</div>
      <div id="fileMeta" class="topbar-meta"></div>
    </div>
  </div>
  <div class="topbar-right">
    <div class="search">
      <input type="search" id="studentSearch" placeholder="Rechercher un étudiant (nom, prénom, n°)…" autocomplete="off">
      <div id="searchResults" class="search-results hidden"></div>
    </div>
    <button class="btn ghost" id="thresholdBtn" title="Seuils de coloration (ACQ %, σ)">⚙</button>
    <a id="exportAll" class="btn" href="#">⬇ Tout (ZIP)</a>
    <a class="btn ghost" href="index.php">Nouvelle analyse</a>
  </div>
</header>
<div id="warnings"></div>
<main id="main" class="container">
  <div class="loading">Chargement de l'analyse…</div>
</main>
<div id="modal" class="modal hidden">
  <div class="modal-box">
    <button id="modalClose" class="modal-close" title="Fermer">×</button>
    <div id="modalContent"></div>
  </div>
</div>
<div id="thresholdModal" class="modal hidden">
  <div class="modal-box small">
    <button class="modal-close" id="thresholdClose" title="Fermer">×</button>
    <h2>Réglages</h2>
    <div class="th-fields">
      <label>ACQ en <b class="bad">rouge</b> si &lt; <input type="number" id="thAcqLow" step="1" min="0" max="100"> %</label>
      <label>ACQ en <b class="good">bleu</b> si &gt; <input type="number" id="thAcqHigh" step="1" min="0" max="100"> %</label>
      <label>Écart-type (σ) en <b class="bad">rouge</b> si &gt; <input type="number" id="thSigma" step="0.1" min="0"></label>
    </div>
    <h3 style="margin-top:16px">Couleurs par parcours</h3>
    <div class="th-colors" id="thColors"></div>
    <div class="actions" style="margin-top:16px">
      <button class="btn" id="thApply">Appliquer</button>
      <button class="btn ghost" id="thReset">Réinitialiser</button>
    </div>
  </div>
</div>
<script src="assets/libs/chart.umd.js"></script>
<script>window.APO_TOKEN = <?= json_encode($token) ?>;</script>
<script src="assets/js/global.js?v=<?= sha1_file(__DIR__ . '/assets/js/global.js') ?>"></script>
</body>
</html>
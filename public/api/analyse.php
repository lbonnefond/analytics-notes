<?php

declare(strict_types=1);

$token = (string) ($_GET['token'] ?? '');
$g = isset($_GET['g']) && $_GET['g'] !== '' ? (int) $_GET['g'] : null;
$all = isset($_GET['all']) && $_GET['all'] === '1';
$storage = dirname(__DIR__, 2) . '/storage'; // 2 niveaux : public/api → racine
$path = preg_match('/^[a-f0-9]{32}$/', $token) ? $storage . '/' . $token . '.json' : null;

if ($path === null || !is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Analyse</title></head>'
        . '<body style="font-family:system-ui"><h1>Analyse introuvable ou expirée (24 h)</h1>'
        . '<p>Token : <code>' . htmlspecialchars($token) . '</code></p>'
        . '<p><a href="index.php">← Nouvelle analyse</a></p></body></html>';
    exit;
}

$analysis = json_decode((string) file_get_contents($path), true);
if (!is_array($analysis)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => "Fichier d'analyse corrompu."], JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($analysis['groups']) && is_array($analysis['groups'])) {
    // JSON multi-parcours : le front global demande l'intégralité (all=1),
    // le front unitaire un groupe précis (g=i)
    if ($all) {
        echo json_encode(['ok' => true, 'data' => $analysis], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($g === null || $g < 0 || !isset($analysis['groups'][$g])) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Groupe de parcours introuvable (paramètre g manquant ou invalide).'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $group = $analysis['groups'][$g];
    echo json_encode([
        'ok' => true,
        'data' => $group['analysis'],
        'multi' => ['groupIndex' => $g, 'groupLabel' => $group['label']],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode(['ok' => true, 'data' => $analysis], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
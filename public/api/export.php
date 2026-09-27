<?php

declare(strict_types=1);

$token = (string) ($_GET['token'] ?? '');
$type = (string) ($_GET['type'] ?? '');
$g = isset($_GET['g']) && $_GET['g'] !== '' ? (int) $_GET['g'] : null;

$storage = dirname(__DIR__, 2) . '/storage';
$path = preg_match('/^[a-f0-9]{32}$/', $token) ? $storage . '/' . $token . '.json' : null;

if ($path === null || !is_file($path)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Analyse introuvable ou expirée.']);
    exit;
}
$a = json_decode((string) file_get_contents($path), true);
if (!is_array($a)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => "Fichier d'analyse corrompu."]);
    exit;
}

$isMulti = isset($a['groups']) && is_array($a['groups']);
$base = preg_replace('/\.xlsx$/i', '', (string) (($a['meta']['fileName'] ?? ($isMulti ? 'multi' : 'analyse'))));
$base = preg_replace('/[^\w\-]+/', '_', $base);

/* ============================ EXPORT ZIP ============================ */

if ($type === 'zip') {
    $zip = new ZipArchive();
    $tmp = tempnam(sys_get_temp_dir(), 'apozip_');
    if ($tmp === false) {
        http_response_code(500);
        echo 'Impossible de créer un fichier temporaire pour le ZIP.';
        exit;
    }
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        http_response_code(500);
        echo 'Création du ZIP impossible (errno ' . $zip->errno . ').';
        exit;
    }

    if ($isMulti) {
        foreach ($a['groups'] as $i => $group) {
            $prefix = sanitizeLabel($group['label']) . '/';
            foreach (buildExports($group['analysis']) as [$name, $headers, $rows]) {
                $zip->addFromString($prefix . $name, csvContent($headers, $rows));
            }
        }
        foreach (buildGlobalExports($a) as [$name, $headers, $rows]) {
            $zip->addFromString('global/' . $name, csvContent($headers, $rows));
        }
        $fileName = 'analyses_' . $base . '.zip';
    } else {
        foreach (buildExports($a) as [$name, $headers, $rows]) {
            $zip->addFromString($name, csvContent($headers, $rows));
        }
        $fileName = $base . '_exports.zip';
    }

    $zip->close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    readfile($tmp);
    @unlink($tmp);
    exit;
}

/* ========================= EXPORTS CSV UNITAIRES ========================= */

if (preg_match('/^csv:(stats_ue|stats_ue_cur|absences|borderline|sem_borderline|etudiants)$/', $type, $mm)) {
    if ($isMulti) {
        if ($g === null || !isset($a['groups'][$g])) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Export CSV multi-parcours : paramètre g (index du parcours) manquant.']);
            exit;
        }
        $analysis = $a['groups'][$g]['analysis'];
        $prefix = sanitizeLabel($a['groups'][$g]['label']) . '_';
    } else {
        $analysis = $a;
        $prefix = '';
    }
    [$name, $headers, $rows] = buildExports($analysis)[$mm[1]];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $prefix . $name . '"');
    echo csvContent($headers, $rows);
    exit;
}

/* ====================== EXPORTS CSV GLOBAUX (multi) ====================== */

if (preg_match('/^csvg:(absences|sem_borderline|borderline|parcours|stats_ue)$/', $type, $mm)) {
    if (!$isMulti) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Exports globaux : analyse multi-parcours requise.']);
        exit;
    }
    [$name, $headers, $rows] = buildGlobalExports($a)[$mm[1]];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    echo csvContent($headers, $rows);
    exit;
}

http_response_code(400);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => false, 'error' => "Type d'export inconnu (zip, csv:<nom> en unitaire, csvg:<nom> en global)."]);

/* ========================= GÉNÉRATION UNITAIRE ========================= */

/**
 * @return array<string, array{0: string, 1: list<string>, 2: list<list<mixed>>}>
 */
function buildExports(array $a): array
{
    $rows = [];
    foreach ($a['ues'] as $ue) {
        $s = $ue['stats'];
        $rows[] = [
            $ue['code'],
            $ue['name'],
            (int) $s['effectif'],
            (int) $s['acquis'],
            csvNum($s['mean'] ?? null),
            csvNum($s['median'] ?? null),
            csvNum($s['std'] ?? null),
            csvNum($s['max'] ?? null, 3),
            csvNum($s['min'] ?? null, 3)
        ];
    }
    $statsUe = [
        'stats_par_UE.csv',
        ['CODE', 'Matière', 'Effectif', 'Admis (note ≥ 10)', 'Moyenne /20', 'Médiane', 'Ecart-type', 'Note la plus haute', 'Note la plus basse'],
        $rows
    ];

    $rows = [];
    foreach ($a['ues'] as $ue) {
        $s = $ue['statsSessionCourante'];
        $rows[] = [
            $ue['code'],
            $ue['name'],
            (int) $s['effectif'],
            (int) $s['acquis'],
            csvNum($s['mean'] ?? null),
            csvNum($s['median'] ?? null),
            csvNum($s['std'] ?? null),
            csvNum($s['max'] ?? null, 3),
            csvNum($s['min'] ?? null, 3),
            (int) $ue['nbSessionPrecedente']
        ];
    }
    $statsUeCur = [
        'stats_par_UE_session_courante.csv',
        ['CODE', 'Matière', 'Effectif', 'Admis (note ≥ 10)', 'Moyenne /20', 'Médiane', 'Ecart-type', 'Note max', 'Note min', 'Acquis en session précédente'],
        $rows
    ];

    $rows = [];
    foreach ($a['absences'] as $r) {
        $rows[] = [$r['id'], $r['nom'], $r['prenom'], (int) $r['DIS'], (int) $r['ABJ'], (int) $r['ABI']];
    }
    $absences = ['absences.csv', ['N°Etu', 'NOM', 'Prénom', 'DIS', 'ABJ', 'ABI'], $rows];

    $rows = [];
    foreach ($a['borderline'] as $r) {
        $rows[] = [$r['id'], $r['nom'], $r['prenom'], csvNum($r['note'], 3), $r['ueCode'], $r['ueName']];
    }
    $borderline = ['matieres_a_corriger_9_95_10.csv', ['N°Etu', 'NOM', 'Prénom', 'Note', 'CODE UE', 'Matière'], $rows];

    $rows = [];
    foreach ($a['semBorderline'] ?? [] as $r) {
        $rows[] = [$r['id'], $r['nom'], $r['prenom'], csvNum($r['note'], 3), $r['resultat']];
    }
    $semBorderline = ['notes_sem_9_95_10.csv', ['N°Etu', 'NOM', 'Prénom', 'Note sem.', 'Résultat'], $rows];

    $headers = ['N°Etu', 'NOM', 'Prénom', 'Note sem.', 'Résultat sem.', 'PtJury/20', 'Crédits', 'Rang'];
    foreach ($a['ues'] as $ue) {
        $headers[] = $ue['code'] . ' note';
        $headers[] = $ue['code'] . ' resultat';
        $headers[] = $ue['code'] . ' An/Session';
    }
    $rows = [];
    foreach ($a['students'] as $s) {
        $row = [
            $s['id'],
            $s['nom'],
            $s['prenom'],
            csvNum($s['sem']['note'] ?? null, 3),
            $s['sem']['resultat'],
            csvNum($s['sem']['ptjury'] ?? null),
            csvNum($s['sem']['credits'] ?? null),
            csvNum($s['sem']['rang'] ?? null)
        ];
        foreach ($a['ues'] as $i => $ue) {
            $u = $s['ues'][$i] ?? null;
            if ($u === null) {
                $row[] = '';
                $row[] = '';
                $row[] = '';
                continue;
            }
            $row[] = (string) $u['noteRaw'];
            $row[] = $u['resultat'];
            $row[] = $u['anSession'] !== '' ? $u['anSession'] : ($u['sessionPrecedente'] ? 'session précédente' : '');
        }
        $rows[] = $row;
    }
    $etudiants = ['etudiants_detail.csv', $headers, $rows];

    return [
        'stats_ue' => $statsUe,
        'stats_ue_cur' => $statsUeCur,
        'absences' => $absences,
        'borderline' => $borderline,
        'sem_borderline' => $semBorderline,
        'etudiants' => $etudiants,
    ];
}

/* ==================== GÉNÉRATION GLOBALE (multi) ==================== */

/**
 * @return array<string, array{0: string, 1: list<string>, 2: list<list<mixed>>}>
 */
function buildGlobalExports(array $a): array
{
    $global = $a['global'] ?? [];
    $out = [];

    $rows = [];
    foreach ($global['absences'] ?? [] as $r) {
        $rows[] = [$r['label'], $r['id'], $r['nom'], $r['prenom'], (int) $r['DIS'], (int) $r['ABJ'], (int) $r['ABI']];
    }
    $out['absences'] = ['absences_global.csv', ['Parcours', 'N°Etu', 'NOM', 'Prénom', 'DIS', 'ABJ', 'ABI'], $rows];

    $rows = [];
    foreach ($global['semBorderline'] ?? [] as $r) {
        $rows[] = [$r['label'], $r['id'], $r['nom'], $r['prenom'], csvNum($r['note'], 3), $r['resultat']];
    }
    $out['sem_borderline'] = ['notes_sem_9_95_10_global.csv', ['Parcours', 'N°Etu', 'NOM', 'Prénom', 'Note sem.', 'Résultat'], $rows];

    $rows = [];
    foreach ($global['borderline'] ?? [] as $r) {
        $rows[] = [$r['label'], $r['id'], $r['nom'], $r['prenom'], csvNum($r['note'], 3), $r['ueCode'], $r['ueName']];
    }
    $out['borderline'] = ['matieres_a_corriger_global.csv', ['Parcours', 'N°Etu', 'NOM', 'Prénom', 'Note', 'CODE UE', 'Matière'], $rows];

    $rows = [];
    foreach ($global['byParcours'] ?? [] as $p) {
        $rows[] = [
            $p['label'],
            (int) $p['effectif'],
            (int) $p['ADM'],
            (int) $p['AJ'],
            (int) $p['DEF'],
            csvNum($p['tauxAdm'], 1),
            csvNum($p['mean']),
            csvNum($p['median']),
            csvNum($p['std'])
        ];
    }
    $out['parcours'] = [
        'stats_par_parcours.csv',
        ['Parcours', 'Effectif', 'ADM', 'AJ', 'DEF', 'Taux ADM %', 'Moyenne', 'Médiane', 'Ecart-type'],
        $rows
    ];

    $rows = [];
    foreach ($global['sharedSubjects'] ?? [] as $s) {
        foreach ($s['groups'] as $gr) {
            $rows[] = [
                $gr['label'],
                $s['code'],
                $s['name'],
                (int) $gr['effectif'],
                (int) $gr['admis'],
                $gr['acqPct'] === null ? '' : $gr['acqPct'] . ' %',
                csvNum($gr['mean']),
                csvNum($gr['median']),
                csvNum($gr['std'])
            ];
        }
    }
    $out['stats_ue'] = [
        'stats_par_UE_partagees.csv',
        ['Parcours', 'CODE', 'Matière', 'Effectif', 'Admis', 'ACQ %', 'Moyenne', 'Médiane', 'Ecart-type'],
        $rows
    ];

    return $out;
}

/* ============================== HELPERS ============================== */

function sanitizeLabel(string $label): string
{
    $label = preg_replace('/[^\w\-]+/', '_', trim($label)) ?? 'groupe';

    return substr($label, 0, 60);
}

function csvNum(?float $v, int $d = 2): string
{
    return $v === null ? '' : number_format($v, $d, ',', '');
}

function csvContent(array $headers, array $rows): string
{
    $buf = fopen('php://temp', 'r+');
    fwrite($buf, "\xEF\xBB\xBF"); // BOM UTF-8 : compatibilité Excel
    fwrite($buf, csvLine($headers));
    foreach ($rows as $row) {
        fwrite($buf, csvLine($row));
    }
    rewind($buf);
    $out = (string) stream_get_contents($buf);
    fclose($buf);

    return $out;
}

function csvLine(array $cells): string
{
    $out = [];
    foreach ($cells as $c) {
        if ($c === null) {
            $c = '';
        }
        $c = (string) $c;
        if (preg_match('/[",\r\n;]/', $c)) {
            $c = '"' . str_replace('"', '""', $c) . '"';
        }
        $out[] = $c;
    }

    return implode(';', $out) . "\n"; // point-virgule : Excel FR
}

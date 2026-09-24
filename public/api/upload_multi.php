<?php

declare(strict_types=1);

use AnalyticsNotes\ApofileParser;
use AnalyticsNotes\GlobalAggregator;
use AnalyticsNotes\RedoublantsLoader;

require __DIR__ . '/../../vendor/autoload.php';

header('Content-Type: application/json; charset=utf-8');

function fail(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Méthode non autorisée.', 405);
}

$files = $_FILES['apofiles'] ?? null;
if (!is_array($files) || empty($files['name'])) {
    fail('Aucun fichier reçu (champ « apofiles »).');
}

$names = $files['name'];
if (count($names) < 2) {
    fail('Le mode multi-parcours nécessite au moins 2 fichiers.');
}

$storage = dirname(__DIR__, 2) . '/storage';
if (!is_dir($storage) && !mkdir($storage, 0775, true)) {
    fail('Impossible de créer le dossier storage/.');
}

$token = bin2hex(random_bytes(16));
$groupFiles = [];

try {
    foreach ($names as $i => $name) {
        $orig = basename((string) $name);
        if (strtolower(pathinfo($orig, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new \RuntimeException("Fichier « $orig » : seul .xlsx est accepté.");
        }
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Fichier « ' . $orig . ' » : erreur de téléversement (code ' . ($files['error'][$i] ?? '?') . ').');
        }
        $tmp = $storage . '/' . $token . '_' . $i . '.xlsx';
        if (!move_uploaded_file($files['tmp_name'][$i], $tmp)) {
            throw new \RuntimeException('Fichier « ' . $orig . ' » : déplacement du fichier temporaire impossible.');
        }
        $groupFiles[$tmp] = $orig;
    }

    [$redouIds, $redouSource] = loadRedoublants($storage);

    $groups = [];
    $keys = [];
    $warnings = [];
    foreach ($groupFiles as $tmp => $orig) {
        $analysis = ApofileParser::fromFile($tmp, $orig)->analyze($redouIds);
        @unlink($tmp);

        $fn = $analysis['meta']['fileNameParsed'] ?? null;
        $obj = $analysis['meta']['objetParsed'] ?? null;
        $label = $fn['parcours'] ?? '';
        if ($label === '') {
            $label = preg_replace('/\.xlsx$/i', '', $orig);
        }
        // (niveau · semestre) n'est plus répété par parcours : il passe dans le titre global
        $baseKey = ($obj['annee'] ?? '?') . '/' . ($fn['semestre'] ?? '?');
        $n = $keys[$baseKey] ?? 0;
        $key = $n > 0 ? $baseKey . ' #' . ($n + 1) : $baseKey;
        $keys[$baseKey] = $n + 1;

        foreach ($analysis['warnings'] as $w) {
            $warnings[] = $label . ' : ' . $w;
        }
        $groups[] = [
            'key' => $key,
            'label' => $label,
            'fileName' => $orig,
            'meta' => $analysis['meta'],
            'analysis' => $analysis,
        ];
    }

    // Cohorte commune (niveau + semestre) : affichée une seule fois en titre ;
    // les écarts sont signalés.
    $multiNiveau = null;
    $multiSemestre = null;
    $cohortKeys = [];
    foreach ($groups as $g) {
        $fn2 = $g['meta']['fileNameParsed'] ?? null;
        $cohortKeys[] = ($fn2['niveau'] ?? '') . '|' . ($fn2['semestre'] ?? '');
    }
    $uniqueCohorts = array_unique($cohortKeys);
    $cohort = null;
    if (count($uniqueCohorts) === 1) {
        $cohort = $uniqueCohorts[0];
    } else {
        $counts = array_count_values($cohortKeys);
        arsort($counts);
        $cohort = array_key_first($counts);
    }
    if ($cohort !== null) {
        [$cn, $cs] = explode('|', $cohort);
        if ($cn !== '' || $cs !== '') {
            $multiNiveau = $cn;
            $multiSemestre = $cs;
        }
        if (count($uniqueCohorts) > 1) {
            foreach ($groups as $g) {
                $fn3 = $g['meta']['fileNameParsed'] ?? null;
                $k3 = ($fn3['niveau'] ?? '') . '|' . ($fn3['semestre'] ?? '');
                if ($k3 !== $cohort) {
                    $warnCohort = trim(($fn3['niveau'] ?? '?') . ' ' . ($fn3['semestre'] ?? '?'));
                    $warnings[] = '« ' . $g['label'] . ' » est en ' . $warnCohort . ', pas en ' . trim($cn . ' ' . $cs) . " : ce fichier est inclus mais ses matières ne seront pas comparées avec les autres parcours.";
                }
            }
        }
    }    

    $multi = [
        'meta' => [
            'multi' => true,
            'filesCount' => count($groups),
            'groupKeys' => array_values($keys),
            'redoublantSource' => $redouSource,
            'warnings' => $warnings,
            'niveau' => $multiNiveau,
            'semestre' => $multiSemestre,
            'generatedAt' => date('c'),
        ],
        'groups' => $groups,
        'global' => GlobalAggregator::build($groups, GlobalAggregator::MATCH_CODE4),
    ];

    $json = json_encode($multi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        fail('Encodage de l\'analyse impossible : ' . json_last_error_msg());
    }
    if (file_put_contents($storage . '/' . $token . '.json', $json) === false) {
        $last = error_get_last();
        fail('Écriture de l\'analyse dans storage/ impossible. ' . ($last ? 'Dernière erreur PHP : ' . $last['message'] : 'Vérifiez les droits d\'écriture.'));
    }
} catch (Throwable $e) {
    foreach (array_keys($groupFiles) as $f) {
        @unlink($f);
    }
    fail('Lecture des fichiers impossible : ' . $e->getMessage() . ' [' . basename($e->getFile()) . ':' . $e->getLine() . ']');
}

cleanStorage($storage, 24 * 3600);

echo json_encode([
    'ok' => true,
    'token' => $token,
    'meta' => $multi['meta'],
    'groups' => array_map(
        static fn (array $g): array => ['label' => $g['label'], 'fileName' => $g['fileName']],
        $multi['groups']
    ),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

function loadRedoublants(string $storage): array
{
    $f = $_FILES['redoufile'] ?? null;
    if ($f !== null && ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $name = basename((string) $f['name']);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
            throw new \RuntimeException('Fichier redoublants : format non supporté (.xlsx, .xls ou .csv).');
        }
        $tmpR = $storage . '/redou_' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $tmpR)) {
            throw new \RuntimeException('Fichier redoublants : déplacement du fichier temporaire impossible.');
        }
        try {
            $ids = RedoublantsLoader::load($tmpR, $name);
        } finally {
            @unlink($tmpR);
        }
        if ($ids === []) {
            throw new \RuntimeException('Fichier redoublants : aucun identifiant trouvé.');
        }

        return [$ids, $name];
    }

    $manual = (string) ($_POST['redoublants'] ?? '');
    if (trim($manual) !== '') {
        $ids = array_values(array_unique(array_filter(
            array_map('trim', preg_split('/[\s,;]+/', $manual)),
            static fn (string $s): bool => $s !== ''
        )));

        return [$ids, 'saisie manuelle'];
    }

    return [[], null];
}

function cleanStorage(string $dir, int $maxAgeSec): void
{
    foreach (glob($dir . '/*.json') ?: [] as $f) {
        if (is_file($f) && (time() - filemtime($f)) > $maxAgeSec) {
            @unlink($f);
        }
    }
}
<?php

declare(strict_types=1);

use AnalyticsNotes\ApofileParser;
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

$file = $_FILES['apofile'] ?? null;
if ($file === null) {
    fail('Aucun fichier reçu (champ « apofile »).');
}
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $messages = [
        UPLOAD_ERR_INI_SIZE => 'Fichier trop volumineux (limite upload_max_filesize).',
        UPLOAD_ERR_FORM_SIZE => 'Fichier trop volumineux (limite de formulaire).',
        UPLOAD_ERR_PARTIAL => 'Fichier partiellement téléversé.',
        UPLOAD_ERR_NO_TMP => 'Aucun dossier temporaire disponible.',
        UPLOAD_ERR_CANT_WRITE => 'Écriture du fichier temporaire impossible.',
    ];
    fail($messages[$file['error']] ?? 'Erreur de téléversement inconnue (code ' . ($file['error'] ?? '?') . ').');
}

$orig = basename((string) ($file['name'] ?? 'apofile.xlsx'));
if (strtolower(pathinfo($orig, PATHINFO_EXTENSION)) !== 'xlsx') {
    fail('Format non supporté : seul .xlsx est accepté pour le relevé de notes.');
}

$storage = dirname(__DIR__, 2) . '/storage';
if (!is_dir($storage) && !mkdir($storage, 0775, true)) {
    fail('Impossible de créer le dossier storage/.');
}

$token = bin2hex(random_bytes(16));
$tmp = $storage . '/' . $token . '.xlsx';
if (!move_uploaded_file($file['tmp_name'], $tmp)) {
    fail('Impossible de déplacer le fichier téléversé.');
}

try {
    [$redouIds, $redouSource] = loadRedoublants($storage);
    $analysis = ApofileParser::fromFile($tmp, $orig)->analyze($redouIds);
    if ($redouSource !== null) {
        $analysis['meta']['redoublantSource'] = $redouSource;
    }
} catch (Throwable $e) {
    @unlink($tmp);
    fail('Lecture du fichier impossible : ' . $e->getMessage() . ' [' . basename($e->getFile()) . ':' . $e->getLine() . ']');
}

// Seule l'analyse JSON est conservée (pas de BDD), le xlsx source est supprimé.
$json = json_encode($analysis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($json)) {
    @unlink($tmp);
    fail('Encodage de l\'analyse impossible : ' . json_last_error_msg());
}
if (file_put_contents($storage . '/' . $token . '.json', $json) === false) {
    @unlink($tmp);
    $last = error_get_last();
    fail('Écriture de l\'analyse dans storage/ impossible. '
        . ($last ? 'Dernière erreur PHP : ' . $last['message'] : 'Vérifiez les droits d\'écriture du dossier storage/.'));
}
@unlink($tmp);

cleanStorage($storage, 24 * 3600);

echo json_encode([
    'ok' => true,
    'token' => $token,
    'meta' => $analysis['meta'],
    'warnings' => $analysis['warnings'],
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
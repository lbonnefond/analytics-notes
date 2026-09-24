<?php

declare(strict_types=1);

namespace AnalyticsNotes;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Lecture d'un relevé APO_*.xlsx (onglet « PVout », en-tête sur 2 lignes)
 * et extraction de l'ensemble des données nécessaires à l'analyse.
 *
 * Portage fidèle de la logique de détection du script Python :
 *  - une UE = colonne dont le parent contient « ELP » (sans « Semestre »)
 *    et dont l'enfant contient « Note » ;
 *  - les épreuves = colonnes « Note » situées entre deux UE
 *    (libellées « EPR … » en ligne 1) ;
 *  - le bloc « Semestre » est isolé (plus détecté comme matière).
 *
 * Repère (légende du fichier) : une note ou un résultat souligné, ou une
 * colonne An/Session non vide, indique un acquis obtenu en session précédente.
 */
final class ApofileParser
{
    private const HEADER_LAST = 300; // largeur de recherche de l'en-tête
    private const MAX_ROWS = 2000;   // garde-fou : nombre max d'étudiants

    private Worksheet $sheet;

    /** @var array<int, array{parent: string, child: string}> indexé par n° de colonne (1-based) */
    private array $cols = [];
    private int $headerLast = 0;

    private array $meta = [];
    private array $warnings = [];
    private array $id = ['id' => null, 'nom' => null, 'prenom' => null];
    /** @var array<string, array{label: string, col: int}> colonnes du bloc « Etudiant » */
    private ?string $filePath = null;
    private array $info = [];
    /** @var array{note: ?int, res: ?int, ptjury: ?int, credits: ?int, rang: ?int, parent: string} */
    private array $sem = ['note' => null, 'res' => null, 'ptjury' => null, 'credits' => null, 'rang' => null, 'parent' => ''];
    /** @var array<int, array{col: int, code: string, name: string, resultatCol: ?int, creditsCol: ?int, anSessionCol: ?int, es1Col: ?int, exams: array}> */
    private array $ues = [];

    public static function fromFile(string $path, ?string $originalName = null): self
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('PVout') ?? $spreadsheet->getSheet(0);
        $parser = new self($sheet, $originalName ?? basename($path));
        $parser->filePath = $path;
        $parser->buildHeader();
        // Pas de disconnectWorksheets() : en PhpSpreadsheet 2.x, tout accès
        // cellule après coup lève « cellCollection must not be accessed
        // before initialization ». La mémoire est libérée à la fin de la requête.

        return $parser;
    }

    private function __construct(Worksheet $sheet, string $fileName)
    {
        $this->sheet = $sheet;
        $this->meta['fileName'] = $fileName;
    }

    /* ============================== ANALYSE ============================== */

    public function analyze(array $redoublantIds = []): array
    {
        $this->parseFileNameMeta();
        $this->parseObjetCell();
        $this->identifyColumns();
        $this->sem = $this->findSemesterBlock();
        $this->ues = $this->attachExams($this->findUes());

        if ($this->ues === []) {
            $this->warnings[] = 'Aucune UE détectée : vérifiez que l\'en-tête est sur 2 lignes et que les UE sont libellées « ELP … ».';
        }
        if ($this->sem['note'] === null || $this->sem['res'] === null) {
            $this->warnings[] = 'Bloc « Semestre » (Note / Résultat) incomplet : les KPI du semestre seront partiels.';
        }
        if ($this->id['id'] === null || $this->id['nom'] === null || $this->id['prenom'] === null) {
            $this->warnings[] = 'Colonnes N°Etu / Nom / Prénom introuvables : l\'affichage des listes ne sera pas possible.';
        }
        $this->diagnoseUnmappedNoteColumns();

        $matrix = $this->buildMatrix();
        if ($this->sheet->getHighestRow() > self::MAX_ROWS) {
            foreach (array_keys($matrix) as $r) {
                if ($r > self::MAX_ROWS) {
                    unset($matrix[$r]);
                }
            }
            $this->warnings[] = sprintf('Plus de %d lignes dans le fichier : seules les %d premières sont analysées.', self::MAX_ROWS, self::MAX_ROWS);
        }

        $students = $this->readStudents($matrix);

        $this->meta['effectif'] = count($students);
        $this->meta['nbUe'] = count($this->ues);
        $this->meta['parcours'] = $this->sem['parent'] !== '' ? self::splitUeLabel($this->sem['parent']) : null;
        $this->meta['generatedAt'] = date('c');
        $this->meta['colonnes'] = $this->headerList();

        $redoublants = null;
        if ($redoublantIds !== []) {
            $set = array_map('strval', $redoublantIds);
            $subset = array_values(array_filter(
                $students,
                static fn(array $s): bool => in_array($s['id'], $set, true)
            ));
            $redoublants = [
                'requested' => count($set),
                'matched' => count($subset),
                'kpis' => $this->computeKpis($subset),
                'ues' => $this->computeSubsetUeStats($subset),
            ];
        }

        // Drapeau par étudiant : présent dans la liste de redoublants fournie
        if ($redoublantIds !== []) {
            $set = array_map('strval', $redoublantIds);
            foreach ($students as &$s) {
                $s['isRedoublant'] = in_array($s['id'], $set, true);
            }
            unset($s);
        }

        return [
            'meta' => $this->meta,
            'kpis' => $this->computeKpis($students),
            'ues' => $this->computeUes($students),
            'absences' => $this->computeAbsences($students),
            'borderline' => $this->computeBorderline($students),
            'redoublants' => $redoublants,
            'students' => $students,
            'warnings' => $this->warnings,
            'semBorderline' => $this->computeSemBorderline($students),
            'anomalies' => $this->detectAnomalies($students),
        ];
    }

    /* ============================== EN-TÊTE ============================== */

    private function buildHeader(): void
    {
        $this->fillMergedHeaderCells();

        $usedCol = Coordinate::columnIndexFromString((string) $this->sheet->getHighestColumn());
        $maxCol = min(self::HEADER_LAST, $usedCol);

        $last = 0;
        for ($c = 1; $c <= $maxCol; $c++) {
            $parent = $this->cellStr(1, $c);
            $child = $this->cellStr(2, $c);
            if ($parent === '' && $child === '') {
                if ($c > $last + 1) {
                    break; // trou de plus d'une colonne : fin de l'en-tête
                }
                continue;
            }
            $this->cols[$c] = ['parent' => $parent, 'child' => $child];
            $last = $c;
        }
        $this->headerLast = max($last, 1);

        // Le libellé d'un bloc n'est posé que dans sa 1re colonne (pas de
        // cellules fusionnées dans ce format) : on propage le dernier libellé
        // vu vers les colonnes suivantes tant que leur parent est vide.
        $currentParent = '';
        foreach ($this->cols as $c => &$col) {
            if ($col['parent'] !== '') {
                $currentParent = $col['parent'];
            }
            $col['parent'] = $col['parent'] !== '' ? $col['parent'] : $currentParent;
        }
        unset($col);
    }

    /** Propage les valeurs des cellules fusionnées sur les lignes 1 et 2. */
    private function fillMergedHeaderCells(): void
    {
        foreach ($this->sheet->getMergeCells() as $range) {
            // PhpSpreadsheet 2.x renvoie des strings ("A1:C5") ;
            // 1.x renvoie des objets MergeCell.
            if (is_string($range)) {
                $parts = explode(':', $range);
                if (count($parts) !== 2) {
                    continue;
                }
                $start = $parts[0];
                $end = $parts[1];
            } else {
                $start = $range->getStartCoordinate();
                $end = $range->getEndCoordinate();
            }

            [$sc, $sr] = Coordinate::coordinateFromString($start);
            [$ec, $er] = Coordinate::coordinateFromString($end);
            $sc = Coordinate::columnIndexFromString($sc);
            $ec = Coordinate::columnIndexFromString($ec);
            // coordinateFromString renvoie la LIGNE en string (capture de regex) :
            // on caste en int pour satisfaire cellStr(int $row, ...).
            $sr = (int) $sr;
            $er = (int) $er;

            if ($sr > 2) {
                continue;
            }
            $value = $this->cellStr($sr, $sc);
            // Les libellés d'en-tête sont courts : on ne propage jamais un long
            // bloc de texte (ex. la cellule « Objet : … » en pied de feuille).
            if ($value === '' || strlen($value) > 100 || str_contains(strtolower($value), 'objet')) {
                continue;
            }
            for ($r = max($sr, 1); $r <= min($er, 2); $r++) {
                for ($c = $sc; $c <= $ec; $c++) {
                    if ($this->cellStr($r, $c) === '') {
                        $this->cellObj($c, $r)->setValue($value);
                    }
                }
            }
        }
    }

    /**
     * Récupère une cellule par (colonne, ligne) d'une manière portable
     * sur toutes les versions de PhpSpreadsheet.
     */
    private function cellObj(int $col, int $row)
    {
        return $this->sheet->getCell(Coordinate::stringFromColumnIndex($col) . $row);
    }

    private function cellStr(int $row, int $col): string
    {
        if ($row < 1 || $col < 1) {
            return '';
        }
        $v = $this->cellObj($col, $row)->getValue();

        return $this->valueToText($v);
    }

    /** Normalisation pour la recherche de libellés (minuscules, sans accents). */
    private function norm(string $s): string
    {
        $s = strtolower($s);
        $s = strtr($s, [
            'é' => 'e',
            'è' => 'e',
            'ê' => 'e',
            'ë' => 'e',
            'à' => 'a',
            'â' => 'a',
            'ù' => 'u',
            'ü' => 'u',
            'ï' => 'i',
            'ô' => 'o',
            'ö' => 'o',
            'ç' => 'c',
            '°' => '',
        ]);

        return $s;
    }

    /* ======================= CONVERSION DE VALEURS ======================= */

    /**
     * Convertit une valeur de cellule en texte lisible.
     * Les valeurs numériques (int, float ou texte numérique) comprises entre
     * 20000 et 60000 sont interprétées comme des dates Excel ; les notes (0–20)
     * et les identifiants (8 chiffres, > 60000) ne sont jamais touchés.
     */
    private function valueToText(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->format('d/m/Y');
        }
        if (is_int($v) || is_float($v)) {
            $f = (float) $v;
            if ($f >= 20000 && $f <= 60000) {
                return self::serialToDate($f);
            }
            if (floor($f) === $f) {
                return (string) (int) $f;
            }
            return (string) $f;
        }
        if (is_string($v)) {
            $t = trim($v);
            if (is_numeric($t)) {
                $f = (float) $t;
                if ($f >= 20000 && $f <= 60000) {
                    return self::serialToDate($f);
                }
            }
            // Normalise un éventuel format ISO (2002-03-05) en FR
            if (preg_match('#^(\d{4})-(\d{2})-(\d{2})#', $t, $m)) {
                return $m[3] . '/' . $m[2] . '/' . $m[1];
            }
            return $t;
        }

        return trim((string) $v);
    }

    /**
     * Convertit un sérial Excel (20000–60000) en date lisible.
     * Conversion en pur PHP, sans dépendre d'une méthode de la librairie
     * (toFormattedDate / ExcelToDateTime ont disparu en 2.x).
     * Sérial 25569 = 01/01/1970 (époque Unix).
     */
    private static function serialToDate(float $serial): string
    {
        $unixTs = (int) (($serial - 25569) * 86400);
        if ($unixTs < 0) {
            return (string) (int) $serial;
        }
        $date = date('d/m/Y', $unixTs);
        return $date !== false ? $date : (string) (int) $serial;
    }

    private function valueToFloat(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        if (is_string($v)) {
            $t = trim($v);
            if ($t !== '' && is_numeric($t)) {
                return (float) $t;
            }
        }

        return null;
    }

    /** @param mixed $v */
    private function codeOf(mixed $v): string
    {
        return strtoupper(trim($this->valueToText($v)));
    }

    /** @param mixed $v */
    private function cellAt(array $row, ?int $col): mixed
    {
        if ($col === null) {
            return null;
        }

        return $row[$col] ?? null;
    }

    private function isUnderlined(int $col, int $row): bool
    {
        try {
            $underline = $this->cellObj($col, $row)->getStyle()->getFont()->getUnderline();

            return $underline !== null && $underline !== Font::UNDERLINE_NONE;
        } catch (\Throwable) {
            // Style illisible : la valeur An/Session reste le signal principal
            // d'un acquis de session précédente.
            return false;
        }
    }

    /* ============================ IDENTIFICATION ========================= */

    private function identifyColumns(): void
    {
        foreach ($this->cols as $c => $col) {
            $p = $this->norm($col['parent']);
            $ch = $this->norm($col['child']);

            if ($this->id['id'] === null && (str_contains($ch, 'netu') || str_contains($ch, 'id etudiant'))) {
                $this->id['id'] = $c;
                continue;
            }
            if ($this->id['prenom'] === null && str_contains($ch, 'prenom')) {
                $this->id['prenom'] = $c;
                continue;
            }
            if ($this->id['nom'] === null && str_contains($ch, 'nom') && !str_contains($ch, 'prenom')) {
                $this->id['nom'] = $c;
                continue;
            }
            // Bloc « Etudiant » : sans cellules fusionnées, seul A1 porte le
            // libellé ; les colonnes suivantes ont un parent vide.
            if ($p === '' || str_contains($p, 'etudiant')) {
                if (str_starts_with($ch, 'ses')) {
                    $this->info['ses' . count($this->info)] = ['label' => $col['child'], 'col' => $c];
                } elseif (str_contains($ch, 'naissance') && !isset($this->info['naissance'])) {
                    $this->info['naissance'] = ['label' => $col['child'], 'col' => $c];
                } elseif (str_contains($ch, 'ville') && !isset($this->info['ville'])) {
                    $this->info['ville'] = ['label' => $col['child'], 'col' => $c];
                } elseif (str_contains($ch, 'dep') && !isset($this->info['dep'])) {
                    $this->info['dep'] = ['label' => $col['child'], 'col' => $c];
                }
            }
        }
    }

    private function findSemesterBlock(): array
    {
        $sem = ['note' => null, 'res' => null, 'ptjury' => null, 'credits' => null, 'rang' => null, 'parent' => ''];
        foreach ($this->cols as $c => $col) {
            if (!str_contains($this->norm($col['parent']), 'semestre')) {
                continue;
            }
            $ch = $this->norm($col['child']);
            $short = strlen($col['child']) <= 20;
            if ($sem['note'] === null && $short && str_contains($ch, 'note')) {
                $sem['note'] = $c;
                $sem['parent'] = $col['parent'];
            }
            if ($sem['res'] === null && $short && str_contains($ch, 'resultat')) {
                $sem['res'] = $c;
            }
            if ($sem['ptjury'] === null && $short && str_contains($ch, 'ptjury')) {
                $sem['ptjury'] = $c;
            }
            if ($sem['credits'] === null && $short && str_contains($ch, 'credits')) {
                $sem['credits'] = $c;
            }
            if ($sem['rang'] === null && $short && str_contains($ch, 'rang')) {
                $sem['rang'] = $c;
            }
        }

        return $sem;
    }

    private function findUes(): array
    {
        $ues = [];
        foreach ($this->cols as $c => $col) {
            $p = $this->norm($col['parent']);
            $ch = $this->norm($col['child']);
            if (!str_contains($p, 'elp') || str_contains($p, 'semestre')) {
                continue;
            }
            if (strlen($col['child']) > 20 || !str_contains($ch, 'note')) {
                continue;
            }
            [$code, $name] = self::splitUeLabel($col['parent']);
            $ues[] = [
                'col' => $c,
                'code' => $code,
                'name' => $name,
                'resultatCol' => $this->findNearChild($c, 'resultat', 3),
                'creditsCol' => $this->findNearChild($c, 'credits', 5),
                'anSessionCol' => $this->findNearChild($c, 'an/session', 5),
                'es1Col' => $this->findNearChild($c, 'es1', 5),
                'exams' => [],
            ];
            if ($ues[count($ues) - 1]['resultatCol'] === null) {
                $this->warnings[] = sprintf('UE %s : colonne « Résultat » non trouvée juste après la note.', $code);
            }
        }

        return $ues;
    }

    /** Cherche une sous-colonne (par libellé) dans les $maxOffset colonnes suivant $from. */
    private function findNearChild(int $from, string $needle, int $maxOffset): ?int
    {
        for ($d = 1; $d <= $maxOffset; $d++) {
            $col = $this->cols[$from + $d] ?? null;
            if ($col !== null && str_contains($this->norm($col['child']), $needle)) {
                return $from + $d;
            }
        }

        return null;
    }

    /** Rattache les épreuves à leur UE par position (colonnes « Note » entre deux UE). */
    private function attachExams(array $ues): array
    {
        foreach ($ues as $i => $ue) {
            $stop = $i + 1 < count($ues) ? $ues[$i + 1]['col'] : $this->headerLast + 1;
            for ($c = $ue['col'] + 1; $c < $stop; $c++) {
                $col = $this->cols[$c] ?? null;
                if ($col === null || strlen($col['child']) > 20 || !str_contains($this->norm($col['child']), 'note')) {
                    continue;
                }
                [$code, $name] = self::splitExamLabel($col['parent']);
                $ues[$i]['exams'][] = ['col' => $c, 'code' => $code, 'name' => $name];
            }
        }

        return $ues;
    }

    /** @return array{0: string, 1: string} [code, nom] */
    public static function splitUeLabel(string $label): array
    {
        $label = trim($label);
        if (preg_match('/^ELP\s+(\S+)\s+(.+)$/i', $label, $m)) {
            return [trim($m[1]), trim($m[2])];
        }
        if (preg_match('/^ELP\s+(\S+)$/i', $label, $m)) {
            return [trim($m[1]), ''];
        }

        return ['ELP', $label];
    }

    /** @return array{0: string, 1: string} [code, nom] */
    public static function splitExamLabel(string $label): array
    {
        $label = trim($label);
        if (preg_match('/^EPR\s+(\S+)\s+(.+)$/i', $label, $m)) {
            return [trim($m[1]), trim($m[2])];
        }
        if (preg_match('/^EPR\s+(\S+)$/i', $label, $m)) {
            return [trim($m[1]), ''];
        }
        if (str_contains($label, 'ELP')) {
            // parent fusionné sur l'intégralité du bloc UE : épreuve sans libellé propre
            return ['', 'Épreuve'];
        }

        return ['', $label];
    }

    private function diagnoseUnmappedNoteColumns(): void
    {
        $known = [];
        foreach ($this->ues as $ue) {
            $known[$ue['col']] = true;
            foreach ($ue['exams'] as $ex) {
                $known[$ex['col']] = true;
            }
        }
        if ($this->sem['note'] !== null) {
            $known[$this->sem['note']] = true;
        }
        foreach ($this->cols as $c => $col) {
            if (isset($known[$c]) || !str_contains($this->norm($col['child']), 'note')) {
                continue;
            }
            $this->warnings[] = sprintf('Colonne « Note » non rattachée (col. %d : %s / %s) : elle sera ignorée.', $c, $col['parent'], $col['child']);
        }
    }

    /* =========================== LECTURE LIGNES ========================== */

    /**
     * Construit la matrice des données (lignes 3 → maxRow) en lisant chaque
     * cellule individuellement avec getCell(), ce qui garantit un alignement
     * exact des colonnes (contrairement à toArray() qui peut décaler les
     * colonnes vides).
     *
     * @return array<int, array<int, mixed>> [ligne => [colonne => valeur brute]]
     */
    private function buildMatrix(): array
    {
        $matrix = [];
        $maxRow = min($this->sheet->getHighestRow(), self::MAX_ROWS);
        $maxCol = min(
            Coordinate::columnIndexFromString((string) $this->sheet->getHighestColumn()),
            self::HEADER_LAST
        );

        for ($r = 3; $r <= $maxRow; $r++) {
            $row = [];
            for ($c = 1; $c <= $maxCol; $c++) {
                $row[$c] = $this->cellObj($c, $r)->getValue();
            }
            $matrix[$r] = $row;
        }

        return $matrix;
    }

    /** @param array<int, array<int, mixed>> $matrix */
    private function readStudents(array $matrix): array
    {
        $students = [];
        $maxRow = min($this->sheet->getHighestRow(), self::MAX_ROWS);

        for ($r = 3; $r <= $maxRow; $r++) {
            $row = $matrix[$r] ?? [];

            // Le pied de page (« Objet : … ») peut se trouver dans une ligne
            // d'étudiant : on neutralise ces cellules.
            foreach ($row as $ci => $v) {
                $t = $this->valueToText($v);
                if (strlen($t) > 30 && stripos($t, 'objet') !== false) {
                    $row[$ci] = null;
                }
            }

            $id = $this->valueToText($this->cellAt($row, $this->id['id']));
            $nom = $this->valueToText($this->cellAt($row, $this->id['nom']));
            $prenom = $this->valueToText($this->cellAt($row, $this->id['prenom']));
            if ($id === '' && $nom === '' && $prenom === '') {
                continue;
            }
            $students[] = $this->buildStudent($r, $row, $id, $nom, $prenom);
        }

        return $students;
    }

    /** @param array<int, mixed> $row */
    private function buildStudent(int $r, array $row, string $id, string $nom, string $prenom): array
    {
        $student = [
            'id' => $id,
            'nom' => $nom,
            'prenom' => $prenom,
            'info' => [],
            'sem' => [
                'note' => $this->valueToFloat($this->cellAt($row, $this->sem['note'])),
                'resultat' => $this->codeOf($this->cellAt($row, $this->sem['res'])),
                'ptjury' => $this->valueToFloat($this->cellAt($row, $this->sem['ptjury'])),
                'credits' => $this->valueToFloat($this->cellAt($row, $this->sem['credits'])),
                'rang' => $this->valueToFloat($this->cellAt($row, $this->sem['rang'])),
            ],
            'absences' => ['DIS' => 0, 'ABJ' => 0, 'ABI' => 0],
            'ues' => [],
        ];

        foreach ($this->info as $c) {
            $student['info'][$c['label']] = $this->valueToText($this->cellAt($row, $c['col']));
        }

        // Comptage DIS/ABJ/ABI sur TOUTE la ligne (portage fidèle du script :
        // row == "DIS" / "ABJ" / "ABI" sur l'ensemble des colonnes).
        foreach ($row as $v) {
            $s = $this->codeOf($v);
            if ($s === 'ABJ') {
                $student['absences']['ABJ']++;
            } elseif ($s === 'ABI') {
                $student['absences']['ABI']++;
            } elseif ($s === 'DIS') {
                $student['absences']['DIS']++;
            }
        }

        foreach ($this->ues as $ue) {
            $noteRaw = $this->valueToText($this->cellAt($row, $ue['col']));
            $resRaw = $this->valueToText($this->cellAt($row, $ue['resultatCol']));
            $anSes = $this->valueToText($this->cellAt($row, $ue['anSessionCol']));
            $prev = $anSes !== ''
                || ($noteRaw !== '' && $this->isUnderlined($ue['col'], $r))
                || ($resRaw !== '' && $ue['resultatCol'] !== null && $this->isUnderlined($ue['resultatCol'], $r));

            $exams = [];
            foreach ($ue['exams'] as $ex) {
                $exams[] = [
                    'code' => $ex['code'],
                    'name' => $ex['name'],
                    'raw' => $this->valueToText($this->cellAt($row, $ex['col'])),
                    'note' => $this->valueToFloat($this->cellAt($row, $ex['col'])),
                ];
            }

            $student['ues'][] = [
                'code' => $ue['code'],
                'noteRaw' => $noteRaw,
                'note' => $this->valueToFloat($this->cellAt($row, $ue['col'])),
                'resultat' => $this->codeOf($resRaw),
                'credits' => $this->valueToFloat($this->cellAt($row, $ue['creditsCol'])),
                'anSession' => $anSes,
                'sessionPrecedente' => $prev,
                'exams' => $exams,
            ];
        }

        return $student;
    }

    /* ============================== CALCULS ============================== */

    /** @param array<int, array<string, mixed>> $students */
    private function computeKpis(array $students): array
    {
        $total = count($students);
        $repartition = ['ADM' => 0, 'AJ' => 0, 'DEF' => 0, 'autres' => 0];
        $notesSem = [];
        $notesAdmis = [];

        foreach ($students as $s) {
            $res = $s['sem']['resultat'];
            if (isset($repartition[$res])) {
                $repartition[$res]++;
            } elseif ($res !== '') {
                $repartition['autres']++;
            }
            if ($s['sem']['note'] !== null) {
                $notesSem[] = $s['sem']['note'];
                if ($res === 'ADM') {
                    $notesAdmis[] = $s['sem']['note'];
                }
            }
        }

        return [
            'effectif' => $total,
            'repartition' => $repartition,
            'taux' => [
                'ADM' => $total > 0 ? round($repartition['ADM'] / $total * 100, 1) : 0.0,
                'AJ' => $total > 0 ? round($repartition['AJ'] / $total * 100, 1) : 0.0,
                'DEF' => $total > 0 ? round($repartition['DEF'] / $total * 100, 1) : 0.0,
            ],
            'semNote' => StatsCalculator::describe($notesSem),
            'semNoteAdmis' => StatsCalculator::describe($notesAdmis),
            'histSem' => StatsCalculator::histogram($notesSem),
            'histSemAdmis' => StatsCalculator::histogram($notesAdmis),
        ];
    }

    /** @param array<int, array<string, mixed>> $students */
    private function computeUes(array $students): array
    {
        $result = [];
        foreach ($this->ues as $i => $ue) {
            $allNotes = [];
            $curNotes = [];
            $defAll = 0;
            $defCur = 0;
            $prevCount = 0;
            $resCounts = [];
            $examNotes = [];
            $examRaw = [];
            $examNotesCur = [];
            $examRawCur = [];

            foreach ($students as $s) {
                $u = $s['ues'][$i] ?? null;
                if ($u === null) {
                    continue;
                }

                // Portage exact de calculer_stats() :
                // effectif = valeurs numériques + valeurs "DEF" (ABJ/ABI/DIS exclus)
                $raw = trim($u['noteRaw']);
                if ($raw !== '' && is_numeric($raw)) {
                    $allNotes[] = (float) $raw;
                    if (!$u['sessionPrecedente']) {
                        $curNotes[] = (float) $raw;
                    }
                } elseif ($raw === 'DEF') {
                    $defAll++;
                    if (!$u['sessionPrecedente']) {
                        $defCur++;
                    }
                }
                if ($u['resultat'] !== '') {
                    $resCounts[$u['resultat']] = ($resCounts[$u['resultat']] ?? 0) + 1;
                }
                if ($u['sessionPrecedente']) {
                    $prevCount++;
                }

                foreach ($u['exams'] as $ex) {
                    $k = $ex['code'] . '|' . $ex['name'];
                    $rawE = trim($ex['raw']);
                    if ($rawE !== '' && is_numeric($rawE)) {
                        $examNotes[$k][] = (float) $rawE;
                        if (!$u['sessionPrecedente']) {
                            $examNotesCur[$k][] = (float) $rawE;
                        }
                    } elseif ($rawE !== '') {
                        $code = strtoupper($rawE);
                        $examRaw[$k][$code] = ($examRaw[$k][$code] ?? 0) + 1;
                        if (!$u['sessionPrecedente']) {
                            $examRawCur[$k][$code] = ($examRawCur[$k][$code] ?? 0) + 1;
                        }
                    }
                }
            }

            $exams = [];
            $examsCur = [];
            foreach ($ue['exams'] as $ex) {
                $k = $ex['code'] . '|' . $ex['name'];
                $notesE = $examNotes[$k] ?? [];
                $exams[] = [
                    'code' => $ex['code'],
                    'name' => $ex['name'],
                    'stats' => StatsCalculator::describe($notesE),
                    'hist' => StatsCalculator::histogram($notesE),
                    'rawCounts' => $examRaw[$k] ?? [],
                ];
                $notesEc = $examNotesCur[$k] ?? [];
                $examsCur[] = [
                    'code' => $ex['code'],
                    'name' => $ex['name'],
                    'stats' => StatsCalculator::describe($notesEc),
                    'hist' => StatsCalculator::histogram($notesEc),
                    'rawCounts' => $examRawCur[$k] ?? [],
                ];
            }

            $result[] = [
                'code' => $ue['code'],
                'name' => $ue['name'],
                'stats' => array_merge(
                    ['effectif' => count($allNotes) + $defAll, 'acquis' => count(array_filter($allNotes, fn(float $x) => $x >= 10)), 'def' => $defAll],
                    StatsCalculator::describe($allNotes)
                ),
                'statsSessionCourante' => array_merge(
                    ['effectif' => count($curNotes) + $defCur, 'acquis' => count(array_filter($curNotes, fn(float $x) => $x >= 10)), 'def' => $defCur],
                    StatsCalculator::describe($curNotes)
                ),
                'nbSessionPrecedente' => $prevCount,
                'repartition' => $resCounts,
                'hist' => StatsCalculator::histogram($allNotes),
                'histSessionCourante' => StatsCalculator::histogram($curNotes),
                'exams' => $exams,
                'examsSessionCourante' => $examsCur,
                'ueNotes' => $allNotes,
            ];
        }

        return $result;
    }

    /** @param array<int, array<string, mixed>> $students */
    private function computeAbsences(array $students): array
    {
        $out = [];
        foreach ($students as $s) {
            if ($s['sem']['resultat'] !== 'DEF') {
                continue;
            }
            $a = $s['absences'];
            if ($a['ABJ'] + $a['ABI'] > 0) {
                $out[] = [
                    'id' => $s['id'],
                    'nom' => $s['nom'],
                    'prenom' => $s['prenom'],
                    'DIS' => $a['DIS'],
                    'ABJ' => $a['ABJ'],
                    'ABI' => $a['ABI'],
                ];
            }
        }

        return $out;
    }

    /** @param array<int, array<string, mixed>> $students */
    private function computeBorderline(array $students): array
    {
        $out = [];
        foreach ($students as $s) {
            foreach (array_keys($s['ues']) as $i) {
                $u = $s['ues'][$i];
                if ($u['note'] !== null && $u['note'] > 9.95 && $u['note'] < 10) {
                    $out[] = [
                        'id' => $s['id'],
                        'nom' => $s['nom'],
                        'prenom' => $s['prenom'],
                        'note' => $u['note'],
                        'ueCode' => $u['code'],
                        'ueName' => $this->ues[$i]['name'] ?? '',
                    ];
                }
            }
        }
        usort($out, fn(array $a, array $b) => [$b['note'], strtolower($a['nom'] . ' ' . $a['prenom'])] <=> [$a['note'], strtolower($b['nom'] . ' ' . $b['prenom'])]);

        return $out;
    }

    /** Notes de semestre comprises entre 9,95 (inclue) et 10 (exclue). */
    private function computeSemBorderline(array $students): array
    {
        $out = [];
        foreach ($students as $s) {
            $n = $s['sem']['note'] ?? null;
            if ($n !== null && $n >= 9.95 && $n < 10) {
                $out[] = [
                    'id' => $s['id'],
                    'nom' => $s['nom'],
                    'prenom' => $s['prenom'],
                    'note' => $n,
                    'resultat' => $s['sem']['resultat'] ?? '',
                ];
            }
        }
        usort($out, static fn(array $a, array $b): int => [$a['note'], strtolower($a['nom'])] <=> [$b['note'], strtolower($b['nom'])]);

        return $out;
    }

    /**
     * Contrôles sur un PV provisoire (à masquer pour un PV définitif) :
     *  - ue_single_note      : une seule note (au total, sur les épreuves) ⇒ la mention
     *                          de l'UE doit être DEF et la moyenne de l'UE NON calculée ;
     *  - ue_note_vide        : une ou plusieurs case(s) d'épreuve(s) vide(s)
     *                          (ni note, ni ABI/ABJ/DIS) alors qu'au moins une autre
     *                          épreuve est renseignée ⇒ donnée manquante à chercher
     *                          pour le PV final. Toutes les cases vides en revanche
     *                          est légitime (ex. redoublant UE acquise session précédente) ;
     *  - sem_def_incoherent  : DEF dans une UE ⇒ le semestre doit être DEF et sa moyenne
     *                          ne doit pas être calculée.
     */
    private function detectAnomalies(array $students): array
    {
        $out = [];
        foreach ($students as $s) {
            $hasUeDef = false;
            foreach (array_keys($s['ues']) as $i) {
                $u = $s['ues'][$i];
                $ueCode = $u['code'];
                $ueName = $this->ues[$i]['name'] ?? $ueCode;

                $totalExams = 0;
                $numericExams = 0;
                $filledExams = 0;
                $emptyExams = [];
                foreach ($u['exams'] as $ex) {
                    $totalExams++;
                    $rawE = trim($ex['raw']);
                    if ($ex['note'] !== null) {
                        $numericExams++;
                        $filledExams++;
                    } elseif ($rawE !== '') {
                        $filledExams++; // mention ABI / ABJ / DIS …
                    } else {
                        $emptyExams[] = $ex['name'] !== ''
                            ? $ex['name']
                            : ($ex['code'] !== '' ? $ex['code'] : 'Épreuve');
                    }
                }

                // (a) Une seule note au total sur les épreuves de l'UE
                //     → mention DEF obligatoire + moyenne UE non calculée
                if ($totalExams >= 2 && $numericExams === 1) {
                    $issues = [];
                    if ($u['resultat'] !== 'DEF') {
                        $issues[] = $u['resultat'] !== ''
                            ? 'mention « ' . $u['resultat'] . ' » au lieu de DEF'
                            : 'mention UE vide (DEF attendu)';
                    }
                    if ($u['note'] !== null) {
                        $issues[] = 'moyenne UE calculée (' . $u['note'] . ')';
                    }
                    if ($issues !== []) {
                        $out[] = [
                            'type' => 'ue_single_note',
                            'id' => $s['id'],
                            'nom' => $s['nom'],
                            'prenom' => $s['prenom'],
                            'ueCode' => $ueCode,
                            'ueName' => $ueName,
                            'message' => 'Une seule note notée pour cette UE : ' . implode(' et ', $issues),
                        ];
                    }
                }

                // (b) Case(s) d'épreuve(s) vide(s) — ni note, ni ABI/ABJ — alors que
                //     d'autres épreuves sont renseignées ⇒ donnée manquante à chercher.
                //     Le cas « toutes les cases vides » est exclu (légitime : UE acquise
                //     session précédente, ou étudiant non concerné).
                if ($totalExams >= 2 && $filledExams > 0 && $filledExams < $totalExams) {
                    $out[] = [
                        'type' => 'ue_note_vide',
                        'id' => $s['id'],
                        'nom' => $s['nom'],
                        'prenom' => $s['prenom'],
                        'ueCode' => $ueCode,
                        'ueName' => $ueName,
                        'message' => 'Case(s) vide(s) à compléter pour le PV final : ' . implode(', ', $emptyExams),
                    ];
                }

                if ($u['resultat'] === 'DEF') {
                    $hasUeDef = true;
                }
            }

            // (c) DEF dans une UE ⇒ semestre DEF et moyenne semestre non calculée
            if ($hasUeDef) {
                $semRes = $s['sem']['resultat'] ?? '';
                $semNote = $s['sem']['note'] ?? null;
                $issues = [];
                if ($semRes !== 'DEF') {
                    $issues[] = $semRes !== ''
                        ? 'résultat semestre « ' . $semRes . ' »'
                        : 'résultat semestre vide (DEF attendu)';
                }
                if ($semNote !== null) {
                    $issues[] = 'moyenne semestre calculée (' . $semNote . ')';
                }
                if ($issues !== []) {
                    $out[] = [
                        'type' => 'sem_def_incoherent',
                        'id' => $s['id'],
                        'nom' => $s['nom'],
                        'prenom' => $s['prenom'],
                        'ueCode' => '',
                        'ueName' => '',
                        'message' => 'DEF dans une UE mais ' . implode(' et ', $issues),
                    ];
                }
            }
        }

        return $out;
    }

    /** @param array<int, array<string, mixed>> $students */
    private function computeSubsetUeStats(array $students): array
    {
        $out = [];
        foreach ($this->ues as $i => $ue) {
            $notes = [];
            $def = 0;
            $examNotes = [];
            $examRaw = [];
            foreach ($students as $s) {
                $u = $s['ues'][$i] ?? null;
                if ($u === null) {
                    continue;
                }
                $raw = trim($u['noteRaw']);
                if ($raw !== '' && is_numeric($raw)) {
                    $notes[] = (float) $raw;
                } elseif ($raw === 'DEF') {
                    $def++;
                }
                foreach ($u['exams'] as $ex) {
                    $k = $ex['code'] . '|' . $ex['name'];
                    $rawE = trim($ex['raw']);
                    if ($rawE !== '' && is_numeric($rawE)) {
                        $examNotes[$k][] = (float) $rawE;
                    } elseif ($rawE !== '') {
                        $code = strtoupper($rawE);
                        $examRaw[$k][$code] = ($examRaw[$k][$code] ?? 0) + 1;
                    }
                }
            }
            $exams = [];
            foreach ($ue['exams'] as $ex) {
                $k = $ex['code'] . '|' . $ex['name'];
                $notesE = $examNotes[$k] ?? [];
                $exams[] = [
                    'code' => $ex['code'],
                    'name' => $ex['name'],
                    'stats' => StatsCalculator::describe($notesE),
                    'hist' => StatsCalculator::histogram($notesE),
                    'rawCounts' => $examRaw[$k] ?? [],
                ];
            }
            $out[] = array_merge(
                [
                    'code' => $ue['code'],
                    'name' => $ue['name'],
                    'effectif' => count($notes) + $def,
                    'acquis' => count(array_filter($notes, static fn(float $x): bool => $x >= 10)),
                    'def' => $def,
                ],
                StatsCalculator::describe($notes),
                [
                    'hist' => StatsCalculator::histogram($notes),
                    'exams' => $exams,
                ]
            );
        }

        return $out;
    }

    /* ============================ MÉTADONNÉES ============================ */

    /** Analyse le nom de fichier (ex. APO_PVL2S3BIO.xlsx → L2 / S3 / BIO). */
    private function parseFileNameMeta(): void
    {
        $name = $this->meta['fileName'] ?? '';
        if (preg_match('/(L\d{1,2})\s*(S\d{1,2})\s*([A-Z]{2,})/i', $name, $m)) {
            $this->meta['fileNameParsed'] = [
                'niveau' => strtoupper($m[1]),
                'semestre' => strtoupper($m[2]),
                'parcours' => strtoupper($m[3]),
            ];
        }
    }

    /**
     * Recherche le texte « Objet : … » (parcours, année, session).
     * Dans le fichier de test il n'est pas dans une cellule mais dans une
     * boîte de texte (dessin) : on prolonge alors la recherche dans le
     * XML brut du xlsx (sharedStrings, drawings).
     */
    private function parseObjetCell(): void
    {
        $maxRow = min($this->sheet->getHighestRow(), self::MAX_ROWS);
        $maxCol = min(
            Coordinate::columnIndexFromString((string) $this->sheet->getHighestColumn()),
            self::HEADER_LAST
        );

        $objet = null;
        for ($r = 1; $r <= $maxRow; $r++) {
            for ($c = 1; $c <= $maxCol; $c++) {
                $v = $this->cellStr($r, $c);
                if ($v !== '' && stripos($v, 'objet') !== false) {
                    $objet = $v;
                    break 2;
                }
            }
        }

        if ($objet === null) {
            $objet = $this->searchFooterInXml();
        }
        if ($objet === null) {
            return;
        }

        $this->meta['objet'] = $objet;
        if (preg_match('/Année\s*:\s*(\d{4})\s*\/?\s*(\d{2,4})/iu', $objet, $m)) {
            $this->meta['objetParsed']['annee'] = $m[1] . '/' . $m[2];
        }
        if (preg_match('/Session\s*:\s*(\d+)/iu', $objet, $m)) {
            $this->meta['objetParsed']['session'] = $m[1];
        }
        if (preg_match('/Semestre\s+(\d+)/iu', $objet, $m)) {
            $this->meta['objetParsed']['semestre'] = $m[1];
        }
        if (preg_match('/ELP\s+(\S+)/i', $objet, $m)) {
            $this->meta['objetParsed']['code'] = $m[1];
        }
    }

    /** Recherche le pied de page dans le XML brut du xlsx (chaînes partagées / boîtes de texte). */
    private function searchFooterInXml(): ?string
    {
        if ($this->filePath === null || !is_file($this->filePath)) {
            return null;
        }

        $zip = new \ZipArchive();
        if ($zip->open($this->filePath) !== true) {
            return null;
        }

        $xmls = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (is_string($name) && preg_match('#^xl/(sharedStrings\.xml|drawings/)#', $name)) {
                $content = $zip->getFromName($name);
                if (is_string($content) && $content !== '' && stripos($content, 'objet') !== false) {
                    $xmls[] = $content;
                }
            }
        }
        $zip->close();

        foreach ($xmls as $xml) {
            if (!preg_match_all('#<t[^>]*>(.*?)</t>#s', $xml, $m)) {
                continue;
            }
            $parts = array_map(
                static fn(string $p): string => html_entity_decode($p, ENT_QUOTES, 'UTF-8'),
                $m[1]
            );
            foreach ($parts as $t) {
                if (stripos($t, 'objet') !== false) {
                    return $t;
                }
            }
            // Texte « Objet » découpé sur plusieurs segments : on le reconstitue
            $joined = '';
            foreach ($parts as $t) {
                if ($joined === '') {
                    if (stripos($t, 'objet') !== false) {
                        $joined = $t;
                    }
                    continue;
                }
                $joined .= $t;
                if (strlen($joined) > 400) {
                    break;
                }
            }
            if ($joined !== '') {
                return $joined;
            }
        }

        return null;
    }

    private function headerList(): array
    {
        $out = [];
        foreach ($this->cols as $c => $col) {
            $out[] = ['col' => $c, 'parent' => $col['parent'], 'child' => $col['child']];
        }

        return $out;
    }
}

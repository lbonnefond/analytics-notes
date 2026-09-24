<?php

declare(strict_types=1);

namespace AnalyticsNotes;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Charge la liste des identifiants de redoublants depuis un fichier
 * (.xlsx, .xls ou .csv), à l'image de la lecture du script Python d'origine.
 * Tolère un en-tête de colonne (« ID_ETUDIANT », « ID »…) ou une simple
 * colonne d'identifiants.
 */
final class RedoublantsLoader
{
    /** @return string[] les identifiants (dé-dupliqués plus loin, en amont) */
    public static function load(string $path, string $originalName): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === 'csv') {
            $rows = self::readCsv($path);
        } else {
            $spreadsheet = IOFactory::load($path);
            $rows = self::sheetToRows($spreadsheet->getSheet(0));
            $spreadsheet->disconnectWorksheets();
        }

        if ($rows === []) {
            return [];
        }

        $first = $rows[0];
        $ncols = count($first);

        // 1) Recherche d'un en-tête « identifiant » dans la 1re ligne
        $idCol = null;
        foreach ($first as $ci => $labelRaw) {
            $label = self::norm((string) $labelRaw);
            if ($label !== '' && self::isIdHeader($label)) {
                $idCol = $ci;
                break;
            }
        }

        if ($idCol !== null) {
            $data = array_slice($rows, 1);
        } else {
            // Pas d'en-tête reconnu : on examine les colonnes
            $nonEmptyCols = 0;
            for ($ci = 0; $ci < $ncols; $ci++) {
                $has = false;
                foreach ($rows as $row) {
                    if (trim((string) ($row[$ci] ?? '')) !== '') {
                        $has = true;
                        break;
                    }
                }
                if ($has) {
                    $nonEmptyCols++;
                    if ($idCol === null && $has) {
                        $idCol = $ci;
                    }
                }
            }
            if ($idCol === null) {
                return [];
            }
            if ($nonEmptyCols > 1) {
                $labels = array_map(static fn ($v): string => self::norm((string) $v), $first);
                if (in_array('nom', $labels, true) && in_array('prenom', $labels, true)) {
                    throw new \InvalidArgumentException(
                        "Fichier redoublants : colonnes « Nom » / « Prénom » détectées mais pas d'identifiant. "
                        . "Fournissez la colonne des numéros d'étudiant (N°Etu) : l'appariement se fait par identifiant."
                    );
                }
                // Plusieurs colonnes sans en-tête connu : on prend la 1re colonne non vide
            }
            $data = $rows;
            // Si la 1re valeur ressemble à un libellé d'en-tête, on saute la ligne
            $firstVal = self::norm((string) ($rows[0][$idCol] ?? ''));
            if ($firstVal !== '' && self::isIdHeader($firstVal)) {
                $data = array_slice($data, 1);
            }
        }

        $ids = [];
        foreach ($data as $row) {
            $v = trim((string) ($row[$idCol] ?? ''));
            if ($v !== '') {
                $ids[] = $v;
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return list<list<string>> */
    private static function sheetToRows(Worksheet $sheet): array
    {
        $rows = [];
        $maxRow = $sheet->getHighestRow();
        $maxCol = Coordinate::columnIndexFromString((string) $sheet->getHighestColumn());
        for ($r = 1; $r <= $maxRow; $r++) {
            $row = [];
            for ($c = 1; $c <= $maxCol; $c++) {
                $v = $sheet->getCell(Coordinate::stringFromColumnIndex($c) . $r)->getValue();
                if ($v === null) {
                    $row[] = '';
                } elseif (is_bool($v)) {
                    $row[] = $v ? '1' : '0';
                } elseif (is_float($v)) {
                    // Longs numéros stockés en float : éviter la notation scientifique
                    $row[] = (floor($v) === $v) ? (string) (int) $v : (string) $v;
                } else {
                    $row[] = (string) $v;
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** @return list<list<string>> */
    private static function readCsv(string $path): array
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            return [];
        }
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        if (trim($content) === '') {
            return [];
        }
        // Séparateur : le fichier FR utilise le plus souvent le point-virgule
        $firstLine = strtok($content, "\n");
        $delim = substr_count((string) $firstLine, ';') >= substr_count((string) $firstLine, ',') ? ';' : ',';

        $rows = [];
        foreach (explode("\n", $content) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $parsed = str_getcsv($line, $delim, '"');
            if ($parsed !== false) {
                $rows[] = $parsed;
            }
        }

        return $rows;
    }

    private static function norm(string $s): string
    {
        $s = strtolower(trim($s));
        $s = strtr($s, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ç' => 'c', '°' => '']);

        return str_replace([' ', '_', '-'], '', $s);
    }

    private static function isIdHeader(string $label): bool
    {
        if ($label === '') {
            return false;
        }
        if (in_array($label, ['id', 'numero', 'num', 'netu'], true)) {
            return true;
        }

        return str_contains($label, 'id') && str_contains($label, 'etu');
    }
}
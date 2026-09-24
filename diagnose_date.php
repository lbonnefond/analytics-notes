<?php
require __DIR__ . '/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$w = (IOFactory::load('APO_PVL2S3BIO.xlsx'))->getSheetByName('PVout') ?? null;
if (!$w) { echo "Feuille PVout introuvable\n"; exit(1); }
$mr = $w->getHighestRow();
$mc = Coordinate::columnIndexFromString((string) $w->getHighestColumn());

// Trouve la colonne « Naissance » (ligne 2)
$colN = null;
for ($c = 1; $c <= $mc; $c++) {
    $v = $w->getCell(Coordinate::stringFromColumnIndex($c) . '2')->getValue();
    if (is_string($v) && stripos($v, 'naissance') !== false) { $colN = $c; break; }
}
echo "Colonne Naissance = " . ($colN ? Coordinate::stringFromColumnIndex($colN) : 'introuvable') . "\n\n";
if ($colN) {
    $count = 0;
    for ($r = 3; $r <= $mr; $r++) {
        $cell = $w->getCell(Coordinate::stringFromColumnIndex($colN) . $r);
        $v = $cell->getValue();
        if ($v === null || $v === '') continue;
        $count++;
        $fmt = $cell->getStyle()->getNumberFormat()->getFormatCode();
        $id = $w->getCell('A' . $r)->getValue();
        echo "N°Etu=" . $id . "  type=" . gettype($v) . "  valeur=" . var_export($v, true)
           . "  format=" . $fmt . "\n";
        if ($count >= 10) { echo "(arrêt après 10)\n"; break; }
    }
}
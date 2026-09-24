<?php

declare(strict_types=1);

namespace AnalyticsNotes;

/**
 * Statistiques portées fidèlement du script Python d'origine :
 * - moyenne / médiane / écart-type échantillonnal (ddof=1, comme pandas)
 * - arrondi 2 décimales (comme round(x, 2))
 * - "admis" = note >= 10
 */
final class StatsCalculator
{
    /**
     * @param float[] $notes notes numériques (les DEF/absents sont déjà écartés)
     * @return array{n:int, mean:?float, median:?float, std:?float, max:?float, min:?float}
     */
    public static function describe(array $notes): array
    {
        $n = count($notes);
        if ($n === 0) {
            return ['n' => 0, 'mean' => null, 'median' => null, 'std' => null, 'max' => null, 'min' => null];
        }

        $mean = array_sum($notes) / $n;

        $sorted = $notes;
        sort($sorted);
        $median = ($n % 2 === 1)
            ? $sorted[intdiv($n, 2)]
            : ($sorted[$n / 2 - 1] + $sorted[$n / 2]) / 2;

        if ($n > 1) {
            $sq = 0.0;
            foreach ($notes as $x) {
                $sq += ($x - $mean) ** 2;
            }
            $std = round(sqrt($sq / ($n - 1)), 2); // ddof=1 (pandas, échantillonnal)
        } else {
            $std = null; // pandas renvoie NaN pour n=1
        }

        return [
            'n' => $n,
            'mean' => round($mean, 2),
            'median' => round($median, 2),
            'std' => $std,
            'max' => $sorted[$n - 1],
            'min' => $sorted[0],
        ];
    }

    /**
     * Histogramme sur l'axe 0–20 : 20 cases de largeur 1.
     * (Note : le script Python utilisait les bins auto de seaborn ; ici la grille
     * est fixe 0/1/.../20 pour rester lisible et comparable entre UE.)
     *
     * @return array{labels: string[], counts: int[]}
     */
    public static function histogram(array $notes): array
    {
        $counts = array_fill(0, 20, 0);
        foreach ($notes as $x) {
            $i = (int) floor($x);
            if ($i < 0) {
                $i = 0;
            }
            if ($i >= 20) {
                $i = 19; // une note == 20 tombe dans la dernière case
            }
            $counts[$i]++;
        }

        return [
            'labels' => array_map(strval(...), range(0, 19)),
            'counts' => $counts,
        ];
    }
}

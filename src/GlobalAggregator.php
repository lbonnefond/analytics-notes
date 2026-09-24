<?php

declare(strict_types=1);

namespace AnalyticsNotes;

/**
 * Agrège l'analyse de plusieurs parcours en une vue globale : KPIs combinés,
 * réussite par parcours, matières partagées / spécifiques (avec stats
 * combinées), listes jury consolidées, redoublants.
 *
 * Les matières sont regroupées entre parcours via une clé de matching dérivée
 * du code UE (par défaut les 4 derniers caractères, ex. « CUST » pour
 * Statistiques), car une même matière peut porter un code complet différent
 * selon le parcours (ex. VI1DCUST en BIO, VI47CUST en CB).
 */
final class GlobalAggregator
{
    public const MATCH_CODE4 = 'code4';
    public const MATCH_CODE2 = 'code2';
    public const MATCH_NAME  = 'name';
    public const MATCH_FULL  = 'full';

    /**
     * @param array<int, array{label: string, key: string, fileName: string, meta: array, analysis: array}> $groups
     */
    public static function build(array $groups, string $matchMode = self::MATCH_CODE4): array
    {
        $kpis = [
            'effectif' => 0,
            'repartition' => ['ADM' => 0, 'AJ' => 0, 'DEF' => 0, 'autres' => 0],
            'taux' => [],
        ];
        $allNotes = [];
        $admNotes = [];
        $byParcours = [];

        foreach ($groups as $g) {
            $k = $g['analysis']['kpis'] ?? [];
            $rep = $k['repartition'] ?? [];
            $total = (int) ($k['effectif'] ?? 0);

            $kpis['effectif'] += $total;
            foreach (['ADM', 'AJ', 'DEF', 'autres'] as $rk) {
                $kpis['repartition'][$rk] += (int) ($rep[$rk] ?? 0);
            }
            foreach (($g['analysis']['students'] ?? []) as $s) {
                $note = $s['sem']['note'] ?? null;
                if ($note !== null) {
                    $allNotes[] = $note;
                    if (($s['sem']['resultat'] ?? '') === 'ADM') {
                        $admNotes[] = $note;
                    }
                }
            }
            $byParcours[] = [
                'label' => $g['label'],
                'fileName' => $g['fileName'],
                'effectif' => $total,
                'ADM' => (int) ($rep['ADM'] ?? 0),
                'AJ' => (int) ($rep['AJ'] ?? 0),
                'DEF' => (int) ($rep['DEF'] ?? 0),
                'tauxAdm' => $total > 0 ? round($rep['ADM'] / $total * 100, 1) : 0.0,
                'mean' => $k['semNote']['mean'] ?? null,
                'median' => $k['semNote']['median'] ?? null,
                'std' => $k['semNote']['std'] ?? null,
            ];
        }

        $t = $kpis['effectif'];
        $kpis['taux'] = [
            'ADM' => $t > 0 ? round($kpis['repartition']['ADM'] / $t * 100, 1) : 0.0,
            'AJ' => $t > 0 ? round($kpis['repartition']['AJ'] / $t * 100, 1) : 0.0,
            'DEF' => $t > 0 ? round($kpis['repartition']['DEF'] / $t * 100, 1) : 0.0,
        ];
        $kpis['semNote'] = StatsCalculator::describe($allNotes);
        $kpis['semNoteAdmis'] = StatsCalculator::describe($admNotes);
        $kpis['histSem'] = StatsCalculator::histogram($allNotes);
        $kpis['histSemAdmis'] = StatsCalculator::histogram($admNotes);

        // Matières : regroupées par clé de matching
        $byCode = [];
        foreach ($groups as $gi => $g) {
            foreach (($g['analysis']['ues'] ?? []) as $ue) {
                $code = $ue['code'] ?? '';
                $name = $ue['name'] ?? '';
                $key = self::matchKey($code, $name, $matchMode);
                $rep = $ue['repartition'] ?? [];
                $tot = 0;
                foreach ($rep as $v) {
                    $tot += (int) $v;
                }
                $acq = (int) ($rep['ACQ'] ?? 0);
                $byCode[$key][] = [
                    'g' => $gi,
                    'label' => $g['label'],
                    'name' => $name,
                    'fullCode' => $code,
                    'effectif' => (int) $ue['stats']['effectif'],
                    'admis' => (int) $ue['stats']['acquis'],
                    'def' => (int) $ue['stats']['def'],
                    'mean' => $ue['stats']['mean'] ?? null,
                    'median' => $ue['stats']['median'] ?? null,
                    'std' => $ue['stats']['std'] ?? null,
                    'acqPct' => $tot > 0 ? (int) round($acq / $tot * 100) : null,
                    'hist' => $ue['hist'] ?? [],
                    'exams' => $ue['exams'] ?? [],   
                    'notes' => $ue['ueNotes'] ?? [],
                ];
            }
        }

        $shared = [];
        $specific = [];
        foreach ($byCode as $key => $rows) {
            $names = [];
            $fullCodes = [];
            foreach ($rows as $r) {
                if ($r['name'] !== '' && !in_array($r['name'], $names, true)) {
                    $names[] = $r['name'];
                }
                if ($r['fullCode'] !== '' && !in_array($r['fullCode'], $fullCodes, true)) {
                    $fullCodes[] = $r['fullCode'];
                }
            }
            $name = $names[0] ?? '';

            if (count($rows) >= 2) {
                // Stats combinées exactes : on pool les notes brutes de tous les parcours
                $pooled = [];
                $totalEff = 0;
                $totalAdmis = 0;
                foreach ($rows as $r) {
                    $pooled = array_merge($pooled, $r['notes']);
                    $totalEff += $r['effectif'];
                    $totalAdmis += $r['admis'];
                }
                $combined = StatsCalculator::describe($pooled);
                $combined['effectif'] = $totalEff;
                $combined['admis'] = $totalAdmis;
                $combined['acqPct'] = $totalEff > 0 ? (int) round($totalAdmis / $totalEff * 100) : null;
                $combined['hist'] = StatsCalculator::histogram($pooled);

                $shared[] = [
                    'key' => $key,
                    'code' => $key,
                    'name' => $name,
                    'names' => $names,
                    'fullCodes' => $fullCodes,
                    'ambiguous' => count($names) > 1,
                    'combined' => $combined,
                    'groups' => $rows,
                ];
            } else {
                $r = $rows[0];
                $specific[] = [
                    'key' => $key,
                    'code' => $key,
                    'name' => $name,
                    'fullCode' => $r['fullCode'],
                    'label' => $r['label'],
                    'g' => $r['g'],
                    'effectif' => $r['effectif'],
                    'admis' => $r['admis'],
                    'def' => $r['def'],
                    'mean' => $r['mean'],
                    'median' => $r['median'],
                    'std' => $r['std'],
                    'acqPct' => $r['acqPct'],
                    'hist' => $r['hist'],
                    'exams' => $r['exams'] ?? [],                    
                ];
            }
        }

        $absences = [];
        $borderline = [];
        foreach ($groups as $gi => $g) {
            foreach (($g['analysis']['absences'] ?? []) as $r) {
                $r['g'] = $gi;
                $r['label'] = $g['label'];
                $absences[] = $r;
            }
            foreach (($g['analysis']['borderline'] ?? []) as $r) {
                $r['g'] = $gi;
                $r['label'] = $g['label'];
                $borderline[] = $r;
            }
        }

        $semBorderline = [];
        $anomalies = [];
        foreach ($groups as $gi => $g) {
            foreach (($g['analysis']['semBorderline'] ?? []) as $r) {
                $r['g'] = $gi;
                $r['label'] = $g['label'];
                $semBorderline[] = $r;
            }
            foreach (($g['analysis']['anomalies'] ?? []) as $a) {
                $a['g'] = $gi;
                $a['label'] = $g['label'];
                $anomalies[] = $a;
            }
        }
        $anomalyCounts = ['ue_note_vide' => 0, 'ue_single_note' => 0, 'sem_def_incoherent' => 0];
        foreach ($anomalies as $a) {
            if (isset($anomalyCounts[$a['type']])) {
                $anomalyCounts[$a['type']]++;
            }
        }

        $redoublants = null;
        $anyRed = false;
        foreach ($groups as $g) {
            if (isset($g['analysis']['redoublants'])) {
                $anyRed = true;
                break;
            }
        }
        if ($anyRed) {
            $requested = 0;
            $matched = 0;
            $byGroup = [];
            foreach ($groups as $gi => $g) {
                $R = $g['analysis']['redoublants'] ?? null;
                if ($R === null) {
                    continue;
                }
                $requested = (int) $R['requested'];
                $matched += (int) $R['matched'];
                $k = $R['kpis'] ?? [];
                $rep = $k['repartition'] ?? [];
                $n = (int) ($k['effectif'] ?? 0);
                $byGroup[] = [
                    'g' => $gi,
                    'label' => $g['label'],
                    'matched' => (int) $R['matched'],
                    'ADM' => (int) ($rep['ADM'] ?? 0),
                    'AJ' => (int) ($rep['AJ'] ?? 0),
                    'DEF' => (int) ($rep['DEF'] ?? 0),
                    'tauxAdm' => $n > 0 ? round($rep['ADM'] / $n * 100, 1) : 0.0,
                    'mean' => $k['semNote']['mean'] ?? null,
                    'median' => $k['semNote']['median'] ?? null,
                    'std' => $k['semNote']['std'] ?? null,
                ];
            }
            $redoublants = ['requested' => $requested, 'matched' => $matched, 'byGroup' => $byGroup];
        }

        return [
            'kpis' => $kpis,
            'byParcours' => $byParcours,
            'sharedSubjects' => $shared,
            'specificSubjects' => $specific,
            'absences' => $absences,
            'borderline' => $borderline,
            'redoublants' => $redoublants,
            'matchMode' => $matchMode,
            'semBorderline' => $semBorderline,
            'anomalies' => $anomalies,
            'anomalyCounts' => $anomalyCounts,
        ];
    }

    private static function matchKey(string $code, string $name, string $mode): string
    {
        $code = strtoupper(trim($code));
        switch ($mode) {
            case self::MATCH_CODE2:
                return $code !== '' ? substr($code, -2) : '';
            case self::MATCH_NAME:
                return self::normalizeName($name);
            case self::MATCH_FULL:
                return $code;
            case self::MATCH_CODE4:
            default:
                return $code !== '' ? substr($code, -4) : '';
        }
    }

    private static function normalizeName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = strtr($name, [
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'à' => 'a', 'â' => 'a', 'ù' => 'u', 'ü' => 'u',
            'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ç' => 'c',
        ]);

        return (string) preg_replace('/\s+/', ' ', $name);
    }
}
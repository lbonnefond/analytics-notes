# Analyse des relevés de notes (APO) — app web

Analyse de relevés de notes Licence (fichiers APO_*.xlsx) : KPIs du semestre,
statistiques par UE et par épreuve, listes « jury » (absences, notes 9,95–10),
exports CSV / ZIP.

## Installation locale
1. Prérequis : PHP >= 8.0 + Composer
2. `composer install`
3. `php -S localhost:8000 -t public`
4. Ouvrir http://localhost:8000 → déposer un fichier APO_*.xlsx

## Vérifier la fidélité du portage
Comparer la table « Statistiques par UE » de la page avec le fichier
`_stats_par_UE.xlsx` produit par le script Python sur le même fichier
(même effectif, mêmes moyennes/médianes/écart-types), et les KPIs avec
`_stats_global.xlsx`.

## Déploiement shared host
- Docroot = `public/` (le dossier `storage/` reste hors du docroot, écrit par PHP).
- `public/.user.ini` ajuste les limites (à caler sur les limites du hébergeur).
- Aucune BDD : traitement en mémoire, accès via lien token aléatoire (24 h).
- Le xlsx source est supprimé après traitement ; l'analyse JSON (noms, notes)
  reste 24 h dans storage/ puis est nettoyée au prochain upload.
- Aucune authentification en phase 1 : si les données sont sensibles, prévoir
  un accès protégé en phase 4 avant de mettre en ligne.

## État
Phase 1 (MVP) : 1 fichier → analyse. La page est en v1 « validation » ;
la version interactive (Chart.js, filtres, fiche étudiant) suit.
<?php
/* ============================================================
   CYAM Yerres — Rattrapage ponctuel : adherents.csv → Google Sheets
   ------------------------------------------------------------
   À utiliser UNE SEULE FOIS après avoir configuré SHEETS_WEBHOOK_URL
   / SHEETS_TOKEN dans config.php, pour renvoyer vers la Sheet les
   adhésions déjà enregistrées dans adherents.csv avant la mise en
   service de la synchronisation en direct.

   Usage : ouvrir dans le navigateur
     https://cyamyerres.fr/helloasso/backfill-sheets.php?token=VOTRE_NOTIFY_TOKEN

   Sans risque à relancer plusieurs fois : l'anti-doublon du script
   Google (référence + adhérent + horaire) empêche les doublons.
   Fichier à SUPPRIMER du serveur une fois le rattrapage terminé.
   ============================================================ */

require __DIR__ . '/config.php';
require __DIR__ . '/commun.php';
set_time_limit(180);
header('Content-Type: text/plain; charset=utf-8');

$NOTIFY_TOKEN = defined('NOTIFY_TOKEN') ? NOTIFY_TOKEN : '';
if ($NOTIFY_TOKEN !== '' && ($_GET['token'] ?? '') !== $NOTIFY_TOKEN) {
    http_response_code(403);
    echo "Jeton invalide.\n";
    exit;
}
if (!defined('SHEETS_WEBHOOK_URL') || SHEETS_WEBHOOK_URL === '') {
    echo "SHEETS_WEBHOOK_URL n'est pas configurée dans config.php — rien à faire.\n";
    exit;
}

$csvFile = __DIR__ . '/adherents.csv';
if (!is_file($csvFile)) { echo "adherents.csv introuvable.\n"; exit; }

/* ---------- lecture du CSV (mêmes colonnes que csv_headers()) ---------- */
$fh = fopen($csvFile, 'r');
// retire le BOM UTF-8 éventuel avant de parser la 1re ligne
$bom = fread($fh, 3);
if ($bom !== "\xEF\xBB\xBF") rewind($fh);
$headers = fgetcsv($fh, 0, ';');
$idx = array_flip($headers);

function col($row, $idx, $name) {
    return isset($idx[$name], $row[$idx[$name]]) ? $row[$idx[$name]] : '';
}

/* Découpe "Prénom Nom (né·e le JJ/MM/AAAA) ; Prénom2 Nom2 (né·e le ...)"
   en une liste de {texte, naissance} pour retrouver une date de
   naissance par préfixe (même principe que trouver_naissance()). */
function parse_adherents($txt) {
    $out = [];
    foreach (explode(' ; ', $txt) as $part) {
        $part = trim($part);
        if ($part === '') continue;
        if (preg_match('/^(.*) \(né·e le (\d{2}\/\d{2}\/\d{4})\)$/u', $part, $m)) {
            $out[] = ['texte' => $m[1], 'naissance' => $m[2]];
        }
    }
    return $out;
}

/* Découpe "Discipline / Catégorie[ / Horaire] — pour Adhérent — 123,00 € (-15%)"
   en une liste de cours structurés. */
function parse_cours($txt) {
    $out = [];
    foreach (explode(' ; ', $txt) as $part) {
        $part = trim($part);
        if ($part === '') continue;
        if (!preg_match('/^(.*) — pour (.*) — ([\d.,]+)\s?€(?:\s*\(-(\d+)%\))?$/u', $part, $m)) continue;
        $segs = explode(' / ', $m[1]);
        $discipline = array_shift($segs);
        $categorie  = $segs ? array_shift($segs) : '';
        $horaire    = $segs ? implode(' / ', $segs) : '';
        $out[] = [
            'discipline' => trim($discipline),
            'categorie'  => trim($categorie),
            'horaire'    => trim($horaire),
            'adherent'   => trim($m[2]),
            'montant'    => trim($m[3]),
            'remise'     => isset($m[4]) ? ('-' . $m[4] . '%') : '',
        ];
    }
    return $out;
}

$nLignes = 0; $nCours = 0; $nErreurs = 0;
while (($row = fgetcsv($fh, 0, ';')) !== false) {
    if (count($row) < 2) continue;
    $nLignes++;

    $adherentsList = parse_adherents(col($row, $idx, 'Adhérents'));
    $coursList     = parse_cours(col($row, $idx, 'Cours'));
    $adresseComplete = trim(col($row, $idx, 'Adresse') . ' ' . col($row, $idx, 'Code postal') . ' ' . col($row, $idx, 'Ville'));

    foreach ($coursList as $c) {
        $naissance = '';
        foreach ($adherentsList as $a) {
            if (strpos($a['texte'], $c['adherent']) === 0) { $naissance = $a['naissance']; break; }
        }
        $res = sheets_push_ligne($c['discipline'], [
            'date'      => col($row, $idx, 'Date réception'),
            'statut'    => col($row, $idx, 'Statut'),
            'paiement'  => col($row, $idx, 'Mode de paiement'),
            'reference' => col($row, $idx, 'Référence'),
            'saison'    => col($row, $idx, 'Saison'),
            'adherent'  => $c['adherent'],
            'naissance' => $naissance,
            'categorie' => $c['categorie'],
            'horaire'   => $c['horaire'],
            'montant'   => $c['montant'],
            'remise'    => $c['remise'],
            'email'     => col($row, $idx, 'E-mail'),
            'tel'       => col($row, $idx, 'Téléphone'),
            'adresse'   => $adresseComplete,
        ]);
        $nCours++;
        $ok = (strpos($res, 'HTTP 200') === 0);
        if (!$ok) $nErreurs++;
        echo ($ok ? '[OK]  ' : '[ERR] ') . $c['discipline'] . ' — ' . $c['adherent'] . ' — ' . $res . "\n";
        @ob_flush(); @flush();
    }
}
fclose($fh);

echo "\n--- Terminé : $nLignes lignes CSV, $nCours cours traités, $nErreurs erreur(s). ---\n";

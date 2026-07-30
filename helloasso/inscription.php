<?php
/* ============================================================
   CYAM Yerres — Inscription réglée sur place (chèque / espèces)
   ------------------------------------------------------------
   Ne passe PAS par HelloAsso, pas de facture. Recalcule le
   montant (−15% sauf la plus chère), enregistre dans le même
   adherents.csv (statut « à régler »), prévient le club et
   envoie à l'internaute un e-mail « inscription enregistrée,
   définitive après règlement ».
   ============================================================ */

require __DIR__ . '/config.php';
require __DIR__ . '/commun.php';
header('Content-Type: application/json; charset=utf-8');

$CLUB_EMAIL  = defined('CLUB_EMAIL')  ? CLUB_EMAIL  : 'contact@cyamyerres.fr';
$NOTIFY_FROM = defined('NOTIFY_FROM') ? NOTIFY_FROM : 'contact@cyamyerres.fr';
$CSV_FILE    = __DIR__ . '/adherents.csv';
$LOG_FILE    = __DIR__ . '/notify.log';

function ilog($m) { global $LOG_FILE; @file_put_contents($LOG_FILE, date('Y-m-d H:i:s') . '  INSCR ' . $m . "\n", FILE_APPEND | LOCK_EX); }
function ifail($m, $c = 400) { http_response_code($c); echo json_encode(['error' => $m], JSON_UNESCAPED_UNICODE); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') ifail('Méthode non autorisée.', 405);

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) ifail('Requête invalide.');

$lignes    = is_array($in['lignes']    ?? null) ? $in['lignes']    : [];
$contact   = is_array($in['contact']   ?? null) ? $in['contact']   : [];
$adherents = is_array($in['adherents'] ?? null) ? $in['adherents'] : [];
if (count($lignes) === 0) ifail('Aucun cours sélectionné.');

/* ---------- recalcul serveur (source de vérité) ---------- */
$r = recompute_panier($lignes, __DIR__ . '/../tarifs.json');
if ($r === null) ifail('Grille tarifaire indisponible.', 500);
if (isset($r['error'])) ifail($r['error']);
$items  = $r['items'];
$total  = $r['total'];
$saison = $r['saison'];
if ($total <= 0) ifail('Montant nul.');

/* ---------- coordonnées ---------- */
$pprenom = $adherents[0]['prenom'] ?? '';
$pnom    = $adherents[0]['nom'] ?? '';
$email   = $contact['email'] ?? '';
$tel     = $contact['tel'] ?? '';
$adresse = $contact['adresse'] ?? '';
$cp      = $contact['cp'] ?? '';
$ville   = $contact['ville'] ?? '';

/* ---------- contact du foyer obligatoire ---------- */
$telNum = preg_replace('/\s+/', '', (string)$tel);
if (trim($adresse) === '') ifail("Merci d'indiquer l'adresse du foyer.");
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) ifail('Adresse e-mail invalide.');
if (!preg_match('/^0[1-9]\d{8}$/', $telNum)) ifail('Numéro de téléphone invalide (format attendu : 06 07 08 09 10).');

/* ---------- référence d'inscription ---------- */
try { $rand = strtoupper(bin2hex(random_bytes(2))); } catch (Exception $e) { $rand = strtoupper(dechex(mt_rand(0, 65535))); }
$ref = 'INS-' . date('ymd-His') . '-' . substr($rand, 0, 4);

/* ---------- textes récap ---------- */
$txtAdh = [];
foreach ($adherents as $a) {
    $n  = trim(($a['prenom'] ?? '') . ' ' . ($a['nom'] ?? ''));
    $ne = !empty($a['naissance']) ? ' (né·e le ' . frdate($a['naissance']) . ')' : '';
    $txtAdh[] = $n . $ne;
}
$txtAdh = implode(' ; ', $txtAdh);

$txtCours = [];
$listeCours = [];
foreach ($items as $it) {
    $l = ($it['disc']) . ' / ' . ($it['cat']);
    if (!empty($it['horaire']))  $l .= ' / ' . $it['horaire'];
    if (!empty($it['adherent'])) $l .= ' — pour ' . $it['adherent'];
    $l .= ' — ' . eur($it['prix']) . ' €';
    if (!empty($it['taux'])) $l .= ' (-' . round($it['taux'] * 100) . '%)';
    $txtCours[]   = $l;
    $listeCours[] = $l;
}
$txtCours = implode(' ; ', $txtCours);

/* ---------- écriture CSV ---------- */
$row = csv_ligne([
    'statut'        => 'À régler (chèque/espèces)',
    'mode_paiement' => 'Chèque ou espèces',
    'reference'     => $ref,
    'saison'        => $saison,
    'pprenom'       => $pprenom,
    'pnom'          => $pnom,
    'email'         => $email,
    'tel'           => $tel,
    'adresse'       => $adresse,
    'cp'            => $cp,
    'ville'         => $ville,
    'total_cents'   => $total,
    'ech'           => [],   // pas d'échéancier en ligne
    'adherents_txt' => $txtAdh,
    'cours_txt'     => $txtCours,
]);
if (!csv_append($CSV_FILE, $row)) ifail('Enregistrement impossible (droits du dossier ?).', 500);
ilog("CSV ajouté (chèque/espèces, réf $ref, " . eur($total) . " €)");

/* ---------- e-mail au club ---------- */
$sujetClub = 'Inscription à régler (chèque/espèces) — ' . trim($pprenom . ' ' . $pnom) . ' — ' . eur($total) . ' €';
$cc  = "Une inscription vient d'être enregistrée avec règlement SUR PLACE (chèque ou espèces).\n";
$cc .= "⚠️ Elle n'est PAS encore payée — à encaisser auprès de l'adhérent.\n\n";
$cc .= "Référence     : $ref\n";
$cc .= "Saison        : $saison\n";
$cc .= "Contact       : " . trim($pprenom . ' ' . $pnom) . "\n";
$cc .= "E-mail        : $email\n";
$cc .= "Téléphone     : $tel\n";
$cc .= "Adresse       : " . trim($adresse . ' ' . $cp . ' ' . $ville) . "\n";
$cc .= "Montant dû    : " . eur($total) . " €\n";
$cc .= "\nAdhérent·e·s :\n";
foreach ($adherents as $a) {
    $n  = trim(($a['prenom'] ?? '') . ' ' . ($a['nom'] ?? ''));
    $ne = !empty($a['naissance']) ? ' (né·e le ' . frdate($a['naissance']) . ')' : '';
    $cc .= "  • $n$ne\n";
}
$cc .= "\nCours :\n";
foreach ($listeCours as $l) $cc .= "  • $l\n";
$cc .= "\n— Message automatique du site cyamyerres.fr\n";

$hc  = 'From: CYAM Yerres <' . $NOTIFY_FROM . ">\r\n";
if (filter_var($email, FILTER_VALIDATE_EMAIL)) $hc .= 'Reply-To: ' . $email . "\r\n";
$hc .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
@mail($CLUB_EMAIL, '=?UTF-8?B?' . base64_encode($sujetClub) . '?=', $cc, $hc);

/* ---------- e-mail à l'internaute ---------- */
$envoiClient = false;
if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $sujet = "Votre inscription au CYAM Yerres — à confirmer par le règlement";
    $ci  = "Bonjour " . trim($pprenom . ' ' . $pnom) . ",\n\n";
    $ci .= "Nous avons bien enregistré votre demande d'inscription au Club Yerrois d'Arts Martiaux pour la saison " . $saison . ".\n\n";
    $ci .= "⚠️ Votre inscription ne deviendra DÉFINITIVE qu'une fois le règlement effectué.\n\n";
    $ci .= "Montant à régler : " . eur($total) . " €\n";
    $ci .= "À remettre au professeur lors du prochain cours, par chèque (à l'ordre du CYAM) ou en espèces.\n\n";
    $ci .= "Détail :\n";
    foreach ($listeCours as $l) $ci .= "  • $l\n";
    $ci .= "\nRéférence de votre inscription : " . $ref . "\n\n";
    $ci .= "Sportivement,\nLe CYAM Yerres\ncontact@cyamyerres.fr\n";

    $hi  = 'From: CYAM Yerres <' . $NOTIFY_FROM . ">\r\n";
    $hi .= 'Reply-To: ' . $NOTIFY_FROM . "\r\n";
    $hi .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
    $envoiClient = @mail($email, '=?UTF-8?B?' . base64_encode($sujet) . '?=', $ci, $hi);
}
ilog('E-mail client ' . ($envoiClient ? 'envoyé à ' . $email : 'non envoyé'));

echo json_encode(['ok' => true, 'reference' => $ref, 'total_cents' => $total], JSON_UNESCAPED_UNICODE);

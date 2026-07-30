<?php
/* ============================================================
   CYAM Yerres — Réception des notifications HelloAsso (webhook)
   ------------------------------------------------------------
   HelloAsso appelle ce fichier à chaque événement. On récolte
   l'adhésion sur « Order » OU « Payment » (anti-doublon par
   n° de commande), on ajoute une ligne à adherents.csv et on
   envoie un e-mail récap au club.
   Un journal notify.log trace chaque appel (débogage).
   ============================================================ */

require __DIR__ . '/config.php';

$CLUB_EMAIL   = defined('CLUB_EMAIL')   ? CLUB_EMAIL   : 'contact@cyamyerres.fr';
$NOTIFY_FROM  = defined('NOTIFY_FROM')  ? NOTIFY_FROM  : 'contact@cyamyerres.fr';
$NOTIFY_TOKEN = defined('NOTIFY_TOKEN') ? NOTIFY_TOKEN : '';
$CSV_FILE     = __DIR__ . '/adherents.csv';
$LOG_FILE     = __DIR__ . '/notify.log';

/* ---------- journal ---------- */
function jlog($msg) {
    global $LOG_FILE;
    @file_put_contents($LOG_FILE, date('Y-m-d H:i:s') . '  ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

/* ---------- réponses ---------- */
function ok($msg = 'OK')  { jlog('-> 200 ' . $msg); http_response_code(200); echo $msg; exit; }
function ko($msg, $code) { jlog('-> ' . $code . ' ' . $msg); http_response_code($code); echo $msg; exit; }

$method = $_SERVER['REQUEST_METHOD'] ?? '?';
jlog("APPEL $method  token=" . (($_GET['token'] ?? '') !== '' ? 'fourni' : 'absent'));

/* ---------- filtre d'accès (jeton dans l'URL) ---------- */
if ($NOTIFY_TOKEN !== '' && ($_GET['token'] ?? '') !== $NOTIFY_TOKEN)
    ko('Jeton invalide.', 403);

/* Simple GET (test navigateur) */
if ($method !== 'POST')
    ok('notify.php prêt (en attente des notifications HelloAsso).');

/* ---------- lecture du corps ---------- */
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) { jlog('Corps non JSON: ' . substr($raw, 0, 200)); ok('Corps non JSON, ignoré.'); }

$eventType = $body['eventType'] ?? '';
$data      = is_array($body['data'] ?? null) ? $body['data'] : [];
$meta      = $body['metadata'] ?? ($data['metadata'] ?? []);
if (!is_array($meta)) $meta = [];
jlog("eventType=$eventType  metadata=" . (empty($meta) ? 'vide' : 'présente')
     . '  adherents=' . (empty($meta['adherents']) ? 'non' : count($meta['adherents'])));

/* On récolte sur Order ou Payment (anti-doublon plus bas) */
if (!in_array($eventType, ['Order', 'Payment'], true))
    ok("Événement « $eventType » ignoré.");

/* Filtre : seulement NOS adhésions (signature = metadata.adherents) */
if (empty($meta['adherents']))
    ok('Notification hors adhésion CYAM, ignorée.');

/* ---------- helpers ---------- */
function eur($cents) { return number_format(((int)$cents) / 100, 2, ',', ' '); }
function frdate($iso) {
    $iso = substr((string)$iso, 0, 10);
    $d = DateTime::createFromFormat('Y-m-d', $iso);
    return $d ? $d->format('d/m/Y') : $iso;
}

/* ---------- infos commande / payeur ---------- */
$orderId = (string)($data['order']['id'] ?? $data['id'] ?? '');
$payer   = is_array($data['payer'] ?? null) ? $data['payer'] : [];
$contact = is_array($meta['contact'] ?? null) ? $meta['contact'] : [];
$adhs    = $meta['adherents'];

$payPrenom = $payer['firstName'] ?? ($adhs[0]['prenom'] ?? '');
$payNom    = $payer['lastName']  ?? ($adhs[0]['nom'] ?? '');
$email     = $contact['email']   ?? ($payer['email'] ?? '');
$tel       = $contact['tel']     ?? '';
$adresse   = $contact['adresse'] ?? '';
$cp        = $contact['cp']      ?? '';
$ville     = $contact['ville']   ?? '';
$saison    = $meta['saison']     ?? '';
$mode      = ($meta['mode'] ?? '1x') === '3x' ? '3 fois' : '1 fois';
$total     = $meta['total_cents'] ?? 0;

/* ---------- échéances (avec repli si absentes) ---------- */
$ech = isset($meta['echeances']) && is_array($meta['echeances']) ? array_values($meta['echeances']) : [];
if (empty($ech)) $ech = [['date' => date('Y-m-d'), 'montant_cents' => $total]];
$ecol = ['', '', '', '', '', ''];
for ($i = 0; $i < 3 && $i < count($ech); $i++) {
    $ecol[$i * 2]     = frdate($ech[$i]['date'] ?? '');
    $ecol[$i * 2 + 1] = eur($ech[$i]['montant_cents'] ?? 0);
}

/* ---------- textes « Adhérents » et « Cours » ---------- */
$txtAdh = [];
foreach ($adhs as $a) {
    $nom = trim(($a['prenom'] ?? '') . ' ' . ($a['nom'] ?? ''));
    $ne  = !empty($a['naissance']) ? ' (né·e le ' . frdate($a['naissance']) . ')' : '';
    $txtAdh[] = $nom . $ne;
}
$txtAdh = implode(' ; ', $txtAdh);

$txtCours = [];
foreach (($meta['cours'] ?? []) as $c) {
    $ligne = ($c['discipline'] ?? '') . ' / ' . ($c['categorie'] ?? '');
    if (!empty($c['horaire']))  $ligne .= ' / ' . $c['horaire'];
    if (!empty($c['adherent'])) $ligne .= ' — pour ' . $c['adherent'];
    $ligne .= ' — ' . eur($c['prix_cents'] ?? 0) . ' €';
    if (!empty($c['remise_pct'])) $ligne .= ' (-' . $c['remise_pct'] . '%)';
    $txtCours[] = $ligne;
}
$txtCours = implode(' ; ', $txtCours);

/* ---------- 1) écriture CSV (une ligne par adhésion) ---------- */
$headers = ['Date réception', 'N° commande', 'Saison', 'Payeur prénom', 'Payeur nom',
    'E-mail', 'Téléphone', 'Adresse', 'Code postal', 'Ville', 'Paiement', 'Montant total (€)',
    'Éch. 1 date', 'Éch. 1 (€)', 'Éch. 2 date', 'Éch. 2 (€)', 'Éch. 3 date', 'Éch. 3 (€)',
    'Adhérents', 'Cours'];

$row = [date('d/m/Y H:i'), $orderId, $saison, $payPrenom, $payNom, $email, $tel,
    $adresse, $cp, $ville, $mode, eur($total),
    $ecol[0], $ecol[1], $ecol[2], $ecol[3], $ecol[4], $ecol[5], $txtAdh, $txtCours];

/* anti-doublon : commande déjà présente ? (id encadré par ';') */
if ($orderId !== '' && is_file($CSV_FILE)) {
    $deja = file_get_contents($CSV_FILE);
    if ($deja !== false && strpos($deja, ';' . $orderId . ';') !== false)
        ok('Commande ' . $orderId . ' déjà enregistrée.');
}

$isNew = !is_file($CSV_FILE);
$fh = @fopen($CSV_FILE, 'a');
if ($fh === false) ko('Écriture CSV impossible (droits du dossier ?).', 500);
if (flock($fh, LOCK_EX)) {
    if ($isNew) { fwrite($fh, "\xEF\xBB\xBF"); fputcsv($fh, $headers, ';'); }
    fputcsv($fh, $row, ';');
    fflush($fh);
    flock($fh, LOCK_UN);
}
fclose($fh);
jlog("CSV: ligne ajoutée (commande $orderId, " . eur($total) . " €)");

/* ---------- 2) e-mail récapitulatif au club ---------- */
$sujet = 'Nouvelle adhésion CYAM — ' . trim($payPrenom . ' ' . $payNom) . ' — ' . eur($total) . ' € (' . $mode . ')';

$corps  = "Une nouvelle adhésion vient d'être réglée sur HelloAsso.\n\n";
$corps .= "Saison        : $saison\n";
$corps .= "Payeur        : " . trim($payPrenom . ' ' . $payNom) . "\n";
$corps .= "E-mail        : $email\n";
$corps .= "Téléphone     : $tel\n";
$corps .= "Adresse       : " . trim($adresse . ' ' . $cp . ' ' . $ville) . "\n";
$corps .= "N° commande   : $orderId\n";
$corps .= "Montant total : " . eur($total) . " €\n";
$corps .= "Paiement      : en $mode\n";
$corps .= "\nÉchéancier :\n";
foreach ($ech as $e) {
    $corps .= "  • " . frdate($e['date'] ?? '') . " : " . eur($e['montant_cents'] ?? 0) . " €\n";
}
$corps .= "\nAdhérent·e·s :\n";
foreach ($adhs as $a) {
    $nom = trim(($a['prenom'] ?? '') . ' ' . ($a['nom'] ?? ''));
    $ne  = !empty($a['naissance']) ? ' (né·e le ' . frdate($a['naissance']) . ')' : '';
    $corps .= "  • $nom$ne\n";
}
$corps .= "\nCours :\n";
foreach (($meta['cours'] ?? []) as $c) {
    $l = '  • ' . ($c['discipline'] ?? '') . ' / ' . ($c['categorie'] ?? '');
    if (!empty($c['horaire']))  $l .= ' / ' . $c['horaire'];
    if (!empty($c['adherent'])) $l .= ' — pour ' . $c['adherent'];
    $l .= ' — ' . eur($c['prix_cents'] ?? 0) . ' €';
    if (!empty($c['remise_pct'])) $l .= ' (-' . $c['remise_pct'] . '%)';
    $corps .= $l . "\n";
}
$corps .= "\n— Message automatique du site cyamyerres.fr\n";

$mh  = 'From: CYAM Yerres <' . $NOTIFY_FROM . ">\r\n";
if (filter_var($email, FILTER_VALIDATE_EMAIL)) $mh .= 'Reply-To: ' . $email . "\r\n";
$mh .= "MIME-Version: 1.0\r\n";
$mh .= "Content-Type: text/plain; charset=UTF-8\r\n";
$mh .= "Content-Transfer-Encoding: 8bit\r\n";
$sujetEnc = '=?UTF-8?B?' . base64_encode($sujet) . '?=';

$sent = @mail($CLUB_EMAIL, $sujetEnc, $corps, $mh);
jlog('E-mail ' . ($sent ? 'envoyé' : 'ÉCHEC mail()') . ' à ' . $CLUB_EMAIL);

ok('Adhésion enregistrée.');

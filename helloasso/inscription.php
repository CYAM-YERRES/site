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

/* ---------- synchronisation Google Sheets (une ligne par cours) ---------- */
foreach ($items as $it) {
    $sres = sheets_push_ligne($it['disc'] ?? 'Divers', [
        'date'       => date('d/m/Y H:i'),
        'statut'     => 'À régler (chèque/espèces)',
        'paiement'   => 'Chèque ou espèces',
        'reference'  => $ref,
        'saison'     => $saison,
        'adherent'   => $it['adherent'] ?? '',
        'naissance'  => trouver_naissance($adherents, $it['adherent'] ?? ''),
        'categorie'  => $it['cat'] ?? '',
        'horaire'    => $it['horaire'] ?? '',
        'montant'    => eur($it['prix'] ?? 0),
        'remise'     => !empty($it['taux']) ? '-' . round($it['taux'] * 100) . '%' : '',
        'email'      => $email,
        'tel'        => $tel,
        'adresse'    => trim($adresse . ' ' . $cp . ' ' . $ville),
    ]);
    ilog('Sheets [' . ($it['disc'] ?? '?') . ']: ' . $sres);
}

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

    // Version HTML (soignée, avec logo)
    $esc = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $coursHtml = '';
    foreach ($listeCours as $l) $coursHtml .= '<li style="margin:4px 0">' . $esc($l) . '</li>';
    $html =
        '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
      . '<body style="margin:0;background:#f4f1ea;font-family:Arial,Helvetica,sans-serif;color:#1c1a15">'
      . '<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden">'
      . '<div style="background:#141210;padding:22px 24px;text-align:center">'
      . '<img src="https://cyamyerres.fr/logo-cyam.png" alt="CYAM" width="64" height="64" style="display:inline-block;border-radius:50%">'
      . '<div style="color:#f4eee4;font-size:18px;font-weight:bold;margin-top:8px;letter-spacing:.03em">Club Yerrois d\'Arts Martiaux</div></div>'
      . '<div style="padding:26px 26px 10px">'
      . '<p style="font-size:15px">Bonjour <strong>' . $esc(trim($pprenom . ' ' . $pnom)) . '</strong>,</p>'
      . '<p style="font-size:15px">Nous avons bien enregistré votre demande d\'inscription au CYAM pour la saison <strong>' . $esc($saison) . '</strong>.</p>'
      . '<div style="background:#fbf3e2;border:1px solid #c9a24b;border-radius:10px;padding:14px 16px;margin:18px 0">'
      . '<div style="font-weight:bold;color:#8a6d1f">⚠️ Inscription à confirmer</div>'
      . '<div style="margin-top:5px;font-size:14px">Votre inscription deviendra <strong>définitive une fois le règlement effectué</strong>.</div></div>'
      . '<p style="font-size:15px;margin:0 0 4px">Montant à régler&nbsp;: <strong style="font-size:22px;color:#E4231F">' . eur($total) . ' €</strong></p>'
      . '<p style="font-size:14px;color:#57524a">À remettre au professeur lors du prochain cours, par <strong>chèque</strong> (à l\'ordre du CYAM) ou en <strong>espèces</strong>.</p>'
      . '<p style="font-size:14px;margin:16px 0 4px"><strong>Détail de l\'inscription&nbsp;:</strong></p>'
      . '<ul style="margin:0 0 14px;padding-left:20px;font-size:14px;color:#57524a">' . $coursHtml . '</ul>'
      . '<p style="font-size:12px;color:#8a857c">Référence&nbsp;: ' . $esc($ref) . '</p>'
      . '<p style="font-size:15px;margin-top:22px">Sportivement,<br>Le CYAM Yerres 🥋</p></div>'
      . '<div style="background:#141210;color:#a79e92;font-size:12px;padding:16px 24px;text-align:center;line-height:1.5">'
      . 'CYAM — 13 rue Lucien Mânes, 91330 Yerres<br>contact@cyamyerres.fr · cyamyerres.fr</div>'
      . '</div></body></html>';

    $envoiClient = f_mail_html($email, $NOTIFY_FROM, $sujet, $ci, $html);
}
ilog('E-mail client ' . ($envoiClient ? 'envoyé à ' . $email : 'non envoyé'));

echo json_encode(['ok' => true, 'reference' => $ref, 'total_cents' => $total], JSON_UNESCAPED_UNICODE);

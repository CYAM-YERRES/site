<?php
/* ============================================================
   CYAM Yerres — Pont de paiement HelloAsso (Checkout)
   Reçoit le panier du formulaire, RECALCULE le montant côté
   serveur (sécurité), crée un checkout HelloAsso (1× ou 3×)
   et renvoie l'URL de paiement.
   ============================================================ */
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

function fail($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

/* Appel HTTP générique via cURL */
function http_call($url, $method, $headers, $body) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 25,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $res = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) return [0, null, $err];
    return [$status, $res, ''];
}

/* ---------- garde-fous ---------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Méthode non autorisée.', 405);
if (HA_CLIENT_ID === '' || HA_CLIENT_SECRET === '')
    fail('Le paiement en ligne n\'est pas encore configuré (clés HelloAsso manquantes).', 503);

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) fail('Requête invalide.');

$lignes    = $in['lignes']    ?? [];
$mode      = ((int)($in['mode'] ?? 1) === 3) ? 3 : 1;
$contact   = is_array($in['contact']   ?? null) ? $in['contact']   : [];
$adherents = is_array($in['adherents'] ?? null) ? $in['adherents'] : [];
if (!is_array($lignes) || count($lignes) === 0) fail('Aucun cours sélectionné.');

/* ---------- grille tarifaire = source de vérité (serveur) ---------- */
$tarifs = json_decode(@file_get_contents(__DIR__ . '/../tarifs.json'), true);
if (!$tarifs || empty($tarifs['disciplines'])) fail('Grille tarifaire indisponible.', 500);

$parCode = [];
foreach ($tarifs['disciplines'] as $d)
    foreach ($d['categories'] as $c)
        foreach ($c['cours'] as $co)
            $parCode[$co['code']] = [
                'tarif'   => (int) round($co['tarif'] * 100), // centimes
                'disc'    => $d['nom'],
                'cat'     => $c['nom'],
                'horaire' => $co['horaire'],
            ];
$tauxRemise = (float) ($tarifs['remise']['taux'] ?? 0.15);
$saison     = (string) ($tarifs['saison'] ?? '');

/* ---------- recalcul des montants (jamais confiance au client) ---------- */
$items = [];
foreach ($lignes as $l) {
    $code = is_array($l) ? ($l['code'] ?? '') : '';
    if ($code === '' || !isset($parCode[$code])) fail('Cours inconnu : ' . $code);
    $items[] = $parCode[$code] + [
        'code'     => $code,
        'adherent' => substr((string)($l['adherent'] ?? ''), 0, 60),
    ];
}
// tri décroissant : la cotisation la plus chère reste au plein tarif
usort($items, function ($a, $b) { return $b['tarif'] - $a['tarif']; });
$total = 0; $premier = true;
foreach ($items as &$it) {
    $it['taux'] = $premier ? 0.0 : $tauxRemise;   // -15% sur toutes sauf la plus chère
    $it['prix'] = (int) round($it['tarif'] * (1 - $it['taux']));
    $total += $it['prix'];
    $premier = false;
}
unset($it);
if ($total <= 0) fail('Montant nul.');

/* ---------- échéancier 1× / 3× ---------- */
$initialAmount = $total;
$terms = [];
if ($mode === 3) {
    $part = intdiv($total, 3);
    $initialAmount = $total - 2 * $part;      // le reste sur le 1er prélèvement
    $jour = min((int) date('j'), 27);          // jamais après le 27
    $mk = function ($plusMois) use ($jour) {
        $dt = new DateTime('first day of this month');
        $dt->modify("+$plusMois month");
        $dt->setDate((int)$dt->format('Y'), (int)$dt->format('n'), $jour);
        $dt->setTime(12, 0, 0);
        return $dt->format('Y-m-d\TH:i:s');
    };
    $terms = [
        ['amount' => $part, 'date' => $mk(1)],  // mois +1
        ['amount' => $part, 'date' => $mk(2)],  // mois +2
    ];
}

/* ---------- payeur (facultatif) ---------- */
$payer = ['country' => 'FRA'];
if (!empty($contact['email']))        $payer['email']     = substr($contact['email'], 0, 255);
if (!empty($adherents[0]['prenom']))  $payer['firstName'] = substr($adherents[0]['prenom'], 0, 255);
if (!empty($adherents[0]['nom']))     $payer['lastName']  = substr($adherents[0]['nom'], 0, 255);
if (!empty($contact['adresse']))      $payer['address']   = substr($contact['adresse'], 0, 255);
if (!empty($contact['ville']))        $payer['city']      = substr($contact['ville'], 0, 255);
if (!empty($contact['cp']))           $payer['zipCode']   = substr($contact['cp'], 0, 20);

/* ---------- métadonnées (dossier du club) ---------- */
$metadata = [
    'saison'    => $saison,
    'mode'      => $mode . 'x',
    'adherents' => array_map(function ($a) {
        return ['prenom' => $a['prenom'] ?? '', 'nom' => $a['nom'] ?? '', 'naissance' => $a['naissance'] ?? ''];
    }, $adherents),
    'cours' => array_map(function ($it) {
        return ['code' => $it['code'], 'discipline' => $it['disc'], 'categorie' => $it['cat'],
                'adherent' => $it['adherent'], 'remise_pct' => (int) round($it['taux'] * 100), 'prix_cents' => $it['prix']];
    }, $items),
    'contact' => ['email' => $contact['email'] ?? '', 'tel' => $contact['tel'] ?? '', 'adresse' => $contact['adresse'] ?? ''],
];

/* ---------- 1) obtenir un access_token ---------- */
list($st, $res, $err) = http_call(HA_API_BASE . '/oauth2/token', 'POST',
    ['Content-Type: application/x-www-form-urlencoded'],
    http_build_query([
        'grant_type'    => 'client_credentials',
        'client_id'     => HA_CLIENT_ID,
        'client_secret' => HA_CLIENT_SECRET,
    ]));
if ($st !== 200) fail('Authentification HelloAsso échouée (' . $st . ').', 502);
$access = json_decode($res, true)['access_token'] ?? '';
if ($access === '') fail('Jeton HelloAsso manquant.', 502);

/* ---------- 2) créer le checkout ---------- */
$payload = [
    'totalAmount'      => $total,
    'initialAmount'    => $initialAmount,
    'itemName'         => 'Adhésion CYAM Yerres ' . $saison,
    'backUrl'          => SITE_URL . '/adhesion.html',
    'errorUrl'         => SITE_URL . '/adhesion.html?paiement=erreur',
    'returnUrl'        => SITE_URL . '/merci.html',
    'containsDonation' => false,
    'payer'            => $payer,
    'metadata'         => $metadata,
];
if ($mode === 3) $payload['terms'] = $terms;

list($st, $res, $err) = http_call(
    HA_API_BASE . '/v5/organizations/' . HA_ORG_SLUG . '/checkout-intents', 'POST',
    ['Authorization: Bearer ' . $access, 'Content-Type: application/json'],
    json_encode($payload, JSON_UNESCAPED_UNICODE));

if ($st < 200 || $st >= 300)
    fail('Création du paiement refusée par HelloAsso (' . $st . '). ' . substr((string)$res, 0, 400), 502);

$out = json_decode($res, true);
if (empty($out['redirectUrl'])) fail('Réponse HelloAsso inattendue.', 502);

echo json_encode(['redirectUrl' => $out['redirectUrl'], 'id' => $out['id'] ?? null], JSON_UNESCAPED_UNICODE);

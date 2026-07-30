<?php
/* ============================================================
   CYAM Yerres — Fonctions communes (paiement en ligne & sur place)
   ------------------------------------------------------------
   Partagé par notify.php (carte/HelloAsso) et inscription.php
   (chèque/espèces au club) : recalcul du panier, écriture CSV
   unifiée (mêmes colonnes pour les deux modes), helpers.
   ============================================================ */

if (!function_exists('eur')) {
    function eur($cents) { return number_format(((int)$cents) / 100, 2, ',', ' '); }
}
if (!function_exists('frdate')) {
    function frdate($iso) {
        $iso = substr((string)$iso, 0, 10);
        $d = DateTime::createFromFormat('Y-m-d', $iso);
        return $d ? $d->format('d/m/Y') : $iso;
    }
}

/* ---------- colonnes du fichier adherents.csv ---------- */
function csv_headers() {
    return ['Date réception', 'Statut', 'Mode de paiement', 'Référence', 'Saison',
        'Payeur prénom', 'Payeur nom', 'E-mail', 'Téléphone', 'Adresse', 'Code postal', 'Ville',
        'Montant total (€)', 'Éch. 1 date', 'Éch. 1 (€)', 'Éch. 2 date', 'Éch. 2 (€)',
        'Éch. 3 date', 'Éch. 3 (€)', 'Adhérents', 'Cours'];
}

/* Construit une ligne CSV (ordre = csv_headers()) depuis un tableau nommé. */
function csv_ligne($d) {
    $ecol = ['', '', '', '', '', ''];
    $ech = is_array($d['ech'] ?? null) ? array_values($d['ech']) : [];
    for ($i = 0; $i < 3 && $i < count($ech); $i++) {
        $ecol[$i * 2]     = frdate($ech[$i]['date'] ?? '');
        $ecol[$i * 2 + 1] = eur($ech[$i]['montant_cents'] ?? 0);
    }
    return [
        $d['date'] ?? date('d/m/Y H:i'),
        $d['statut'] ?? '',
        $d['mode_paiement'] ?? '',
        $d['reference'] ?? '',
        $d['saison'] ?? '',
        $d['pprenom'] ?? '',
        $d['pnom'] ?? '',
        $d['email'] ?? '',
        $d['tel'] ?? '',
        $d['adresse'] ?? '',
        $d['cp'] ?? '',
        $d['ville'] ?? '',
        eur($d['total_cents'] ?? 0),
        $ecol[0], $ecol[1], $ecol[2], $ecol[3], $ecol[4], $ecol[5],
        $d['adherents_txt'] ?? '',
        $d['cours_txt'] ?? '',
    ];
}

/* Ajoute une ligne au CSV (crée l'en-tête + BOM UTF-8 si nouveau). */
function csv_append($file, $row) {
    $isNew = !is_file($file);
    $fh = @fopen($file, 'a');
    if ($fh === false) return false;
    $done = false;
    if (flock($fh, LOCK_EX)) {
        if ($isNew) { fwrite($fh, "\xEF\xBB\xBF"); fputcsv($fh, csv_headers(), ';'); }
        fputcsv($fh, $row, ';');
        fflush($fh);
        flock($fh, LOCK_UN);
        $done = true;
    }
    fclose($fh);
    return $done;
}

/* ---------- recalcul sécurisé du panier depuis tarifs.json ----------
   Renvoie ['items'=>[...], 'total'=>cents, 'saison'=>...] ou ['error'=>...] ou null. */
function recompute_panier($lignes, $tarifsPath) {
    $tarifs = json_decode(@file_get_contents($tarifsPath), true);
    if (!$tarifs || empty($tarifs['disciplines'])) return null;

    $parCode = [];
    foreach ($tarifs['disciplines'] as $d)
        foreach ($d['categories'] as $c)
            foreach ($c['cours'] as $co)
                $parCode[$co['code']] = [
                    'tarif'   => (int) round($co['tarif'] * 100),
                    'disc'    => $d['nom'],
                    'cat'     => $c['nom'],
                    'horaire' => $co['horaire'],
                ];
    $taux   = (float) ($tarifs['remise']['taux'] ?? 0.15);
    $saison = (string) ($tarifs['saison'] ?? '');

    $items = [];
    foreach ($lignes as $l) {
        $code = is_array($l) ? ($l['code'] ?? '') : '';
        if ($code === '' || !isset($parCode[$code])) return ['error' => 'Cours inconnu : ' . $code];
        $items[] = $parCode[$code] + [
            'code'     => $code,
            'adherent' => substr((string)($l['adherent'] ?? ''), 0, 60),
        ];
    }
    // la cotisation la plus chère reste au plein tarif ; -15% sur les autres
    usort($items, function ($a, $b) { return $b['tarif'] - $a['tarif']; });
    $total = 0; $premier = true;
    foreach ($items as &$it) {
        $it['taux'] = $premier ? 0.0 : $taux;
        $it['prix'] = (int) round($it['tarif'] * (1 - $it['taux']));
        $total += $it['prix'];
        $premier = false;
    }
    unset($it);

    return ['items' => $items, 'total' => $total, 'saison' => $saison];
}

/* ---------- e-mail HTML (multipart : texte + HTML) ---------- */
function f_mail_html($to, $from, $subject, $textBody, $htmlBody) {
    $b = 'cyam' . md5(uniqid('', true));
    $subjEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $h  = 'From: CYAM Yerres <' . $from . ">\r\n";
    $h .= 'Reply-To: ' . $from . "\r\n";
    $h .= "MIME-Version: 1.0\r\n";
    $h .= 'Content-Type: multipart/alternative; boundary="' . $b . '"' . "\r\n";
    $m  = '--' . $b . "\r\n";
    $m .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
    $m .= $textBody . "\r\n\r\n";
    $m .= '--' . $b . "\r\n";
    $m .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
    $m .= $htmlBody . "\r\n\r\n";
    $m .= '--' . $b . '--';
    return @mail($to, $subjEnc, $m, $h);
}

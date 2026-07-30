<?php
/* ============================================================
   CYAM Yerres — Génération de la facture PDF + envoi à l'adhérent
   ------------------------------------------------------------
   Appelé par notify.php à la confirmation du paiement.
   Aucune librairie externe : petit générateur PDF intégré.
   👉 Identité du club modifiable dans generer_et_envoyer_facture().
   ============================================================ */

/* ---------- mini générateur PDF (1 page A4, polices standard) ---------- */
if (!class_exists('MiniPDF')) {
class MiniPDF {
    private $w = 595.28, $h = 841.89;
    private $buf = '';
    private $fs = 10;
    private $ff = 'F1';

    function setFont($size, $bold = false) { $this->fs = $size; $this->ff = $bold ? 'F2' : 'F1'; }

    private function enc($s) {
        $r = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
        if ($r === false) $r = @utf8_decode($s);
        return $r === false ? $s : $r;
    }
    private function esc($s) { return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s); }

    function text($x, $yTop, $s) {
        $y = $this->h - $yTop;
        $t = $this->esc($this->enc($s));
        $this->buf .= "BT /{$this->ff} {$this->fs} Tf {$x} {$y} Td ({$t}) Tj ET\n";
    }
    function textWrap($x, $yTop, $s, $width, $lh = 13) {
        foreach (explode("\n", wordwrap($s, $width, "\n", false)) as $line) {
            $this->text($x, $yTop, $line);
            $yTop += $lh;
        }
        return $yTop;
    }
    function line($x1, $y1Top, $x2, $y2Top, $lw = 0.4) {
        $y1 = $this->h - $y1Top; $y2 = $this->h - $y2Top;
        $this->buf .= "{$lw} w {$x1} {$y1} m {$x2} {$y2} l S\n";
    }
    function output() {
        $c = "2 J\n" . $this->buf;
        $o = [];
        $o[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $o[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 {$this->w} {$this->h}] >>";
        $o[3] = "<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>";
        $o[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $o[5] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";
        $len = strlen($c);
        $o[6] = "<< /Length {$len} >>\nstream\n{$c}\nendstream";
        $out = "%PDF-1.4\n";
        $off = [];
        for ($i = 1; $i <= 6; $i++) { $off[$i] = strlen($out); $out .= "{$i} 0 obj\n{$o[$i]}\nendobj\n"; }
        $xref = strlen($out);
        $out .= "xref\n0 7\n0000000000 65535 f \n";
        for ($i = 1; $i <= 6; $i++) $out .= sprintf("%010d 00000 n \n", $off[$i]);
        $out .= "trailer\n<< /Size 7 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
        return $out;
    }
}
}

if (!function_exists('f_eur')) {
    function f_eur($c) { return number_format(((int)$c) / 100, 2, ',', ' '); }
}
if (!function_exists('f_frdate')) {
    function f_frdate($iso) {
        $iso = substr((string)$iso, 0, 10);
        $d = DateTime::createFromFormat('Y-m-d', $iso);
        return $d ? $d->format('d/m/Y') : $iso;
    }
}

/* ---------- envoi d'un e-mail avec pièce jointe PDF ---------- */
if (!function_exists('f_mail_pdf')) {
function f_mail_pdf($to, $from, $subject, $bodyText, $pdfBin, $pdfName) {
    $b = 'cyam' . md5(uniqid('', true));
    $subjEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $h  = 'From: CYAM Yerres <' . $from . ">\r\n";
    $h .= 'Reply-To: ' . $from . "\r\n";
    $h .= "MIME-Version: 1.0\r\n";
    $h .= 'Content-Type: multipart/mixed; boundary="' . $b . '"' . "\r\n";
    $m  = '--' . $b . "\r\n";
    $m .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $m .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $m .= $bodyText . "\r\n\r\n";
    $m .= '--' . $b . "\r\n";
    $m .= 'Content-Type: application/pdf; name="' . $pdfName . "\"\r\n";
    $m .= "Content-Transfer-Encoding: base64\r\n";
    $m .= 'Content-Disposition: attachment; filename="' . $pdfName . "\"\r\n\r\n";
    $m .= chunk_split(base64_encode($pdfBin)) . "\r\n";
    $m .= '--' . $b . '--';
    return @mail($to, $subjEnc, $m, $h);
}
}

/* ============================================================
   Génère la facture, en sauvegarde une copie, et l'envoie à
   l'adhérent. Retourne un message de statut (pour le journal).
   ============================================================ */
function generer_et_envoyer_facture($meta, $orderId, $data, $fromEmail) {

    /* --- Identité du club (⚠️ modifiable ici si besoin) --- */
    $CLUB_NOM   = "Club Yerrois d'Arts Martiaux (CYAM)";
    $CLUB_STAT  = "Association loi 1901 déclarée";
    $CLUB_ADR   = "13 Rue Lucien Mânes — 91330 Yerres, France";
    $CLUB_IMMAT = "SIRET 324 119 551 00038 — RNA W912001176";
    $CLUB_MAIL  = "contact@cyamyerres.fr";

    /* --- Destinataire (adhérent / payeur) --- */
    $contact = is_array($meta['contact'] ?? null) ? $meta['contact'] : [];
    $payer   = is_array($data['payer'] ?? null) ? $data['payer'] : [];
    $adhs    = is_array($meta['adherents'] ?? null) ? $meta['adherents'] : [];
    $prenom  = $payer['firstName'] ?? ($adhs[0]['prenom'] ?? '');
    $nom     = $payer['lastName']  ?? ($adhs[0]['nom'] ?? '');
    $email   = $contact['email'] ?? ($payer['email'] ?? '');
    $adresse = trim(($contact['adresse'] ?? '') . '   ' . ($contact['cp'] ?? '') . ' ' . ($contact['ville'] ?? ''));
    $saison  = $meta['saison'] ?? '';
    $mode3   = ($meta['mode'] ?? '1x') === '3x';
    $total   = $meta['total_cents'] ?? 0;

    /* --- Numéro de facture séquentiel (compteur persistant) --- */
    $annee = substr($saison, 0, 4); if ($annee === '') $annee = date('Y');
    $dir = __DIR__ . '/factures';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $num = 0;
    $cf = @fopen($dir . '/compteur.txt', 'c+');
    if ($cf) {
        if (flock($cf, LOCK_EX)) {
            $num = (int) stream_get_contents($cf);
            $num++;
            ftruncate($cf, 0); rewind($cf); fwrite($cf, (string)$num); fflush($cf);
            flock($cf, LOCK_UN);
        }
        fclose($cf);
    }
    if ($num <= 0) $num = 1;
    $noFacture = 'F' . $annee . '-' . str_pad((string)$num, 4, '0', STR_PAD_LEFT);

    /* --- Construction du PDF --- */
    $pdf = new MiniPDF();
    $x = 40;
    $pdf->setFont(14, true);  $pdf->text($x, 60, $CLUB_NOM);
    $pdf->setFont(9, false);
    $pdf->text($x, 78, $CLUB_STAT);
    $pdf->text($x, 90, $CLUB_ADR);
    $pdf->text($x, 102, $CLUB_IMMAT);
    $pdf->text($x, 114, "Courriel : " . $CLUB_MAIL);

    $pdf->setFont(20, true);  $pdf->text(400, 64, "FACTURE");
    $pdf->setFont(9, false);
    $pdf->text(400, 84, "N° " . $noFacture);
    $pdf->text(400, 96, "Date : " . date('d/m/Y'));
    if ($orderId !== '') $pdf->text(400, 108, "Réf. HelloAsso : " . $orderId);

    $pdf->line($x, 132, 555, 132);

    $pdf->setFont(10, true);  $pdf->text($x, 156, "Facturé à :");
    $pdf->setFont(10, false);
    $pdf->text($x, 172, trim($prenom . ' ' . $nom));
    $y = 184;
    if ($adresse !== '') { $pdf->text($x, $y, $adresse); $y += 12; }
    if ($email !== '')   { $pdf->text($x, $y, $email);   $y += 12; }

    $y += 8;
    $pdf->text($x, $y, "Objet : adhésion au club — saison " . $saison); $y += 20;

    $pdf->setFont(10, true);
    $pdf->text($x, $y, "Description");
    $pdf->text(470, $y, "Montant");
    $y += 6; $pdf->line($x, $y, 555, $y); $y += 16;

    $pdf->setFont(10, false);
    foreach (($meta['cours'] ?? []) as $c) {
        $desc = ($c['discipline'] ?? '') . ' — ' . ($c['categorie'] ?? '');
        if (!empty($c['horaire']))  $desc .= ' (' . $c['horaire'] . ')';
        if (!empty($c['adherent'])) $desc .= ' — ' . $c['adherent'];
        if (!empty($c['remise_pct'])) $desc .= ' [remise -' . $c['remise_pct'] . '%]';
        $startY = $y;
        $y = $pdf->textWrap($x, $y, $desc, 70, 13);
        $pdf->text(470, $startY, f_eur($c['prix_cents'] ?? 0) . ' €');
        $y += 4;
    }
    $pdf->line($x, $y, 555, $y); $y += 18;

    $pdf->setFont(12, true);
    $pdf->text(340, $y, "Total réglé :");
    $pdf->text(470, $y, f_eur($total) . ' €');
    $y += 24;

    $pdf->setFont(10, false);
    $pdf->text($x, $y, "Mode de règlement : " . ($mode3 ? "paiement en 3 fois" : "paiement en 1 fois") . " par carte bancaire (HelloAsso)."); $y += 16;

    $ech = isset($meta['echeances']) && is_array($meta['echeances']) ? $meta['echeances'] : [];
    if ($mode3 && $ech) {
        $pdf->setFont(10, true); $pdf->text($x, $y, "Échéancier :"); $y += 14;
        $pdf->setFont(10, false);
        foreach ($ech as $e) {
            $pdf->text($x + 12, $y, "•  " . f_frdate($e['date'] ?? '') . "  :  " . f_eur($e['montant_cents'] ?? 0) . " €");
            $y += 13;
        }
        $y += 4;
    }

    $y += 8;
    $pdf->setFont(10, false);
    $pdf->text($x, $y, "Le CYAM vous remercie de votre adhésion et vous souhaite une belle saison sportive.");

    $pdf->setFont(8, false);
    $pdf->text($x, 800, $CLUB_NOM . " — " . $CLUB_STAT . " — " . $CLUB_ADR);
    $pdf->text($x, 811, $CLUB_IMMAT . " — Cotisation associative non soumise à la TVA.");

    $bin = $pdf->output();

    /* --- Copie serveur (dossier privé) --- */
    $safe = preg_replace('/[^A-Za-z0-9_\-]/', '', $noFacture);
    @file_put_contents($dir . '/facture_' . $safe . '.pdf', $bin);

    /* --- Envoi à l'adhérent --- */
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        return "PDF $noFacture généré, mais e-mail adhérent absent/invalide (pas d'envoi).";

    $sujet  = "Votre facture d'adhésion — CYAM Yerres (" . $noFacture . ")";
    $corps  = "Bonjour " . trim($prenom . ' ' . $nom) . ",\n\n";
    $corps .= "Nous vous confirmons votre adhésion au Club Yerrois d'Arts Martiaux pour la saison " . $saison . ".\n";
    $corps .= "Vous trouverez votre facture (n° " . $noFacture . ") en pièce jointe, au format PDF.\n\n";
    $corps .= "Montant réglé : " . f_eur($total) . " € (" . ($mode3 ? "en 3 fois" : "en 1 fois") . ").\n\n";
    $corps .= "Sportivement,\nLe CYAM Yerres\ncontact@cyamyerres.fr\n";

    $envoye = f_mail_pdf($email, $fromEmail, $sujet, $corps, $bin, 'facture_' . $safe . '.pdf');
    return "Facture " . $noFacture . ' ' . ($envoye ? "envoyée à " . $email : "NON envoyée (mail() a échoué)");
}

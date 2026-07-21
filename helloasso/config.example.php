<?php
/* ============================================================
   CYAM Yerres — Modèle de configuration HelloAsso
   ------------------------------------------------------------
   👉 COPIEZ ce fichier en « config.php » (dans le même dossier)
      puis renseignez vos clés. Le vrai « config.php » n'est PAS
      suivi par Git (il contient votre secret) : il ne vit que
      sur votre PC et sur le serveur.
   ============================================================ */

// --- Vos identifiants HelloAsso -----------------------------
const HA_CLIENT_ID     = '';   // ← collez votre clientId ici
const HA_CLIENT_SECRET = '';   // ← collez votre clientSecret ici
const HA_ORG_SLUG      = 'club-yerrois-d-arts-martiaux';

// --- Environnement ------------------------------------------
// true  = bac à sable HelloAsso (tests, aucun vrai débit)
// false = production (vrais paiements)
const HA_SANDBOX = false;

// --- Adresse du site (pour les pages de retour) -------------
const SITE_URL = 'https://cyamyerres.fr';

// ============================================================
//  Ne rien modifier en dessous.
// ============================================================
const HA_API_BASE = HA_SANDBOX
    ? 'https://api.sandbox.helloasso.com'
    : 'https://api.helloasso.com';

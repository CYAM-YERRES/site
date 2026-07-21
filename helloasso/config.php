<?php
/* ============================================================
   CYAM Yerres — Configuration du paiement HelloAsso
   ------------------------------------------------------------
   ⚠️  RENSEIGNEZ VOS CLÉS CI-DESSOUS (HelloAsso → Mon compte →
       Ma clé API). Ne partagez JAMAIS ce fichier ni votre
       clientSecret. Ce fichier PHP n'est jamais visible par les
       visiteurs (il s'exécute côté serveur).
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

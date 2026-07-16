# Site CYAM Yerres

Site web du **Club Yerrois d'Arts Martiaux** (CYAM) — Yerres (91), fondé en 1965.

## Pages

| Fichier          | Rôle                                                                 |
|------------------|---------------------------------------------------------------------|
| `index.html`     | Page d'accueil : présentation du club, disciplines, actualités, contact |
| `adhesion.html`  | Formulaire d'adhésion en ligne avec remise famille dégressive       |

## Aperçu en local

Le site est en **HTML/CSS/JavaScript purs**, sans dépendance ni étape de build.
Pour le prévisualiser, ouvrez simplement `index.html` dans un navigateur.

## Personnalisation

Le contenu éditable se trouve dans les blocs `<script>` en bas de chaque page :

- **`index.html`** — tableaux `DISCIPLINES` et `NEWS` (ajouter une actualité = ajouter un objet dans `NEWS`).
- **`adhesion.html`** — tableau `DISCIPLINES` (tarifs), `REMISES` (taux de remise par rang) et `PORTEE` (remise « famille » ou « personne »).

> ⚠️ La page d'adhésion est un **prototype** : le bouton de paiement affiche les données
> qui seraient envoyées au serveur, mais aucun paiement réel n'est déclenché.

## Contact

Club Yerrois d'Arts Martiaux — 13 Rue Lucien Mânes, 91330 Yerres.

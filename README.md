# PRISE / PREDIRE — React + Laravel (V9)

Application de suivi des contrats des projets **PRISE** et **PREDIRE**.
La V9 reprend **toutes les fonctionnalités, le design et les règles métier de l'application ASP.NET Core d'origine** (version « Suivi, paiements, modification, suppression ») dans la pile **React (Vite) + Laravel (API, Sanctum) + MySQL**.

```
backend/    API Laravel 13 (PHP ≥ 8.3), MySQL
frontend/   React 18 + Vite, thème PRISE (Bootstrap 5 + feuilles de style de l'application C#)
```

## 1. Ce que contient la V9

| Domaine | Reprise de l'application C# |
|---|---|
| Authentification | Connexion par projet, verrouillage 15 min après 5 échecs, inscription **en attente de validation**, mot de passe oublié par **lien à usage unique (30 min)**, réinitialisation, comptes migrés (PBKDF2) re-hachés à la première connexion |
| Super-administrateur | `hermansimbaleva20@gmail.com` dans PRISE et PREDIRE (commande `php artisan prise:create-super-admin`) |
| Phases | Sélecteur de phase dans l'en-tête (toutes les pages suivent la phase choisie), administration des phases, archives |
| Dashboard | KPI, aperçu des contrats (bouton **Détail = page en lecture seule pour tous**), aperçu des garanties, alertes récentes, carte des provinces |
| Contrats | Liste paginée, création/modification (catégories TRAVAUX / BIENS / SERVICE, sites, types d'ouvrages, garantie), fiche de gestion (avenants, résiliation, suppression avec motif, documents PDF/Word), fiche **lecture seule** |
| Suivi & Avancement | Avancement **cumulé par site** (max 100 %), moyenne des sites pour le contrat, correction et suppression avec motif, histogrammes horizontaux |
| Paiements | Montants avec **virgule ou point**, ajout / correction / suppression avec motif, avance de démarrage |
| Garanties | Modification, **renouvellement**, **prolongation**, historique, alertes |
| Rapports | Situation **actuelle / à une date / sur une période**, contrats filtrés selon la date ou la période, **matrice des garanties** à part, histogrammes, **export Excel** (.xlsx) |
| Alertes | Tableau stylé (gravité, type, indicateur), filtres, **envoi manuel** par e-mail, historique |
| Administration | Entreprises (coordonnées + banque de garantie), utilisateurs (approbation, habilitations, mot de passe temporaire), paramètres SMTP (mot de passe chiffré), sauvegarde SQL (super-admin), journal d'audit |
| Interface | En-tête avec icônes d'alerte (contrats, garanties, sécurité) avant la phase, bandeau mobile/tablette, sidebar tablette compacte, thème vert PRISE |

Le calcul d'avancement, de durée consommée, de statut (Normal / Vigilance / Retard / Terminé / Résilié) et de situation des garanties est **identique** à celui de l'application C# (`backend/app/Support/Metrics.php`).

## 2. Installation (poste de développement, ex. WAMP)

### Backend
```bash
cd backend
copy .env.example .env          # puis renseigner DB_DATABASE / DB_USERNAME / DB_PASSWORD
composer install
php artisan key:generate
php artisan migrate             # crée ou met à jour toutes les tables (V8 + V9)
php artisan prise:create-super-admin
php artisan serve               # API sur http://127.0.0.1:8000
```
La commande `prise:create-super-admin` affiche un **lien de définition du mot de passe** (24 h, usage unique) pour chaque projet.

### Frontend
```bash
cd frontend
copy .env.example .env          # VITE_API_URL=http://127.0.0.1:8000/api
npm install
npm run dev                     # http://localhost:5173
npm run build                   # génère frontend/dist (production)
```

Dans `backend/.env`, vérifiez : `APP_TIMEZONE=Africa/Kinshasa`, `FRONTEND_URL=http://localhost:5173` (adresse du site React, utilisée dans les e-mails), `SUPER_ADMIN_EMAIL`.

## 3. Mise à jour d'une base existante (V8 → V9)

**Sauvegardez d'abord la base.** Deux méthodes équivalentes, sans perte de données :

1. **Recommandée** : `php artisan migrate` (la migration `2026_10_20_000004_align_with_csharp_features` est additive et relançable).
2. **phpMyAdmin / MySQL** : importer `backend/database/sql/V9_mysql_update.sql` (idempotent ; il enregistre aussi la migration dans la table `migrations`).

Pour obtenir le SQL exact de création complète sans l'exécuter : `php artisan migrate --pretend`.

Ce que la mise à jour ajoute : colonnes `completed`, `work_type_text` (contrats), `added_months` / `previous_end_date` (avenants), `created_by` (garanties, avenants), `project_id` (audit), `status` / `user_id` (demandes de réinitialisation), tables `user_reset_tokens`, `work_types`, `provinces`, unicité du numéro de contrat **par projet**, saisie d'avancement possible sans site, lignes SMTP par projet.

## Installation sur une base MySQL neuve (tables métier vides)

1. Créez dans phpMyAdmin une base `prise_db` avec l'interclassement `utf8mb4_unicode_ci`.
2. Configurez `backend/.env` avec les identifiants MySQL.
3. Dans `backend`, exécutez `composer install`, puis `php artisan key:generate` si `APP_KEY` est vide.
4. Exécutez `php artisan migrate` pour créer le schéma.
5. Exécutez `php artisan prise:reset-structure` et tapez `RESET` à la demande. Sur cette base nouvellement créée, il n'y a aucune donnée métier à sauvegarder. Si la création de sauvegarde échoue parce que `mysqldump` n'est pas disponible, relancez avec `php artisan prise:reset-structure --no-backup` puis tapez `RESET`.
6. Exécutez `php artisan prise:create-super-admin` et utilisez le lien affiché pour définir le mot de passe du super-administrateur.
7. Ne lancez pas `php artisan db:seed` : le seeder de démonstration ajoute volontairement une entreprise et un contrat de démonstration.

Après ces étapes, les tables métier (entreprises, contrats, sites, avancements, paiements, garanties, avenants, documents, alertes et audit) sont vides. Les seules données initiales sont la configuration nécessaire : projets PRISE/PREDIRE, phases, provinces, types de travaux et paramètres SMTP. La table `users` contiendra le super-administrateur créé à l'étape 6.

## 3 bis. Repartir d'une structure propre (tables vides, utilisateurs conservés)

Si les anciennes données posent problème (clés étrangères, imports incomplets) et que vous préférez **ressaisir les contrats dans cette nouvelle version**, utilisez la réinitialisation de structure. Elle recrée **toutes les tables métier vides** et **ne conserve que les comptes** (`users`) ainsi que la configuration indispensable (projets, phases, paramètres SMTP, provinces, jetons de connexion).

| Situation | Méthode |
|---|---|
| Base **neuve** (vide) | `php artisan migrate` : crée les tables vides ; créez ensuite le super-administrateur avec `php artisan prise:create-super-admin` |
| Base **existante**, comptes à garder | `php artisan migrate` (mise à jour des colonnes), puis **`php artisan prise:reset-structure`** |
| Sans ligne de commande (phpMyAdmin) | Importer `backend/database/sql/V9_clean_structure_keep_users.sql` (après `migrate` ou après `V9_mysql_update.sql`) |

La commande `prise:reset-structure` :
1. affiche ce qui sera supprimé et ce qui est conservé, et demande de **taper RESET** ;
2. crée d'abord une **sauvegarde SQL complète** dans `backend/storage/app/private/backups` (option `--no-backup` pour l'ignorer) ;
3. supprime puis recrée vides : entreprises, contrats, sites, avancements, paiements, documents, garanties et leurs prolongements/renouvellements, avenants, types d'ouvrages, journal d'audit, demandes de réinitialisation ;
4. supprime les fichiers des documents de contrats ;
5. recrée la configuration si elle manque (projets PRISE/PREDIRE, une phase courante par projet, provinces, catalogue d'ouvrages de départ, ligne SMTP) — **aucun contrat, aucune entreprise, aucun paiement**.

Les comptes utilisateurs (mots de passe, habilitations, super-administrateur) sont intacts. Après la réinitialisation : connectez-vous, créez les **entreprises** (*Entreprises*), puis les **contrats** (*Contrats > Nouveau contrat*).

## 4. Déploiement en ligne (hébergement Apache / cPanel / OVH)

1. **Base MySQL** : créez la base et l'utilisateur dans le panneau d'hébergement, puis renseignez `backend/.env` (`APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, `FRONTEND_URL`, `DB_*`).
2. **Backend** : envoyez `backend/` sur le serveur (idéalement **hors** du dossier public), exécutez `composer install --no-dev -o`, `php artisan key:generate`, `php artisan migrate --force`, `php artisan prise:create-super-admin`. Le domaine ou sous-domaine de l'API doit pointer sur `backend/public`.
3. **Frontend** : `VITE_API_URL=https://api.votre-domaine.org/api` dans `frontend/.env`, `npm run build`, puis envoyez le contenu de `frontend/dist` à la racine du site (le fichier `.htaccess` fourni gère les routes React).
4. **Droits** : `backend/storage` et `backend/bootstrap/cache` doivent être inscriptibles.
5. **Sécurité** : HTTPS obligatoire (certificat gratuit Let's Encrypt), restreignez les origines autorisées de l'API (CORS) à votre domaine, sauvegardez régulièrement la base (bouton du super-administrateur dans *Paramètres*) **et** le dossier `backend/storage/app/private` (documents des contrats).
6. **E-mails** : renseignez le SMTP de chaque projet dans *Paramètres* (port 587 conseillé). Les liens de réinitialisation et les alertes en dépendent.

## 5. Habilitations

`can_manage_contracts`, `can_manage_progress`, `can_manage_payments`, `can_follow_new_contracts` (notifié de chaque nouveau contrat pour en commencer le suivi), `can_view_alert_history` (modèles et historique des alertes : le rôle d'administrateur ne le donne pas), `can_manage_guarantees`, `can_manage_companies`, `can_manage_users`, `can_manage_settings`, `can_delete_contracts`, `can_view_reports`, `can_view_alerts_contracts`, `can_view_alerts_guarantees`, `can_view_alerts_logistics`, `can_view_situation`, `can_view_archives`. Les administrateurs et le super-administrateur disposent de toutes les habilitations ; **seul le super-administrateur** peut lancer et télécharger la sauvegarde SQL.

## 6. Limites connues

- Le code PHP et la compilation `vite build` n'ont **pas été exécutés** dans l'environnement de livraison (pas de PHP ni d'accès npm). Le frontend a été compilé avec esbuild et ses 21 écrans ont été rendus avec une API simulée ; le backend a été relu et vérifié syntaxiquement. Faites un premier passage complet sur chaque menu après installation.
- Les **contrats** de l'ancienne application ne sont pas importés : la V9 se démarre sur une structure propre (voir 3 bis) ; seuls les comptes peuvent être repris (`backend/docs/MIGRATION_UTILISATEURS_LEGACY.md`).
- L'export Excel est produit sans bibliothèque externe (mise en forme simple : en-têtes colorés, formats montant/date/pourcentage).

# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Projet

Application de suivi des contrats des projets **PRISE** et **PREDIRE** (RDC). C'est un portage React + Laravel d'une application ASP.NET Core d'origine : les règles métier, les libellés et le design doivent rester fidèles à l'application C#. Tout le code, les commentaires et les messages utilisateur sont en français.

Les évolutions suivent le TDR d'amélioration (logistique, charroi, groupes électrogènes, décomptes, alertes, reporting). L'état d'avancement et les décisions métier encore ouvertes (D1–D12) sont dans [docs/AUDIT_TDR_PHASE0.md](docs/AUDIT_TDR_PHASE0.md). Ne pas coder une formule financière (retenues, assiette de garantie) sans validation écrite du RAF. Décisions déjà prises : devise unique USD (pas de conversion) ; un décompte n'est pris en compte qu'après validation par le chef de cellule infrastructures (décision du 03/11/2026, auparavant le comptable).

- `backend/` : API Laravel 13 (PHP ≥ 8.3), Sanctum, MySQL (WAMP en local). **Tables en InnoDB** : le serveur WAMP crée du MyISAM par défaut, ce qui ignore transactions et clés étrangères ; `config/database.php` impose `engine = InnoDB`.
- `frontend/` : React 18 + Vite 8 (Rolldown) + react-router-dom 7, Bootstrap 5 + bootstrap-icons, Chart.js, Leaflet, Tailwind CSS 4. Node ≥ 20.19 requis. `vite`, `@vitejs/plugin-react` et `react-router-dom` sont figés en version exacte, et `rolldown` via `overrides` : n'en changer qu'après `npm audit` et un `npm run build`.
- Le dossier est sous `C:\wamp64\www`, donc exposé par Apache/WAMP. Les `.htaccess` de la racine, de `backend/` et de `backend/storage/` refusent tout accès (`.env`, sauvegardes SQL, documents). Ne pas les supprimer et ne pas laisser de copies `*.bak` ou de dossiers de sauvegarde dans le projet : les anciennes copies sont archivées dans `C:\wamp64\_archives_Suivi_contrat_prise\`.

## Commandes

Backend (depuis `backend/`) :
```bash
composer install
php artisan migrate                      # migrations additives et relançables
php artisan serve                        # API sur http://127.0.0.1:8000
php artisan prise:create-super-admin     # crée/promeut le super-admin et affiche un lien de définition du mot de passe
php artisan prise:reset-structure        # recrée vides les tables métier, conserve users + config (taper RESET ; --no-backup si mysqldump absent)
php artisan prise:import-legacy-users fichier.csv   # reprise des comptes de l'application C# (voir backend/docs/MIGRATION_UTILISATEURS_LEGACY.md)
php vendor/phpunit/phpunit/phpunit                          # tous les tests (Composer absent : pas de vendor/bin)
php vendor/phpunit/phpunit/phpunit --filter test_statuts    # un seul test
php vendor/laravel/pint/builds/pint                         # formatage PHP
```
Les tests ([backend/tests/](backend/tests/)) tournent sur une **base MySQL dédiée `prise_test`**, vidée à chaque lancement (`CREATE DATABASE prise_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`). SQLite ne convient pas, car les migrations contiennent du SQL propre à MySQL. `tests/TestCase.php` refuse toute base dont le nom ne finit pas par `_test`. Le mailer est forcé à `array`.
- `tests/Unit` couvre `Metrics` et `Dates` sans démarrer Laravel.
- `tests/Feature` couvre les habilitations, l'aperçu financier et l'API du socle organisationnel (isolation projet/pool, audit).
- Mockery n'est pas installé : utilisez `$this->actingAs($user, 'sanctum')`, pas `Sanctum::actingAs`.
- `tests/bootstrap.php` enregistre le namespace `Tests\` tant que `composer dump-autoload` n'a pas été relancé.

Avant chaque `php artisan migrate` sur une vraie base, faites une sauvegarde : `mysqldump -uroot prise_db | gzip > storage/app/private/backups/…sql.gz`.
Ne pas lancer `php artisan db:seed` sur une base réelle : le seeder ajoute une entreprise et un contrat de démonstration.

Frontend (depuis `frontend/`) :
```bash
npm run dev      # http://localhost:5173 ; VITE_API_URL=http://127.0.0.1:8000/api dans frontend/.env
npm run build    # génère frontend/dist (avec .htaccess pour les routes React)
npm run lint     # ESLint 9 (eslint.config.js) ; règles de sécurité en erreur : no-danger, jsx-no-target-blank, no-eval
```

Le frontend n'a pas de tests. Pour vérifier un changement d'interface, lancez les deux serveurs et parcourez les écrans concernés.

## Architecture

### Contexte projet / phase (transversal)
Chaque requête porte les en-têtes `X-Project` (`PRISE` | `PREDIRE`) et `X-Phase`. L'intercepteur de [frontend/src/api.js](frontend/src/api.js) les remplit depuis `localStorage` (`prise_token`, `prise_project`, `prise_phase`). Côté serveur, le middleware `project` ([ProjectContext.php](backend/app/Http/Middleware/ProjectContext.php)) :
- vérifie que l'utilisateur appartient au projet, sauf s'il est super-admin ;
- résout la phase demandée et retombe sur la phase courante si elle est absente, ou si elle est archivée sans l'habilitation `can_view_archives`.

Les contrôleurs lisent ce contexte via `App\Support\Ctx` (`Ctx::projectId()`, `Ctx::phaseId()`). Toute requête métier doit être filtrée par projet et, sauf exception, par phase.

### Habilitations
Les habilitations sont des colonnes booléennes `can_*` sur `users` (liste dans le README §5). Les admins (`role` ADMIN/ADMINISTRATEUR/SUPER-ADMIN/SUPER_ADMIN) et `is_super_admin` les ont toutes ; un administrateur ne les exerce que dans son unité. **Seul le super-admin** octroie les habilitations, choisit le rôle (administrateur ou utilisateur simple) et `can_view_pools` (`PUT /users/{id}/permissions`). Il n'y a plus de « Fonction » (colonne `users.function` supprimée) : le poste occupé (`Ctx::title`, champ `title` de la vue utilisateur) tient lieu de rôle affiché. Elles sont appliquées à trois niveaux :
- routes : middleware `permission:a|b` dans [routes/api.php](backend/routes/api.php), qui vaut OU logique ;
- contrôleurs : `Ctx::need('can_...')` ; c'est le cas des contrats, avancements et paiements, dont la consultation est ouverte à tous ;
- frontend : `can()` de `AuthContext` et `<Guard any={[...]}>` dans [App.jsx](frontend/src/App.jsx).

Seul le super-admin peut lancer ou télécharger une sauvegarde SQL. Les opérations sensibles sont journalisées avec `Ctx::audit(...)`, ou avec `Ctx::auditChange(..., $avant, $apres)` pour conserver les valeurs avant et après (TDR §12). L'audit ne doit jamais faire échouer l'opération.

### Socle organisationnel (TDR §5)
Chaque projet comporte des unités (`organizational_units` : une `COORDINATION` nationale et des `POOL`) et des postes (`positions`), gérés dans Administration > Organisation ([OrganizationController](backend/app/Http/Controllers/OrganizationController.php)). Ces listes ne sont jamais codées en dur. Un élément utilisé par des comptes est désactivé, pas supprimé. Chaque compte a un `organizational_unit_id` et un `position_id`, choisis à l'inscription et modifiables dans Utilisateurs.

Tout nouveau module rattaché à une unité (stocks, véhicules, groupes…) doit appliquer l'isolation **côté API** :
- lecture : `Ctx::readableUnitIds()`. Chaque unité est autonome : il renvoie `[id]` (sa seule unité, coordination nationale comprise), `[]` pour un compte non affecté, et `null` (tout le projet) seulement pour le super-admin et les comptes `can_view_pools` qu'il désigne ;
- écriture : `Ctx::needUnit($unitId)`. Hors super-admin, seule l'unité d'affectation est modifiable, même pour un administrateur ou un compte à vue nationale.
- Logisticien national : toute la logistique de la coordination (parc, catalogue, stock, carnets) et, avec `can_view_pools`, la situation des antennes en lecture seule (`otherUnit` dans la fiche de stock et le carnet, mention `ReadOnlyNote`). Dans Utilisateurs, « Accorder la logistique nationale et la vue des antennes » coche les deux.
- Les comptes eux-mêmes suivent la règle : hors super-admin, Utilisateurs et le journal d'audit ne montrent que l'unité du compte (`UserController::guard`).

Les modules logistiques reprennent les **modèles de documents papier du projet** (colonnes, libellés, visas), décrits dans la section 3 ter de l'audit.

### Stocks (TDR §6)
[StockController](backend/app/Http/Controllers/StockController.php), habilitations `can_view_stock` / `can_manage_stock` :
- `stock_articles` : articles (« prix unitaires évalués »). Chaque unité tient les siens (décision du 04/11/2026, plus de catalogue commun) : `organizational_unit_id` obligatoire, article visible de cette unité et de la vue nationale, mouvements enregistrés dans cette seule unité (`record`), fiche sans choix d'unité. Deux façons d'ajouter des articles : par contrat (`contract_id`, import du fichier des articles du contrat) ou sans contrat, un par un. La désignation et le N° de fiche sont uniques par unité.
- `stock_movements` : lignes de la fiche de stock, par article et par unité. Une ligne n'est jamais modifiée ni supprimée : elle est annulée avec un motif (`cancelled_at`) et reste affichée barrée.
- `App\Support\StockLedger` calcule la colonne STOCK (fonctions pures, testées). Une sortie, même antidatée, ou l'annulation d'une entrée est refusée si le stock devient négatif à une date quelconque. Le verrou `lockForUpdate` sur l'article sérialise les saisies.
- Le stock n'est pas filtré par phase : il se poursuit d'une phase à l'autre.

Stock, décisions du 28/10/2026 :
- une entrée enregistre la livraison de l'entreprise titulaire (`company_id`, `reference` = n° de bon de livraison). `POST /stock/deliveries` enregistre plusieurs articles en tout ou rien ;
- chaque ligne porte un `operation_no` stable sur sa fiche (article × unité) ;
- colisage facultatif par article (`pack_size`, `pack_unit`) ;
- le catalogue s'importe depuis un fichier .xlsx ou .csv ([StockImport](backend/app/Support/StockImport.php), `Xlsx::read`) : aperçu avec `dry_run`, puis import tout ou rien ;
- l'unité de saisie est celle du compte (`writeUnit`) : elle n'est jamais redemandée à l'agent.

Stock, décisions du 04/11/2026 : niveau d'alerte d'un article (`StockLedger::threshold`) : celui saisi sur l'article, sinon 1 pour un paquet (Pqt, Pqt/100pces…) et 2 pour une pièce (Pce, Pièce), atteint ou dépassé à la baisse ; les autres unités (Bte, Rame…) n'alertent qu'en rupture si aucun niveau n'est saisi. L'état du stock montre tous les articles, sans aucune valeur (N°, Article, Unité, Entrées, Sorties, Stock, Dernier mouvement) ; les prix restent dans le catalogue. La consommation s'enregistre avec « Enregistrer une sortie » (`POST /stock/exits`, plusieurs articles en tout ou rien, `ownStock` donne le disponible de l'unité du compte). La destination d'une sortie est toujours un poste (`position_id`) : poste actif de l'unité de saisie ou commun à toutes (`StockController::unitPositions`), contrôlé côté API sur `/stock/exits` et `/stock/movements`, jamais un texte libre ; son libellé est recopié dans `origin_destination`. La fiche de stock s'ouvre en cliquant sur un article ; elle s'imprime sur le modèle papier des annexes (`StockCardPrint`, `PrintDocument`, lignes vides jusqu'à 20, visas).

Les postes (`positions.organizational_unit_id`) sont rattachés à une unité ; un poste sans unité est commun à toutes. L'inscription et l'affectation n'acceptent qu'un poste de l'unité choisie (`OrganizationController::positionFitsUnit`). Le super-administrateur est rattaché à la coordination nationale.

Le trait [Concerns\UnitScope](backend/app/Http/Controllers/Concerns/UnitScope.php) fournit le périmètre commun : `units()`, `requestedUnit()`, `writableUnitIds($units, 'can_…')`, et `hasNationalView()` (choix libre de l'unité consultée). Chaque unité ajoute et modifie ses propres articles, et tient son propre parc d'engins.

### Notifications
`user_notifications` + [Notifier](backend/app/Support/Notifier.php) (`send`, `resolve`, `holders`) et `NotificationController` (`/notifications`) ; cloche de l'en-tête ([NotificationBell](frontend/src/components/NotificationBell.jsx), données de `lib/notifications.js`, requêtes `silent` qui ne redirigent jamais vers `/error`). Un décompte transmis notifie les comptes `can_validate_decomptes`, soit le chef de cellule infrastructures (à défaut le super-admin, dans l'application seulement) ; une course notifie la personne désignée ; chaque décision notifie l'auteur, et clôt les notifications « à traiter » de l'élément. Un nouveau contrat notifie les comptes `can_follow_new_contracts` (`NOUVEAU_CONTRAT`, décision du 05/11/2026 : le chef de cellule infrastructures et le personnel de sa cellule, cochés par le super-admin dans Utilisateurs ; à défaut le super-admin), sauf les fournitures de bureau, suivies par le stock seul. Comme l'audit, une notification ne fait jamais échouer l'opération.

### Charroi et groupes électrogènes (TDR §7, §8)
[FleetController](backend/app/Http/Controllers/FleetController.php). Habilitations : `can_view_fleet` (consultation), `can_manage_fleet` (parc et saisies de la logistique dans son unité), `can_authorize_fleet` (C.A en antenne, HRAF ou comptable à la coordination).
- Chaque saisie du carnet est adressée (`assigned_to`, obligatoire) à un compte `can_authorize_fleet` **de l'unité de l'engin** (chaque unité gère sa logistique de manière autonome : le C.A dans une antenne, le HRAF ou le comptable à la coordination nationale ; à défaut, le super-admin), choisi selon sa disponibilité ; elle peut être réadressée tant qu'elle est en attente (`PUT /fleet/logs/{id}/assignee`). Seule la personne désignée (ou le super-admin) l'autorise ou y fait objection avec un motif (`/authorize`, `/objection`), jamais sur sa propre saisie. Sa boîte : `GET /fleet/authorizations`, écran Logistique › Autorisations. Une saisie objectée sort des totaux (`objected`) ; la logistique l'annule et la ressaisit.
- Qualité d'autorisation par poste : `users.logistics_authority` = `CA` (compte d'antenne), `HRAF` ou `COMPTABLE` (compte de la coordination), choisie par le super-admin (une case par poste dans Utilisateurs) ; `can_authorize_fleet` en découle (`UserController::permissions`). La liste des valideurs affiche cette qualité.
- `fleet_logs.lubricant` est un texte (`PLEIN` par défaut) : les totaux comptent les appoints (`lubricantEntries`).
- Tables :
  - `fleet_assets` : un parc commun aux véhicules et aux groupes. Le compteur (« meter ») est en km pour un véhicule et en heures pour un groupe. Chaque engin porte ses propres seuils : réservoir, alerte, taux de consommation, prix du litre, entretien.
  - `fleet_logs` : les lignes du carnet de bord. Elles s'annulent avec un motif, elles ne se modifient pas. La ligne conserve le prix du litre retenu.
  - `fleet_maintenances` et `fleet_insurances` : corrigeables, et supprimables avec un motif.
- `App\Support\FleetMetrics` regroupe tous les calculs, sous forme de fonctions pures testées : estimation km × taux, niveau du réservoir reporté de ligne en ligne, contrôle du compteur (il ne recule pas, même pour une saisie antidatée), totaux et consommation moyenne, échéances, alertes. Les règles sont décrites dans la section 3 quater de l'audit.
- Décision du 28/10/2026 : seules les saisies **autorisées** entrent dans les totaux (`FleetMetrics::totals`). Les autres sont comptées à part (`pending`, `pendingDistance`, `pendingLitres`).
- Entretien, décision du 05/11/2026 (`FleetMetrics::maintenance`), au compteur seulement, sans échéance par date : on programme le compteur du dernier entretien et celui du prochain (`PUT /fleet/assets/{id}/maintenance-plan`, `fleet_assets.maintenance_last_meter|next_meter`), et chaque entretien enregistré porte son prochain (`fleet_maintenances.next_meter`, obligatoire). Alerte aux trois quarts du parcours (`MAINTENANCE_WARNING_SHARE`), mise à jour avec le compteur, en retard une fois le prochain atteint ; elle s'arrête quand l'entretien est enregistré, qui efface la programmation. `maintenance_interval` ne sert plus qu'à proposer le prochain ; `maintenance_warning` n'est plus utilisé.
- Écrans dans `frontend/src/modules/fleet/`. Les mêmes composants servent aux véhicules (`/fleet/*`) et aux groupes (`/generators/*`), selon la prop `kind`.

### Décomptes (situation du contrat)
[DecompteController](backend/app/Http/Controllers/DecompteController.php) et `App\Support\DecompteLedger` (fonctions pures, testées sur la ligne du décompte N°1 du modèle « Situation du contrat N°144 »).
- Formules : avance perçue = montant × taux d'avance (0 pour un contrat de SERVICE) ; avance retenue = décompte × taux d'avance, plafonnée au solde de l'avance ; retenue B.E. = décompte × taux de la garantie ; montant payé = décompte − les deux retenues.
- Les taux sont figés sur chaque ligne au moment de la saisie.
- `can_manage_payments` saisit ; `can_validate_decomptes` (le chef de cellule infrastructures, accordé par le super-admin dans Utilisateurs) valide ou rejette, jamais une ligne qu'il a saisie. Un rejet exige le motif et la date de l'opération (`decision_date`, entre la date du décompte et aujourd'hui) ; une validation prend la date du jour.
- Impression : la situation du contrat est un document à part ([PrintDocument](frontend/src/modules/shared/PrintDocument.jsx), portail sur `<body>`, A4 paysage, en couleur) : entête du projet, intitulé en vert avec « Projet « PRISE » » souligné, titre « SITUATION DU CONTRAT N° … », puis le tableau et sa ligne « Total validé » (ni colonne Décision, ni notes). Tant qu'un `PrintDocument` est monté, l'impression masque toute l'application (styles `.print-doc` / `.pdoc-*` de `prise-react.css`). Tout document imprimé commence par [PrintLetterhead](frontend/src/modules/shared/PrintLetterhead.jsx) : bandeau des logos, intitulé du projet en vert, nom court souligné — code du projet suivi du numéro de la phase en chiffres romains à partir de la 2e (« PRISE II », « PREDIRE », `lib/printTitle.js`), sans « Projet « » » —, puis le titre du document ; `orientation="paysage"` pour les tableaux larges. L'impression d'une page masque toujours la barre latérale et l'en-tête. Toutes les `@page` ont une marge à 0, pour que Chrome et Edge n'impriment ni date, ni titre, ni adresse autour du papier : les marges sont reproduites par le `padding` de `.print-doc` et de `.content` avec `box-decoration-break: clone`. Ne pas remettre de marge dans `@page` ni de `@bottom-right` (une marge non nulle rétablit l'en-tête du navigateur). Pour contrôler un rendu imprimé, générez le PDF avec Chrome (`page.pdf`, média print) plutôt qu'une capture d'écran.
- Une ligne rejetée ne compte pas ; une ligne en attente compte à titre provisoire.
- Le chef de cellule décide les lignes **dans l'ordre** (`line_no`). Depuis le 03/11/2026, la validation ne crée plus de paiement : elle notifie les comptes `can_manage_payments` (`DECOMPTE_A_PAYER`) et la page Paiements affiche « il mérite le paiement de la facture » (`DecompteController::unpaid`, [DecomptesToPay](frontend/src/modules/payments/DecomptesToPay.jsx)). Le gestionnaire renseigne la date et les références : `POST /payments` avec `decompte_id` crée le paiement au montant de la colonne MONTANT PAYÉ, jamais un montant saisi (un seul par décompte). Sa date et ses références se corrigent, pas son montant ; le supprimer remet le décompte dans la liste. L'aperçu et les rapports ne le comptent qu'une fois enregistré.
- Ligne « RETENUE » (`kind = LIBERATION`, `POST /contracts/{id}/decomptes/release`) : elle reverse la retenue de garantie validée. Elle n'est possible que pour un contrat terminé et sans ligne en attente, et suit le même circuit de validation. Après elle, aucun décompte ne peut plus être ajouté.

### Journal d'audit (TDR §12)
[AuditController](backend/app/Http/Controllers/AuditController.php) : filtres par personne (`user_id`, `system` pour l'envoi planifié), action, type d'élément, période et texte ; `/audit/users` donne l'activité par compte ; `/audit/export` produit l'export Excel. Ajoutez le libellé de tout nouveau type d'élément journalisé dans `AuditController::ENTITIES`.

### IA (TDR §10.3)
Assistance IA retirée du projet (décision du 05/11/2026, « pour le moment ») : ni écran, ni menu, ni route `/api/ai`. Le code est archivé dans `C:\wamp64\_archives_Suivi_contrat_prise\retrait_IA_20261105\`. La table `ai_settings`, vide, reste dans le schéma mais n'est plus lue. Ne pas réintroduire d'appel à un service d'IA sans nouvelle décision.

### Rapports figés (TDR §11)
[ReportSnapshotController](backend/app/Http/Controllers/ReportSnapshotController.php) et `App\Support\ReportBuilder` :
- périodes : trimestre, mois, année ou dates libres ;
- indicateurs par contrat, calculés à la date de fin de période (`Metrics` avec `asOf`), plus le carburant de la période (saisies autorisées) ;
- un état figé (`report_snapshots.data`, JSON) n'est plus jamais recalculé. Figer exige `can_manage_settings` ; seul le super-admin supprime un état, avec un motif ;
- comparaison A → B, B pouvant être la situation actuelle (`b=live`) ;
- export Excel des colonnes choisies (`ReportBuilder::COLUMNS`, liste provisoire en attendant la matrice interne du projet).

### Synthèse des contrats
Bouton « Synthèse contrat » de Rapports & suivi, `/reports/synthese` ([SynthesisPage](frontend/src/modules/reports/SynthesisPage.jsx), `styles/synthesis.css`), `GET /reports/synthesis` et [ContractSynthesis](backend/app/Support/ContractSynthesis.php) (fonctions pures sur Loader et DecompteLedger) :
- période : trimestre, mois, année ou dates libres (`ReportBuilder::period`), un contrat seul en option ; périmètre comme le rapport de suivi (`ReportController::inScope`). Les indicateurs sont arrêtés à la fin de la période, ou à aujourd'hui si elle est en cours ; une période à venir est refusée ;
- les statuts se comptent et ne se moyennent jamais ; résumé et constats sont rédigés à partir des chiffres, sans IA ;
- le même contenu sert à l'écran et à l'impression, tout en A4 paysage (`@page syn-paysage`) : Chrome coupe une page paysage à la largeur d'une première page portrait, ne pas mêler les orientations. Graphiques en HTML/CSS pour rester nets.

### Répartition des contrats par province
Tableau de bord, en pleine largeur, à la place de « Situation générale » et des « Alertes récentes », retirées comme le tableau à côté de la carte : la carte des provinces occupe toute la largeur et le détail d'une province s'obtient d'un clic (décisions du 05/11/2026). [ProvinceContractsTable](frontend/src/modules/dashboard/ProvinceContractsTable.jsx), données `provinceContracts` de `DashboardController::byProvince`, sur le modèle du tableau du rapport du projet, plus la catégorie et le type d'ouvrage. Provinces par ordre alphabétique, numérotation qui repart de 01, contrats par numéro ; contrat sur plusieurs provinces répété sous chacune avec les ouvrages de ses sites de cette province ; période renouvelée (`periodStart`, `periodMonths`) ; phase consultée, sans les fournitures de bureau (`Loader`). L'impression monte le `PrintDocument` à la demande : monté en permanence, il masquerait le tableau de bord à l'impression de la page.

### Habilitations d'alertes
« Voir alertes » est scindée (décision du 04/11/2026) : `can_view_alerts_contracts`, `can_view_alerts_guarantees`, `can_view_alerts_logistics`, au choix du super-admin. La colonne `can_view_alerts` reste mais ne sert plus. Chaque rubrique de la page Alertes, l'historique, les modèles, le compteur de l'en-tête et `POST /alerts/send` suivent ces habilitations. Les modèles et l'historique exigent en plus `can_view_alert_history` (`Ctx::canViewAlertHistory`, décision du 05/11/2026) : le super-admin l'a toujours, tout autre compte seulement si le super-admin coche la case. Être administrateur ne suffit pas : côté interface, lisez `me.user.permissions.canViewAlertHistory`, pas `can()`, et la case reste modifiable pour un administrateur (`EXPLICIT` de `Users.jsx`, comme `can_follow_new_contracts`). Les alertes logistiques (`AlertEngine::logistics`, mêmes règles que les e-mails) suivent `Ctx::readableUnitIds()`. Le produit concerné clignote ([AlertBlink](frontend/src/modules/shared/AlertBlink.jsx)) dans la page Alertes, Stock, Charroi, Groupes et le tableau de bord Logistique, pour les seuls comptes habilités.

### Alertes par e-mail (TDR §10)
Décisions du 05/11/2026 : les alertes logistiques (stock, charroi, groupes) restent dans l'application (`AlertEngine::logistics`) et les décomptes à valider dans l'espace du valideur (notification `DECOMPTE_A_VALIDER`) ; ni les unes ni les autres ne partent par e-mail. `AlertEngine::CATEGORIES` ne contient plus que RETARD et GARANTIE ; `AlertEngine::run` n'envoie que les messages de ces catégories.

[AlertSettingsController](backend/app/Http/Controllers/AlertSettingsController.php), réservé au super-admin, et `App\Support\AlertEngine` :
- `alert_recipients` : le personnel du projet, seule liste de destinataires côté projet (adresse, nom et poste `position_id` obligatoires, catégories). Ajout, modification et suppression dans Paramètres › Destinataires du projet (`POST`/`PUT`/`DELETE /settings/recipients`, `can_manage_settings` à la coordination nationale, toutes les catégories à l'ajout), catégories réglées par le super-admin dans Alertes e-mail. Les colonnes `smtp_settings.recipient1..4` sont vidées et ne servent plus ;
- `alert_rules` : pour GARANTIE et RETARD, seule l'activation compte ;
- `alert_dispatches` : historique des envois. Seuls les envois réussis consomment la séquence.
- La commande `php artisan prise:send-alerts [--dry-run] [--project=PRISE]` est planifiée chaque jour à 7 h dans [routes/console.php](backend/routes/console.php). Elle ne part que si le serveur lance `php artisan schedule:run` chaque minute (cron). Sous Windows (PC et services WAMP en démarrage manuel, 7 h exactes souvent manquées), utilisez plutôt [backend/scripts/installer-tache-alertes.ps1](backend/scripts/installer-tache-alertes.ps1) `-Activer` : tâche « PRISE - Envoi des alertes », à 7 h puis chaque heure jusqu'à 18 h pour rattraper un retard (pas de doublon : la commande ne renvoie que ce qui est dû), journal dans `storage/logs/alertes.log`.
- Sans destinataire précisé (test SMTP), `MailService::send` écrit au personnel du projet.
- Entreprises et personnel ont des messages distincts (décision du 02/11/2026), **jamais de copie** : le message d'une entreprise ne part qu'à l'adresse de sa fiche.
- Rappel au personnel du projet (`AlertEngine::runStaff`, modèles `GARANTIE_PERSONNEL` et `CONTRAT_PERSONNEL`, champ `{niveau}`) : un message par garantie (destinataires GARANTIE) ou par contrat (destinataires RETARD) à suivre, une fois tous les 7 jours et sans plafond (clés `PERSONNEL:GARANTIE:id:début:fin`, `PERSONNEL:CONTRAT:id`). Il s'arrête à la prolongation ou au renouvellement de la garantie, et quand le contrat n'est plus en vigilance ni en retard (`Metrics::status`). Interrompre les relances d'une garantie ne vise que l'entreprise ; désactiver la catégorie coupe aussi le rappel.
- Garanties de bonne exécution (décision du 30/10/2026), `App\Support\GuaranteeAlerts` (fonctions pures testées) : pas de récapitulatif interne, mais un message par garantie à l'adresse e-mail de l'entreprise du contrat. Le message porte toujours le n° de la garantie (`performance_guarantees.account_number`, champ `{numero_garantie}`).
- Modèles des messages ([AlertTemplates](backend/app/Support/AlertTemplates.php), table `alert_templates`, page Alertes) : pour l'entreprise, vigilance, retard et satisfaction, pour la garantie et pour le contrat ; pour le personnel du projet, un modèle pour la garantie et un pour le contrat. Les modèles par défaut sont dans le code et une ligne de la table remplace un modèle. Seuls les champs de `AlertTemplates::fields()` sont acceptés. Les modifications sont réservées à un administrateur de la coordination nationale qui a cette habilitation, ou au super-admin.
  - Les alertes des contrats à l'entreprise sont automatiques (`AlertEngine::runContracts`, clés `CONTRAT:id:VIGILANCE|RETARD|SATISFACTION`) : vigilance et retard une fois tous les 7 jours tant que le contrat reste dans cet état, satisfaction une seule fois quand il est terminé. La catégorie RETARD désactivée les coupe. Le bouton de la page Alertes envoie aussi à la demande, après un aperçu (`POST /alerts/send` avec `preview`), et compte dans la séquence.
  - Le message de satisfaction d'une garantie part automatiquement une fois, après la prolongation ou le renouvellement d'une garantie déjà relancée (clé `…:S`).
- Historique des envois (`GET /alerts/history`) : `alert_dispatches` conserve le contrat, la garantie, le n° de garantie, la nature (`message_type`, dont `PERSONNEL`) et le texte envoyé de chaque message. Le filtre se fait par contrat, n° de garantie, nature et résultat. Tout envoi aux entreprises et au personnel sur une garantie ou un contrat passe par `AlertEngine::record`.
  - La séquence dépend de la part consommée de la **période en cours** (la dernière prolongation ou le dernier renouvellement, sinon la garantie initiale) : vigilance à 75 / 85 / 95 % (1, 2, 3 envois par semaine) ; retard après l'échéance (4 par semaine). Jours d'envoi fixes dans la semaine (`WEEK_PLAN`) avec rattrapage ; le premier envoi d'un niveau part tout de suite.
  - La clé d'envoi contient la période (`GARANTIE:id:début:fin:V|R`) : une prolongation, un renouvellement ou une modification des dates arrête la séquence.
  - Paliers (`alert_rules.levels`, JSON), interruption avec motif et fréquence propre à une garantie (`performance_guarantees.alerts_*`) : administrateur de la coordination nationale ou super-admin, panneau « Relances aux entreprises » de Finance › Garanties. Sans adresse e-mail valide, rien ne part (`SANS_ADRESSE`).
  - La période de garantie est celle du contrat : fixée à la création, et resynchronisée à la modification du contrat tant qu'elle n'a été ni prolongée ni renouvelée. Un avenant ne prolonge pas la garantie : l'entreprise doit la faire prolonger (d'où les relances).

### Menu
`NAV` de [Layout.jsx](frontend/src/components/Layout.jsx) accepte des sous-options (8ᵉ élément). « Logistique » est **une seule option** qui ouvre le tableau de bord ; Stock, Charroi et Groupes électrogènes sont ses sous-options, affichées lorsque l'on se trouve dans la rubrique. Ne pas les remonter au premier niveau. De même, « Finance » (`/finance`, qui ouvre la première sous-option accessible) regroupe Paiements, Décomptes et Garanties ; la liste est dans [lib/navigation.js](frontend/src/lib/navigation.js). Les options sont rangées sous des titres de catégorie (Suivi, Analyse, Opérations, Administration), toujours visibles sur ordinateur (`.nav-section`, `prise-react.css`).

### Menu Logistique
La rubrique Logistique comporte un tableau de bord (`/logistique`, [LogisticsController](backend/app/Http/Controllers/LogisticsController.php), qui réutilise les calculs des modules) et trois sous-modules : Stock, Charroi, Groupes électrogènes. Chacun a sa sous-navigation par onglets ([ModuleHeader](frontend/src/modules/shared/ModuleHeader.jsx), configuration dans [logisticsNav.js](frontend/src/modules/shared/logisticsNav.js)). Sans preflight, `tw:border-dashed` s'applique aux quatre côtés : ajoutez `tw:border-0` avant `tw:border-b`.

### Chaîne de calcul des contrats (`backend/app/Support/`)
- `Loader` charge les contrats et leurs dépendances (sites + saisies, paiements, avenants, garantie + prolongations) en requêtes groupées `DB::table`, sous forme de **tableaux normalisés en snake_case**, pas de modèles Eloquent.
- `Metrics` effectue tous les calculs sur ces tableaux : avancement cumulé par site plafonné à 100 % puis moyenne des sites, durée consommée, statut Normal / Vigilance / Retard / Terminé / Résilié, situation des garanties. C'est le portage exact de `ContractMetrics.cs` / `GuaranteeMetrics.cs` ; une modification ici change les résultats de toute l'application.
- `Views` convertit ces tableaux en JSON **camelCase** pour le frontend.
- `Dates` regroupe les helpers de dates (fuseau `Africa/Kinshasa`, dates sous forme de chaînes `Y-m-d` comparées lexicalement). `Xlsx` produit l'export Excel sans bibliothèque externe. `MailService` envoie les e-mails via le SMTP propre à chaque projet (table `smtp_settings`, mot de passe chiffré). `Backup` fait le dump `mysqldump`. `Structure` contient le schéma « propre » des tables métier utilisé par `prise:reset-structure`.

Le dashboard, les rapports, les alertes et les fiches contrat passent tous par Loader → Metrics → Views. Pour ajouter un champ calculé, modifiez ces trois couches plutôt que les contrôleurs.

### Schéma
Toute évolution du schéma doit être reportée à trois endroits :
- une nouvelle migration additive et idempotente (vérifier avec `Schema::hasColumn`/`hasTable`, comme dans `2026_10_20_000004_align_with_csharp_features`) ;
- `App\Support\Structure` ;
- les scripts SQL de `backend/database/sql/` (`V9_mysql_update.sql`, `V9_clean_structure_keep_users.sql`, `V10_socle_organisationnel.sql`, `V11_stocks.sql`, `V12_charroi.sql`, `V13_logistique.sql`, `V14_decomptes.sql`, `V15_rapports_alertes.sql`, `V16_ia_retenue.sql`, `V17_roles_notifications.sql`, `V18_decompte_date_decision.sql`, `V19_qualite_autorisation.sql`, `V20_articles_par_unite.sql`, `V21_relances_garanties.sql`, `V22_modeles_messages.sql`, `V23_destinataires_projet.sql`, `V24_poste_destinataires_validation_decomptes.sql`, `V25_habilitations_alertes.sql`, `V26_renouvellement_contrats.sql`, `V27_procedures_biens.sql`, `V28_avance_fournitures_bureau.sql`, `V29_fournitures_bureau_stock.sql`, `V30_montants_fournitures_bureau.sql`, `V31_fournitures_par_unite.sql`, `V32_programmation_entretien.sql`, `V33_suivi_nouveaux_contrats.sql`, `V34_habilitation_historique_alertes.sql`)

, qui servent aux installations par phpMyAdmin. Ils doivent pouvoir être rejoués : pour enregistrer une migration, calculez `MAX(batch)` dans une table dérivée, sinon l'agrégat renvoie une ligne même quand `NOT EXISTS` est faux et la migration est enregistrée en double. Rejouez chaque script deux fois sur `prise_test`.

Les bases issues de l'application C# n'ont pas exactement le schéma des migrations (par exemple, pas de colonne `users.name`). Testez donc toute migration avec `php artisan migrate --pretend` sur `prise_db` avant de l'appliquer.

Procédures des marchés de BIENS (décisions du 04/11/2026, document « Procédure pour marché de biens », [GoodsProcedure](backend/app/Support/GoodsProcedure.php), pur et testé) : `contracts.goods_procedure` = `BAD`, `NATIONALE` ou `FOURNITURES_BUREAU`, jamais deux à la fois, avec `goods_origin` (`ETRANGER` | `LOCAL`). Les taux du document préremplissent le formulaire et restent modifiables (avance + tranches = 100 %) ; une fois une avance demandée ou un décompte saisi, ils sont figés.
- BAD et nationale (`GoodsProcedure::governs`) : l'avance est versée sur demande de l'entreprise contre une garantie de restitution au moins égale ([ContractAdvanceController](backend/app/Http/Controllers/ContractAdvanceController.php), `contract_advances`, `POST /contracts/{id}/advance`, `/advances/{id}/pay|cancel`, panneau de la page Paiements). `Metrics::initialFinancing` ne la compte qu'une fois payée, à sa date de paiement (`asOf`). Elle se traite avant le premier décompte, sinon les lignes déjà calculées changeraient. Pas de validation par le chef de cellule pour l'avance.
- L'échéancier (`payment_schedule`) reste indicatif : les paiements suivent les décomptes et leurs retenues, au taux de la procédure. Choix de l'utilisateur, à confirmer par écrit par le RAF, car la garantie étant bancaire le document ne prévoit pas de retenue.
- Procédure nationale : pénalité de retard (0,05 % par semaine, plafond 10 %) sur le montant du marché, par semaine complète de la fin du contrat à la réception, déduite du dernier décompte (marqué `is_final`, ou celui qui atteint le montant du marché), figée sur la ligne (`penalty_amount`) et plafonnée à ce que la ligne peut supporter. `remainingAmount` la retire. Base et semaines complètes à confirmer par le RAF. Formule d'actualisation des prix du document non codée : sa première page est coupée.
- `FOURNITURES_BUREAU` (`GoodsProcedure::stockOnly`, décision du 04/11/2026) : suivi par le stock uniquement, sans montant de contrat (`amount` à 0), garantie, avance, décompte, paiement ni avancement. `goods_min_amount` et `goods_max_amount` (500 et 5 500 USD par défaut) sont une simple clause du contrat sur le montant d'une commande : affichés, jamais contrôlés sur les livraisons. Provinces et site restent obligatoires. `Loader::contracts` les écarte par défaut (dashboard, rapports, synthèse, alertes, Paiements, Décomptes, Suivi ; argument `$withStockOnly` pour le registre des contrats) ; l'API refuse ces saisies (`STOCK_ONLY_MESSAGE`). Tout nouvel usage de `Loader` hérite de cette exclusion. `Metrics::status` ne les met jamais en vigilance ni en retard. Dans Stock : filtre par contrat (`?contrat=id`, `sans` pour les articles sans contrat), colonnes Entrées et Sorties, et réception d'une livraison par contrat (`contract_id` contrôlé sur chaque article, entreprise reprise du contrat). L'évolution d'un article est sa fiche de stock.
- Anciens contrats de biens (`goods_procedure` NULL) : règles habituelles, avance comptée à la signature. Avec les fournitures de bureau, ce sont les seuls à pouvoir être renouvelés (`GoodsProcedure::renewable`).

Renouvellement d'un contrat de BIENS (décision du 04/11/2026, `POST /contracts/{id}/renew`, `contract_renewals`) : même contrat, nouvelle période, montant ajouté et numéro à suffixe (`144/R1`, modifiable). `contracts.renewed_amount` reste hors de l'assiette de l'avance (`Metrics::initialFinancing`, `DecompteController::ledger`) et de la garantie : pas de nouvelle avance. La garantie n'est plus exigée (`contract_renewed` : plus de relance ni d'alerte, libellé « Non exigée ») mais un ancien contrat de biens qui en avait une garde son taux de retenue B.E. sur les décomptes suivants ; à confirmer par écrit par le RAF. Les contrats de fournitures de bureau n'ont ni garantie ni décompte. Une ligne RETENUE antérieure au renouvellement ne bloque plus les décomptes (`last_line_no`). L'avancement reste cumulé : un contrat à 100 % reste Terminé. L'écran ne montre que la nouvelle période (`Views::contract` : `periodStart`, `periodMonths`, à utiliser plutôt que `startDate` et `durationMonths`, qui restent les valeurs brutes) et le temps consommé repart de cette période (`Metrics::elapsed`).

Le numéro de contrat est unique **par projet**. Un contrat de catégorie **SERVICE** n'a ni financement initial (forcé à 0) ni garantie de bonne exécution (aucune créée, aucune exigée) ; `ContractController::store/update` l'appliquent, le formulaire désactive ces champs. La liste des contrats est paginée par 5 (`statusCounts` donne les compteurs sur tous les contrats retenus). Dans les histogrammes, le taux de paiement est en rouge quand il dépasse l'avancement physique ([lib/chartColors.js](frontend/src/lib/chartColors.js)). Les contrats, paiements, décomptes et garanties ne sont pas rattachés à une unité : ils sont tenus par la coordination nationale (décision D1 ter) ; seuls les modules logistiques sont isolés par unité, ainsi que les contrats de fournitures de bureau : `contracts.organizational_unit_id` (l'unité du compte qui les crée), lus par cette unité et la vue nationale (`Ctx::canReadContract`), gérés par ses comptes « Gérer le stock » (`Ctx::canManageContract`, décision du 04/11/2026 : logisticien à la coordination, SECA en antenne). « Gérer les contrats » ne donne aucun droit sur eux.

### Frontend
- **Migration progressive vers Tailwind CSS 4** (décision du TDR). Les nouveaux écrans s'écrivent en Tailwind dans `src/modules/<domaine>/` (par exemple [OrganizationPage.jsx](frontend/src/modules/organization/OrganizationPage.jsx)) ; les écrans Bootstrap existants sont migrés un par un. [tailwind.css](frontend/src/styles/tailwind.css) fixe trois règles :
  - classes **préfixées `tw:`** (`tw:flex`, `tw:hover:bg-prise-green-soft`), pour éviter toute collision avec Bootstrap ;
  - **pas de preflight**, donc les styles par défaut du navigateur restent actifs : retirez explicitement la bordure des boutons (`tw:border-0 tw:bg-transparent`) ;
  - jetons du thème PRISE disponibles : `prise-green`, `prise-blue`, `prise-muted`, `font-display`, `font-tech`, `rounded-card`, `shadow-card`…
  - classes communes des boutons, champs et panneaux dans [modules/shared/tw.jsx](frontend/src/modules/shared/tw.jsx) ; masquez les commandes à l'impression avec `tw:print:hidden` (déjà inclus dans `btn`).
- Connexion, demande d'accès et mot de passe oublié : `AuthPage` (fond et photo), [login-motion.css](frontend/src/styles/login-motion.css) (entrée de la page, ondes et lueur autour de la carte via `CardHalo`, dérive de la photo). L'entrée attend la fin du chargeur d'ouverture ; les déplacements passent par `translate` et `scale`, car `prise-ui.css` impose `transform: none !important` à la carte sur petit écran. La lueur en boucle est une rotation (`.card-glint`) plutôt qu'un tracé SVG, redessiné à chaque image. Coût non mesuré de façon fiable : Chrome sans affichage donne des résultats trop variables, à vérifier sur un poste modeste.
- Retour au clic : [lib/pressFeedback.js](frontend/src/lib/pressFeedback.js), installé une fois dans `main.jsx`, anime tous les boutons (`button`, `.btn`, `[role="button"]`) : pression et onde posée sur `<body>`, sans rien à ajouter dans les écrans. Dans une boîte qui enregistre, utilisez `SubmitButton` (`ui.jsx`, états idle, busy, done) et affichez l'erreur **dans la boîte** : un `Notice` de la page reste caché derrière elle et l'enregistrement semble ne rien faire (voir `saveDialog` dans `Users.jsx`, `animateModalOut` dans `lib/modalMotion.js`).
- `pages/` contient un écran par module (Bootstrap). `components/ui.jsx` regroupe les composants partagés, `Layout.jsx` l'en-tête (sélecteur de phase, icônes d'alerte) et la sidebar, `lib/format.js` le formatage des montants et dates (saisie des montants avec virgule ou point).
- Un 401 vide le jeton et renvoie vers `/login`. Les erreurs réseau et 5xx redirigent vers `/error?type=...`. Pour afficher les erreurs Laravel, utilisez `apiError()` / `fieldErrors()`.
- Les styles sont importés dans cet ordre dans [main.jsx](frontend/src/main.jsx) : Bootstrap → `prise-ui.css` → `prise-v19.css` → `prise-react.css` → `prise-design.css` → `tailwind.css`. [prise-design.css](frontend/src/styles/prise-design.css) est la couche de design « relevé holographique » : gris teintés de vert, cartes, tableaux, boutons, champs, barre latérale, et l'effet holographique réservé aux arrière-plans (jamais sous les données). Les titres de page n'ont pas de kicker (`PageHead` ignore la prop). Le chargeur d'ouverture est [OpeningLoader.jsx](frontend/src/components/OpeningLoader.jsx) : coupe de forage animée (6 s), respectant `prefers-reduced-motion`.
 Typographie : Montserrat pour les titres, Roboto pour le texte, Inter (police locale dans `public/fonts`) pour les éléments techniques. Thème vert PRISE.
- La carte des provinces (`ProvinceMap.jsx`) charge le GeoJSON et les tuiles depuis Internet.

## Configuration
`config/app.php` fait lire `APP_TIMEZONE` à Laravel (sans lui, l'application tourne en UTC). Les variables à vérifier dans `backend/.env` sont `APP_TIMEZONE=Africa/Kinshasa`, `FRONTEND_URL` (utilisée pour les liens des e-mails) et `SUPER_ADMIN_EMAIL`. Les constantes d'authentification (verrouillage pendant 15 min après 5 échecs, jeton de réinitialisation valable 30 min) sont dans [backend/config/prise.php](backend/config/prise.php). Les comptes migrés hachés en PBKDF2 sont re-hachés à la première connexion.

# Audit de l'existant face au TDR d'amélioration — Phase 0

Date : 28/10/2026 · Référence : *TDR_PRISE_PREDIRE_version_amelioree.pdf* (§16, phase 0)

Ce document compare le code réel de l'application (V9 React + Laravel + MySQL) aux exigences du TDR. Il sert de base à la phase 1 (conception et validation). Le TDR interdit de créer tables et écrans avant cette validation (§16, §19).

## 1. Écarts d'architecture

| Point du TDR | Existant | Conséquence |
|---|---|---|
| Frontend « React + Vite + Tailwind CSS » | React 18 + Vite 8 + **Bootstrap 5** et feuilles de style PRISE héritées de l'application C# | Ajouter Tailwind en parallèle créerait deux systèmes de style concurrents. **Recommandation : conserver Bootstrap** et corriger le TDR. |
| API versionnée, Form Requests, Policies | Routes `/api/...` non versionnées ; validation et droits dans les contrôleurs (`Ctx::need`) et un middleware `permission` | Les nouveaux modules peuvent adopter Form Requests et Policies sans casser l'existant. Le versionnement `/api/v1` est à décider. |
| Isolation par projet **et par pool** au niveau API et base | Isolation par projet (en-tête `X-Project` vérifié contre `users.project_id`) et par phase. **Aucune notion de pool ni de poste.** | C'est le prérequis de tous les nouveaux modules (phase 2). |
| Rôles (logisticien, comptable/RAF, approbateur, audit…) | 12 habilitations booléennes `can_*` sur `users` et un champ `role` libre (ADMIN, USER…) | Il faut un modèle rôles/permissions par affectation (projet + unité). Les colonnes `can_*` doivent rester compatibles pendant la transition. |
| Traitements différés (Scheduler/Queue) | Table `jobs` présente ; aucune tâche planifiée (`routes/console.php` vide) ; alertes calculées à la volée et envoyées manuellement | Le moteur d'alertes et les séquences demandent un planificateur, à déclarer côté hébergement (cron). |
| Audit avec valeurs avant/après, projet/pool, résultat | `audit_logs` : utilisateur, action, entité, détails texte, projet. Pas d'avant/après ni de pool. | Extension additive possible. |
| Tests automatisés des règles critiques | PHPUnit en place (25 tests : Metrics, Dates, habilitations, aperçu financier) ; ESLint frontend | Socle prêt pour les tests exigés au §17. |

## 2. Couverture fonctionnelle

| Domaine du TDR | État | Détail |
|---|---|---|
| Socle : projets, phases | ✅ Existant | Projets PRISE/PREDIRE, phases et archives |
| Socle : pools, postes, affectations | ✅ **Fait (phase 2)** | Référentiel administrable (Administration > Organisation), choix de l'unité et du poste à l'inscription, affectation dans Utilisateurs, règles d'isolation par unité (`Ctx::readableUnitIds` / `Ctx::needUnit`), audit avant/après, 6 tests d'API |
| Stocks (§6) | ✅ **Fait (phase 3)** | Logistique > Stocks : catalogue des prix unitaires évalués, fiche de stock par article et par unité (modèle papier, impression avec visas), contrôle du stock disponible y compris pour les sorties antidatées, annulation motivée, isolation par pool, 13 tests |
| Charroi automobile (§7) | ✅ **Fait (phase 3)** | Parc et seuils par engin, carnet de bord mensuel (modèle papier, impression avec visas), visa du comptable ou du RAF, entretiens, assurances, alertes (réservoir ¼, entretien 2 500 / 3 000 km, assurance 30 jours, carnet non tenu 7 jours), rapport carburant par période avec export Excel |
| Groupes électrogènes (§8) | ✅ **Fait (phase 3)** | Mêmes écrans en heures : relevés hebdomadaires, entretien à 75 / 100 h, consommation en L/h |
| Aperçu contrats : payé / taux / restant (§9.1) | ✅ **Fait** | Colonnes « Montant payé » et « Montant restant » ajoutées au tableau de bord. Le calcul du restant est fait côté serveur (`Views::contract`). |
| Chronogrammes de paiement, jalons BIENS (§9.2) | ❌ Absent | Seul le taux de financement initial `initial_financing_rate` existe |
| Décomptes et workflow de validation (§9.3–9.4) | ✅ **Fait** | Situation du contrat : avance perçue, retenue d'avance de démarrage (plafonnée au solde), retenue de garantie B.E., montant payé, soldes du contrat et de l'avance ; validation ou rejet par le comptable ; pas d'avance pour un contrat de service. Reste à faire : ligne de libération de la retenue en fin de contrat, et lien avec les paiements. |
| Avenants avec incidence financière | ⚠️ Partiel | Les avenants modifient la durée, pas le montant. Le « montant contractuel révisé » n'est donc pas calculable. |
| Garanties rattachées au contrat (§9.5) | ✅ Existant | `performance_guarantees.contract_id` |
| Libellé « N° garantie » (§9.5) | ✅ **Fait** | Formulaire, fiche contrat, liste des garanties et export Excel |
| Unicité du N° garantie | ❌ À faire | Il faut d'abord contrôler les doublons existants en base |
| Devise sur montants | ✅ Décidé (D9) | Devise unique USD : les montants existants sont réputés en USD, sans colonne de devise à ajouter |
| Alertes : règles, séquences, destinataires, historique (§10) | ⚠️ Partiel | Alertes calculées, envoi manuel autorisé (conforme au §10.2) ; ni règles paramétrables, ni séquences, ni journal par destinataire |
| Rapports XLSX (§11) | ✅ Existant | Export Excel sans bibliothèque externe |
| Rapports PDF, états historisés (§11) | ❌ Absent | — |
| Logo du projet dans les rapports | ⚠️ Partiel | Logos `prise2.png` / `predire_logo.png` choisis dans `Layout.jsx`, pas dans les exports |
| Sauvegarde SQL | ✅ Existant | Réservée au super-admin |
| IA (§10.3) | ❌ Absent | Optionnelle, à traiter en dernier |

## 3. Sécurité : corrections déjà apportées

- Le dossier du projet étant sous `C:\wamp64\www`, Apache servait `backend/.env`, les sauvegardes SQL et des fichiers `.bak`. L'accès est maintenant bloqué par des `.htaccess`.
- `bootstrap/app.php` envoyait un BOM et une ligne vide avant chaque réponse de l'API. C'est corrigé.
- Dépendances frontend mises à jour : 0 vulnérabilité npm.
- **Base en MyISAM** : les 26 tables de `prise_db` étaient en MyISAM, le moteur par défaut du serveur WAMP. Les transactions du code et les clés étrangères n'avaient donc aucun effet. La base est convertie en InnoDB (migration `2026_10_28_000004`, après sauvegarde vérifiée), et InnoDB est imposé pour les nouvelles tables.
- **Demande d'accès cassée** : le formulaire envoyait toujours un nom vide, et toute inscription était refusée. C'est corrigé. Sur une installation neuve, la colonne `users.name` obligatoire provoquait en plus une erreur 500 ; elle est rendue facultative.
- **Messages illisibles** : `UserController.php` et `SettingsController.php` étaient encodés deux fois en UTF-8, et les utilisateurs voyaient « Compte rÃ©activÃ© ». C'est corrigé.

## 3 bis. Décisions prises

- **D2 — Style** : migration vers **Tailwind CSS**, décidée le 28/10/2026. Elle se fait progressivement : classes préfixées `tw:`, sans preflight, en cohabitation avec Bootstrap. Les nouveaux écrans sont en Tailwind, et les écrans existants seront migrés un par un.
- **D1 — Pools** (28/10/2026) : PRISE compte deux pools, **Antenne MBUJI-MAYI** et **Antenne KANANGA** ; PREDIRE en compte un, **Antenne GBADOLITE**. Ils sont créés par la migration `2026_10_28_000006_seed_initial_pools` (et le script V10). L'administrateur garde la main pour en ajouter, en modifier ou en supprimer ; un pool auquel des comptes sont rattachés se désactive au lieu d'être supprimé.
- **D9 — Devise** (28/10/2026) : devise unique, le **dollar américain (USD)**, pour les montants et la valorisation des stocks. Aucune conversion de devise à gérer.
- **D7 bis — Valideur des décomptes** (03/11/2026) : la validation passe du comptable au **chef de cellule infrastructures**, qui reçoit un rappel par e-mail un jour après la transmission puis chaque jour tant que le décompte est en attente. Le paragraphe suivant reste valable pour le reste du circuit.
- **D7 — Circuit des décomptes** (28/10/2026) : chaque décompte reste en attente jusqu'à sa **validation par le comptable**. Un décompte non validé ne compte pas dans le montant payé. La formule des retenues reste à valider (voir D7 ci-dessous).
- **D1 ter — Rôle des pools** (29/10/2026) : les pools interviennent surtout dans la **logistique** (stock, charroi, groupes électrogènes), où chaque unité gère son espace de manière autonome. Les contrats, avancements, paiements, décomptes, garanties et rapports restent **communs au projet** et sont tenus par la **coordination nationale** : ils ne sont pas rattachés à un pool. Le super-admin n'accorde donc les habilitations de ces modules qu'aux comptes de la coordination.

## 3 ter. Modèles de documents du projet (annexes reçues le 28/10/2026)

Les annexes servent de modèle de structure ; leur contenu n'est pas repris.

**Prix unitaires évalués** : N°, article, unité, quantité, PU $, PT $. Les unités sont des libellés de conditionnement (Pce, Pqt, Pqt/100pces, Pqt/50pces) : le conditionnement fait partie de l'unité, il n'y a pas de conversion à gérer. → Repris tel quel dans le catalogue des articles.

**Fiche de stock N° …** (une par article) : N°, date, origine ou destination, entrée, sortie, stock, observation ; visas « Pour la logistique », « Comptabilité », coordonnateur national. → Reprise à l'identique, une fiche par article et par unité. La valeur du stock est calculée au PU évalué du catalogue. Les visas sont portés sur la fiche imprimée : aucune validation n'est demandée mouvement par mouvement.

**Situation du contrat (décomptes)** : N°, N° décompte, date, montant décompte USD, avance de démarrage USD, retenue garantie 5 % USD, montant facture payée, montant contrat, solde contrat, avance perçue, solde avance ; une ligne « Avance perçue » en tête, une ligne « Retenue » (libération) en fin, puis total et solde. Relations relevées sur le modèle et vérifiées sur la ligne du décompte N° 1, **à confirmer par écrit par le RAF avant d'être codées** :
- avance perçue = taux d'avance × montant du contrat (20 % sur le modèle ; ce taux existe déjà par contrat, `initial_financing_rate`) ;
- remboursement de l'avance sur chaque décompte = même taux × montant **brut** du décompte, plafonné au solde de l'avance (le dernier décompte reprend le solde restant) ;
- retenue de garantie = 5 % × montant **brut** du décompte ;
- montant payé = brut − remboursement de l'avance − retenue de garantie ;
- solde du contrat = solde précédent − montant payé ; solde de l'avance = avance perçue − remboursements cumulés ;
- la retenue cumulée est versée en une ligne « Retenue » en fin de contrat.

**Carnet de bord véhicule N° …** (un par véhicule et par mois) : en-tête mois, fiche N°, date d'assurance, dernier et prochain entretien ; colonnes date, destination, km départ, km arrivée, total km parcourus, quantité de carburant, quantité de lubrifiant, heure de départ, heure d'arrivée, motif de la course, demandeur, autorisation, visa contrôle retour, observation ; pied : total km mensuel, total carburant mensuel, consommation moyenne aux 100 km ; visas contrôle hiérarchique/logistique, RAF, approbation du coordonnateur. → Modèle du futur module Charroi : la consommation moyenne se calcule comme carburant du mois ÷ km du mois × 100.

## 3 quater. Charroi et groupes électrogènes : règles reçues le 28/10/2026 et appliquées

- **Carnet de bord** : véhicule et immatriculation, demandeur, destination, km de départ et d'arrivée (total calculé), quantité avant départ (Plein, ¾, ½, ¼, ⅕ ou saisie libre en litres), carburant acheté (seulement en cas d'achat), lubrifiant, heures, motif, autorisation du comptable ou du RAF.
- **Estimation** : km × 0,2 L/km, puis litres × 1,37 USD/L. Le taux et le prix sont réglables par engin, et le prix peut être modifié à chaque saisie ; le prix retenu est conservé avec la ligne.
- **Réservoir** : 120 L, alerte à ¼ (30 L), réglables par engin. Le niveau est estimé de course en course (niveau au départ + achats − estimation), pour lancer la réquisition avant la panne.
- **Entretien** : avertissement à 2 500 km et échéance à 3 000 km pour un véhicule ; 75 h et 100 h pour un groupe. Ces seuils sont réglables par engin. Un entretien enregistré remet le décompte à zéro.
- **Assurance** : date de début et de fin, désignation, coût, observation ; alerte 30 jours avant l'échéance.
- **Tenue du carnet** : alerte si aucune saisie depuis 7 jours (périodicité hebdomadaire retenue pour les groupes).
- **Rapport** : par période choisie, par engin et par mois, avec totaux par unité et total général : km, carburant acheté et montant, estimation, consommation moyenne (L/100 km ou L/h).
- **Rôles** : `can_view_fleet` (consultation), `can_manage_fleet` (logistique : saisies de son unité ; le parc et ses seuils sont tenus par la coordination nationale), `can_authorize_fleet` (comptable / RAF : visa des lignes, jamais les siennes).
- **Décidé le 28/10/2026** :
  - seules les saisies autorisées par le comptable ou le RAF entrent dans les totaux et les rapports ; les autres sont affichées à part, « hors totaux » ;
  - l'entretien se déclenche au compteur seulement : 3 000 km avec avertissement à 2 500 km (100 h et 75 h pour un groupe), réglables par engin.
- **Menu Logistique** : un tableau de bord et trois sous-modules (Stock, Charroi, Groupes électrogènes), chacun avec ses onglets.

## 3 quinquies. Stock : processus retenu le 28/10/2026

- **Approvisionnement** : c'est l'entreprise titulaire du contrat qui livre. Les bons de commande ne sont pas gérés dans l'application. La réception d'une livraison enregistre le fournisseur, la date, le n° de bon de livraison et une entrée par article sur sa fiche.
- **Retrait** : la fiche de demande (demandeur, signature) reste manuelle. Le logisticien vérifie la quantité disponible dans l'application, puis saisit la sortie, qui est refusée si le stock est insuffisant.
- **Fiche de stock** : N° d'opération, date, origine ou destination, entrée, sortie, stock restant, observation. Elle s'imprime sur une période choisie.
- **Articles** : saisis un par un avec les rubriques des « prix unitaires évalués », ou importés depuis un fichier Excel ou CSV, avec un aperçu avant validation et un modèle téléchargeable. Chaque article peut avoir un niveau minimum qui déclenche une alerte, et un colisage paramétrable (par exemple 1 carton = 12 pièces).
- **Antennes** : saisie locale des entrées et des sorties ; la coordination nationale consulte l'ensemble et tient le catalogue.
- **Postes** : chaque poste est rattaché à une unité. À l'inscription, l'agent ne voit que les postes de l'unité qu'il choisit. Une fois connecté, l'unité ne lui est plus jamais demandée.

## 4. Décisions à faire valider (TDR §15)

Le développement des modules concernés ne doit pas commencer sans ces réponses écrites.

| N° | Sujet | Question | Qui valide |
|---|---|---|---|
| D1 bis | Organisation | Liste des postes par projet ; un utilisateur peut-il appartenir à plusieurs unités ? (Les pools sont décidés.) | Coordination |
| D3 | Stocks | **Appliqué d'après le modèle, à confirmer** : unités sans conversion, valeur au PU évalué du catalogue, visas sur la fiche imprimée plutôt que validation de chaque mouvement. Le catalogue est tenu par la coordination nationale ; chaque pool saisit ses propres mouvements. | Logistique |
| D4 | Carburant | ✅ Décidé (§3 quater). Reste à confirmer : les saisies non autorisées comptent-elles dans les totaux ? | Logistique / RAF |
| D5 | Entretien | ✅ Seuils décidés (§3 quater). Reste à décider : faut-il aussi une échéance par date ? | Responsable du parc |
| D6 | Contrats | Date de référence du délai : signature, remise du site ou démarrage effectif | Passation des marchés |
| D7 | **Décomptes** | Confirmer par écrit les formules relevées sur le modèle « Situation du contrat » (§3 ter) : retenue de 5 % et remboursement de l'avance sur le brut, plafonnement au solde de l'avance. Le taux de retenue est-il toujours 5 % ou propre à chaque contrat ? Existe-t-il d'autres retenues (impôts, pénalités) ? Traitement des avenants. Un décompte rejeté par le comptable revient-il en brouillon pour correction ? (Le circuit de validation est décidé.) | **RAF / comptabilité** |
| D8 | Taux payé | L'avance de démarrage compte-t-elle comme « payé » ? (C'est le cas aujourd'hui, comme dans l'application C#.) | RAF |
| D10 | Alertes | Intervalles, nombre maximal d'envois, destinataires, durée de conservation ; activation d'une tâche planifiée sur le serveur | Coordination |
| D11 | Reporting | Liste des rapports mensuels prioritaires et des états officiels à historiser | Coordination |
| D12 | IA | Fonctions autorisées, fournisseur et politique de données | Coordination |

## 5. Ordre de réalisation proposé

1. **Phase 2 — Socle organisationnel** (dépend de D1) : unités organisationnelles (coordination nationale / pools), postes, affectations, rôles et permissions, isolation testée, audit avant/après. Tous les autres modules en dépendent.
2. **Phase 3 — Logistique** (D3, D4, D5) : stocks, puis charroi, puis groupes électrogènes.
3. **Phase 4 — Décomptes et garanties** (D6 à D9) : uniquement après validation écrite du RAF.
4. **Phase 5 — Alertes et reporting** (D10, D11) : moteur de règles en mode simulation, PDF, états historisés.
5. **Phase 6 — Recette et déploiement.**

Chaque phase suit la même règle : migrations additives, sauvegarde vérifiée avant `migrate`, tests automatisés des calculs et de l'isolation.

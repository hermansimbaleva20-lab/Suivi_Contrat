# Déploiement sécurisé de PRISE / PREDIRE

Procédure de mise en ligne sur un serveur Windows avec Apache, PHP et MySQL (WAMP ou équivalent). Suivez les étapes dans l'ordre : la dernière vérifie la configuration et refuse la mise en ligne tant qu'un point reste dangereux.

## 1. Emplacement des fichiers

Le serveur web ne doit servir que deux dossiers :
- `backend/public` : l'API (point d'entrée `index.php`) ;
- `frontend/dist` : l'application compilée.

Installez le projet **hors** du dossier servi par Apache (par exemple `C:\apps\prise`, pas `C:\wamp64\www`). Le code, le fichier `.env`, les sauvegardes (`backend/storage/app/private/backups`) et les documents des contrats ne sont alors jamais accessibles par une URL, même si un `.htaccess` est ignoré.

## 2. MySQL

1. Donnez un mot de passe au compte `root`, qui n'en a pas sous WAMP :
   ```sql
   ALTER USER 'root'@'localhost' IDENTIFIED BY '<mot de passe administrateur>';
   ```
2. Créez un compte réservé à l'application, limité à sa base :
   ```sql
   CREATE USER 'prise_app'@'localhost' IDENTIFIED BY '<mot de passe long et aléatoire>';
   GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES
     ON prise_db.* TO 'prise_app'@'localhost';
   ```
3. Dans `my.ini`, section `[mysqld]`, n'écoutez que la machine elle-même : `bind-address = 127.0.0.1`. Aujourd'hui, MySQL écoute sur toutes les interfaces : `root` n'y est accepté que localement, mais le port 3306 reste visible du réseau.
4. phpMyAdmin et Adminer : laissez `Require local` dans `C:\wamp64\alias\*.conf`, ou supprimez ces alias du serveur de production.

## 3. HTTPS

Sans HTTPS, les mots de passe et les jetons de session circulent en clair sur le réseau.
- Installez un certificat (Let's Encrypt avec win-acme, ou le certificat de l'organisation) pour l'API et pour le frontend.
- Dans `frontend/dist/.htaccess`, décommentez les deux lignes de redirection vers HTTPS. L'en-tête HSTS y est déjà prévu et ne s'applique qu'en HTTPS.

## 4. Apache et PHP

Dans `httpd.conf` :
```apache
ServerTokens Prod
ServerSignature Off
TraceEnable Off
```
Gardez `AllowOverride All` sur `backend/public` et `frontend/dist` : leurs `.htaccess` portent les règles de réécriture et les en-têtes de sécurité, ce qui exige aussi le module `mod_headers`.

Dans `php.ini` :
```ini
expose_php = Off
display_errors = Off
log_errors = On
upload_max_filesize = 20M
post_max_size = 25M
```

## 5. Backend

```bash
cd backend
copy .env.example .env          # puis remplacez chaque valeur entre < >
php artisan key:generate        # une seule fois : APP_KEY chiffre le mot de passe SMTP enregistré
php artisan migrate --pretend   # relisez les changements
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan prise:create-super-admin <adresse>
```
Ne copiez jamais le `.env` de développement : il contient `APP_DEBUG=true`, `APP_ENV=local` et le compte `root` sans mot de passe.

Droits d'écriture : seulement `backend/storage` et `backend/bootstrap/cache`, pour le compte qui exécute Apache.

## 6. Frontend

Compilez avec l'adresse définitive de l'API : elle est écrite dans la politique de sécurité du contenu (CSP) de `dist/.htaccess`, qui n'autorise l'application à appeler que cette adresse.
```bash
cd frontend
set VITE_API_URL=https://<api.domaine.cd>/api
npm ci
npm audit --omit=dev            # doit annoncer 0 vulnérabilité
npm run build
```
Publiez le contenu de `frontend/dist`, y compris le fichier caché `.htaccess`. Une nouvelle ressource externe (police, carte, service) doit être ajoutée à la CSP de `frontend/public/.htaccess`, sinon le navigateur la bloque sans message.

## 7. Vérification avant ouverture

```bash
cd backend
php artisan prise:verifier-securite
```
La commande doit afficher « Configuration prête pour la mise en ligne. » : elle échoue tant que le mode débogage, HTTP, le compte `root`, un mot de passe vide, un CORS ouvert ou le niveau de journal `debug` subsistent.

Puis, depuis un autre poste :
- `https://<api>/.env`, `https://<api>/../.env` et `https://<suivi>/.htaccess` doivent répondre 403 ou 404 ;
- les en-têtes de `https://<suivi>/` doivent contenir `Content-Security-Policy`, `X-Frame-Options: DENY` et `Strict-Transport-Security` ;
- une connexion avec un mauvais mot de passe doit afficher le message générique, et le 31ᵉ essai en 10 minutes depuis le même poste doit être refusé (code 429).

## 8. Exploitation

- **Sauvegardes** : copiez régulièrement `backend/storage/app/private/backups` hors du serveur. Elles contiennent toutes les données, dont les hachages des mots de passe.
- **Alertes e-mail** : tâche planifiée Windows (`backend/scripts/installer-tache-alertes.ps1 -Activer`), exécutée avec un compte de service sans droits d'administrateur.
- **Mises à jour** : chaque mois, lancez `npm audit` et appliquez les correctifs de sécurité de PHP, Apache et MySQL.
- **Comptes** : désactivez sans attendre le compte d'une personne qui quitte le projet. La désactivation ferme ses sessions ouvertes.

## Protections intégrées à l'application

| Risque | Protection |
|---|---|
| Essais de mots de passe | Limite de 30 connexions par 10 minutes et par adresse IP, et blocage du compte 15 minutes après 5 échecs. |
| Recherche de comptes existants | Un seul message d'échec et la même durée de réponse, que le compte existe, soit bloqué ou non. |
| Compte désactivé ou mot de passe volé | La désactivation, la réinitialisation et le mot de passe temporaire ferment toutes les sessions. Un compte désactivé est refusé dès la requête suivante. |
| Inscriptions et e-mails en rafale | 10 inscriptions par heure et 5 demandes de réinitialisation par 15 minutes, par adresse IP, et 5 par heure et par compte. |
| Mot de passe faible | 10 caractères au moins, avec lettres et chiffres. |
| Script injecté, affichage dans un autre site | CSP stricte, `X-Frame-Options: DENY`, `nosniff`, sur le frontend comme sur l'API. |
| Appels de l'API depuis un autre site | CORS limité aux origines de `CORS_ALLOWED_ORIGINS`. |
| Classeur Excel piégé | Taille décompressée bornée à 20 Mo par partie du fichier. |

Risques restants, à connaître :
- **Blocage volontaire d'un compte** : connaître une adresse e-mail suffit pour bloquer ce compte 15 minutes (5 mauvais essais). C'est la règle reprise de l'application C#.
- **Jeton de session** : il est gardé par le navigateur (`localStorage`) pendant 14 jours. La CSP empêche l'exécution de scripts étrangers, mais un poste partagé doit toujours se déconnecter.
- **Mot de passe temporaire** : rien n'oblige à le changer à la première connexion. Demandez à l'utilisateur de passer par « Mot de passe oublié ».

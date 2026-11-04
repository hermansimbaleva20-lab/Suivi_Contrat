# Migration des comptes depuis PRISE.Web (SQL Server) vers MySQL

La migration de schéma ajoute les champs d'affectation au projet, droits, état du compte et empreinte historique. Elle ne supprime pas les tables ou les données existantes. Les mots de passe de l'ancienne version sont stockés sous forme `PasswordHash` + `PasswordSalt` (PBKDF2-SHA256, 120 000 itérations). Ils ne sont pas réversibles; ils sont conservés et vérifiés au premier login. Après une première connexion réussie, le mot de passe est automatiquement re-haché au format Laravel.

## 1. Exporter les utilisateurs depuis l'ancienne base SQL Server

Dans SSMS, sélectionnez la base de données utilisée par l'ancienne application, ouvrez une nouvelle requête et exécutez :

```sql
SELECT
    p.Code AS ProjectCode,
    u.FullName, u.Email, u.FunctionName AS [Function], u.Position AS ProjectPosition,
    u.Role, u.IsActive, u.CanManageSettings, u.CanManageUsers,
    u.CanDeleteContracts, u.CanManageContracts, u.CanManageCompanies,
    u.CanManageProgress, u.CanManagePayments, u.CanViewReports,
    u.CanViewAlerts, u.CanViewSituation, u.CanViewArchives,
    u.CanManageGuarantees, u.IsSuperAdmin, u.IsPending,
    u.FailedLoginCount, u.LockoutEnd, u.PasswordHash, u.PasswordSalt,
    u.CreatedAt, u.LastLoginAt
FROM dbo.Users u
JOIN dbo.Projects p ON p.Id = u.ProjectId
ORDER BY p.Code, u.Id;
```

Dans SSMS, choisissez **Enregistrer les résultats sous…** et enregistrez en CSV UTF-8 sous `users_legacy.csv`. Le fichier contient des empreintes de mots de passe, donc gardez-le confidentiel et supprimez-le après import vérifié.

## 2. Installer le schéma complémentaire

Dans PowerShell :

```powershell
cd C:\wamp64\www\suivi-contrat\backend
php artisan migrate
```

Cette migration est additive. **Ne lancez pas `migrate:fresh`**.

## 3. Importer les comptes

Copiez le CSV dans `backend\database\imports\users_legacy.csv`, puis lancez :

```powershell
php artisan prise:import-legacy-users database/imports/users_legacy.csv
```

Le script associe chaque compte au projet via `ProjectCode` (PRISE ou PREDIRE) et conserve les droits et empreintes historiques. Il met à jour un compte existant uniquement lorsqu'il a la même adresse e-mail et le même projet.

## 4. Connexion

Sélectionnez le même projet que dans l'ancienne application, puis utilisez l'adresse e-mail et le mot de passe historiques. Si le compte est actif et non en attente, la première connexion valide l'empreinte PBKDF2 et la convertit automatiquement en hash Laravel. Si le compte était désactivé/en attente, le comportement d'accès est conservé.

## Notes

- Les mots de passe ne peuvent pas être exportés en clair; ne cherchez pas à les déchiffrer.
- L'envoi d'e-mails de réinitialisation nécessite une configuration SMTP. Jusqu'à cette configuration, le formulaire indique de contacter l'administrateur du projet.
- Vérifiez l'existence des projets PRISE et PREDIRE dans MySQL avant l'import.

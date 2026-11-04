@echo off
rem Envoi des alertes par e-mail, lance par la tache Windows "PRISE - Envoi des alertes".
rem Le journal est dans storage\logs\alertes.log. Pour simuler sans rien envoyer : envoi-alertes.cmd --dry-run
cd /d "%~dp0.."
echo ===== %date% %time% %* >> storage\logs\alertes.log
php artisan prise:send-alerts %* >> storage\logs\alertes.log 2>&1
exit /b %errorlevel%

# Cree ou remplace la tache Windows qui envoie les alertes par e-mail.
#
#   powershell -ExecutionPolicy Bypass -File installer-tache-alertes.ps1             tache creee, desactivee
#   powershell -ExecutionPolicy Bypass -File installer-tache-alertes.ps1 -Activer    tache creee, active
#
# La tache part a 7 h puis chaque heure jusqu'a 18 h. La commande est sans risque de doublon (jamais deux relances le
# meme jour pour une garantie, 7 jours entre deux rappels au personnel) : les heures suivantes ne servent qu'a rattraper
# un PC allume en retard, un WAMP demarre apres 7 h ou un echec d'envoi. Un demarrage manque est rattrape des que possible.
# Sans droits d'administrateur, elle ne tourne que lorsque le compte est connecte.

param([switch]$Activer)

$nom = 'PRISE - Envoi des alertes'
$script = Join-Path $PSScriptRoot 'envoi-alertes.cmd'
$arguments = "-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -Command `"& '$script'; exit `$LASTEXITCODE`""

$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $arguments
$declencheur = New-ScheduledTaskTrigger -Daily -At '07:00'
$repetition = New-ScheduledTaskTrigger -Once -At '07:00' -RepetitionInterval (New-TimeSpan -Hours 1) -RepetitionDuration (New-TimeSpan -Hours 11)
$declencheur.Repetition = $repetition.Repetition
$reglages = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 30)
$reglages.Enabled = [bool]$Activer
$compte = New-ScheduledTaskPrincipal -UserId ([Security.Principal.WindowsIdentity]::GetCurrent().Name) -LogonType Interactive

Register-ScheduledTask -TaskName $nom -Action $action -Trigger $declencheur -Settings $reglages -Principal $compte -Description 'Alertes par e-mail de l''application de suivi PRISE / PREDIRE (php artisan prise:send-alerts).' -Force | Out-Null
$tache = Get-ScheduledTask -TaskName $nom
Write-Output "Tache '$nom' : $($tache.State)"

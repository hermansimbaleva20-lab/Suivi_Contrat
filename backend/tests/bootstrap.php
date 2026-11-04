<?php

// Enregistre le namespace Tests\ même si « composer dump-autoload » n'a pas été relancé après l'ajout d'autoload-dev.
$loader = require __DIR__ . '/../vendor/autoload.php';
$loader->addPsr4('Tests\\', __DIR__);

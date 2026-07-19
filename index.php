<?php
// Puente para accesos directos a /index.php.
// La aplicacion publica vive unicamente en public/index.php.
chdir(__DIR__ . '/public');
require __DIR__ . '/public/index.php';

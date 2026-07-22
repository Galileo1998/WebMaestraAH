<?php
declare(strict_types=1);

/*
 * Entrada oficial de Monitoreo.
 *
 * La implementacion monolitica aprobada se conserva en el tag
 * monitoreo-aprobado-20260722 y en el respaldo de produccion. El modulo V2
 * mantiene las funciones de monitoreo, pero carga listados, etapas y centros
 * bajo demanda para evitar bloquear la interfaz y el desplazamiento.
 */
require __DIR__ . '/monitoreo_v2.php';

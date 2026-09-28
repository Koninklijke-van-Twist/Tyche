<?php
/**
 * Auth-template voor Tyche. Kopieer naar web/auth.php (niet in git).
 *
 * Mímir heeft de voorkeur. Houd de Business Central-gegevens hiernaast,
 * als automatische fallback wanneer Mímir uitvalt:
 *   $mimirApi  = 'mimir_…';  // verplicht om Mímir te activeren
 *   $mimirBase = 'https://sleutels.kvt.nl/mimir/api'; // optioneel
 *
 * Met $mimirApi gezet probeert Tyche eerst Mímir en valt terug op het BC-blok.
 * Zonder $mimirApi wordt alleen het BC-blok gebruikt.
 */

// --- Mímir (aanbevolen) ---
// $mimirApi  = 'mimir_…';
// $mimirBase = 'https://sleutels.kvt.nl/mimir/api';

// --- Business Central (directe route, én fallback als Mímir faalt) ---
// Deze variabelen moeten naast $mimirApi blijven staan.
$environment = 'Production';
$auth_list = [
    'Production' => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD'],
];
$auth = $auth_list[$environment];
$baseUrl = 'https://my-bc-domain.com:7148/';
// Company-OData-root die index.php gebruikt (.../ODataV4/Company('Naam')).
$base = $baseUrl . $environment . "/ODataV4/Company('Bedrijfsnaam')";

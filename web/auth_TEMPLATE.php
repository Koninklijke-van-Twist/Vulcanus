<?php
/**
 * Kopieer naar web/auth.php op de server (niet committen).
 *
 * $allowedUsers:
 * - weglaten of []  → elke geldige Entra-login heeft toegang
 * - lijst met e-mails → alleen die accounts
 *
 * Live Business Central:
 * - $mimirApi zet Mímir aan. Bij een fout valt Vulcanus terug op de BC-variabelen hieronder.
 * - Zonder $mimirApi wordt alleen die directe route gebruikt, als die gezet is.
 * - Zonder beide blijven de rapporten op sample-data. ?sample=1 forceert sample.
 * - $mimirBase en $mimirCompany zijn optioneel.
 *
 * $baseUrl eindigt op ODataV4/ (het environment zit in het pad), bijvoorbeeld
 *   https://api.businesscentral.dynamics.com/v2.0/<tenant>/<environment>/ODataV4/
 * Een basis zonder /ODataV4 (https://bc-host:7148/) wordt aangevuld met
 * $environment . '/ODataV4', dezelfde vorm als de andere apps.
 * $auth is basic of ntlm. De fallback gebruikt $auth_list van de environment van het
 * gevraagde bedrijf (via 'companies' op die entry, of $companyEnvironments), niet
 * automatisch de primaire $auth. Zonder die koppeling en met één environment geldt die.
 */
// $allowedUsers = [
//     "user@domain.nl",
// ];

// $mimirApi     = 'mimir_…';                              // Mímir eerst; leeg = alleen directe BC
// $mimirBase    = 'https://sleutels.kvt.nl/mimir/api';    // optioneel; alleen https
// $mimirCompany = 'Koninklijke van Twist';                // optioneel

// $baseUrl     = 'https://api.businesscentral.dynamics.com/v2.0/<tenant>/<environment>/ODataV4/';
// $environment = 'Production';
// $auth        = ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD'];
// $auth_list   = [
//     'Production' => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD'],
//     'Sandbox' => [
//         'mode' => 'basic',
//         'user' => 'USERNAME',
//         'pass' => 'PASSWORD',
//         'companies' => ['Koninklijke van Twist'],
//     ],
// ];
// $companyEnvironments = ['Koninklijke van Twist' => 'Production'];

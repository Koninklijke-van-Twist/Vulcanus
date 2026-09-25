<?php
/**
 * Kopieer naar web/auth.php op de server (niet committen).
 *
 * $allowedUsers:
 * - weglaten of []  → elke geldige Entra-login heeft toegang
 * - lijst met e-mails → alleen die accounts
 *
 * $mimirApi zet OData-reads via Mímir aan (later).
 */
// $allowedUsers = [
//     "user@domain.nl",
// ];

$mimirApi  = 'mimir_…';
$mimirBase = 'https://sleutels.kvt.nl/mimir/api'; // optioneel; dit is de default

// BC-credentials alleen nodig zolang Mímir nog niet aan staat / voor writes.
// $auth_list = [ ... ];
// $environment = "env1";
// $auth = $auth_list[$environment];
// $baseUrl = "https://…/";

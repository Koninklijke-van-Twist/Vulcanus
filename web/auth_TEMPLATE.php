<?php
/**
 * Kopieer naar web/auth.php op de server (niet committen).
 *
 * $allowedUsers:
 * - weglaten of []  → elke geldige Entra-login heeft toegang
 * - lijst met e-mails → alleen die accounts
 *
 * Live Business Central via Mímir:
 * - Zet $mimirApi op een niet-lege string om live rapporten te laden.
 * - Zonder $mimirApi (of met ?sample=1) gebruiken de rapporten sample-data.
 * - $mimirBase en $mimirCompany zijn optioneel; defaults staan hieronder.
 */
// $allowedUsers = [
//     "user@domain.nl",
// ];

// $mimirApi     = 'mimir_…';                              // verplicht voor live data
// $mimirBase    = 'https://sleutels.kvt.nl/mimir/api';    // optioneel
// $mimirCompany = 'Koninklijke van Twist';                // optioneel

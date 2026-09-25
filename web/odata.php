<?php
/**
 * Slim Mímir-client voor Vulcanus.
 *
 * Actief alleen als $mimirApi in auth.php een niet-lege string is.
 * Zonder sleutel blijven de rapporten op sample-data.
 *
 * Optioneel in auth.php:
 *   $mimirBase    = 'https://sleutels.kvt.nl/mimir/api'; // alleen https
 *   $mimirCompany = 'Koninklijke van Twist';
 *
 * POST {base}/query.php
 *   { company, table, max_age, top: 0, select?: string[], filter?: string }
 *
 * Redirects worden niet gevolgd: anders zou Authorization naar een andere host
 * meegaan (zelfde praktijk als Seshat/Talos).
 */

declare(strict_types=1);

function mimir_api_key(): string
{
    global $mimirApi;
    if (!isset($mimirApi) || !is_string($mimirApi)) {
        return '';
    }
    return trim($mimirApi);
}

function mimir_enabled(): bool
{
    return mimir_api_key() !== '';
}

function mimir_base_url(): string
{
    global $mimirBase;
    if (isset($mimirBase) && is_string($mimirBase) && trim($mimirBase) !== '') {
        $base = rtrim(trim($mimirBase), '/');
        $scheme = parse_url($base, PHP_URL_SCHEME);
        if (!is_string($scheme) || strcasecmp($scheme, 'https') !== 0) {
            throw new Exception('Mímir-basis-URL moet https zijn ($mimirBase).');
        }
        return $base;
    }
    return 'https://sleutels.kvt.nl/mimir/api';
}

function mimir_company(): string
{
    global $mimirCompany;
    if (isset($mimirCompany) && is_string($mimirCompany) && trim($mimirCompany) !== '') {
        return trim($mimirCompany);
    }
    return 'Koninklijke van Twist';
}

/**
 * OData-stringliteral: enkele quotes, een ' in de waarde wordt ''.
 */
function mimir_odata_literal(string $value): string
{
    return "'" . str_replace("'", "''", $value) . "'";
}

/**
 * Filter `Field eq 'waarde'` met OData-quoting.
 */
function mimir_odata_eq(string $field, string $value): string
{
    return $field . ' eq ' . mimir_odata_literal($value);
}

/**
 * Test-double voor de HTTP-call.
 * Signature: function (string $url, array $curlOptions): array{code: int, raw: string}
 */
function mimir_set_transport(?callable $transport): void
{
    if ($transport === null) {
        unset($GLOBALS['vulcanus_mimir_transport']);
        return;
    }
    $GLOBALS['vulcanus_mimir_transport'] = $transport;
}

/**
 * @param list<string> $headers
 * @return array<int, mixed>
 */
function mimir_curl_options(string $payload, array $headers): array
{
    return [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT => 600,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_USERAGENT => 'Vulcanus-MimirClient/1.0',
    ];
}

/**
 * @param array<string, mixed> $jsonBody
 * @return array<string, mixed>
 */
function mimir_post(array $jsonBody): array
{
    $apiKey = mimir_api_key();
    if ($apiKey === '') {
        throw new Exception('Mímir API-sleutel ontbreekt ($mimirApi).');
    }

    $payload = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        throw new Exception('Mímir request JSON encode mislukt.');
    }

    $url = mimir_base_url() . '/query.php';
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
        'X-API-Key: ' . $apiKey,
    ];
    $options = mimir_curl_options($payload, $headers);

    $transport = $GLOBALS['vulcanus_mimir_transport'] ?? null;
    if (is_callable($transport)) {
        $result = $transport($url, $options);
        if (!is_array($result) || !array_key_exists('code', $result) || !array_key_exists('raw', $result)) {
            throw new Exception("Mímir test-transport gaf geen {code, raw} terug.");
        }
        $code = (int) $result['code'];
        $raw = (string) $result['raw'];
    } else {
        if (!function_exists('curl_init')) {
            throw new Exception('cURL is niet beschikbaar voor Mímir.');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new Exception('Mímir cURL init mislukt.');
        }
        curl_setopt_array($ch, $options);
        $rawExec = curl_exec($ch);
        if ($rawExec === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new Exception('Mímir cURL error: ' . $err);
        }
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $raw = (string) $rawExec;
    }

    $decoded = json_decode($raw, true);
    if ($code < 200 || $code >= 300) {
        $message = is_array($decoded) ? (string) ($decoded['error'] ?? $raw) : $raw;
        throw new Exception('Mímir HTTP ' . $code . ': ' . $message);
    }
    if (!is_array($decoded)) {
        throw new Exception('Mímir gaf ongeldige JSON terug.');
    }
    return $decoded;
}

/**
 * Lees een BC-tabel via Mímir. Geeft de OData `value`-rijen terug.
 *
 * @param list<string> $select
 * @return list<array<string, mixed>>
 */
function mimir_query(string $table, string $filter, array $select, int $maxAge = 300): array
{
    $body = [
        'company' => mimir_company(),
        'table' => $table,
        'max_age' => max(0, $maxAge),
        'top' => 0,
    ];

    $cols = [];
    foreach ($select as $col) {
        if (!is_string($col)) {
            continue;
        }
        $col = trim($col);
        if ($col !== '') {
            $cols[] = $col;
        }
    }
    if ($cols !== []) {
        $body['select'] = $cols;
    }

    $filter = trim($filter);
    if ($filter !== '') {
        $body['filter'] = $filter;
    }

    $response = mimir_post($body);
    if (!isset($response['value']) || !is_array($response['value'])) {
        throw new Exception("Mímir query-antwoord mist 'value'.");
    }

    $rows = [];
    foreach ($response['value'] as $row) {
        if (is_array($row)) {
            $rows[] = $row;
        }
    }
    return $rows;
}

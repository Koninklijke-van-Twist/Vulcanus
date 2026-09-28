<?php
/**
 * Directe Business Central-fetch als Mímir faalt.
 *
 * Elke live query loopt via vulcanus_odata_query(). Met $mimirApi gaat die
 * eerst naar Mímir. cURL/timeout, HTTP niet-2xx, ongeldige JSON of een
 * Mímir-foutpayload openen een circuit voor de rest van dit PHP-proces en
 * halen dezelfde tabel daarna direct op ($baseUrl, $auth / $auth_list, $environment).
 * Zonder $mimirApi wordt alleen die directe route gebruikt.
 * Zonder BC-credentials wordt de oorspronkelijke Mímir-fout opnieuw gegooid.
 */

declare(strict_types=1);

require_once __DIR__ . '/odata.php';

/**
 * @return array{open: bool, error: ?Throwable}
 */
function &vulcanus_mimir_circuit_state(): array
{
    static $state = [
        'open' => false,
        'error' => null,
    ];
    return $state;
}

function vulcanus_mimir_circuit_open(): bool
{
    $state = &vulcanus_mimir_circuit_state();
    return $state['open'] === true;
}

function vulcanus_mimir_last_error(): ?Throwable
{
    $state = &vulcanus_mimir_circuit_state();
    return $state['error'] instanceof Throwable ? $state['error'] : null;
}

function vulcanus_mimir_trip(Throwable $exception): void
{
    $state = &vulcanus_mimir_circuit_state();
    if ($state['open'] === true) {
        return;
    }
    $state['open'] = true;
    $state['error'] = $exception;
}

function vulcanus_mimir_circuit_reset(): void
{
    $state = &vulcanus_mimir_circuit_state();
    $state['open'] = false;
    $state['error'] = null;
}

function vulcanus_mimir_connect_timeout_seconds(): int
{
    return 10;
}

function vulcanus_mimir_timeout_seconds_for_sapi(string $sapi): int
{
    return strtolower($sapi) === 'cli' ? 600 : 90;
}

function vulcanus_mimir_timeout_seconds(): int
{
    return vulcanus_mimir_timeout_seconds_for_sapi(PHP_SAPI);
}

/**
 * @param mixed $auth
 */
function vulcanus_bc_auth_is_usable($auth): bool
{
    if (!is_array($auth)) {
        return false;
    }
    $user = trim((string) ($auth['user'] ?? ''));
    if ($user === '') {
        return false;
    }
    if (!array_key_exists('pass', $auth)) {
        return false;
    }
    $mode = (string) ($auth['mode'] ?? 'basic');
    return $mode === 'basic' || $mode === 'ntlm';
}

/**
 * auth.php die vanuit een functie wordt geladen zet variabelen lokaal.
 * Kopieer de BC-config naar $GLOBALS zodat de fallback ze daarna ziet.
 *
 * @param array<string, mixed> $defined
 */
function vulcanus_publish_bc_auth_globals(array $defined): void
{
    foreach (['mimirApi', 'mimirBase', 'mimirCompany', 'baseUrl', 'environment', 'auth', 'auth_list', 'companyEnvironments', 'allowedUsers'] as $name) {
        if (array_key_exists($name, $defined)) {
            $GLOBALS[$name] = $defined[$name];
        }
    }
}

function vulcanus_ensure_bc_auth_loaded(): void
{
    static $attempted = false;
    if ($attempted) {
        return;
    }
    $attempted = true;
    $path = __DIR__ . '/auth.php';
    if (!is_file($path)) {
        return;
    }
    $real = realpath($path);
    foreach (get_included_files() as $loaded) {
        if ($real !== false && realpath($loaded) === $real) {
            return;
        }
    }
    require_once $path;
    vulcanus_publish_bc_auth_globals(get_defined_vars());
}

function vulcanus_bc_base_url(): ?string
{
    vulcanus_ensure_bc_auth_loaded();
    global $baseUrl;
    if (!isset($baseUrl) || !is_string($baseUrl)) {
        return null;
    }
    $base = rtrim(trim($baseUrl), '/');
    if ($base === '' || stripos($base, 'mimir.invalid') !== false) {
        return null;
    }
    return $base;
}

/**
 * @return list<string>
 */
function vulcanus_bc_environment_list(): array
{
    vulcanus_ensure_bc_auth_loaded();
    global $environment, $auth_list;
    $list = [];
    if (isset($environment)) {
        $raw = is_array($environment) ? $environment : [$environment];
        foreach ($raw as $item) {
            $env = trim((string) $item);
            if ($env === '' || strcasecmp($env, 'mimir') === 0) {
                continue;
            }
            $list[] = $env;
        }
    }
    if ($list === [] && isset($auth_list) && is_array($auth_list)) {
        foreach ($auth_list as $name => $entry) {
            if (!is_string($name) || $name === '' || strcasecmp($name, 'mimir') === 0) {
                continue;
            }
            if (!vulcanus_bc_auth_is_usable($entry)) {
                continue;
            }
            $list[] = $name;
        }
    }
    $unique = [];
    foreach ($list as $env) {
        if (!in_array($env, $unique, true)) {
            $unique[] = $env;
        }
    }
    return $unique;
}

function vulcanus_bc_mapped_environment(string $company): ?string
{
    vulcanus_ensure_bc_auth_loaded();
    $company = trim($company);
    if ($company === '') {
        return null;
    }
    global $companyEnvironments, $auth_list;
    if (isset($companyEnvironments) && is_array($companyEnvironments)) {
        foreach ($companyEnvironments as $name => $env) {
            if (!is_string($name) || strcasecmp($name, $company) !== 0) {
                continue;
            }
            $envName = trim((string) $env);
            if ($envName !== '' && strcasecmp($envName, 'mimir') !== 0) {
                return $envName;
            }
        }
    }
    if (isset($auth_list) && is_array($auth_list)) {
        foreach ($auth_list as $envName => $entry) {
            if (!is_string($envName) || !is_array($entry)) {
                continue;
            }
            $names = [];
            if (isset($entry['company'])) {
                $names[] = $entry['company'];
            }
            if (isset($entry['companies']) && is_array($entry['companies'])) {
                foreach ($entry['companies'] as $item) {
                    $names[] = $item;
                }
            }
            foreach ($names as $name) {
                if (strcasecmp(trim((string) $name), $company) === 0) {
                    $envName = trim($envName);
                    if ($envName !== '' && strcasecmp($envName, 'mimir') !== 0) {
                        return $envName;
                    }
                }
            }
        }
    }
    return null;
}

/**
 * Environment van het gevraagde bedrijf. Bij meerdere environments zonder
 * koppeling niet de primaire gokken.
 */
function vulcanus_bc_environment_for_company(string $company): ?string
{
    $mapped = vulcanus_bc_mapped_environment($company);
    if ($mapped !== null) {
        return $mapped;
    }
    $list = vulcanus_bc_environment_list();
    if (count($list) === 1) {
        return $list[0];
    }
    return null;
}

function vulcanus_bc_environment(): ?string
{
    return vulcanus_bc_environment_for_company(mimir_company());
}

function vulcanus_bc_auth_for_environment(string $env): ?array
{
    vulcanus_ensure_bc_auth_loaded();
    global $auth, $auth_list;
    if (isset($auth_list) && is_array($auth_list) && isset($auth_list[$env]) && vulcanus_bc_auth_is_usable($auth_list[$env])) {
        return $auth_list[$env];
    }
    $list = vulcanus_bc_environment_list();
    $primary = $list[0] ?? null;
    if ($primary !== null && strcasecmp($primary, $env) === 0 && isset($auth) && vulcanus_bc_auth_is_usable($auth)) {
        return $auth;
    }
    return null;
}

function vulcanus_bc_auth(): ?array
{
    $env = vulcanus_bc_environment();
    if ($env === null) {
        return null;
    }
    return vulcanus_bc_auth_for_environment($env);
}

/**
 * ODataV4-root. Een $baseUrl die al op /ODataV4 eindigt blijft zo
 * (SaaS: .../v2.0/<tenant>/<environment>/ODataV4). Anders Daedalus-stijl:
 * {base}/{environment}/ODataV4.
 */
function vulcanus_bc_odata_root_for_environment(string $env): ?string
{
    $base = vulcanus_bc_base_url();
    if ($base === null || $env === '') {
        return null;
    }
    if (preg_match('#/ODataV4$#i', $base) !== 1) {
        return $base . '/' . $env . '/ODataV4';
    }
    $known = vulcanus_bc_environment_list();
    global $auth_list;
    if (isset($auth_list) && is_array($auth_list)) {
        foreach ($auth_list as $name => $entry) {
            if (is_string($name) && $name !== '') {
                $known[] = $name;
            }
        }
    }
    foreach ($known as $name) {
        $name = trim((string) $name);
        if ($name === '' || strcasecmp($name, 'mimir') === 0) {
            continue;
        }
        $pattern = '#/' . preg_quote($name, '#') . '/ODataV4$#i';
        if (preg_match($pattern, $base) === 1) {
            $replaced = preg_replace($pattern, '/' . $env . '/ODataV4', $base, 1);
            return is_string($replaced) ? $replaced : $base;
        }
    }
    return $base;
}

function vulcanus_bc_odata_root(): ?string
{
    $env = vulcanus_bc_environment();
    if ($env === null) {
        return null;
    }
    return vulcanus_bc_odata_root_for_environment($env);
}

function vulcanus_bc_credentials_configured(): bool
{
    if (vulcanus_bc_odata_root() === null || vulcanus_bc_environment() === null) {
        return false;
    }
    return vulcanus_bc_auth() !== null;
}

/**
 * @return list<string>
 */
function vulcanus_odata_secrets(): array
{
    vulcanus_ensure_bc_auth_loaded();
    $secrets = [];
    $apiKey = mimir_api_key();
    if ($apiKey !== '') {
        $secrets[] = $apiKey;
    }
    global $auth, $auth_list;
    $entries = [];
    if (isset($auth) && is_array($auth)) {
        $entries[] = $auth;
    }
    if (isset($auth_list) && is_array($auth_list)) {
        foreach ($auth_list as $entry) {
            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }
    }
    foreach ($entries as $entry) {
        if (isset($entry['pass']) && is_string($entry['pass']) && $entry['pass'] !== '') {
            $secrets[] = $entry['pass'];
        }
    }
    usort($secrets, static function (string $a, string $b): int {
        return strlen($b) <=> strlen($a);
    });
    return $secrets;
}

function vulcanus_redact_sensitive(string $message): string
{
    foreach (vulcanus_odata_secrets() as $secret) {
        if ($secret !== '') {
            $message = str_replace($secret, '[redacted]', $message);
        }
    }
    $patterns = [
        '/Authorization\s*:[^\r\n]*/i' => 'Authorization: [redacted]',
        '/(Bearer\s+)\S+/i' => '$1[redacted]',
        '/(Basic\s+)[A-Za-z0-9+\/=]+/i' => '$1[redacted]',
        '#(https?://)[^/\s:@]+:[^/\s@]+@#i' => '$1',
    ];
    foreach ($patterns as $pattern => $replacement) {
        $sanitized = preg_replace($pattern, $replacement, $message);
        if (is_string($sanitized)) {
            $message = $sanitized;
        }
    }
    return $message;
}

function vulcanus_mimir_log_fallback(Throwable $exception): void
{
    error_log('[Vulcanus] Mímir failed, falling back to direct OData: ' . vulcanus_redact_sensitive($exception->getMessage()));
}

/**
 * Zelfde scheme, host en poort als de geconfigureerde OData-root. Alleen https,
 * en geen userinfo in de URL: credentials gaan niet naar een andere origin.
 */
function vulcanus_bc_same_origin(string $url, string $root): bool
{
    $page = parse_url($url);
    $base = parse_url($root);
    if (!is_array($page) || !is_array($base)) {
        return false;
    }
    if (isset($page['user']) || isset($page['pass']) || isset($base['user']) || isset($base['pass'])) {
        return false;
    }
    $schemeA = strtolower((string) ($page['scheme'] ?? ''));
    $schemeB = strtolower((string) ($base['scheme'] ?? ''));
    $hostA = (string) ($page['host'] ?? '');
    $hostB = (string) ($base['host'] ?? '');
    if ($schemeA !== 'https' || $schemeB !== 'https' || $hostA === '' || $hostB === '') {
        return false;
    }
    $portA = isset($page['port']) ? (int) $page['port'] : 443;
    $portB = isset($base['port']) ? (int) $base['port'] : 443;
    return strcasecmp($hostA, $hostB) === 0 && $portA === $portB;
}

/**
 * @param callable(): array $viaMimir
 * @param callable(): array $viaDirect
 * @return array
 */
function vulcanus_mimir_or_direct(callable $viaMimir, callable $viaDirect)
{
    if (vulcanus_mimir_circuit_open()) {
        $original = vulcanus_mimir_last_error();
        if (!vulcanus_bc_credentials_configured()) {
            if ($original instanceof Throwable) {
                throw $original;
            }
            throw new Exception('Mímir eerder mislukt.');
        }
        $reason = $original instanceof Throwable
            ? $original
            : new Exception('Mímir overgeslagen na eerdere fout.');
        vulcanus_mimir_log_fallback($reason);
        return $viaDirect();
    }

    try {
        return $viaMimir();
    } catch (Throwable $exception) {
        vulcanus_mimir_trip($exception);
        if (!vulcanus_bc_credentials_configured()) {
            throw $exception;
        }
        vulcanus_mimir_log_fallback($exception);
        return $viaDirect();
    }
}

/**
 * @param list<string> $select
 */
function vulcanus_bc_entity_url(string $table, string $filter, array $select): string
{
    $root = vulcanus_bc_odata_root();
    if ($root === null) {
        throw new Exception('Business Central-basis-URL ontbreekt ($baseUrl).');
    }

    $entity = trim($table);
    if ($entity === '' || preg_match('/^[A-Za-z0-9_]+$/', $entity) !== 1) {
        throw new Exception('Ongeldige OData-tabel.');
    }

    $url = $root . "/Company('" . rawurlencode(mimir_company()) . "')/" . $entity;
    $params = [];
    $filter = trim($filter);
    if ($filter !== '') {
        $params['$filter'] = $filter;
    }

    $cols = [];
    foreach ($select as $col) {
        if (!is_string($col)) {
            continue;
        }
        $col = trim($col);
        if ($col !== '' && preg_match('/^[A-Za-z0-9_]+$/', $col) === 1) {
            $cols[] = $col;
        }
    }
    if ($cols !== []) {
        $params['$select'] = implode(',', $cols);
    }
    if ($params !== []) {
        $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
    return $url;
}

/**
 * @return array<string, mixed>
 */
function vulcanus_bc_get_json(string $url, array $auth): array
{
    $root = vulcanus_bc_odata_root();
    if ($root === null || !vulcanus_bc_same_origin($url, $root)) {
        throw new Exception('OData-URL wijst naar een andere of onveilige origin.');
    }
    if (isset($GLOBALS['VULCANUS_ODATA_BC_HTTP']) && is_callable($GLOBALS['VULCANUS_ODATA_BC_HTTP'])) {
        $http = $GLOBALS['VULCANUS_ODATA_BC_HTTP'];
        $decoded = $http($url, $auth);
        if (!is_array($decoded)) {
            throw new Exception('OData test-transport gaf geen JSON terug.');
        }
        return $decoded;
    }
    if (!function_exists('curl_init')) {
        throw new Exception('cURL is niet beschikbaar voor Business Central.');
    }
    $ch = curl_init($url);
    if ($ch === false) {
        throw new Exception('Business Central cURL init mislukt.');
    }

    $mode = (string) ($auth['mode'] ?? 'basic');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => vulcanus_mimir_connect_timeout_seconds(),
        CURLOPT_TIMEOUT => vulcanus_mimir_timeout_seconds(),
        CURLOPT_USERAGENT => 'Vulcanus-ODataClient/1.0',
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Accept-Language: nl-NL,nl;q=0.9,en;q=0.8',
        ],
        CURLOPT_HTTPAUTH => $mode === 'ntlm' ? CURLAUTH_NTLM : CURLAUTH_BASIC,
        CURLOPT_USERPWD => (string) ($auth['user'] ?? '') . ':' . (string) ($auth['pass'] ?? ''),
    ]);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception(vulcanus_redact_sensitive('cURL error: ' . $err));
    }
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        throw new Exception(vulcanus_redact_sensitive('HTTP ' . $code . ' from OData'));
    }

    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        throw new Exception('Invalid JSON from OData');
    }
    return $decoded;
}

/**
 * @return list<array<string, mixed>>
 */
function vulcanus_bc_fetch_all(string $url, array $auth): array
{
    if (isset($GLOBALS['VULCANUS_ODATA_BC_FETCH']) && is_callable($GLOBALS['VULCANUS_ODATA_BC_FETCH'])) {
        $stub = $GLOBALS['VULCANUS_ODATA_BC_FETCH'];
        $rows = $stub($url, $auth);
        if (!is_array($rows)) {
            throw new Exception('OData test-transport gaf geen rijen terug.');
        }
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }
        return $out;
    }

    $root = vulcanus_bc_odata_root();
    $all = [];
    $next = $url;
    $pages = 0;
    while ($next !== '') {
        $pages++;
        if ($pages > 100) {
            throw new Exception('OData nextLink stopte niet.');
        }
        if ($root === null || !vulcanus_bc_same_origin($next, $root)) {
            throw new Exception('OData nextLink wijst naar een andere of onveilige origin.');
        }
        $json = vulcanus_bc_get_json($next, $auth);
        if (!isset($json['value']) || !is_array($json['value'])) {
            throw new Exception("OData response missing 'value' array");
        }
        foreach ($json['value'] as $row) {
            if (is_array($row)) {
                $all[] = $row;
            }
        }
        $link = $json['@odata.nextLink'] ?? null;
        $next = is_string($link) ? trim($link) : '';
    }
    return $all;
}

/**
 * @param list<string> $select
 * @return list<array<string, mixed>>
 */
function vulcanus_bc_query(string $table, string $filter, array $select): array
{
    if (!vulcanus_bc_credentials_configured()) {
        $previous = vulcanus_mimir_last_error();
        if ($previous instanceof Throwable) {
            throw $previous;
        }
        throw new Exception('Business Central-credentials ontbreken ($baseUrl, $auth, $environment).');
    }
    $auth = vulcanus_bc_auth();
    if ($auth === null) {
        $previous = vulcanus_mimir_last_error();
        if ($previous instanceof Throwable) {
            throw $previous;
        }
        throw new Exception('Business Central-credentials ontbreken ($baseUrl, $auth, $environment).');
    }
    return vulcanus_bc_fetch_all(vulcanus_bc_entity_url($table, $filter, $select), $auth);
}

/**
 * Zelfde contract als mimir_query. Lege $mimirApi slaat Mímir over.
 *
 * @param list<string> $select
 * @return list<array<string, mixed>>
 */
function vulcanus_odata_query(string $table, string $filter, array $select, int $maxAge = 300): array
{
    $direct = static function () use ($table, $filter, $select): array {
        return vulcanus_bc_query($table, $filter, $select);
    };
    if (!mimir_enabled()) {
        return $direct();
    }
    return vulcanus_mimir_or_direct(
        static function () use ($table, $filter, $select, $maxAge): array {
            return mimir_query($table, $filter, $select, $maxAge);
        },
        $direct
    );
}

/**
 * @return 'mimir'|'bc'
 */
function vulcanus_odata_source(): string
{
    if (mimir_enabled() && !vulcanus_mimir_circuit_open()) {
        return 'mimir';
    }
    return 'bc';
}

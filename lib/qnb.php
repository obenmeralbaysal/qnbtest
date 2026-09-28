<?php
declare(strict_types=1);

function qnb_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    return isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443';
}

function qnb_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = qnb_is_https();
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => $https ? 'None' : 'Lax',
    ]);
    session_start();
}

function qnb_hosts(): array
{
    return [
        'test' => 'https://vpostest.qnb.com.tr',
        'live' => 'https://vpos.qnb.com.tr',
    ];
}

function qnb_gateway(string $env, string $mode): string
{
    $hosts = qnb_hosts();
    $host = $hosts[$env] ?? $hosts['test'];
    $path = $mode === '3dhost' ? '/Gateway/3DHost.aspx' : '/Gateway/Default.aspx';
    return $host . $path;
}

function qnb_secure_type(string $mode): string
{
    return $mode === '3dhost' ? '3DHost' : '3DPay';
}

function qnb_demo(string $mode): array
{
    if ($mode === '3dhost') {
        return [
            'mbrId' => '5',
            'merchantId' => '085300000009746',
            'merchantPass' => '12345678',
            'userCode' => 'QNB_API_KULLANICI_HOST',
            'userPass' => 'Rxs42',
        ];
    }

    return [
        'mbrId' => '5',
        'merchantId' => '085300000009704',
        'merchantPass' => '12345678',
        'userCode' => 'QNB_API_KULLANICI_3DPAY',
        'userPass' => 'UcBN0',
    ];
}

function qnb_hash(string $plain): string
{
    return base64_encode(pack('H*', sha1($plain)));
}

function qnb_request_hash(
    string $mbrId,
    string $orderId,
    string $purchAmount,
    string $okUrl,
    string $failUrl,
    string $txnType,
    string $installmentCount,
    string $rnd,
    string $merchantPass
): string {
    $plain = $mbrId . $orderId . $purchAmount . $okUrl . $failUrl . $txnType . $installmentCount . $rnd . $merchantPass;
    return qnb_hash($plain);
}

function qnb_response_hash(
    string $merchantId,
    string $merchantPass,
    string $orderId,
    string $authCode,
    string $procReturnCode,
    string $threeDStatus,
    string $responseRnd,
    string $userCode
): string {
    $plain = $merchantId . $merchantPass . $orderId . $authCode . $procReturnCode . $threeDStatus . $responseRnd . $userCode;
    return qnb_hash($plain);
}

function qnb_callback_url(): string
{
    $scheme = qnb_is_https() ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $host = preg_replace('/[^A-Za-z0-9.:_-]/', '', $host) ?? 'localhost';
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $dir = rtrim(dirname($script), '/');
    return $scheme . '://' . $host . $dir . '/callback.php';
}

function qnb_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function qnb_mask(string $key, string $value): string
{
    $sensitive = ['pan', 'cvv2', 'cvv', 'userpass', 'merchantpass'];
    if (!in_array(strtolower($key), $sensitive, true) || $value === '') {
        return $value;
    }
    $len = strlen($value);
    if ($len <= 4) {
        return str_repeat('*', $len);
    }
    return str_repeat('*', $len - 4) . substr($value, -4);
}

function qnb_storage_dir(): string
{
    return dirname(__DIR__) . '/storage';
}

function qnb_context_path(string $orderId): string
{
    return qnb_storage_dir() . '/' . hash('sha256', $orderId) . '.json';
}

function qnb_cleanup_context_files(): void
{
    $dir = qnb_storage_dir();
    if (!is_dir($dir)) {
        return;
    }
    foreach (glob($dir . '/*.json') ?: [] as $file) {
        if (is_file($file) && filemtime($file) < time() - 7200) {
            unlink($file);
        }
    }
}

function qnb_store_context(array $ctx): void
{
    qnb_session_start();
    $_SESSION['qnb_payment'] = $ctx;

    $dir = qnb_storage_dir();
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        return;
    }
    qnb_cleanup_context_files();

    $orderId = (string) ($ctx['orderId'] ?? '');
    if ($orderId === '') {
        return;
    }

    $payload = json_encode($ctx, JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        return;
    }
    $path = qnb_context_path($orderId);
    file_put_contents($path, $payload, LOCK_EX);
    chmod($path, 0600);
}

function qnb_read_context_file(string $orderId): ?array
{
    if ($orderId === '') {
        return null;
    }
    $path = qnb_context_path($orderId);
    if (!is_file($path)) {
        return null;
    }
    if (filemtime($path) < time() - 7200) {
        unlink($path);
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function qnb_load_context(string $orderId): ?array
{
    $file = qnb_read_context_file($orderId);
    if ($file !== null) {
        return $file;
    }

    qnb_session_start();
    $session = $_SESSION['qnb_payment'] ?? null;
    if (is_array($session) && (string) ($session['orderId'] ?? '') === $orderId) {
        return $session;
    }
    return null;
}

function qnb_api_gateway(string $env): string
{
    return qnb_gateway($env, '3dpay');
}

function qnb_parse_gateway_response(string $raw): array
{
    $parsed = [];
    foreach (explode(';;', trim($raw)) as $part) {
        if ($part === '' || !str_contains($part, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $part, 2);
        $parsed[$key] = $value;
    }
    return $parsed;
}

function qnb_gateway_post(string $url, array $fields): array
{
    $body = http_build_query($fields);
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl başlatılamadı', 'raw' => ''];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
    ]);

    $result = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($result === false) {
        return ['ok' => false, 'error' => $error !== '' ? $error : 'İstek başarısız', 'raw' => ''];
    }

    return ['ok' => true, 'error' => '', 'raw' => $result];
}

function qnb_clear_context(string $orderId): void
{
    qnb_session_start();
    $session = $_SESSION['qnb_payment'] ?? null;
    if (is_array($session) && (string) ($session['orderId'] ?? '') === $orderId) {
        unset($_SESSION['qnb_payment']);
    }
    $path = qnb_context_path($orderId);
    if (is_file($path)) {
        unlink($path);
    }
}

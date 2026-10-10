<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('default_charset', '');
ini_set('log_errors', '1');
$buffer_level = ob_get_level();
ob_start();
$request_id = bin2hex(random_bytes(8));
$started = microtime(true);
$server = null;
header('X-Content-Type-Options: nosniff');
header('X-Request-ID: '.$request_id);
header('Cache-Control: no-cache');
try {
    foreach (['dom', 'gd', 'pdo_sqlite', 'xmlwriter', 'mbstring', 'iconv'] as $extension) {
        if (!extension_loaded($extension)) {
            while (ob_get_level() > $buffer_level) {
                ob_end_clean();
            }
            http_response_code(503);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Missing PHP extension: '.$extension.'. See INSTALLATION.md.';
            exit;
        }
    }
    require dirname(__DIR__).'/vendor/autoload.php';
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        header('Allow: GET');
        throw new PureWms\WmsException('Only HTTP GET is supported.', 'OperationNotSupported', 405);
    }
    $config = new PureWms\Config(dirname(__DIR__));
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed_origins = $config->server['cors_origins'] ?? [];
    if (in_array('*', $allowed_origins, true)) {
        header('Access-Control-Allow-Origin: *');
    } elseif ($allowed_origins !== []) {
        header('Vary: Origin');
        if (in_array($origin, $allowed_origins, true)) {
            header('Access-Control-Allow-Origin: '.$origin);
        }
    }
    $request = PureWms\Request::fromQuery($_SERVER['QUERY_STRING'] ?? '');
    $server = new PureWms\Server($config);
    [$mime,$body] = $server->handle($request);
    header('X-WMS-Cache: '.$server->diagnostics['cache']);
    header('Server-Timing: wms;dur='.round((microtime(true) - $started) * 1000, 2));
    while (ob_get_level() > $buffer_level) {
        ob_end_clean();
    }
    $etag = '"'.hash('sha256', $body).'"';
    header('ETag: '.$etag);
    if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
    } else {
        header('Content-Type: '.$mime);
        header('Content-Length: '.strlen($body));
        echo $body;
    }
} catch (Throwable $error) {
    while (ob_get_level() > $buffer_level) {
        ob_end_clean();
    }
    if (!$error instanceof PureWms\WmsException) {
        error_log('WMS '.$request_id.': '.$error->getMessage());
        if (!class_exists(PureWms\WmsException::class)) {
            http_response_code(503);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Installation incomplete. Check the PHP error log. Reference: '.$request_id;
            exit;
        }
        $error = new PureWms\WmsException('Service unavailable. Reference: '.$request_id, 'NoApplicableCode', 503);
    }
    if ($server !== null) { $server->diagnostics['failure'] = $error->getMessage(); $server->diagnostics['wms_code'] = $error->wmsCode; }
    http_response_code($error->status);
    if ($error->status === 503) {
        header('Retry-After: 5');
    }
    header('Content-Type: text/xml; charset=UTF-8');
    header('Cache-Control: no-store');
    echo PureWms\Capabilities::exception($error);
}
 finally {
    if (isset($config) && $config->server['log_requests']) {
        error_log(json_encode(['event' => 'wms_request', 'request_id' => $request_id,
            'status' => http_response_code(), 'duration_ms' => round((microtime(true) - $started) * 1000, 2),
            'peak_php_bytes' => memory_get_peak_usage(true)] + ($server?->diagnostics ?? []), JSON_UNESCAPED_SLASHES));
    }
}

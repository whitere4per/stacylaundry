<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function (Throwable $exception): void {
    error_log('Unhandled exception: ' . $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine());
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code(500);
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
    }
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Server error | Stacey Laundry Hub</title><body style="font-family:Arial,sans-serif;background:#f4f7fb;color:#1d2636;padding:32px"><main style="max-width:560px;margin:10vh auto;background:#fff;border:1px solid #e4eaf2;border-radius:16px;padding:28px"><h1>Something went wrong</h1><p>Your change was not confirmed as saved. Please try again or contact the administrator if the problem continues.</p></main></body></html>';
    exit;
});

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    || (isset($_SERVER['HTTP_CF_VISITOR']) && strpos($_SERVER['HTTP_CF_VISITOR'], '"scheme":"https"') !== false);

session_set_cookie_params([
    'lifetime' => 3600,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax'
]);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
function redirect(string $path): void {
    if (headers_sent()) {
        echo '<script>window.location.assign(' . json_encode($path, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) . ');</script>';
        exit;
    }

    header('Location: ' . $path, true, 303);
    exit;
}
function bootMessage(string $type, string $message, bool $critical = false): void {
    $types = ['success', 'error', 'warning', 'information'];
    if (!in_array($type, $types, true)) {
        $type = 'information';
    }

    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    } elseif (isset($_SESSION['flash']['message'])) {
        $_SESSION['flash'] = [$_SESSION['flash']];
    }

    $_SESSION['flash'][] = [
        'type' => $type,
        'message' => $message,
        'critical' => $critical
    ];
}
function flash(): array {
    if (empty($_SESSION['flash'])) {
        return [];
    }

    $items = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return isset($items['message']) ? [$items] : $items;
}
function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
function safePost(string $key, $default = ''): string {
    return isset($_POST[$key]) ? trim((string) $_POST[$key]) : (string) $default;
}

function logApplicationError(Throwable $exception, string $context): void {
    error_log(sprintf(
        '[%s] %s: %s in %s:%d',
        $context,
        get_class($exception),
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));
}

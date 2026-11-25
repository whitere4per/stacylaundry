<?php
require_once __DIR__ . '/../app/helpers.php';
$user = currentUser();
$pdo = null;
try {
    if ($user || isset($_COOKIE['stacey_remember'])) {
        $pdo = db();
        $pdo->beginTransaction();
        clearRememberCookie($pdo);
        if ($user) {
            logActivity($pdo, 'Logout', 'User: ' . ($user['username'] ?? 'unknown'));
        }
        $pdo->commit();
    }
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logApplicationError($e, 'logout');
    clearRememberCookie();
}
$_SESSION = [];
session_regenerate_id(true);
bootMessage('success', 'You have been logged out.');
redirect('login.php');

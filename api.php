<?php
/**
 * JSON API front controller:  api.php?r=<module>/<action>
 * Every POST must carry the CSRF token (X-CSRF-Token header or _token field).
 */
require __DIR__ . '/app/bootstrap.php';

try {
    $route = (string) ($_GET['r'] ?? '');
    if (!preg_match('#^([a-z_]+)/([a-z_]+)$#', $route, $m)) {
        throw new AppError('Unknown endpoint.', 404);
    }
    [, $module, $action] = $m;

    if (!Auth::check()) {
        throw new AppError('Your session has ended. Please sign in again.', 401);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        csrf_verify();
    }

    $file = APP_PATH . "/api/$module.php";
    if (!is_file($file)) {
        throw new AppError('Unknown endpoint.', 404);
    }
    $handlers = require $file;
    if (!isset($handlers[$action])) {
        throw new AppError('Unknown endpoint.', 404);
    }

    $result = $handlers[$action]();
    json_out(['ok' => true] + (is_array($result) ? $result : []));
} catch (ValidationError $e) {
    json_out(['ok' => false, 'message' => $e->getMessage(), 'errors' => $e->errors], 422);
} catch (AppError $e) {
    json_out(['ok' => false, 'message' => $e->getMessage()], $e->status);
} catch (PDOException $e) {
    error_log('[api] ' . $e->getMessage());
    if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
        json_out(['ok' => false, 'message' => 'A record with the same unique value already exists.'], 409);
    }
    json_out(['ok' => false, 'message' => config('debug') ? 'Database error: ' . $e->getMessage() : 'A database error occurred. Please try again.'], 500);
} catch (Throwable $e) {
    error_log('[api] ' . $e);
    json_out(['ok' => false, 'message' => config('debug') ? $e->getMessage() : 'Something went wrong. Please try again.'], 500);
}

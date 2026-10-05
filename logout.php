<?php
require __DIR__ . '/app/bootstrap.php';

// Logout is a POST with the CSRF token so other sites cannot force a sign-out.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect('index.php');
}
try {
    csrf_verify();
} catch (AppError) {
    redirect('index.php');
}

if (Auth::check()) {
    audit('logout', 'user', Auth::id(), 'Signed out');
}
Auth::logout();
redirect('login.php');

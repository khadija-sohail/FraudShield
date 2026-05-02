<?php
// Entry point for FraudShield — routes API calls to db.php,
// serves the dashboard HTML for everything else.

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Route API requests to the backend
if ($uri === '/db.php' || str_starts_with($uri, '/api')) {
    require __DIR__ . '/db.php';
    exit;
}

// Serve the dashboard for all other requests
readfile(__DIR__ . '/fraud_dashboardd.html');

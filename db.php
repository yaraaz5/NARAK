<?php
// Configure credentials outside of the repository using your local environment.
// In production, use a restricted database user and disable detailed error output.
$host = getenv('NARAK_DB_HOST') ?: 'localhost';
$username = getenv('NARAK_DB_USER') ?: '';
$password = getenv('NARAK_DB_PASSWORD');
$database = getenv('NARAK_DB_NAME') ?: 'narak_db';
$port = (int) (getenv('NARAK_DB_PORT') ?: 3306);

if ($username === '' || $password === false) {
    error_log('NARAK database environment variables not configured');
    http_response_code(500);
    exit('Database configuration unavailable.');
}

$conn = mysqli_connect($host, $username, $password, $database, $port);
if (!$conn) {
    error_log('NARAK database connection failed: ' . mysqli_connect_error());
    http_response_code(500);
    exit('Database connection unavailable.');
}
mysqli_set_charset($conn, 'utf8mb4');

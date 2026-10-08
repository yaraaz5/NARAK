<?php
require_once "db.php";
session_start();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: index.php");
    exit;
}

$firstName = trim($_POST["first_name"] ?? "");
$lastName  = trim($_POST["last_name"] ?? "");
$email     = trim($_POST["email"] ?? "");
$phone     = trim($_POST["phone"] ?? "");
$password  = $_POST["password"] ?? "";
$address   = trim($_POST["address"] ?? "");

$_SESSION['signup_form'] = [
    'first_name' => $firstName,
    'last_name'  => $lastName,
    'email'      => $email,
    'phone'      => $phone,
    'address'    => $address,
];

function redirect_signup_error(string $code): never {
    header("Location: index.php?panel=signup&error=$code");
    exit;
}

if (empty($firstName) || empty($lastName)) redirect_signup_error('missing_name');
if (empty($email))     redirect_signup_error('missing_email');
if (empty($phone))     redirect_signup_error('missing_phone');
if (empty($password))  redirect_signup_error('missing_password');

if (!preg_match('/^[\x{0600}-\x{06FF}\s]+$/u', $firstName) ||
    !preg_match('/^[\x{0600}-\x{06FF}\s]+$/u', $lastName)) {
    redirect_signup_error('invalid_name');
}

if (!preg_match('/^\d{10}$/', $phone)) {
    redirect_signup_error('invalid_phone');
}

if (strlen($password) < 8) {
    redirect_signup_error('password_too_short');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    redirect_signup_error('invalid_email');
}

if (!empty($address) && !filter_var($address, FILTER_VALIDATE_URL)) {
    redirect_signup_error('invalid_address');
}

$checkSql = "SELECT customer_id FROM customer WHERE email = ?";
$stmt = mysqli_prepare($conn, $checkSql);

if (!$stmt) {
    die("Prepare failed: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($stmt, "s", $email);
mysqli_stmt_execute($stmt);
mysqli_stmt_store_result($stmt);

if (mysqli_stmt_num_rows($stmt) > 0) {
    mysqli_stmt_close($stmt);
    header("Location: index.php?panel=signup&error=email_exists");
    exit;
}

mysqli_stmt_close($stmt);

$checkPhone = "SELECT customer_id FROM customer WHERE phone_number = ?";
$stmtPhone = mysqli_prepare($conn, $checkPhone);
mysqli_stmt_bind_param($stmtPhone, "s", $phone);
mysqli_stmt_execute($stmtPhone);
mysqli_stmt_store_result($stmtPhone);
if (mysqli_stmt_num_rows($stmtPhone) > 0) {
    mysqli_stmt_close($stmtPhone);
    header("Location: index.php?panel=signup&error=phone_exists");
    exit;
}
mysqli_stmt_close($stmtPhone);

$passwordHash = password_hash($password, PASSWORD_DEFAULT);

$insertSql = "INSERT INTO customer (first_name, last_name, email, phone_number, password_hash, address)
              VALUES (?, ?, ?, ?, ?, ?)";

$stmt = mysqli_prepare($conn, $insertSql);

if (!$stmt) {
    die("Prepare failed: " . mysqli_error($conn));
}

mysqli_stmt_bind_param(
    $stmt,
    "ssssss",
    $firstName,
    $lastName,
    $email,
    $phone,
    $passwordHash,
    $address
);

if (mysqli_stmt_execute($stmt)) {
    unset($_SESSION['signup_form']);
    $_SESSION["user_id"] = mysqli_insert_id($conn);
    $_SESSION["role"] = "customer";
    $_SESSION["full_name"] = $firstName . " " . $lastName;
    $_SESSION["email"] = $email;

    mysqli_stmt_close($stmt);
    header("Location: customer-dashboard.php?welcome=signup");
    exit;
} else {
    mysqli_stmt_close($stmt);
    header("Location: index.php?panel=signup&error=signup_failed");
    exit;
}
?>
<?php
require_once "db.php";
session_start();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: index.php");
    exit;
}

$email = trim($_POST["email"] ?? "");
$password = $_POST["password"] ?? "";
$role = $_POST["role"] ?? "";

if (empty($email) || empty($password) || empty($role)) {
    header("Location: index.php?error=empty_login");
    exit;
}

if ($role === "customer") {
    $sql = "SELECT customer_id AS id, first_name, last_name, email, password_hash, status
            FROM customer
            WHERE email = ?";
} elseif ($role === "lab") {
    $sql = "SELECT lab_id AS id, lab_name, email, password_hash
            FROM laboratory
            WHERE email = ?";
} elseif ($role === "admin") {
    $sql = "SELECT admin_id AS id, full_name, email, password_hash
            FROM admin
            WHERE email = ?";
} else {
    header("Location: index.php?error=invalid_role");
    exit;
}

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    die("Prepare failed: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($stmt, "s", $email);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if ($row = mysqli_fetch_assoc($result)) {
    if (password_verify($password, $row["password_hash"])) {

        $_SESSION["user_id"] = $row["id"];
        $_SESSION["role"] = $role;
        $_SESSION["email"] = $row["email"];

        if ($role === "customer") {
            if (($row["status"] ?? '') === 'blocked') {
                header("Location: index.php?error=account_blocked");
                exit;
            }
            $_SESSION["full_name"] = $row["first_name"] . " " . $row["last_name"];
            header("Location: customer-dashboard.php?welcome=login");
            exit;
        }

        if ($role === "lab") {
            $_SESSION["full_name"] = $row["lab_name"];
            header("Location: lab-dashboard.php?welcome=login");
            exit;
        }

        if ($role === "admin") {
            $_SESSION["full_name"] = $row["full_name"];
            header("Location: admin-dashboard.php?welcome=login");
            exit;
        }

    } else {
        header("Location: index.php?error=wrong_password");
        exit;
    }
} else {
    header("Location: index.php?error=user_not_found");
    exit;
}
?>
<?php
require_once "db.php";
session_start();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Sat, 01 Jan 2000 00:00:00 GMT");

if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "admin") {
    header("Location: index.php");
    exit;
}

$adminName = $_SESSION["full_name"] ?? "مدير النظام";
/* test*/

/* حظر عميل */
if (isset($_GET["block_customer"]) && isset($_GET["report_id"])) {
    $customerId = (int) $_GET["block_customer"];
    $reportId = (int) $_GET["report_id"];

    $blockQuery = mysqli_prepare($conn, "UPDATE customer SET status = 'blocked' WHERE customer_id = ?");
    mysqli_stmt_bind_param($blockQuery, "i", $customerId);
    mysqli_stmt_execute($blockQuery);

    $closeReportQuery = mysqli_prepare($conn, "UPDATE report SET status = 'closed' WHERE report_id = ?");
    mysqli_stmt_bind_param($closeReportQuery, "i", $reportId);
    mysqli_stmt_execute($closeReportQuery);

    header("Location: admin-dashboard.php");
    exit;
}

/* حذف مختبر */
if (isset($_GET["delete_lab"])) {
    $labId = (int) $_GET["delete_lab"];

    $deleteTests = mysqli_prepare($conn, "DELETE FROM test_type WHERE lab_id = ?");
    mysqli_stmt_bind_param($deleteTests, "i", $labId);
    mysqli_stmt_execute($deleteTests);

    $deleteSlots = mysqli_prepare($conn, "DELETE FROM time_slot WHERE lab_id = ?");
    mysqli_stmt_bind_param($deleteSlots, "i", $labId);
    mysqli_stmt_execute($deleteSlots);

    $deleteLab = mysqli_prepare($conn, "DELETE FROM laboratory WHERE lab_id = ?");
    mysqli_stmt_bind_param($deleteLab, "i", $labId);
    mysqli_stmt_execute($deleteLab);

    header("Location: admin-dashboard.php");
    exit;
}

/* إضافة مختبر */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_lab"])) {
    $lab_name = trim($_POST["lab_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $phone_number = trim($_POST["phone_number"] ?? "");
    $password = $_POST["password"] ?? "";
    $address = trim($_POST["address"] ?? "");

    $lab_logo = "images/2.png";

    if (isset($_FILES["lab_logo_file"]) && $_FILES["lab_logo_file"]["error"] === 0) {
        $uploadDir = "images/";
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $fileName = time() . "_" . basename($_FILES["lab_logo_file"]["name"]);
        $targetFile = $uploadDir . $fileName;

        if (move_uploaded_file($_FILES["lab_logo_file"]["tmp_name"], $targetFile)) {
            $lab_logo = $targetFile;
        }
    }

    if (!empty($lab_name) && !empty($email) && !empty($phone_number) && !empty($password) && !empty($address)) {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = mysqli_prepare($conn, "INSERT INTO laboratory (lab_name, lab_logo, email, phone_number, address, password_hash) VALUES (?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, "ssssss", $lab_name, $lab_logo, $email, $phone_number, $address, $password_hash);
        $inserted = mysqli_stmt_execute($stmt);
        $newLabId = (int)mysqli_insert_id($conn);

        if (!$inserted || $newLabId === 0) {
            header("Location: admin-dashboard.php?tab=labs&lab_error=duplicate");
            exit;
        }

        /* التحاليل الجاهزة مع الأسعار */
        if (!empty($_POST["tests"]) && is_array($_POST["tests"])) {
            $testPrices = $_POST["test_prices"] ?? [];

            $testInsert = mysqli_prepare($conn, "
                INSERT INTO test_type (lab_id, test_name, price, unit, normal_range)
                VALUES (?, ?, ?, ?, ?)
            ");

            foreach ($_POST["tests"] as $testName) {
                $price = isset($testPrices[$testName]) && $testPrices[$testName] !== "" ? (float)$testPrices[$testName] : 0.00;
                $unit = "";
                $normalRange = "";

                mysqli_stmt_bind_param($testInsert, "isdss", $newLabId, $testName, $price, $unit, $normalRange);
                mysqli_stmt_execute($testInsert);
            }
        }

        /* التحاليل الجديدة */
        if (!empty($_POST["custom_test_names"]) && is_array($_POST["custom_test_names"])) {
            $customNames = $_POST["custom_test_names"] ?? [];
            $customRanges = $_POST["custom_test_ranges"] ?? [];
            $customUnits = $_POST["custom_test_units"] ?? [];
            $customPrices = $_POST["custom_test_prices"] ?? [];

            $customInsert = mysqli_prepare($conn, "
                INSERT INTO test_type (lab_id, test_name, price, unit, normal_range)
                VALUES (?, ?, ?, ?, ?)
            ");

            for ($i = 0; $i < count($customNames); $i++) {
                $testName = trim($customNames[$i] ?? "");
                $normalRange = trim($customRanges[$i] ?? "");
                $unit = trim($customUnits[$i] ?? "");
                $price = isset($customPrices[$i]) && $customPrices[$i] !== "" ? (float)$customPrices[$i] : 0.00;

                if ($testName !== "") {
                    mysqli_stmt_bind_param($customInsert, "isdss", $newLabId, $testName, $price, $unit, $normalRange);
                    mysqli_stmt_execute($customInsert);
                }
            }
        }

        header("Location: admin-dashboard.php?tab=labs&lab_success=1");
        exit;
    } else {
        header("Location: admin-dashboard.php?tab=labs&lab_error=missing");
        exit;
    }
}

/* Migration: add created_at column if it doesn't exist */
$colCheck = mysqli_query($conn, "SHOW COLUMNS FROM appointment LIKE 'created_at'");
if (mysqli_num_rows($colCheck) === 0) {
    mysqli_query($conn, "ALTER TABLE appointment ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
}

/* Auto-status: overdue — date has already passed and lab never approved */
mysqli_query($conn, "
    UPDATE appointment a
    JOIN time_slot ts ON a.slot_id = ts.slot_id
    SET a.status = 'overdue'
    WHERE a.status IN ('pending', 'delayed')
      AND ts.slot_date < CURDATE()
");

/* Auto-status: delayed — pending for > 3 days since booking but date not yet passed */
mysqli_query($conn, "
    UPDATE appointment a
    JOIN time_slot ts ON a.slot_id = ts.slot_id
    SET a.status = 'delayed'
    WHERE a.status = 'pending'
      AND DATEDIFF(CURDATE(), a.created_at) > 3
      AND ts.slot_date >= CURDATE()
");

/* إحصائيات */
$totalCustomers = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM customer"))["total"] ?? 0;
$totalLabs = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM laboratory"))["total"] ?? 0;
$totalAppointments = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM appointment"))["total"] ?? 0;
$openReports = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM report WHERE status = 'open'"))["total"] ?? 0;

/* المختبرات */
$labsQuery = mysqli_query($conn, "
    SELECT l.lab_id, l.lab_name, l.lab_logo, l.address,
           GROUP_CONCAT(t.test_name SEPARATOR '، ') AS tests
    FROM laboratory l
    LEFT JOIN test_type t ON l.lab_id = t.lab_id
    GROUP BY l.lab_id
");

/* آخر الطلبات */
$latestAppointments = mysqli_query($conn, "
    SELECT a.appointment_id, a.status, ts.slot_date,
           CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
           l.lab_name,
           GROUP_CONCAT(tt.test_name SEPARATOR '، ') AS tests
    FROM appointment a
    JOIN customer c ON a.customer_id = c.customer_id
    JOIN laboratory l ON a.lab_id = l.lab_id
    JOIN time_slot ts ON a.slot_id = ts.slot_id
    LEFT JOIN appointment_test_type att ON a.appointment_id = att.appointment_id
    LEFT JOIN test_type tt ON att.test_type_id = tt.test_type_id
    GROUP BY a.appointment_id
    ORDER BY a.appointment_id DESC
    LIMIT 3
");

/* ملخص البلاغات */
$summaryReports = mysqli_query($conn, "
    SELECT r.report_id, r.reason, r.report_date,
           CONCAT(c.first_name, ' ', c.last_name) AS customer_name
    FROM report r
    JOIN customer c ON r.customer_id = c.customer_id
    WHERE r.status = 'open'
    ORDER BY r.report_id DESC
    LIMIT 2
");

/* كل البلاغات */
$reportsQuery = mysqli_query($conn, "
    SELECT r.report_id, r.customer_id, r.reason, r.report_date, r.status,
           c.email, c.phone_number,
           CONCAT(c.first_name, ' ', c.last_name) AS customer_name
    FROM report r
    JOIN customer c ON r.customer_id = c.customer_id
    WHERE r.status = 'open'
    ORDER BY r.report_id DESC
");

/* كل الطلبات */
$allAppointments = mysqli_query($conn, "
    SELECT a.appointment_id, a.status, ts.slot_date, ts.slot_time,
           CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
           c.email AS customer_email, c.phone_number AS customer_phone,
           l.lab_name, l.address AS lab_address, l.phone_number AS lab_phone,
           GROUP_CONCAT(tt.test_name SEPARATOR '، ') AS tests,
           COALESCE(SUM(tt.price), 0) AS total_price
    FROM appointment a
    JOIN customer c ON a.customer_id = c.customer_id
    JOIN laboratory l ON a.lab_id = l.lab_id
    JOIN time_slot ts ON a.slot_id = ts.slot_id
    LEFT JOIN appointment_test_type att ON a.appointment_id = att.appointment_id
    LEFT JOIN test_type tt ON att.test_type_id = tt.test_type_id
    GROUP BY a.appointment_id
    ORDER BY a.appointment_id DESC
");
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>نرعاك - لوحة المدير</title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800&display=swap" rel="stylesheet">
<style>
  :root {
    --deep-red: #520000;
    --muted-brown: #BD9E77;
    --light-beige: #ECC590;
    --medium-brown: #8E775E;
    --ivory: #FFFFF0;
    --sidebar-w: 240px;
  }
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'Tajawal', sans-serif; background: #f5f0e8; min-height: 100vh; color: #333; }

  .sidebar {
    position: fixed; top: 0; right: 0;
    width: var(--sidebar-w); height: 100vh;
    background: #1a0a00;
    display: flex; flex-direction: column; z-index: 100;
  }

  .sidebar-logo { padding: 28px 24px 20px; border-bottom: 1px solid rgba(255,255,255,0.07); }
  .sidebar-logo h2 { font-size: 1.6rem; font-weight: 800; color: #fff; }
  .sidebar-logo p { font-size: 0.75rem; color: rgba(255,255,255,0.4); margin-top: 2px; }
  .logo-img{
  width:120px;
  height:90px;
  display:block;
  margin:0 auto;
}

  .sidebar-user {
    padding: 18px 24px; display: flex; align-items: center; gap: 12px;
    border-bottom: 1px solid rgba(255,255,255,0.07);
  }
  .user-avatar {
    width: 38px; height: 38px; background: var(--light-beige);
    border-radius: 50%; display: flex; align-items: center; justify-content: center;
    font-size: 1rem; font-weight: 700; color: var(--deep-red); flex-shrink: 0;
  }
  .user-info strong { font-size: 0.88rem; color: #fff; display: block; font-weight: 600; }
  .user-info span { font-size: 0.75rem; color: rgba(255,255,255,0.4); }

  nav { flex: 1; padding: 16px 12px; overflow-y: auto; }
  .nav-section { font-size: 0.68rem; font-weight: 700; color: rgba(255,255,255,0.25); letter-spacing: 1.2px; text-transform: uppercase; padding: 12px 12px 6px; }
  .nav-item {
    display: flex; align-items: center; gap: 12px; padding: 11px 14px;
    border-radius: 10px; cursor: pointer; transition: all 0.2s; margin-bottom: 2px;
    color: rgba(255,255,255,0.55); font-size: 0.88rem; font-weight: 500; text-decoration: none;
  }
  .nav-item:hover { background: rgba(255,255,255,0.07); color: #fff; }
  .nav-item.active { background: rgba(82,0,0,0.4); color: #fff; font-weight: 600; border-right: 3px solid var(--light-beige); }
  .nav-item .icon { font-size: 1.1rem; width: 20px; text-align: center; }

  .sidebar-footer { padding: 16px 12px; border-top: 1px solid rgba(255,255,255,0.07); }
  .logout-btn {
    display: flex; align-items: center; gap: 10px; padding: 11px 14px;
    border-radius: 10px; cursor: pointer; color: rgba(255,255,255,0.4);
    font-size: 0.85rem; transition: all 0.2s; text-decoration: none;
  }
  .logout-btn:hover { color: #fff; background: rgba(255,255,255,0.06); }

  .main { margin-right: var(--sidebar-w); min-height: 100vh; padding: 32px 36px; }

  .page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 32px; }
  .page-title h1 { font-size: 1.6rem; font-weight: 800; color: var(--deep-red); }
  .page-title p { font-size: 0.85rem; color: var(--medium-brown); margin-top: 2px; }

  .btn { padding: 10px 22px; border-radius: 10px; font-family: 'Tajawal', sans-serif; font-size: 0.9rem; font-weight: 600; cursor: pointer; border: none; transition: all 0.2s; }
  .btn-primary { background: var(--deep-red); color: #fff; }
  .btn-primary:hover { background: #3d0000; transform: translateY(-1px); }
  .btn-success { background: #2d7a3a; color: #fff; }

  .stats-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 16px; margin-bottom: 28px; }
  .stat-card {
    background: #fff; border-radius: 16px; padding: 22px 20px;
    position: relative; overflow: hidden; box-shadow: 0 2px 12px rgba(0,0,0,0.05);
  }
  .stat-card::after {
    content: ''; position: absolute; top: 0; right: 0;
    width: 4px; height: 100%; border-radius: 0 16px 16px 0;
  }
  .stat-card:nth-child(1)::after { background: var(--deep-red); }
  .stat-card:nth-child(2)::after { background: var(--muted-brown); }
  .stat-card:nth-child(3)::after { background: #2d7a3a; }
  .stat-card:nth-child(4)::after { background: #c8860a; }
  .stat-num { font-size: 2rem; font-weight: 800; line-height: 1; }
  .stat-card:nth-child(1) .stat-num { color: var(--deep-red); }
  .stat-card:nth-child(2) .stat-num { color: var(--muted-brown); }
  .stat-card:nth-child(3) .stat-num { color: #2d7a3a; }
  .stat-card:nth-child(4) .stat-num { color: #c8860a; }
  .stat-label { font-size: 0.8rem; color: #888; margin-top: 6px; font-weight: 500; }

  .card { background: #fff; border-radius: 16px; padding: 24px; box-shadow: 0 2px 12px rgba(0,0,0,0.05); margin-bottom: 20px; }
  .card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
  .card-title { font-size: 1rem; font-weight: 700; color: #222; }

  .status-badge { font-size: 0.72rem; font-weight: 600; padding: 5px 12px; border-radius: 20px; white-space: nowrap; }
  .status-pending   { background: #fff8e6; color: #c8860a; }
  .status-confirmed { background: #e8f4ea; color: #2d7a3a; }
  .status-progress  { background: #e8f0fc; color: #2a5cc4; }
  .status-done      { background: #f0f0f0; color: #666; }
  .status-waiting   { background: #fce8e8; color: #c42a2a; }
  .status-delayed   { background: #fff3e0; color: #e65100; }
  .status-overdue   { background: #fce4ec; color: #880e4f; }

  .section { display: none; }
  .section.active { display: block; }

  .table-header, .table-row {
    display: grid; gap: 8px; padding: 10px 0; align-items: center;
  }

.appointment-side {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 10px;
}

.lab-actions {
  position: absolute;
  left: 14px; 
  top: 50%;
  transform: translateY(-50%); 
}
.lab-action-btn {
  font-family: 'Tajawal', sans-serif;
  font-size: 0.75rem;
  padding: 6px 12px;
  border-radius: 8px;
  border: 1.5px solid #e8e0d8;
  background: #fff;
  color: #444;
  cursor: pointer;
  transition: all 0.2s;
  text-decoration: none;
}

.lab-action-btn:hover {
  border-color: var(--deep-red);
  color: var(--deep-red);
}

.lab-action-btn.delete {
  border-color: #f3d3d3;
  color: #c42a2a;
}

.lab-action-btn.delete:hover {
  background: #fff5f5;
  border-color: #e7bcbc;
}
  .table-header { font-size: 0.72rem; font-weight: 700; color: #aaa; letter-spacing: 0.5px; border-bottom: 2px solid #f0ebe4; padding-bottom: 10px; }
  .table-row { border-bottom: 1px solid #f0ebe4; padding: 12px 0; }
  .table-row:last-child { border-bottom: none; }

  .badge-active { font-size: 0.7rem; font-weight: 600; padding: 4px 10px; border-radius: 20px; background: #e8f4ea; color: #2d7a3a; }
  .badge-blocked { font-size: 0.7rem; font-weight: 600; padding: 4px 10px; border-radius: 20px; background: #fce8e8; color: #c42a2a; }
  .badge-waiting { font-size: 0.7rem; font-weight: 600; padding: 4px 10px; border-radius: 20px; background: #fff8e6; color: #c8860a; }

  .home-grid {
    display: grid;
    grid-template-columns: 1.2fr 1fr;
    gap: 20px;
  }

  .labs-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
  }

  .lab-home-card {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px;
    background: #faf8f5;
    border-radius: 12px;
    border: 1px solid #f0ebe4;
    position: relative;
  }

  .lab-logo-box {
    width: 46px;
    height: 46px;
    border-radius: 12px;
    background: #f1e8df;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    overflow: hidden;
  }

  .lab-logo-box img {
    width: 50px;
    height: 50px;
    object-fit: contain;
    display: block;
  }
.lab-details {
  flex: 1;
  padding-left: 60px;
}
  .lab-details strong {
    display: block;
    font-size: 0.88rem;
    color: #222;
    font-weight: 700;
  }

  .lab-details span {
    display: block;
    font-size: 0.75rem;
    color: #999;
    margin-top: 2px;
  }

  .lab-tests-line {
    font-size: 0.72rem;
    color: var(--medium-brown);
    margin-top: 4px;
  }

  /* Appointment detail modal */
  .appt-modal-overlay {
    display: none;
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.45);
    z-index: 500;
    align-items: center;
    justify-content: center;
  }
  .appt-modal-overlay.open { display: flex; }
  .appt-modal {
    background: #fff;
    border-radius: 20px;
    width: 520px;
    max-width: 95vw;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 16px 48px rgba(0,0,0,0.18);
    padding: 32px 30px 28px;
    position: relative;
    direction: rtl;
  }
  .appt-modal-close {
    position: absolute; top: 18px; left: 20px;
    background: none; border: none; font-size: 1.3rem;
    cursor: pointer; color: #aaa; line-height: 1;
  }
  .appt-modal-close:hover { color: var(--deep-red); }
  .appt-modal-title {
    font-size: 1.1rem; font-weight: 800; color: var(--deep-red); margin-bottom: 4px;
  }
  .appt-modal-id { font-size: 0.75rem; color: #aaa; margin-bottom: 20px; }
  .appt-modal-section { margin-bottom: 18px; }
  .appt-modal-section-label {
    font-size: 0.68rem; font-weight: 700; color: #bbb;
    letter-spacing: 0.8px; text-transform: uppercase;
    margin-bottom: 8px; border-bottom: 1px solid #f0ebe4; padding-bottom: 5px;
  }
  .appt-modal-grid {
    display: grid; grid-template-columns: 1fr 1fr; gap: 10px;
  }
  .appt-modal-field { display: flex; flex-direction: column; gap: 2px; }
  .appt-modal-field span { font-size: 0.72rem; color: #aaa; }
  .appt-modal-field strong { font-size: 0.88rem; color: #222; font-weight: 600; }
  .appt-modal-tests {
    background: #faf8f5; border-radius: 10px;
    padding: 10px 14px; font-size: 0.85rem; color: #444; line-height: 1.7;
  }
  .appt-modal-total {
    display: flex; align-items: center; justify-content: space-between;
    background: rgba(82,0,0,0.05); border-radius: 10px;
    padding: 10px 14px; margin-top: 6px;
  }
  .appt-modal-total span { font-size: 0.82rem; color: #666; }
  .appt-modal-total strong { font-size: 1rem; color: var(--deep-red); font-weight: 800; }
  .clickable-row { cursor: pointer; transition: background 0.15s; }
  .clickable-row:hover { background: #faf8f5; }
</style>
</head>
<body>
<?php include 'welcome_toast.php'; ?>

<aside class="sidebar">
  <div class="sidebar-logo">
    <img src="images/2.png" alt="نرعاك" class="logo-img">
  </div>
  <div class="sidebar-user">
    <div class="user-avatar">م</div>
    <div class="user-info">
      <strong><?php echo htmlspecialchars($adminName); ?></strong>
      <span>صلاحيات كاملة</span>
    </div>
  </div>
  <nav>
    <div class="nav-section">الإدارة العامة</div>
    <a class="nav-item active" href="#" onclick="showSection('home',this)">
      <span class="icon">📊</span> الرئيسية
    </a>
    <a class="nav-item" href="#" onclick="showSection('reports',this)">
      <span class="icon">🚩</span> البلاغات
    </a>
    <a class="nav-item" href="#" onclick="showSection('requests',this)">
      <span class="icon">📋</span> المواعيد
    </a>
  </nav>
  <div class="sidebar-footer">
    <a class="logout-btn" href="logout.php"><span>🚪</span> تسجيل الخروج</a>
  </div>
</aside>

<main class="main">

  <!-- HOME -->
  <div class="section active" id="sec-home">
    <div class="page-header">
      <div class="page-title">
        <h1>لوحة المراقبة</h1>
        <p>نظرة عامة على النظام — الثلاثاء، ١٠ مارس ٢٠٢٦</p>
      </div>
    </div>

    <div class="stats-grid">
      <div class="stat-card"><div class="stat-num"><?php echo $totalCustomers; ?></div><div class="stat-label">إجمالي العملاء</div></div>
      <div class="stat-card"><div class="stat-num"><?php echo $totalLabs; ?></div><div class="stat-label">مختبرات نشطة</div></div>
      <div class="stat-card"><div class="stat-num"><?php echo $totalAppointments; ?></div><div class="stat-label">طلبات هذا الشهر</div></div>
      <div class="stat-card"><div class="stat-num"><?php echo $openReports; ?></div><div class="stat-label">البلاغات المفتوحة</div></div>
    </div>

    <div class="home-grid">
      <div>
        <div class="card">
          <div class="card-header">
            <div class="card-title">آخر الطلبات</div>
            <a href="#" style="font-size:0.78rem;color:var(--deep-red);font-weight:600;text-decoration:none;" onclick="showSection('requests',document.querySelector('[onclick*=requests]'))">عرض الكل</a>
          </div>
          <div>
            <div style="display:grid;grid-template-columns:2fr 1.2fr 1fr;gap:8px;font-size:0.7rem;font-weight:700;color:#aaa;padding-bottom:8px;border-bottom:1px solid #f0ebe4;margin-bottom:4px;">
              <div>العميل / الفحص</div><div>المختبر</div><div style="text-align:center">الحالة</div>
            </div>

            <?php if (mysqli_num_rows($latestAppointments) > 0): ?>
              <?php while ($latest = mysqli_fetch_assoc($latestAppointments)): ?>
                <div style="display:grid;grid-template-columns:2fr 1.2fr 1fr;gap:8px;padding:10px 0;align-items:center;border-bottom:1px solid #f0ebe4;">
                  <div><div style="font-size:0.85rem;font-weight:600;"><?php echo htmlspecialchars($latest["customer_name"]); ?></div><div style="font-size:0.73rem;color:#999;"><?php echo htmlspecialchars($latest["tests"] ?: "—"); ?></div></div>
                  <div style="font-size:0.78rem;color:var(--medium-brown);"><?php echo htmlspecialchars($latest["lab_name"]); ?></div>
                  <div style="text-align:center">
                    <?php
                      $badgeClass = "status-pending"; $badgeText = "انتظار";
                      if ($latest["status"] === "confirmed")  { $badgeClass = "status-confirmed"; $badgeText = "مؤكد"; }
                      elseif ($latest["status"] === "completed") { $badgeClass = "status-done";    $badgeText = "مكتمل"; }
                      elseif ($latest["status"] === "cancelled") { $badgeClass = "status-waiting"; $badgeText = "ملغي"; }
                      elseif ($latest["status"] === "delayed")   { $badgeClass = "status-delayed"; $badgeText = "متأخر"; }
                      elseif ($latest["status"] === "overdue")   { $badgeClass = "status-overdue"; $badgeText = "منتهي"; }
                    ?>
                    <span class="status-badge <?php echo $badgeClass; ?>"><?php echo $badgeText; ?></span>
                  </div>
                </div>
              <?php endwhile; ?>
            <?php else: ?>
              <div style="padding:10px 0;font-size:0.85rem;color:#777;">لا توجد طلبات</div>
            <?php endif; ?>
          </div>
        </div>

        <div class="card">
          <div class="card-header">
            <div class="card-title">حسابات المختبرات</div>
          </div>

          <div class="labs-grid">
            <?php if (mysqli_num_rows($labsQuery) > 0): ?>
              <?php while ($lab = mysqli_fetch_assoc($labsQuery)): ?>
                <div class="lab-home-card">
                  <div class="lab-logo-box"><img src="<?php echo htmlspecialchars($lab["lab_logo"]); ?>" alt="habib-logo"></div>
                  <div class="lab-details">
                    <strong><?php echo htmlspecialchars($lab["lab_name"]); ?></strong>
                    <span><?php echo htmlspecialchars($lab["address"]); ?></span>
                    <div class="lab-tests-line"><?php echo htmlspecialchars($lab["tests"] ?: ""); ?></div>
                    <div class="lab-actions">
                      <a class="lab-action-btn delete" href="admin-dashboard.php?delete_lab=<?php echo $lab["lab_id"]; ?>" onclick="event.preventDefault(); showConfirm('هل أنت متأكد من حذف المختبر؟', () => { window.location.href=this.href; })">حذف</a>
                    </div>
                  </div>
                </div>
              <?php endwhile; ?>
            <?php else: ?>
              <div style="font-size:0.85rem;color:#777;">لا توجد مختبرات</div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div>
        <div class="card">
          <div class="card-header">
            <div class="card-title">ملخص البلاغات</div>
          </div>

          <div style="display:flex;flex-direction:column;gap:10px;">
            <?php if (mysqli_num_rows($summaryReports) > 0): ?>
              <?php while ($summary = mysqli_fetch_assoc($summaryReports)): ?>
                <div style="padding:12px;background:#fce8e8;border-radius:10px;border-right:3px solid #c42a2a;">
                  <div style="font-size:0.86rem;font-weight:700;color:#222;">بلاغ على حساب: <?php echo htmlspecialchars($summary["customer_name"]); ?></div>
                  <div style="font-size:0.75rem;color:#777;margin-top:4px;"><?php echo htmlspecialchars($summary["reason"]); ?></div>
                </div>
              <?php endwhile; ?>
            <?php else: ?>
              <div style="padding:12px;background:#fce8e8;border-radius:10px;border-right:3px solid #c42a2a;">
                <div style="font-size:0.86rem;font-weight:700;color:#222;">لا توجد بلاغات</div>
              </div>
            <?php endif; ?>
          </div>
        </div>
        <div class="card">
          <div class="card-header">
            <div class="card-title">إضافة مختبر جديد</div>
          </div>

          <?php if (($_GET['lab_success'] ?? '') === '1'): ?>
            <div style="background:#e8f4ea;border-right:4px solid #2d7a3a;border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:0.88rem;color:#1b5e20;font-weight:600;">
              ✓ تم إضافة المختبر بنجاح
            </div>
          <?php elseif (($_GET['lab_error'] ?? '') === 'missing'): ?>
            <div style="background:#fff3cd;border-right:4px solid #c8860a;border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:0.88rem;color:#7a4f00;font-weight:600;">
              يرجى ملء جميع الحقول المطلوبة
            </div>
          <?php elseif (($_GET['lab_error'] ?? '') === 'duplicate'): ?>
            <div style="background:#fdecea;border-right:4px solid #c62828;border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:0.88rem;color:#b71c1c;font-weight:600;">
              البريد الإلكتروني مسجل مسبقاً، يرجى استخدام بريد آخر
            </div>
          <?php endif; ?>

          <form method="POST" enctype="multipart/form-data">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">

              <div>
                <label style="display:block;font-size:0.8rem;font-weight:700;color:#444;margin-bottom:6px;">اسم المختبر</label>
                <input type="text" name="lab_name" placeholder="مثال: مختبر الأمل الطبي"
                       style="width:100%;padding:12px 14px;border:1.5px solid #e8e0d8;border-radius:10px;font-family:Tajawal,sans-serif;font-size:0.9rem;outline:none;background:#faf8f5;">
              </div>

              <div>
                <label style="display:block;font-size:0.8rem;font-weight:700;color:#444;margin-bottom:6px;">البريد الإلكتروني</label>
                <input type="email" name="email" placeholder="lab@email.com"
                       style="width:100%;padding:12px 14px;border:1.5px solid #e8e0d8;border-radius:10px;font-family:Tajawal,sans-serif;font-size:0.9rem;outline:none;background:#faf8f5;">
              </div>

              <div>
                <label style="display:block;font-size:0.8rem;font-weight:700;color:#444;margin-bottom:6px;">رقم الجوال</label>
                <input type="text" name="phone_number" placeholder="05xxxxxxxx"
                       style="width:100%;padding:12px 14px;border:1.5px solid #e8e0d8;border-radius:10px;font-family:Tajawal,sans-serif;font-size:0.9rem;outline:none;background:#faf8f5;">
              </div>

              <div>
                <label style="display:block;font-size:0.8rem;font-weight:700;color:#444;margin-bottom:6px;">كلمة المرور</label>
                <input type="password" name="password" placeholder="••••••••"
                       style="width:100%;padding:12px 14px;border:1.5px solid #e8e0d8;border-radius:10px;font-family:Tajawal,sans-serif;font-size:0.9rem;outline:none;background:#faf8f5;">
              </div>

              <div>
                <label style="display:block;font-size:0.8rem;font-weight:700;color:#444;margin-bottom:6px;">المدينة</label>
                <input type="text" name="address" placeholder="الرياض"
                       style="width:100%;padding:12px 14px;border:1.5px solid #e8e0d8;border-radius:10px;font-family:Tajawal,sans-serif;font-size:0.9rem;outline:none;background:#faf8f5;">
              </div>

              <div>
                <label style="display:block;font-size:0.8rem;font-weight:700;color:#444;margin-bottom:6px;">شعار المختبر</label>
                <input type="file" name="lab_logo_file"
                       style="width:100%;padding:10px 12px;border:1.5px solid #e8e0d8;border-radius:10px;font-family:Tajawal,sans-serif;font-size:0.85rem;outline:none;background:#faf8f5;">
              </div>

              <div>
                <label style="display:block;font-size:0.8rem;font-weight:700;color:#444;margin-bottom:6px;">زيارة منزلية</label>
                <select name="home_visit" style="width:100%;padding:12px 14px;border:1.5px solid #e8e0d8;border-radius:10px;font-family:Tajawal,sans-serif;font-size:0.9rem;outline:none;background:#faf8f5;">
                  <option value="نعم">نعم</option>
                  <option value="لا">لا</option>
                </select>
              </div>
            </div>

            <div style="margin-top:18px;">
              <label style="display:block;font-size:0.8rem;font-weight:700;color:#444;margin-bottom:8px;">التحاليل التي يقدمها المختبر</label>

              <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:18px;">

                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                  <label style="min-width:140px;">
                    <input type="checkbox" name="tests[]" value="فيتامين د" style="margin-left:6px;"> فيتامين د
                  </label>
                  <input type="number" step="0.01" name="test_prices[فيتامين د]" placeholder="السعر"
                         style="width:140px;padding:10px;border:1px solid #e8e0d8;border-radius:10px;">
                </div>

                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                  <label style="min-width:140px;">
                    <input type="checkbox" name="tests[]" value="كالسيوم" style="margin-left:6px;"> كالسيوم
                  </label>
                  <input type="number" step="0.01" name="test_prices[كالسيوم]" placeholder="السعر"
                         style="width:140px;padding:10px;border:1px solid #e8e0d8;border-radius:10px;">
                </div>

                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                  <label style="min-width:140px;">
                    <input type="checkbox" name="tests[]" value="هيموجلوبين" style="margin-left:6px;"> هيموجلوبين
                  </label>
                  <input type="number" step="0.01" name="test_prices[هيموجلوبين]" placeholder="السعر"
                         style="width:140px;padding:10px;border:1px solid #e8e0d8;border-radius:10px;">
                </div>

                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                  <label style="min-width:140px;">
                    <input type="checkbox" name="tests[]" value="فيتامين ب١٢" style="margin-left:6px;"> فيتامين ب١٢
                  </label>
                  <input type="number" step="0.01" name="test_prices[فيتامين ب١٢]" placeholder="السعر"
                         style="width:140px;padding:10px;border:1px solid #e8e0d8;border-radius:10px;">
                </div>

                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                  <label style="min-width:140px;">
                    <input type="checkbox" name="tests[]" value="الحديد" style="margin-left:6px;"> الحديد
                  </label>
                  <input type="number" step="0.01" name="test_prices[الحديد]" placeholder="السعر"
                         style="width:140px;padding:10px;border:1px solid #e8e0d8;border-radius:10px;">
                </div>

                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                  <label style="min-width:140px;">
                    <input type="checkbox" name="tests[]" value="الكوليسترول الكلي" style="margin-left:6px;"> الكوليسترول الكلي
                  </label>
                  <input type="number" step="0.01" name="test_prices[الكوليسترول الكلي]" placeholder="السعر"
                         style="width:140px;padding:10px;border:1px solid #e8e0d8;border-radius:10px;">
                </div>

              </div>

              <div style="background:#faf8f5;border:1px solid #e8e0d8;border-radius:14px;padding:16px;">
                <div style="font-size:0.85rem;font-weight:700;color:var(--deep-red);margin-bottom:12px;">
                  إضافة تحليل جديد
                </div>

                <div style="display:flex;flex-direction:column;gap:10px;">
                  <div>
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#555;margin-bottom:6px;">اسم التحليل</label>
                    <input type="text" id="newTestName" placeholder="مثال: السكر التراكمي"
                           style="width:100%;padding:12px 14px;border:1.5px solid #e8e0d8;border-radius:10px;font-family:Tajawal,sans-serif;font-size:0.9rem;outline:none;background:#fff;">
                  </div>

                  <div>
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#555;margin-bottom:6px;">النطاق الطبيعي</label>
                    <input type="text" id="newTestRange" placeholder="مثال: أقل من ٥.٧"
                           style="width:100%;padding:12px 14px;border:1.5px solid #e8e0d8;border-radius:10px;font-family:Tajawal,sans-serif;font-size:0.9rem;outline:none;background:#fff;">
                  </div>

                  <div>
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#555;margin-bottom:6px;">الوحدة</label>
                    <input type="text" id="newTestUnit" placeholder="مثال: % أو mg/dL"
                           style="width:100%;padding:12px 14px;border:1.5px solid #e8e0d8;border-radius:10px;font-family:Tajawal,sans-serif;font-size:0.9rem;outline:none;background:#fff;">
                  </div>

                  <div>
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#555;margin-bottom:6px;">السعر</label>
                    <input type="number" id="newTestPrice" placeholder="مثال: 120"
                           style="width:100%;padding:12px 14px;border:1.5px solid #e8e0d8;border-radius:10px;font-family:Tajawal,sans-serif;font-size:0.9rem;outline:none;background:#fff;">
                  </div>

                  <button type="button"
                        onclick="addNewTest()"
                        style="margin-top:8px;padding:12px;border:none;border-radius:10px;background:var(--deep-red);color:#fff;font-family:Tajawal,sans-serif;font-weight:700;cursor:pointer;width:100%;">
                  إضافة
                </button>
                </div>

                <div id="addedTestsList" style="margin-top:14px;display:flex;flex-direction:column;gap:8px;"></div>
              </div>
            </div>

            <div style="margin-top:20px;display:flex;gap:10px;">
              <button class="btn btn-primary" type="submit" name="add_lab">إضافة المختبر</button>
              <button class="btn" type="reset" style="background:#f1f1f1;color:#555;">إلغاء</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>

  <!-- REPORTS -->
  <div class="section" id="sec-reports">
    <div class="page-header">
      <div class="page-title"><h1>البلاغات</h1><p>الحسابات التي تم الإبلاغ عنها فقط</p></div>
    </div>

    <div class="card">
      <div class="table-header" style="grid-template-columns:2fr 1.5fr 1fr 1.5fr 1fr;">
        <div>اسم العميل</div>
        <div>البريد الإلكتروني</div>
        <div>الجوال</div>
        <div>سبب البلاغ</div>
        <div style="text-align:center">الإجراء</div>
      </div>

      <?php if (mysqli_num_rows($reportsQuery) > 0): ?>
        <?php while ($report = mysqli_fetch_assoc($reportsQuery)): ?>
          <div class="table-row" style="grid-template-columns:2fr 1.5fr 1fr 1.5fr 1fr;">
            <div style="display:flex;align-items:center;gap:10px;">
              <div style="width:34px;height:34px;background:rgba(82,0,0,0.08);border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;color:var(--deep-red);font-size:0.9rem;"><?php echo mb_substr($report["customer_name"], 0, 1, "UTF-8"); ?></div>
              <div><div style="font-size:0.88rem;font-weight:600;"><?php echo htmlspecialchars($report["customer_name"]); ?></div><div style="font-size:0.72rem;color:#999;">بلاغ بتاريخ <?php echo htmlspecialchars($report["report_date"]); ?></div></div>
            </div>
            <div style="font-size:0.82rem;color:#555;"><?php echo htmlspecialchars($report["email"]); ?></div>
            <div style="font-size:0.82rem;color:#555;"><?php echo htmlspecialchars($report["phone_number"]); ?></div>
            <div style="font-size:0.8rem;color:#777;"><?php echo htmlspecialchars($report["reason"]); ?></div>
<div style="text-align:center">
  <a class="btn"
     style="background:#fce8e8;color:#c42a2a;padding:6px 14px;font-size:0.75rem;text-decoration:none;"
     href="admin-dashboard.php?block_customer=<?php echo $report["customer_id"]; ?>&report_id=<?php echo $report["report_id"]; ?>"
     onclick="event.preventDefault(); showConfirm('هل أنت متأكد من حظر هذا الحساب؟', () => { window.location.href=this.href; })">
     حظر
  </a>
</div>          </div>
        <?php endwhile; ?>
      <?php else: ?>
        <div class="table-row" style="grid-template-columns:1fr;">
          <div style="font-size:0.85rem;color:#777;">لا توجد بلاغات</div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ALL REQUESTS -->
  <div class="section" id="sec-requests">
    <div class="page-header">
      <div class="page-title"><h1>طلبات المواعيد</h1><p>مراقبة جميع الطلبات في المنصة</p></div>
    </div>

    <div class="card">
      <div class="table-header" style="grid-template-columns:1.5fr 1.5fr 1.5fr 1fr 1fr;">
        <div>العميل</div>
        <div>الفحوصات</div>
        <div>المختبر</div>
        <div>التاريخ</div>
        <div style="text-align:center">الحالة</div>
      </div>

      <?php if (mysqli_num_rows($allAppointments) > 0): ?>
        <?php while ($appointment = mysqli_fetch_assoc($allAppointments)):
          $rowClass = "status-pending"; $rowText = "انتظار";
          if ($appointment["status"] === "confirmed")  { $rowClass = "status-confirmed"; $rowText = "مؤكد"; }
          elseif ($appointment["status"] === "completed") { $rowClass = "status-done";    $rowText = "مكتمل"; }
          elseif ($appointment["status"] === "cancelled") { $rowClass = "status-waiting"; $rowText = "ملغي"; }
          elseif ($appointment["status"] === "delayed")   { $rowClass = "status-delayed"; $rowText = "متأخر"; }
          elseif ($appointment["status"] === "overdue")   { $rowClass = "status-overdue"; $rowText = "منتهي"; }
          $slotTimeFmt = $appointment["slot_time"] ? date("h:i A", strtotime($appointment["slot_time"])) : "—";
        ?>
          <div class="table-row clickable-row" style="grid-template-columns:1.5fr 1.5fr 1.5fr 1fr 1fr;"
            onclick="openApptModal(<?php echo (int)$appointment['appointment_id']; ?>,
              '<?php echo addslashes(htmlspecialchars($appointment["customer_name"])); ?>',
              '<?php echo addslashes(htmlspecialchars($appointment["customer_email"])); ?>',
              '<?php echo addslashes(htmlspecialchars($appointment["customer_phone"])); ?>',
              '<?php echo addslashes(htmlspecialchars($appointment["lab_name"])); ?>',
              '<?php echo addslashes(htmlspecialchars($appointment["lab_address"])); ?>',
              '<?php echo addslashes(htmlspecialchars($appointment["lab_phone"])); ?>',
              '<?php echo addslashes(htmlspecialchars($appointment["slot_date"])); ?>',
              '<?php echo addslashes($slotTimeFmt); ?>',
              '<?php echo addslashes(htmlspecialchars($appointment["tests"] ?: "—")); ?>',
              '<?php echo number_format((float)$appointment["total_price"], 2); ?>',
              '<?php echo addslashes($rowText); ?>')">
            <div><div style="font-size:0.85rem;font-weight:600;"><?php echo htmlspecialchars($appointment["customer_name"]); ?></div></div>
            <div style="font-size:0.8rem;color:var(--medium-brown);"><?php echo htmlspecialchars($appointment["tests"] ?: "—"); ?></div>
            <div style="font-size:0.8rem;color:#555;"><?php echo htmlspecialchars($appointment["lab_name"]); ?></div>
            <div style="font-size:0.8rem;color:#555;"><?php echo htmlspecialchars($appointment["slot_date"]); ?></div>
            <div style="text-align:center">
              <span class="status-badge <?php echo $rowClass; ?>"><?php echo $rowText; ?></span>
            </div>
          </div>
        <?php endwhile; ?>
      <?php else: ?>
        <div class="table-row" style="grid-template-columns:1fr;">
          <div style="font-size:0.85rem;color:#777;">لا توجد طلبات</div>
        </div>
      <?php endif; ?>
    </div>
  </div>

</main>

<!-- Appointment Detail Modal -->
<div class="appt-modal-overlay" id="apptModalOverlay" onclick="closeApptModal(event)">
  <div class="appt-modal">
    <button class="appt-modal-close" onclick="document.getElementById('apptModalOverlay').classList.remove('open')">✕</button>
    <div class="appt-modal-title">تفاصيل الموعد</div>
    <div class="appt-modal-id" id="modalApptId"></div>

    <div class="appt-modal-section">
      <div class="appt-modal-section-label">بيانات العميل</div>
      <div class="appt-modal-grid">
        <div class="appt-modal-field"><span>الاسم</span><strong id="modalCustomerName"></strong></div>
        <div class="appt-modal-field"><span>رقم الجوال</span><strong id="modalCustomerPhone"></strong></div>
        <div class="appt-modal-field" style="grid-column:1/-1;"><span>البريد الإلكتروني</span><strong id="modalCustomerEmail"></strong></div>
      </div>
    </div>

    <div class="appt-modal-section">
      <div class="appt-modal-section-label">بيانات المختبر</div>
      <div class="appt-modal-grid">
        <div class="appt-modal-field"><span>اسم المختبر</span><strong id="modalLabName"></strong></div>
        <div class="appt-modal-field"><span>رقم الجوال</span><strong id="modalLabPhone"></strong></div>
        <div class="appt-modal-field" style="grid-column:1/-1;"><span>العنوان</span><strong id="modalLabAddress"></strong></div>
      </div>
    </div>

    <div class="appt-modal-section">
      <div class="appt-modal-section-label">وقت الموعد</div>
      <div class="appt-modal-grid">
        <div class="appt-modal-field"><span>التاريخ</span><strong id="modalSlotDate"></strong></div>
        <div class="appt-modal-field"><span>الوقت</span><strong id="modalSlotTime"></strong></div>
        <div class="appt-modal-field"><span>الحالة</span><strong id="modalStatus"></strong></div>
      </div>
    </div>

    <div class="appt-modal-section">
      <div class="appt-modal-section-label">الفحوصات المطلوبة</div>
      <div class="appt-modal-tests" id="modalTests"></div>
      <div class="appt-modal-total">
        <span>إجمالي التكلفة</span>
        <strong id="modalTotal"></strong>
      </div>
    </div>
  </div>
</div>

<script>
function openApptModal(id, customerName, customerEmail, customerPhone, labName, labAddress, labPhone, slotDate, slotTime, tests, total, status) {
  document.getElementById('modalApptId').textContent       = 'رقم الموعد: #' + id;
  document.getElementById('modalCustomerName').textContent  = customerName;
  document.getElementById('modalCustomerEmail').textContent = customerEmail;
  document.getElementById('modalCustomerPhone').textContent = customerPhone;
  document.getElementById('modalLabName').textContent       = labName;
  document.getElementById('modalLabAddress').textContent    = labAddress;
  document.getElementById('modalLabPhone').textContent      = labPhone;
  document.getElementById('modalSlotDate').textContent      = slotDate;
  document.getElementById('modalSlotTime').textContent      = slotTime;
  document.getElementById('modalTests').textContent         = tests;
  document.getElementById('modalTotal').textContent         = total + ' ريال';
  document.getElementById('modalStatus').textContent        = status;
  document.getElementById('apptModalOverlay').classList.add('open');
}

function closeApptModal(e) {
  if (e && e.target !== document.getElementById('apptModalOverlay')) return;
  document.getElementById('apptModalOverlay').classList.remove('open');
}

document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') document.getElementById('apptModalOverlay').classList.remove('open');
});

function showSection(name, el) {
  document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
  document.getElementById('sec-' + name).classList.add('active');
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  if (el) el.classList.add('active');
}

function addNewTest() {
  const name = document.getElementById('newTestName').value.trim();
  const range = document.getElementById('newTestRange').value.trim();
  const unit = document.getElementById('newTestUnit').value.trim();
  const price = document.getElementById('newTestPrice').value.trim();

  if (!name || !range || !unit || !price) {
    showAlert('عبّي اسم التحليل والنطاق الطبيعي والوحدة والسعر');
    return;
  }

  const list = document.getElementById('addedTestsList');

  const item = document.createElement('div');
  item.style.cssText = "background:#fff;border:1px solid #e8e0d8;border-radius:10px;padding:10px 12px;font-size:0.82rem;color:#444;";
  item.textContent = name + " — " + range + " — " + unit + " — " + price + " ريال";

  const hiddenName = document.createElement('input');
  hiddenName.type = 'hidden';
  hiddenName.name = 'custom_test_names[]';
  hiddenName.value = name;

  const hiddenRange = document.createElement('input');
  hiddenRange.type = 'hidden';
  hiddenRange.name = 'custom_test_ranges[]';
  hiddenRange.value = range;

  const hiddenUnit = document.createElement('input');
  hiddenUnit.type = 'hidden';
  hiddenUnit.name = 'custom_test_units[]';
  hiddenUnit.value = unit;

  const hiddenPrice = document.createElement('input');
  hiddenPrice.type = 'hidden';
  hiddenPrice.name = 'custom_test_prices[]';
  hiddenPrice.value = price;

  item.appendChild(hiddenName);
  item.appendChild(hiddenRange);
  item.appendChild(hiddenUnit);
  item.appendChild(hiddenPrice);

  list.appendChild(item);

  document.getElementById('newTestName').value = "";
  document.getElementById('newTestRange').value = "";
  document.getElementById('newTestUnit').value = "";
  document.getElementById('newTestPrice').value = "";
}
</script>
<?php include 'ui-modal.php'; ?>
</body>
</html>
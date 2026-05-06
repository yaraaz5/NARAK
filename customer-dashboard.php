<?php
session_start();
require_once 'db.php';

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Sat, 01 Jan 2000 00:00:00 GMT");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header("Location: index.php");
    exit;
}

$customerId = (int) $_SESSION['user_id'];

// Fetch all test results for this customer, newest first per test type
$sql = "
    SELECT
        tt.test_type_id,
        tt.test_name,
        tt.unit,
        tr.result_value,
        tr.normal_range,
        tr.status_flag,
        tr.report_date
    FROM test_result tr
    JOIN appointment a  ON tr.appointment_id = a.appointment_id
    JOIN test_type  tt  ON tr.test_type_id   = tt.test_type_id
    WHERE a.customer_id = ?
    ORDER BY tt.test_type_id, tr.report_date DESC
";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 'i', $customerId);
mysqli_stmt_execute($stmt);
$rs = mysqli_stmt_get_result($stmt);

// Group by test type — keep only the two most recent readings
$grouped = [];
while ($row = mysqli_fetch_assoc($rs)) {
    $id = $row['test_type_id'];
    if (!isset($grouped[$id])) {
        $grouped[$id] = ['current' => null, 'previous' => null];
    }
    if ($grouped[$id]['current']  === null) { $grouped[$id]['current']  = $row; }
    elseif ($grouped[$id]['previous'] === null) { $grouped[$id]['previous'] = $row; }
}

// Parse "50 – 125" or "< 200" → [min, max]
function parseRange(string $range): array {
    if (preg_match('/([\d.]+)\s*[–\-]\s*([\d.]+)/', $range, $m))
        return [(float)$m[1], (float)$m[2]];
    if (preg_match('/<\s*([\d.]+)/', $range, $m))
        return [0.0, (float)$m[1]];
    return [0.0, 100.0];
}

// Bar width %: normal range max = 80 % of the bar
function calcPercent(string $value, float $rangeMax): string {
    if ($rangeMax <= 0) return '50%';
    return min(95, max(2, (int) round(((float)$value / ($rangeMax * 1.25)) * 100))) . '%';
}

function arabicDate(string $ymd): string {
    static $months = [1=>'يناير',2=>'فبراير',3=>'مارس',4=>'أبريل',
                      5=>'مايو',6=>'يونيو',7=>'يوليو',8=>'أغسطس',
                      9=>'سبتمبر',10=>'أكتوبر',11=>'نوفمبر',12=>'ديسمبر'];
    $ts = strtotime($ymd);
    return $ts ? (date('j', $ts) . ' ' . $months[(int)date('n', $ts)] . ' ' . date('Y', $ts)) : $ymd;
}

function statusColor(string $status): string {
    return match($status) { 'low' => '#c8860a', 'high' => '#c42a2a', default => '#2d7a3a' };
}

function formatSlotTime(string $hms): string {
    [$h, $m] = explode(':', $hms);
    $h = (int)$h;
    $suffix = $h < 12 ? 'ص' : 'م';
    $h12 = $h % 12 ?: 12;
    return $h12 . ':' . $m . ' ' . $suffix;
}

// Past appointments for this customer (completed, newest first)
$sqlPast = "
    SELECT
        a.appointment_id,
        l.lab_name,
        ts.slot_date,
        ts.slot_time,
        tt.test_name,
        tt.price,
        tt.unit,
        tr.result_value,
        tr.status_flag
    FROM appointment a
    JOIN laboratory              l   ON a.lab_id        = l.lab_id
    JOIN time_slot               ts  ON a.slot_id        = ts.slot_id
    LEFT JOIN appointment_test_type att ON a.appointment_id = att.appointment_id
    LEFT JOIN test_type          tt  ON att.test_type_id   = tt.test_type_id
    LEFT JOIN test_result        tr  ON tr.appointment_id  = a.appointment_id
                                    AND tr.test_type_id    = tt.test_type_id
    WHERE a.customer_id = ? AND a.status = 'completed'
    ORDER BY ts.slot_date DESC, ts.slot_time DESC, tt.test_name
";
$stmtPast = mysqli_prepare($conn, $sqlPast);
mysqli_stmt_bind_param($stmtPast, 'i', $customerId);
mysqli_stmt_execute($stmtPast);
$rsPast = mysqli_stmt_get_result($stmtPast);

// Group rows by appointment_id
$pastAppointments = [];
while ($row = mysqli_fetch_assoc($rsPast)) {
    $aid = $row['appointment_id'];
    if (!isset($pastAppointments[$aid])) {
        $pastAppointments[$aid] = [
            'lab_name'  => $row['lab_name'],
            'slot_date' => $row['slot_date'],
            'slot_time' => $row['slot_time'],
            'tests'     => [],
            'total'     => 0,
        ];
    }
    if ($row['test_name']) {
        $pastAppointments[$aid]['tests'][] = [
            'name'         => $row['test_name'],
            'price'        => (float)$row['price'],
            'unit'         => trim($row['unit'] ?? ''),
            'result_value' => $row['result_value'],
            'status_flag'  => $row['status_flag'],
        ];
        $pastAppointments[$aid]['total'] += (float)$row['price'];
    }
}

// ── Cancelled appointments ─────────────────────────────────────────────────
$sqlCancelled = "
    SELECT
        a.appointment_id,
        l.lab_name,
        ts.slot_date,
        ts.slot_time,
        GROUP_CONCAT(tt.test_name ORDER BY tt.test_name SEPARATOR '، ') AS test_names,
        COALESCE(SUM(tt.price), 0) AS total_price
    FROM appointment a
    JOIN laboratory              l   ON a.lab_id        = l.lab_id
    JOIN time_slot               ts  ON a.slot_id        = ts.slot_id
    LEFT JOIN appointment_test_type att ON a.appointment_id = att.appointment_id
    LEFT JOIN test_type          tt  ON att.test_type_id   = tt.test_type_id
    WHERE a.customer_id = ? AND a.status = 'cancelled'
    GROUP BY a.appointment_id
    ORDER BY ts.slot_date DESC
";
$stmtCancelled = mysqli_prepare($conn, $sqlCancelled);
mysqli_stmt_bind_param($stmtCancelled, 'i', $customerId);
mysqli_stmt_execute($stmtCancelled);
$cancelledAppointments = mysqli_fetch_all(mysqli_stmt_get_result($stmtCancelled), MYSQLI_ASSOC);

// ── Upcoming appointments (pending / confirmed / in-progress) ──────────────
$sqlUpcoming = "
    SELECT
        a.appointment_id,
        a.status,
        l.lab_name,
        ts.slot_date,
        ts.slot_time,
        GROUP_CONCAT(tt.test_name ORDER BY tt.test_name SEPARATOR ' — ') AS tests
    FROM appointment a
    JOIN laboratory  l   ON a.lab_id  = l.lab_id
    JOIN time_slot   ts  ON a.slot_id = ts.slot_id
    LEFT JOIN appointment_test_type att ON a.appointment_id = att.appointment_id
    LEFT JOIN test_type             tt  ON att.test_type_id = tt.test_type_id
    WHERE a.customer_id = ?
      AND a.status IN ('pending','confirmed','in_progress')
    GROUP BY a.appointment_id, l.lab_name, ts.slot_date, ts.slot_time, a.status
    ORDER BY ts.slot_date ASC, ts.slot_time ASC
";
$stmtUp = mysqli_prepare($conn, $sqlUpcoming);
mysqli_stmt_bind_param($stmtUp, 'i', $customerId);
mysqli_stmt_execute($stmtUp);
$rsUp = mysqli_stmt_get_result($stmtUp);
$upcomingAppointments = mysqli_fetch_all($rsUp, MYSQLI_ASSOC);

// Arabic day names
function arabicDayName(string $ymd): string {
    static $days = ['Sunday'=>'الأحد','Monday'=>'الاثنين','Tuesday'=>'الثلاثاء',
                    'Wednesday'=>'الأربعاء','Thursday'=>'الخميس','Friday'=>'الجمعة','Saturday'=>'السبت'];
    $ts = strtotime($ymd);
    return $ts ? ($days[date('l', $ts)] . '، ' . arabicDate($ymd)) : $ymd;
}

// ── Available laboratories with their test types ───────────────────────────
$sqlLabs = "
    SELECT
        l.lab_id,
        l.lab_name,
        l.address,
        l.lab_logo,
        GROUP_CONCAT(tt.test_name ORDER BY tt.test_name SEPARATOR ',') AS test_names,
        GROUP_CONCAT(tt.price     ORDER BY tt.test_name SEPARATOR ',') AS test_prices,
        GROUP_CONCAT(COALESCE(tt.unit,'') ORDER BY tt.test_name SEPARATOR ',') AS test_units
    FROM laboratory l
    JOIN test_type tt ON tt.lab_id = l.lab_id
    GROUP BY l.lab_id
    ORDER BY l.lab_name
";
$rsLabs = mysqli_query($conn, $sqlLabs);
$labsList = $rsLabs ? mysqli_fetch_all($rsLabs, MYSQLI_ASSOC) : [];

// ── Dashboard stats ────────────────────────────────────────────────────────
$sqlStats = "
    SELECT
        SUM(a.status IN ('pending','confirmed','in_progress'))          AS active_appointments,
        SUM(a.status = 'completed')                                     AS completed_tests,
        COUNT(DISTINCT CASE WHEN a.status = 'completed' THEN a.lab_id END) AS labs_used
    FROM appointment a
    WHERE a.customer_id = ?
";
$stmtStats = mysqli_prepare($conn, $sqlStats);
mysqli_stmt_bind_param($stmtStats, 'i', $customerId);
mysqli_stmt_execute($stmtStats);
$stats = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtStats));

$activeCount    = (int)($stats['active_appointments'] ?? 0);
$completedCount = (int)($stats['completed_tests']     ?? 0);
$labsUsed       = (int)($stats['labs_used']           ?? 0);

// Build comparisonData array for JS
$comparisonData = [];
foreach ($grouped as $id => $g) {
    $cur  = $g['current'];
    $prev = $g['previous'];
    $unit = trim($cur['unit'] ?? '');
    $suffix = $unit ? ' ' . $unit : '';

    [, $rMax] = parseRange($cur['normal_range']);

    $bars = [];
    if ($prev) {
        $bars[] = [
            'label' => arabicDate($prev['report_date']),
            'value' => $prev['result_value'] . $suffix,
            'width' => calcPercent($prev['result_value'], $rMax),
            'color' => '#bfa27a',
        ];
    }
    $bars[] = [
        'label' => arabicDate($cur['report_date']),
        'value' => $cur['result_value'] . $suffix,
        'width' => calcPercent($cur['result_value'], $rMax),
        'color' => statusColor($cur['status_flag']),
    ];
    $bars[] = [
        'label' => 'النطاق الطبيعي',
        'value' => $cur['normal_range'] . $suffix,
        'width' => '80%',
        'color' => '#97b494',
    ];

    $curF  = (float) $cur['result_value'];
    $prevF = $prev ? (float) $prev['result_value'] : null;
    $sf    = $cur['status_flag'];

    $tname = $cur['test_name'];
    $range = $cur['normal_range'] . $suffix;
    if ($sf === 'low') {
        $trendNote = ($prevF !== null && $curF > $prevF)
            ? "مستوى {$tname} لا يزال أقل من النطاق الطبيعي ({$range})، لكنه في تحسّن مقارنةً بالقراءة السابقة. يُنصح بالاستمرار في الخطة العلاجية ومتابعة الطبيب."
            : (($prevF !== null && $curF < $prevF)
                ? "مستوى {$tname} أقل من النطاق الطبيعي ({$range}) وقد انخفض عن القراءة السابقة. يُنصح بمراجعة الطبيب في أقرب وقت."
                : "مستوى {$tname} أقل من النطاق الطبيعي ({$range}). يُنصح بمراجعة الطبيب لتقييم الحالة ووضع خطة علاجية مناسبة.");
        $note = $trendNote;
    } elseif ($sf === 'high') {
        $trendNote = ($prevF !== null && $curF > $prevF)
            ? "مستوى {$tname} أعلى من النطاق الطبيعي ({$range}) وقد ارتفع عن القراءة السابقة. يُنصح بمراجعة الطبيب لتعديل الخطة العلاجية."
            : (($prevF !== null && $curF < $prevF)
                ? "مستوى {$tname} لا يزال أعلى من النطاق الطبيعي ({$range})، لكنه بدأ بالانخفاض. يُنصح بالمتابعة مع الطبيب للوصول إلى النطاق الطبيعي."
                : "مستوى {$tname} أعلى من النطاق الطبيعي ({$range}). يُنصح بمراجعة الطبيب لتقييم الأسباب ووضع خطة علاجية مناسبة.");
        $note = $trendNote;
    } elseif ($prevF !== null && $curF > $prevF) {
        $note = "نتيجة {$tname} ضمن النطاق الطبيعي ({$range}) مع تحسّن ملحوظ عن القراءة السابقة. استمر على نفس النهج.";
    } elseif ($prevF !== null && $curF < $prevF) {
        $note = "نتيجة {$tname} ضمن النطاق الطبيعي ({$range})، لكنها انخفضت قليلاً عن القراءة السابقة. تابع مع طبيبك للتأكد من استقرار المستوى.";
    } else {
        $note = "نتيجة {$tname} مستقرة وضمن النطاق الطبيعي ({$range}). لا يوجد ما يستدعي القلق حالياً.";
    }

    $comparisonData['test_' . $id] = [
        'title' => $cur['test_name'] . ' — تطور النتائج',
        'range' => 'النطاق الطبيعي: ' . $cur['normal_range'] . $suffix,
        'note'  => $note,
        'bars'  => $bars,
    ];
}
?>
<!DOCTYPE html>
<!--
Click nbfs://nbhost/SystemFileSystem/Templates/Licenses/license-default.txt to change this license
Click nbfs://nbhost/SystemFileSystem/Templates/Other/html.html to edit this template
-->
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>نرعاك - لوحة العميل</title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800&display=swap" rel="stylesheet">
<style>
  :root {
    --deep-red: #520000;
    --muted-brown: #BD9E77;
    --light-beige: #ECC590;
    --medium-brown: #8E775E;
    --ivory: #FFFFF0;
    --black: #000000;
  }

  * { margin: 0; padding: 0; box-sizing: border-box; }

  body {
    font-family: 'Tajawal', sans-serif;
    background: #f5f0e8;
    min-height: 100vh;
    color: #333;
  }

  
  .topbar {
  position: sticky;
  top: 0;
  z-index: 200;
  background: var(--deep-red);
  color: #fff;
  height: 82px;
  padding: 0 32px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  box-shadow: 0 4px 20px rgba(82,0,0,0.12);
}

.topbar-right,
.topbar-left {
  display: flex;
  align-items: center;
  gap: 18px;
}

.topbar-logo {
  display: flex;
  align-items: center;
  gap: 12px;
}

.logo-circle {
  width: 42px;
  height: 42px;
  border-radius: 50%;
  background: var(--light-beige);
  color: var(--deep-red);
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 800;
  font-size: 1rem;
  flex-shrink: 0;
}
.logo-img{
  width:150px;
  height:150px;
  object-fit:contain;
}

.logo-text strong {
  display: block;
  font-size: 1.2rem;
  font-weight: 800;
  color: #fff;
}

.logo-text span {
  display: block;
  font-size: 0.75rem;
  color: rgba(255,255,255,0.55);
  margin-top: 2px;
}

.welcome-box {
  text-align: left;
}

.welcome-box strong {
  display: block;
  font-size: 0.95rem;
  font-weight: 700;
  color: #fff;
}

.welcome-box span {
  display: block;
  font-size: 0.75rem;
  color: rgba(255,255,255,0.55);
  margin-top: 2px;
}

.topbar-logout {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 10px 18px;
  border-radius: 10px;
  background: #fff;
  color: var(--deep-red);
  text-decoration: none;
  font-size: 0.85rem;
  font-weight: 700;
  transition: all 0.2s;
}

.topbar-logout:hover {
  background: #f8f1ea;
}

  /* MAIN CONTENT */
.main {
  min-height: calc(100vh - 82px);
  padding: 32px 36px;
}

  .page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 32px;
  }

  .page-title h1 {
    font-size: 1.6rem;
    font-weight: 800;
    color: var(--deep-red);
  }

  .page-title p {
    font-size: 0.85rem;
    color: var(--medium-brown);
    margin-top: 2px;
  }

  .btn {
    padding: 10px 22px;
    border-radius: 10px;
    font-family: 'Tajawal', sans-serif;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    border: none;
    transition: all 0.2s;
  }

  .btn-primary {
    background: var(--deep-red);
    color: #fff;
  }

  .btn-primary:hover {
    background: #3d0000;
    transform: translateY(-1px);
  }

  /* STATS */
  .stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 28px;
  }

  .stat-card {
    background: #fff;
    border-radius: 16px;
    padding: 22px 20px;
    position: relative;
    overflow: hidden;
    box-shadow: 0 2px 12px rgba(0,0,0,0.05);
  }

  .stat-card::after {
    content: '';
    position: absolute;
    top: 0; right: 0;
    width: 4px;
    height: 100%;
    background: var(--deep-red);
    border-radius: 0 16px 16px 0;
  }

  .stat-card:nth-child(2)::after { background: var(--muted-brown); }
  .stat-card:nth-child(3)::after { background: var(--light-beige); }
  .stat-card:nth-child(4)::after { background: var(--medium-brown); }

  .stat-num {
    font-size: 2rem;
    font-weight: 800;
    color: var(--deep-red);
    line-height: 1;
  }

  .stat-card:nth-child(2) .stat-num { color: var(--muted-brown); }
  .stat-card:nth-child(3) .stat-num { color: #b58a3a; }
  .stat-card:nth-child(4) .stat-num { color: var(--medium-brown); }

  .stat-label {
    font-size: 0.8rem;
    color: #888;
    margin-top: 6px;
    font-weight: 500;
  }

  /* GRID LAYOUT */
  .content-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 24px;
  }

  .card {
    background: #fff;
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.05);
  }

  .card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
  }

  .card-title {
    font-size: 1rem;
    font-weight: 700;
    color: #222;
  }

  .card-subtitle {
    font-size: 0.75rem;
    color: #999;
    margin-top: 2px;
  }

  .view-all {
    font-size: 0.78rem;
    color: var(--deep-red);
    text-decoration: none;
    font-weight: 600;
  }

  /* APPOINTMENTS */
  .appointment-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 13px 0;
    border-bottom: 1px solid #f0ebe4;
  }

  .appointment-item:last-child { border-bottom: none; }

  .appt-info h4 {
    font-size: 0.88rem;
    font-weight: 600;
    color: #222;
    margin-bottom: 3px;
  }

  .appt-info p {
    font-size: 0.75rem;
    color: #999;
  }

  .appt-info .lab-name {
    font-size: 0.78rem;
    color: var(--medium-brown);
    font-weight: 500;
    margin-top: 1px;
  }

  .status-badge {
    font-size: 0.72rem;
    font-weight: 600;
    padding: 5px 12px;
    border-radius: 20px;
  }

  .status-pending { background: #fff8e6; color: #c8860a; }
  .status-confirmed { background: #e8f4ea; color: #2d7a3a; }
  .status-progress { background: #e8f0fc; color: #2a5cc4; }
  .status-done { background: #f0f0f0; color: #666; }
  .status-cancelled { background: #fce8e8; color: #c42a2a; }
  
  .appointment-item-actions {
  align-items: flex-start;
}

.appointment-side {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 10px;
}

.appointment-actions {
  display: flex;
  gap: 8px;
}

.appt-action-btn {
  font-family: 'Tajawal', sans-serif;
  font-size: 0.75rem;
  padding: 6px 12px;
  border-radius: 8px;
  border: 1.5px solid #e8e0d8;
  background: #fff;
  color: #444;
  cursor: pointer;
  transition: all 0.2s;
}

.appt-action-btn:hover {
  border-color: var(--deep-red);
  color: var(--deep-red);
}

.appt-action-btn.danger {
  border-color: #f3d3d3;
  color: #c42a2a;
}

.appt-action-btn.danger:hover {
  background: #fff5f5;
  border-color: #e7bcbc;
}

  /* LABS */
    .lab-card-clean {
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    .lab-top {
      display: flex;
      align-items: center;
      gap: 14px;
    }

    .lab-meta {
      flex: 1;
    }

    .lab-tests-list {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
      margin-top: 6px;
    }

    .test-pill {
      font-size: 0.7rem;
      background: #f5f0e8;
      color: var(--medium-brown);
      padding: 4px 10px;
      border-radius: 20px;
      font-weight: 500;
    }

    .lab-bottom {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-top: 6px;
    }

    .home-service {
      font-size: 0.82rem;
      color: #2d7a3a;
      font-weight: 600;
    }

    .book-btn-small {
      padding: 8px 18px;
      font-size: 0.8rem;
      border-radius: 10px;
      min-width: auto;
    }

  .lab-avatar {
    width: 42px; height: 42px;
    background: rgba(82,0,0,0.07);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    flex-shrink: 0;}
.booking-overlay{
  display:none;
  position:fixed;
  inset:0;
  background:rgba(0,0,0,0.35);
  z-index:999;
  align-items:center;
  justify-content:center;
  padding:20px;
}

.booking-modal{
  width:760px;
  max-width:95%;
  max-height:90vh;
  overflow-y:auto;
  background:#fff;
  border-radius:22px;
  box-shadow:0 25px 80px rgba(0,0,0,0.18);
  padding:28px;
  position:relative;
}

.booking-close{
  position:absolute;
  top:16px;
  left:16px;
  width:38px;
  height:38px;
  border:none;
  border-radius:10px;
  background:#f5f0e8;
  color:#555;
  font-size:1.1rem;
  cursor:pointer;
}

.booking-title{
  font-size:1.4rem;
  font-weight:800;
  color:var(--deep-red);
  margin-bottom:6px;
}

.booking-subtitle{
  font-size:0.86rem;
  color:#8E775E;
  margin-bottom:22px;
}

.booking-section{
  background:#faf8f5;
  border:1px solid #f0e8df;
  border-radius:16px;
  padding:18px;
  margin-bottom:16px;
}

.booking-section h3{
  font-size:1rem;
  font-weight:700;
  color:#222;
  margin-bottom:12px;
}

.booking-lab-top{
  display:flex;
  align-items:center;
  gap:14px;
}

.booking-lab-logo{
  width:58px;
  height:58px;
  border-radius:14px;
  background:#f1e8df;
  display:flex;
  align-items:center;
  justify-content:center;
  overflow:hidden;
  flex-shrink:0;
}

.booking-lab-logo img{
  width:38px;
  height:38px;
  object-fit:contain;
}

.booking-lab-name{
  font-size:1rem;
  font-weight:700;
  color:#222;
}

.booking-lab-meta{
  font-size:0.82rem;
  color:#999;
  margin-top:4px;
}

.booking-home{
  margin-top:10px;
  font-size:0.82rem;
  color:#2d7a3a;
  font-weight:700;
}

.booking-tests-info{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:10px;
}

.test-info-card{
  background:#fff;
  border:1px solid #eee4d9;
  border-radius:12px;
  padding:12px;
}

.test-info-card strong{
  display:block;
  font-size:0.88rem;
  color:#222;
  margin-bottom:4px;
}

.test-info-card span{
  font-size:0.76rem;
  color:#888;
  line-height:1.5;
}

.booking-form-grid{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:14px;
}

.booking-field label{
  display:block;
  font-size:0.82rem;
  font-weight:700;
  color:#444;
  margin-bottom:6px;
}

.booking-field input,
.booking-field select{
  width:100%;
  padding:12px 14px;
  border:1.5px solid #e8e0d8;
  border-radius:10px;
  font-family:'Tajawal', sans-serif;
  font-size:0.9rem;
  background:#fff;
  outline:none;
}

.booking-field input:focus,
.booking-field select:focus{
  border-color:var(--deep-red);
}

.booking-tests-select{
  display:flex;
  flex-wrap:wrap;
  gap:10px;
}

.booking-check{
  background:#fff;
  border:1px solid #eadfd4;
  border-radius:10px;
  padding:10px 12px;
  display:flex;
  align-items:center;
  gap:8px;
  font-size:0.85rem;
}

.booking-check input{
  accent-color:#520000;
}

.booking-actions{
  display:flex;
  gap:10px;
  justify-content:flex-start;
  margin-top:18px;
}

.booking-btn-cancel{
  padding:12px 18px;
  border:none;
  border-radius:10px;
  background:#f1f1f1;
  color:#555;
  font-family:'Tajawal', sans-serif;
  font-weight:700;
  cursor:pointer;
}

.booking-btn-confirm{
  padding:12px 18px;
  border:none;
  border-radius:10px;
  background:var(--deep-red);
  color:#fff;
  font-family:'Tajawal', sans-serif;
  font-weight:700;
  cursor:pointer;
}

.confirm-box{
  display:none;
  margin-top:18px;
  background:#e8f4ea;
  border-right:4px solid #2d7a3a;
  border-radius:12px;
  padding:14px 16px;
}

.confirm-box strong{
  display:block;
  color:#2d7a3a;
  margin-bottom:6px;
  font-size:0.92rem;
}

.confirm-box p{
  font-size:0.82rem;
  color:#4f5b52;
  line-height:1.8;
}

@media (max-width: 700px){
  .booking-tests-info,
  .booking-form-grid{
    grid-template-columns:1fr;
  }
}

.past-appointments-list{
  display:flex;
  flex-direction:column;
  gap:14px;
}

.past-appointment-item{
  border:1px solid #efe6dc;
  border-radius:16px;
  overflow:hidden;
  background:#faf8f5;
}

.past-appointment-header{
  width:100%;
  background:#faf8f5;
  border:none;
  padding:18px 20px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:16px;
  cursor:pointer;
  font-family:'Tajawal', sans-serif;
  text-align:right;
}

.past-appointment-main{
  flex:1;
}

.past-appointment-title{
  font-size:1rem;
  font-weight:700;
  color:#222;
  margin-bottom:8px;
}

.past-appointment-meta{
  display:flex;
  flex-wrap:wrap;
  gap:14px;
  font-size:0.8rem;
  color:#8E775E;
}

.past-arrow{
  font-size:1.1rem;
  color:var(--deep-red);
  transition:transform 0.25s ease;
  flex-shrink:0;
}

.past-appointment-item.open .past-arrow{
  transform:rotate(180deg);
}

.past-appointment-body{
  max-height:0;
  overflow:hidden;
  transition:max-height 0.3s ease, padding 0.3s ease;
  background:#fff;
  padding:0 20px;
}

.past-appointment-item.open .past-appointment-body{
  max-height:400px;
  padding:18px 20px 20px;
  border-top:1px solid #f0ebe4;
}

/* Cancelled appointments variant */
.cancelled-appointment-item{
  border:1px solid #f5d0d0;
  border-radius:16px;
  overflow:hidden;
  background:#fff8f8;
}
.cancelled-appointment-header{
  width:100%;
  background:#fff8f8;
  border:none;
  padding:18px 20px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:16px;
  cursor:pointer;
  font-family:'Tajawal', sans-serif;
  text-align:right;
}
.cancelled-appointment-item.open .cancelled-arrow{
  transform:rotate(180deg);
}
.cancelled-arrow{
  font-size:1.1rem;
  color:#b71c1c;
  transition:transform 0.25s ease;
  flex-shrink:0;
}
.cancelled-appointment-body{
  max-height:0;
  overflow:hidden;
  transition:max-height 0.3s ease, padding 0.3s ease;
  background:#fff;
  padding:0 20px;
}
.cancelled-appointment-item.open .cancelled-appointment-body{
  max-height:300px;
  padding:16px 20px 18px;
  border-top:1px solid #f5d0d0;
}
.two-col-appointments{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:20px;
  align-items:start;
}
@media(max-width:768px){
  .two-col-appointments{ grid-template-columns:1fr; }
}

.past-results-grid{
  display:grid;
  grid-template-columns:repeat(2, 1fr);
  gap:12px;
}

.past-result-card{
  background:#f9f6f2;
  border:1px solid #eee6dd;
  border-radius:12px;
  padding:14px;
  display:flex;
  flex-direction:column;
  gap:6px;
}

.past-result-card strong{
  font-size:0.9rem;
  color:#222;
}

.past-result-card span{
  font-size:0.8rem;
  color:#777;
}

@media (max-width: 700px){
  .past-results-grid{
    grid-template-columns:1fr;
  }

  .past-appointment-meta{
    flex-direction:column;
    gap:6px;
  }
}
  /* RESULTS CARD */
  .results-card {
    grid-column: 1 / -1;
  }

  .result-row {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1fr 1fr;
    gap: 8px;
    padding: 12px 0;
    border-bottom: 1px solid #f0ebe4;
    align-items: center;
  }

  .result-row:last-child { border-bottom: none; }

  .result-row.header {
    font-size: 0.72rem;
    font-weight: 700;
    color: #aaa;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding-bottom: 8px;
  }

  .result-row .test-name {
    font-size: 0.88rem;
    font-weight: 600;
    color: #222;
  }

  .result-row .test-val {
    font-size: 0.85rem;
    font-weight: 600;
    text-align: center;
  }

  .val-normal { color: #2d7a3a; }
  .val-high { color: #c42a2a; }
  .val-low { color: #c8860a; }

  .trend {
    font-size: 0.8rem;
    text-align: center;
  }

  .trend-up { color: #c42a2a; }
  .trend-down { color: #2d7a3a; }
  .trend-same { color: #aaa; }

  .result-date {
    font-size: 0.75rem;
    color: #aaa;
    text-align: center;
  }

  .range-text {
    font-size: 0.72rem;
    color: #bbb;
    text-align: center;
  }
  
  .result-row-6 {
  grid-template-columns: 2fr 1fr 1fr 1fr 1.2fr 1fr;
}

.result-meta {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
}

.mini-status {
  font-size: 0.68rem;
  font-weight: 700;
  padding: 5px 10px;
  border-radius: 999px;
}

.mini-status.normal {
  background: #e8f4ea;
  color: #2d7a3a;
}

.mini-status.low {
  background: #fff3e8;
  color: #c8860a;
}

.mini-status.high {
  background: #fce8e8;
  color: #c42a2a;
}

.compare-btn {
  font-family: 'Tajawal', sans-serif;
  font-size: 0.74rem;
  font-weight: 600;
  padding: 6px 12px;
  border-radius: 8px;
  border: 1.5px solid var(--deep-red);
  background: #fff;
  color: var(--deep-red);
  cursor: pointer;
  transition: all 0.2s;
}

.compare-btn:hover {
  background: var(--deep-red);
  color: #fff;
}

  /* SEARCH BAR */
    .labs-search-box{
      margin-bottom:16px;
    }

    .labs-search-input{
      width:100%;
      padding:12px 14px;
      border:1.5px solid #e8e0d8;
      border-radius:12px;
      font-family:'Tajawal', sans-serif;
      font-size:0.9rem;
      background:#fff;
      outline:none;
      transition:all 0.2s;
    }

    .labs-search-input:focus{
      border-color:var(--deep-red);
      box-shadow:0 0 0 3px rgba(82,0,0,0.06);
    }

    .test-pill.highlighted{
      background:var(--deep-red);
      color:#fff;
    }

  /* Summary step */
  .summary-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid #f0ebe4;
  }
  .summary-label {
    font-size: 0.85rem;
    color: #888;
    font-weight: 500;
  }
  .summary-value {
    font-size: 0.9rem;
    font-weight: 600;
    color: #222;
    text-align: left;
  }

  /* Content sections */
  .section { display: none; }
  .section.active { display: block; }
</style>
</head>
<body>
<?php include 'welcome_toast.php'; ?>
<header class="topbar">
  <div class="topbar-right">
    <div class="topbar-logo">
      <img src="images/2.png" alt="نرعاك" class="logo-img">
      <div class="logo-text">
        <span>منصة خدمات المختبرات</span>
      </div>
    </div>
  </div>

  <div class="topbar-left">
    <div class="welcome-box">
      <strong><?php echo htmlspecialchars($_SESSION['full_name']); ?></strong>
      <span>عميل</span>
    </div>

    <a href="logout.php" class="topbar-logout">تسجيل الخروج</a>
  </div>
</header>

<main class="main">

  <!-- HOME SECTION -->
  <div class="section active" id="sec-home">
    <div class="page-header">
      <div class="page-title">
        <h1>مرحباً، <?php echo htmlspecialchars($_SESSION['full_name']); ?> 👋</h1>
        <p><?= arabicDayName(date('Y-m-d')) ?></p>
      </div>
    </div>

    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-num"><?= $activeCount ?></div>
        <div class="stat-label">مواعيد نشطة</div>
      </div>
      <div class="stat-card">
        <div class="stat-num"><?= $completedCount ?></div>
        <div class="stat-label">فحوصات مكتملة</div>
      </div>
      <div class="stat-card">
        <div class="stat-num"><?= count($grouped) ?></div>
        <div class="stat-label">تقارير محفوظة</div>
      </div>
      <div class="stat-card">
        <div class="stat-num"><?= $labsUsed ?></div>
        <div class="stat-label">مختبرات مستخدمة</div>
      </div>
    </div>

    <div class="content-grid">
      <!-- Upcoming Appointments -->
      <div class="card">
        <div class="card-header">
          <div>
            <div class="card-title">المواعيد القادمة</div>
            <div class="card-subtitle">آخر تحديث: اليوم</div>
          </div>
        </div>

        <?php if (empty($upcomingAppointments)): ?>
          <div style="text-align:center;padding:32px;color:#aaa;font-size:0.95rem;">لا توجد مواعيد قادمة حالياً.</div>
        <?php else: ?>
          <?php foreach ($upcomingAppointments as $ua):
            $statusMap = [
              'pending'     => ['label' => 'في الانتظار', 'class' => 'status-pending',   'canEdit' => true],
              'confirmed'   => ['label' => 'مؤكد',        'class' => 'status-confirmed',  'canEdit' => false],
              'in_progress' => ['label' => 'قيد التنفيذ', 'class' => 'status-progress',   'canEdit' => false],
            ];
            $sm = $statusMap[$ua['status']] ?? ['label' => $ua['status'], 'class' => 'status-pending', 'canEdit' => false];
            $testsLabel = $ua['tests'] ?: '—';
          ?>
          <div class="appointment-item appointment-item-actions"
               data-id="<?= $ua['appointment_id'] ?>"
               data-lab="<?= htmlspecialchars($ua['lab_name']) ?>"
               data-tests="<?= htmlspecialchars($ua['tests'] ?? '') ?>"
               data-date="<?= htmlspecialchars($ua['slot_date']) ?>"
               data-time="<?= htmlspecialchars($ua['slot_time']) ?>">

            <div class="appt-info">
              <h4><?= htmlspecialchars($testsLabel) ?></h4>
              <div class="lab-name"><?= htmlspecialchars($ua['lab_name']) ?></div>
              <p><?= arabicDayName($ua['slot_date']) ?> — <?= formatSlotTime($ua['slot_time']) ?></p>
            </div>

            <div class="appointment-side">
              <span class="status-badge <?= $sm['class'] ?>"><?= $sm['label'] ?></span>
              <div class="appointment-actions">
                <?php if ($sm['canEdit']): ?>
                  <button class="appt-action-btn" onclick="openEditModal(this)">تعديل</button>
                  <button class="appt-action-btn danger"
                          onclick="cancelAppointment(this, <?= $ua['appointment_id'] ?>)">إلغاء</button>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
    </div>
      
      <!-- Available Labs -->
      <div class="card">
        <div class="card-header">
          <div>
            <div class="card-title">المختبرات المتاحة</div>
            <div class="card-subtitle">في الرياض</div>
          </div>
        </div>
        <div class="labs-search-box">
           <input type="text" id="labTestSearch" class="labs-search-input" placeholder="ابحث عن فحص مثل: فيتامين د" oninput="filterLabsByTest()">
        </div>
          
        <div id="labsListContainer" style="display:flex;flex-direction:column;gap:12px;">
          <div id="labsNoResults" style="display:none;text-align:center;padding:32px;color:#aaa;font-size:0.95rem;">لا توجد نتائج</div>
          <?php if (empty($labsList)): ?>
            <div style="text-align:center;padding:32px;color:#aaa;font-size:0.95rem;">لا توجد مختبرات متاحة حالياً.</div>
          <?php else: ?>
            <?php foreach ($labsList as $lab):
              $testNamesArr  = explode(',', $lab['test_names'] ?? '');
              $testPricesArr = explode(',', $lab['test_prices'] ?? '');
              $testUnitsArr  = explode(',', $lab['test_units'] ?? '');
              $testsPillsCsv = htmlspecialchars($lab['test_names'] ?? '');

              $jsTests = [];
              foreach ($testNamesArr as $i => $tName) {
                  $price = (float)($testPricesArr[$i] ?? 0);
                  $unit  = trim($testUnitsArr[$i] ?? '');
                  $desc  = $unit ? 'الوحدة: ' . $unit : '';
                  $jsTests[] = sprintf(
                      '{name:%s,price:%s,desc:%s}',
                      json_encode($tName, JSON_UNESCAPED_UNICODE),
                      json_encode($price, JSON_UNESCAPED_UNICODE),
                      json_encode($desc,  JSON_UNESCAPED_UNICODE)
                  );
              }
              $jsTestsStr = '[' . implode(',', $jsTests) . ']';
              $logoPath   = htmlspecialchars($lab['lab_logo'] ?? '');
              $city       = htmlspecialchars($lab['address'] ?? '');
              $labNameJs  = json_encode($lab['lab_name'], JSON_UNESCAPED_UNICODE);
              $cityJs     = json_encode($lab['address']  ?? '', JSON_UNESCAPED_UNICODE);
              $logoJs     = json_encode($lab['lab_logo'] ?? '', JSON_UNESCAPED_UNICODE);
            ?>
            <div class="lab-card-clean lab-item-searchable"
                 data-tests="<?= $testsPillsCsv ?>">
              <div class="lab-top">
                <?php if ($logoPath): ?>
                  <img src="<?= $logoPath ?>" alt="<?= htmlspecialchars($lab['lab_name']) ?>" class="lab-avatar">
                <?php else: ?>
                  <div class="lab-avatar">🔬</div>
                <?php endif; ?>
                <div class="lab-meta">
                  <div class="lab-name-text"><?= htmlspecialchars($lab['lab_name']) ?></div>
                  <p>الرياض</p>
                  <div class="lab-tests-list">
                    <?php foreach ($testNamesArr as $pill): ?>
                      <span class="test-pill"><?= htmlspecialchars($pill) ?></span>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>

              <div class="lab-bottom">
                <span class="home-service">✓ زيارة منزلية</span>
                <button class="btn btn-primary book-btn-small"
                        onclick='openBookingModal(<?= $labNameJs ?>,<?= $cityJs ?>,<?= $logoJs ?>,<?= $jsTestsStr ?>)'>
                  حجز موعد
                </button>
              </div>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div><!-- /card: Available Labs -->
    </div><!-- /content-grid -->

  <div class="booking-overlay" id="bookingOverlay">
  <div class="booking-modal">
    <button class="booking-close" onclick="closeBookingModal()">✕</button>

    <div class="booking-title">حجز موعد جديد</div>
    <div class="booking-subtitle">اختر التحاليل المناسبة وحدد التاريخ والوقت لتأكيد الموعد</div>

    <div id="bookingFormStep">
    <div class="booking-section">
      <h3>معلومات المختبر</h3>
      <div class="booking-lab-top">
        <div class="booking-lab-logo">
          <img id="modalLabLogo" src="" alt="lab logo">
        </div>
        <div>
          <div class="booking-lab-name" id="modalLabName">—</div>
          <div class="booking-lab-meta" id="modalLabCity">—</div>
          <div class="booking-home" id="modalLabHome">✓ زيارة منزلية</div>
        </div>
      </div>
    </div>

    <div class="booking-section">
      <h3>التحاليل التي يوفرها المختبر</h3>
      <div class="booking-tests-info" id="modalTestsInfo"></div>
    </div>

    <div class="booking-section">
      <h3>بيانات الحجز</h3>

      <div class="booking-field" style="margin-bottom:14px;">
        <label>اختر التحاليل المطلوبة (من ١ إلى ٣)</label>
        <div class="booking-tests-select" id="modalTestsSelect"></div>
      </div>
      
      <div id="bookingTotalBox" style="margin-top:12px;background:#faf8f5;border:1px solid #eadfd4;border-radius:12px;padding:12px 14px;">
        <div style="font-size:0.82rem;color:#8E775E;">السعر الإجمالي</div>
        <div id="bookingTotal" style="font-size:1rem;font-weight:800;color:var(--deep-red);margin-top:4px;">0 ريال</div>
      </div>

      <div class="booking-form-grid">
        <div class="booking-field">
          <label>اختر اليوم</label>
          <input type="date" id="bookingDate" min="<?php echo date('Y-m-d'); ?>" onchange="fetchAvailableSlots()">
        </div>

        <div class="booking-field">
          <label>اختر الوقت</label>
          <select id="bookingTime" disabled>
            <option value="">اختر اليوم أولاً</option>
          </select>
        </div>
      </div>

      <div id="bookingWarning" style="display:none;margin-bottom:12px;background:#fff3cd;border-right:4px solid #c8860a;border-radius:10px;padding:12px 14px;font-size:0.85rem;color:#7a4f00;font-weight:600;"></div>

      <div class="booking-actions">
        <button class="booking-btn-cancel" onclick="closeBookingModal()">رجوع</button>
        <button class="booking-btn-confirm" onclick="showBookingSummary()">مراجعة الحجز ←</button>
      </div>
    </div>
    </div><!-- /bookingFormStep -->

    <div id="bookingSummaryStep" style="display:none;">
      <div class="booking-section">
        <h3>ملخص الحجز</h3>
        <div id="summaryContent"></div>
        <div class="booking-actions" style="margin-top:20px;">
          <button class="booking-btn-cancel" onclick="backToBookingForm()">→ تعديل</button>
          <button class="booking-btn-confirm" onclick="confirmBooking()">تأكيد الحجز ✓</button>
        </div>
        <div class="confirm-box" id="confirmBox">
          <strong>تم تأكيد الحجز بنجاح ✓</strong>
          <p id="confirmText"></p>
        </div>
      </div>
    </div>
  </div>
</div>

      <!-- Previous & Cancelled Appointments -->
<div class="card results-card">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:0;align-items:start;">

    <!-- Past completed (right column in RTL) -->
    <div style="padding:4px 4px 4px 0;border-left:1px solid #f0ebe4;">
      <div style="font-size:1rem;font-weight:700;color:#222;margin-bottom:16px;padding-right:4px;">المواعيد السابقة</div>
      <div class="past-appointments-list">
        <?php if (empty($pastAppointments)): ?>
          <div style="text-align:center;padding:32px;color:#aaa;font-size:0.95rem;">لا توجد مواعيد سابقة حتى الآن.</div>
        <?php else: ?>
          <?php foreach ($pastAppointments as $apt): ?>
            <?php
              $testNames  = implode('، ', array_column($apt['tests'], 'name'));
              $totalPrice = number_format($apt['total'], 0);
            ?>
            <div class="past-appointment-item">
              <button class="past-appointment-header" onclick="togglePastAppointment(this)">
                <div class="past-appointment-main">
                  <div class="past-appointment-title"><?= htmlspecialchars($apt['lab_name']) ?></div>
                  <div class="past-appointment-meta">
                    <span>التاريخ: <?= arabicDate($apt['slot_date']) ?></span>
                    <span>الوقت: <?= formatSlotTime($apt['slot_time']) ?></span>
                    <span>التحاليل: <?= htmlspecialchars($testNames ?: '—') ?></span>
                    <span>السعر: <?= $totalPrice ?> ريال</span>
                  </div>
                </div>
                <span class="past-arrow">⌃</span>
              </button>
              <div class="past-appointment-body">
                <div class="past-results-grid">
                  <?php foreach ($apt['tests'] as $t):
                    $statusLabel = match($t['status_flag'] ?? '') {
                      'low'    => 'منخفض',
                      'high'   => 'مرتفع',
                      'normal' => 'طبيعي',
                      default  => 'قيد المعالجة',
                    };
                    $suffix = $t['unit'] ? ' ' . $t['unit'] : '';
                  ?>
                  <div class="past-result-card">
                    <strong><?= htmlspecialchars($t['name']) ?></strong>
                    <?php if ($t['result_value'] !== null): ?>
                      <span>النتيجة: <?= htmlspecialchars($t['result_value'] . $suffix) ?></span>
                      <span>الحالة: <?= $statusLabel ?></span>
                    <?php else: ?>
                      <span style="color:#aaa;">النتيجة: قيد المعالجة</span>
                    <?php endif; ?>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Cancelled (left column in RTL) -->
    <div style="padding:4px 20px 4px 4px;border-right:4px solid #c62828;">
      <div style="font-size:1rem;font-weight:700;color:#b71c1c;margin-bottom:16px;">المواعيد الملغاة</div>
      <div class="past-appointments-list">
        <?php if (empty($cancelledAppointments)): ?>
          <div style="text-align:center;padding:32px;color:#aaa;font-size:0.95rem;">لا توجد مواعيد ملغاة.</div>
        <?php else: ?>
          <?php foreach ($cancelledAppointments as $apt): ?>
            <div class="cancelled-appointment-item" style="padding:18px 20px;">
              <div class="past-appointment-title" style="color:#b71c1c;margin-bottom:8px;"><?= htmlspecialchars($apt['lab_name']) ?></div>
              <div class="past-appointment-meta">
                <span>التاريخ: <?= arabicDate($apt['slot_date']) ?></span>
                <span>الوقت: <?= formatSlotTime($apt['slot_time']) ?></span>
                <?php if ($apt['test_names']): ?>
                  <span>التحاليل: <?= htmlspecialchars($apt['test_names']) ?></span>
                <?php endif; ?>
                <span>السعر: <?= number_format((float)$apt['total_price'], 0) ?> ريال</span>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>
      
  <!-- Latest Results -->
<div class="card results-card" style="margin-top: 24px;">
  <div class="card-header">
    <div>
      <div class="card-title"> نتائج الفحوصات</div>
    </div>
  </div>

  <div class="result-row header result-row-6">
    <div>الفحص</div>
    <div style="text-align:center">النتيجة الحالية</div>
    <div style="text-align:center">النتيجة السابقة</div>
    <div style="text-align:center">النطاق الطبيعي</div>
    <div style="text-align:center">الحالة / الاتجاه</div>
    <div style="text-align:center">الإجراء</div>
  </div>

<?php if (empty($grouped)): ?>
  <div style="text-align:center;padding:32px;color:#aaa;font-size:0.95rem;">لا توجد نتائج فحوصات حتى الآن.</div>
<?php else: ?>
  <?php foreach ($grouped as $id => $g):
    $cur  = $g['current'];
    $prev = $g['previous'];
    $unit = trim($cur['unit'] ?? '');
    $suffix = $unit ? ' ' . $unit : '';
    $key  = 'test_' . $id;

    $sf = $cur['status_flag'];
    $statusClass = match($sf) { 'low' => 'low', 'high' => 'high', default => 'normal' };
    $statusLabel = match($sf) { 'low' => 'منخفض', 'high' => 'مرتفع', default => 'طبيعي' };
    $valClass    = 'val-' . $statusClass;

    if ($prev) {
      $curF  = (float) $cur['result_value'];
      $prevF = (float) $prev['result_value'];
      if      ($curF > $prevF) { $trendClass = 'trend-up';   $trendText = '↑ ارتفع'; }
      elseif  ($curF < $prevF) { $trendClass = 'trend-down'; $trendText = '↓ انخفض'; }
      else                     { $trendClass = 'trend-same'; $trendText = '— ثابت'; }
    } else {
      $trendClass = ''; $trendText = '';
    }
  ?>
  <div class="result-row result-row-6">
    <div class="test-name"><?= htmlspecialchars($cur['test_name']) ?></div>
    <div class="test-val <?= $valClass ?>"><?= htmlspecialchars($cur['result_value'] . $suffix) ?></div>
    <div class="test-val" style="color:#888;font-weight:400">
      <?= $prev ? htmlspecialchars($prev['result_value'] . $suffix) : '—' ?>
    </div>
    <div class="range-text"><?= htmlspecialchars($cur['normal_range']) ?></div>
    <div class="result-meta">
      <span class="mini-status <?= $statusClass ?>"><?= $statusLabel ?></span>
      <?php if ($trendText): ?>
        <span class="trend <?= $trendClass ?>"><?= $trendText ?></span>
      <?php endif; ?>
    </div>
    <div style="text-align:center;">
      <button class="compare-btn" onclick="openComparison('<?= $key ?>')">مقارنة</button>
    </div>
  </div>
  <?php endforeach; ?>
<?php endif; ?>

  <!-- Comparison -->
  <div id="comparison-box" style="display:none;margin-top:24px;padding-top:20px;border-top:1px solid #f0ebe4;">
    <div class="card-header" style="margin-bottom:16px;">
      <div class="card-title" id="comparison-title">فيتامين د — تطور النتائج</div>
      <span id="comparison-range" style="font-size:0.78rem;color:#999;">النطاق الطبيعي: ٥٠ – ١٢٥ nmol/L</span>
    </div>

    <div id="comparison-bars" style="margin-bottom:24px;"></div>

    <div style="background:#fff8e6;border-radius:10px;padding:14px 16px;border-right:3px solid #c8860a;">
      <div style="font-size:0.82rem;font-weight:700;color:#c8860a;margin-bottom:4px;">⚠ ملاحظة</div>
      <div id="comparison-note" style="font-size:0.8rem;color:#666;line-height:1.6;">
        مستوى الفحص يحتاج متابعة.
      </div>
    </div>
  </div>
</div>
</main>

<script>
function showSection(name, el) {
  document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
  document.getElementById('sec-' + name).classList.add('active');
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  if (el) el.classList.add('active');
}

const comparisonData = <?= json_encode($comparisonData, JSON_UNESCAPED_UNICODE) ?>;

function openComparison(key) {
  const data = comparisonData[key];
  if (!data) return;

  document.getElementById('comparison-title').textContent = data.title;
  document.getElementById('comparison-range').textContent = data.range;
  document.getElementById('comparison-note').textContent = data.note;

  const barsContainer = document.getElementById('comparison-bars');
  barsContainer.innerHTML = '';

  data.bars.forEach(item => {
    barsContainer.innerHTML += `
      <div style="display:flex;align-items:center;gap:14px;margin-bottom:12px;">
        <span style="font-size:0.8rem;color:#888;width:100px;flex-shrink:0;">${item.label}</span>
        <div style="flex:1;background:#f0ebe4;border-radius:6px;height:24px;position:relative;overflow:hidden;">
          <div style="width:${item.width};background:${item.color};height:100%;border-radius:6px;opacity:0.9;"></div>
        </div>
        <span style="font-size:0.82rem;font-weight:700;color:${item.color};width:90px;text-align:left;">${item.value}</span>
      </div>
    `;
  });

  const box = document.getElementById('comparison-box');
  box.style.display = 'block';
  box.scrollIntoView({ behavior: 'smooth', block: 'start' });}
  
  function togglePastAppointment(button) {
    const item = button.parentElement;
    item.classList.toggle('open');
  }

  function toggleCancelledAppointment(button) {
    const item = button.parentElement;
    item.classList.toggle('open');
  }

// Build labsData from DB for the edit modal
const labsData = {
<?php foreach ($labsList as $lab):
  $testNamesArr  = explode(',', $lab['test_names'] ?? '');
  $testPricesArr = explode(',', $lab['test_prices'] ?? '');
  $testUnitsArr  = explode(',', $lab['test_units'] ?? '');
  $jsTests = [];
  foreach ($testNamesArr as $i => $tName) {
      $price = (float)($testPricesArr[$i] ?? 0);
      $unit  = trim($testUnitsArr[$i] ?? '');
      $desc  = $unit ? 'الوحدة: ' . $unit : '';
      $jsTests[] = sprintf('{name:%s,price:%s,desc:%s}',
          json_encode($tName, JSON_UNESCAPED_UNICODE),
          json_encode($price, JSON_UNESCAPED_UNICODE),
          json_encode($desc,  JSON_UNESCAPED_UNICODE));
  }
  echo json_encode($lab['lab_name'], JSON_UNESCAPED_UNICODE) . ':['
      . implode(',', $jsTests) . '],';
endforeach; ?>
};

function cancelAppointment(btn, appointmentId) {
  showConfirm('هل أنت متأكد من إلغاء هذا الموعد؟', function () {
    fetch('cancel_appointment.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: 'appointment_id=' + appointmentId
    })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        btn.closest('.appointment-item').remove();
      } else {
        showAlert('تعذّر الإلغاء: ' + (data.message || 'خطأ غير متوقع'));
      }
    })
    .catch(() => showAlert('حدث خطأ في الاتصال بالخادم'));
  });
}

let selectedLabName = null;
let selectedLabTests = null;

function formatTime(timeStr) {
  const parts = timeStr.split(':');
  let h = parseInt(parts[0], 10);
  const m = parts[1] || '00';
  const suffix = h < 12 ? 'ص' : 'م';
  if (h > 12) h -= 12;
  return h + ':' + m + ' ' + suffix;
}

function fetchAvailableSlots() {
  const date = document.getElementById('bookingDate').value;
  const timeSelect = document.getElementById('bookingTime');
  if (!date || !selectedLabName) {
    timeSelect.innerHTML = '<option value="">اختر اليوم أولاً</option>';
    timeSelect.disabled = true;
    return;
  }
  timeSelect.innerHTML = '<option value="">جاري التحميل...</option>';
  timeSelect.disabled = true;
  fetch('get_available_slots.php?lab_name=' + encodeURIComponent(selectedLabName) + '&date=' + encodeURIComponent(date))
    .then(r => r.json())
    .then(data => {
      timeSelect.innerHTML = '';
      if (!data.slots || data.slots.length === 0) {
        timeSelect.innerHTML = '<option value="">لا توجد أوقات متاحة</option>';
        timeSelect.disabled = true;
      } else {
        timeSelect.innerHTML = '<option value="">اختر الوقت</option>';
        data.slots.forEach(s => {
          const opt = document.createElement('option');
          opt.value = s;
          opt.textContent = formatTime(s);
          timeSelect.appendChild(opt);
        });
        timeSelect.disabled = false;
      }
    })
    .catch(() => {
      timeSelect.innerHTML = '<option value="">حدث خطأ، حاول مجدداً</option>';
      timeSelect.disabled = true;
    });
}

function openBookingModal(labName, city, logo, tests) {
  selectedLabName = labName;
  selectedLabTests = tests;

  document.getElementById('modalLabName').textContent = labName;
  document.getElementById('modalLabCity').textContent = city;
  document.getElementById('modalLabLogo').src = logo;

  document.getElementById('bookingDate').value = '';
  const timeSelect = document.getElementById('bookingTime');
  timeSelect.innerHTML = '<option value="">اختر اليوم أولاً</option>';
  timeSelect.disabled = true;
  document.getElementById('confirmBox').style.display = 'none';
  document.getElementById('bookingFormStep').style.display = 'block';
  document.getElementById('bookingSummaryStep').style.display = 'none';
  clearBookingWarning();

  const testsInfo = document.getElementById('modalTestsInfo');
  const testsSelect = document.getElementById('modalTestsSelect');

  testsInfo.innerHTML = '';
  testsSelect.innerHTML = '';

  tests.forEach(test => {
    testsInfo.innerHTML += `
      <div class="test-info-card">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:6px;">
          <strong style="margin:0;">${test.name}</strong>
          <span style="font-size:0.78rem;font-weight:700;color:var(--deep-red);white-space:nowrap;">
            ${test.price} ريال
          </span>
        </div>
        <span>${test.desc}</span>
      </div>
    `;

    testsSelect.innerHTML += `
      <label class="booking-check">
        <input type="checkbox" value="${test.name}" data-price="${test.price}" class="test-checkbox" onchange="updateBookingTotal()">
        <span>${test.name}</span>
      </label>
    `;
  });
  document.getElementById('bookingTotal').textContent = '0 ريال';
  document.getElementById('bookingOverlay').style.display = 'flex';
}

function closeBookingModal() {
  document.getElementById('bookingOverlay').style.display = 'none';
}

function updateBookingTotal() {
  const checked = [...document.querySelectorAll('.test-checkbox:checked')];
  let total = 0;

  checked.forEach(cb => {
    total += Number(cb.dataset.price || 0);
  });

  document.getElementById('bookingTotal').textContent = total + ' ريال';
}

function confirmBooking() {
  const checked = [...document.querySelectorAll('.test-checkbox:checked')].map(cb => cb.value);
  const date = document.getElementById('bookingDate').value;
  const time = document.getElementById('bookingTime').value;
  const total = [...document.querySelectorAll('.test-checkbox:checked')]
    .reduce((sum, cb) => sum + Number(cb.dataset.price || 0), 0);

  if (checked.length < 1 || checked.length > 3) {
    showAlert('اختاري من تحليل واحد إلى ثلاثة تحاليل');
    return;
  }
  if (!date) { showAlert('اختاري اليوم أولاً'); return; }
  if (!time) { showAlert('اختاري الوقت أولاً'); return; }

  const formData = new FormData();
  formData.append('lab_name', selectedLabName);
  formData.append('date', date);
  formData.append('time', time);
  formData.append('tests', JSON.stringify(checked));

  const endpoint = editingItem
    ? 'update_appointment.php'
    : 'book_appointment.php';
  if (editingItem) {
    formData.append('appointment_id', editingItem.dataset.id);
  }

  fetch(endpoint, { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        // Show confirmation message
        const confirmText = `المختبر: ${selectedLabName}<br>التحاليل: ${checked.join('، ')}<br>التاريخ: ${date}<br>الوقت: ${time}<br>السعر الإجمالي: ${total} ريال`;
        document.getElementById('confirmText').innerHTML = confirmText;
        document.getElementById('confirmBox').style.display = 'block';

        // Update editing item if in edit mode
        if (editingItem) {
          editingItem.dataset.tests = checked.join(',');
          editingItem.dataset.date  = date;
          editingItem.dataset.time  = time;
          const testsEl = editingItem.querySelector('.appointment-tests');
          const dateEl  = editingItem.querySelector('.appointment-date');
          if (testsEl) testsEl.textContent = checked.join(' — ');
          if (dateEl)  dateEl.textContent  = `${date} — ${time}`;
          editingItem = null;
        }

        // Reload page after 2 seconds so new appointment appears
        setTimeout(() => location.reload(), 2000);
      } else {
        showAlert('حدث خطأ: ' + (data.message || 'تعذّر الحجز'));
      }
    })
    .catch(() => showAlert('حدث خطأ في الاتصال بالخادم'));
}
let editingItem = null;

function getLabTests(labName) {
  return labsData[labName] || [];
}

function openEditModal(btn) {
  const item = btn.closest('.appointment-item');
  editingItem = item;

  const lab = item.dataset.lab;
  const tests = item.dataset.tests.split(',').map(t => t.trim());
  const date = item.dataset.date;
  const time = item.dataset.time;

  openBookingModal(
    lab,
    'الرياض',
    '',
    getLabTests(lab)
  );

  setTimeout(() => {
    document.getElementById('bookingDate').value = date;
    document.getElementById('bookingTime').value = time;

    const checkboxes = document.querySelectorAll('.test-checkbox');
    checkboxes.forEach(cb => {
      cb.checked = tests.includes(cb.value);
    });

    updateBookingTotal();
  }, 100);
}

function showBookingWarning(msg) {
  const el = document.getElementById('bookingWarning');
  el.textContent = '⚠ ' + msg;
  el.style.display = 'block';
}

function clearBookingWarning() {
  const el = document.getElementById('bookingWarning');
  if (el) el.style.display = 'none';
}

function showBookingSummary() {
  const checked = [...document.querySelectorAll('.test-checkbox:checked')];
  const date = document.getElementById('bookingDate').value;
  const time = document.getElementById('bookingTime').value;

  if (checked.length < 1 || checked.length > 3) {
    showBookingWarning('اختاري من تحليل واحد إلى ثلاثة تحاليل');
    return;
  }
  if (!date) { showBookingWarning('اختاري اليوم أولاً'); return; }
  if (!time) { showBookingWarning('اختاري الوقت أولاً'); return; }
  clearBookingWarning();

  const labName = document.getElementById('modalLabName').textContent;
  const total = checked.reduce((sum, cb) => sum + Number(cb.dataset.price || 0), 0);

  const [h, m] = time.split(':');
  const hNum = parseInt(h);
  const suffix = hNum < 12 ? 'ص' : 'م';
  const h12 = hNum % 12 || 12;
  const timeFormatted = `${h12}:${m} ${suffix}`;

  const testsRows = checked.map(cb => `
    <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #f0ebe4;">
      <span style="font-size:0.88rem;color:#333;">${cb.value}</span>
      <span style="font-size:0.85rem;font-weight:700;color:var(--deep-red);">${cb.dataset.price} ريال</span>
    </div>`).join('');

  document.getElementById('summaryContent').innerHTML = `
    <div style="background:#fff;border:1px solid #eee4d9;border-radius:14px;padding:18px;margin-bottom:4px;">
      <div class="summary-row">
        <span class="summary-label">المختبر</span>
        <span class="summary-value">${labName}</span>
      </div>
      <div style="padding:12px 0;border-bottom:1px solid #f0ebe4;">
        <div class="summary-label" style="margin-bottom:10px;">التحاليل المختارة</div>
        ${testsRows}
      </div>
      <div class="summary-row">
        <span class="summary-label">التاريخ</span>
        <span class="summary-value">${date}</span>
      </div>
      <div class="summary-row">
        <span class="summary-label">الوقت</span>
        <span class="summary-value">${timeFormatted}</span>
      </div>
      <div class="summary-row" style="border-bottom:none;padding-top:14px;">
        <span class="summary-label" style="font-weight:800;color:#222;font-size:0.95rem;">السعر الإجمالي</span>
        <span class="summary-value" style="font-size:1.15rem;font-weight:800;color:var(--deep-red);">${total} ريال</span>
      </div>
    </div>`;

  document.getElementById('bookingFormStep').style.display = 'none';
  document.getElementById('bookingSummaryStep').style.display = 'block';
  document.getElementById('confirmBox').style.display = 'none';
}

function backToBookingForm() {
  document.getElementById('bookingFormStep').style.display = 'block';
  document.getElementById('bookingSummaryStep').style.display = 'none';
}

function filterLabsByTest() {
  const searchInput = document.getElementById('labTestSearch');
  const query = searchInput.value.trim().toLowerCase();
  const labs = [...document.querySelectorAll('.lab-item-searchable')];
  const noResults = document.getElementById('labsNoResults');

  let hasMatch = false;

  labs.forEach(lab => {
    const tests = lab.dataset.tests.toLowerCase();
    const pills = lab.querySelectorAll('.test-pill');

    pills.forEach(pill => pill.classList.remove('highlighted'));

    if (query === '') {
      lab.style.display = '';
      lab.style.order = '0';
    } else if (tests.includes(query)) {
      lab.style.display = '';
      lab.style.order = '-1';
      hasMatch = true;

      pills.forEach(pill => {
        if (pill.textContent.trim().toLowerCase().includes(query)) {
          pill.classList.add('highlighted');
        }
      });
    } else {
      lab.style.display = 'none';
      lab.style.order = '1';
    }
  });

  noResults.style.display = (query !== '' && !hasMatch) ? 'block' : 'none';
}
</script>
<?php include 'ui-modal.php'; ?>
</body>
</html>
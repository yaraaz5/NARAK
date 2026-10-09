<?php
date_default_timezone_set('Asia/Riyadh');
session_start();
require_once 'db.php';
header('Content-Type: application/json; charset=utf-8');

function respond($success, $message, $extra = [], $code = 200) {
    http_response_code($code);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'customer') {
    respond(false, 'غير مصرح', [], 403);
}
$customerId = (int) $_SESSION['user_id'];
$labName = trim($_POST['lab_name'] ?? '');
$date = trim($_POST['date'] ?? '');
$time = trim($_POST['time'] ?? '');
$tests = json_decode($_POST['tests'] ?? '', true);
if ($labName === '' || !is_array($tests) || count($tests) < 1 || count($tests) > 3) {
    respond(false, 'بيانات المختبر أو التحاليل غير صحيحة', [], 422);
}
$dateObj = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
if (!$dateObj || $dateObj->format('Y-m-d') !== $date || $date < date('Y-m-d')) {
    respond(false, 'تاريخ الحجز غير صحيح', [], 422);
}
if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', $time)) {
    respond(false, 'وقت الحجز غير صحيح', [], 422);
}
if (strlen($time) === 5) $time .= ':00';
$tests = array_map(function ($item) {
    return is_string($item) ? trim($item) : '';
}, $tests);
if (in_array('', $tests, true) || count(array_unique($tests)) !== count($tests)) {
    respond(false, 'قائمة التحاليل غير صحيحة', [], 422);
}

try {
    mysqli_begin_transaction($conn);
    $stmt = mysqli_prepare($conn, 'SELECT lab_id FROM laboratory WHERE lab_name = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 's', $labName);
    mysqli_stmt_execute($stmt);
    $lab = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$lab) throw new DomainException('المختبر غير موجود');
    $labId = (int) $lab['lab_id'];

    $testIds = [];
    $testStmt = mysqli_prepare($conn, 'SELECT test_type_id FROM test_type WHERE lab_id = ? AND test_name = ? LIMIT 1');
    foreach ($tests as $test) {
        mysqli_stmt_bind_param($testStmt, 'is', $labId, $test);
        mysqli_stmt_execute($testStmt);
        $record = mysqli_fetch_assoc(mysqli_stmt_get_result($testStmt));
        if (!$record) throw new DomainException('أحد التحاليل غير متاح في المختبر');
        $testIds[] = (int) $record['test_type_id'];
    }

    // Lock the exact published slot. Never manufacture availability.
    $slotStmt = mysqli_prepare($conn, 'SELECT slot_id FROM time_slot WHERE lab_id = ? AND slot_date = ? AND slot_time = ? AND is_available = 1 LIMIT 1 FOR UPDATE');
    mysqli_stmt_bind_param($slotStmt, 'iss', $labId, $date, $time);
    mysqli_stmt_execute($slotStmt);
    $slot = mysqli_fetch_assoc(mysqli_stmt_get_result($slotStmt));
    if (!$slot) throw new DomainException('هذا الموعد غير متاح');
    $slotId = (int) $slot['slot_id'];

    $mark = mysqli_prepare($conn, 'UPDATE time_slot SET is_available = 0 WHERE slot_id = ? AND is_available = 1');
    mysqli_stmt_bind_param($mark, 'i', $slotId);
    if (!mysqli_stmt_execute($mark) || mysqli_stmt_affected_rows($mark) !== 1) {
        throw new DomainException('هذا الموعد محجوز');
    }

    $appt = mysqli_prepare($conn, "INSERT INTO appointment (customer_id, lab_id, slot_id, status) VALUES (?, ?, ?, 'pending')");
    mysqli_stmt_bind_param($appt, 'iii', $customerId, $labId, $slotId);
    if (!mysqli_stmt_execute($appt)) throw new RuntimeException('appointment insert failed');
    $appointmentId = (int) mysqli_insert_id($conn);

    $link = mysqli_prepare($conn, 'INSERT INTO appointment_test_type (appointment_id, test_type_id) VALUES (?, ?)');
    foreach ($testIds as $testId) {
        mysqli_stmt_bind_param($link, 'ii', $appointmentId, $testId);
        if (!mysqli_stmt_execute($link)) throw new RuntimeException('test link failed');
    }
    mysqli_commit($conn);
    respond(true, 'تم الحجز بنجاح', ['appointment_id' => $appointmentId]);
} catch (DomainException $e) {
    mysqli_rollback($conn);
    respond(false, $e->getMessage(), [], 409);
} catch (Throwable $e) {
    mysqli_rollback($conn);
    error_log('NARAK booking failed: ' . $e->getMessage());
    respond(false, 'تعذر إتمام الحجز، حاول مرة أخرى', [], 500);
}

<?php
date_default_timezone_set('Asia/Riyadh');
session_start();
require_once 'db.php';
header('Content-Type: application/json; charset=utf-8');
function reply($success, $message, $extra = [], $code = 200) {
    http_response_code($code);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'customer') {
    reply(false, 'غير مصرح', [], 403);
}
$customerId = (int) $_SESSION['user_id'];
$appointmentId = (int) ($_POST['appointment_id'] ?? 0);
$labName = trim($_POST['lab_name'] ?? '');
$date = trim($_POST['date'] ?? '');
$time = trim($_POST['time'] ?? '');
$tests = json_decode($_POST['tests'] ?? '', true);
if ($appointmentId <= 0 || $labName === '' || !is_array($tests) || count($tests) < 1 || count($tests) > 3) {
    reply(false, 'بيانات غير صحيحة', [], 422);
}
$validDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
if (!$validDate || $validDate->format('Y-m-d') !== $date || $date < date('Y-m-d')) {
    reply(false, 'التاريخ غير صحيح', [], 422);
}
if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', $time)) {
    reply(false, 'الوقت غير صحيح', [], 422);
}
if (strlen($time) === 5) $time .= ':00';
$tests = array_map(fn($t) => is_string($t) ? trim($t) : '', $tests);
if (in_array('', $tests, true) || count(array_unique($tests)) !== count($tests)) {
    reply(false, 'التحاليل غير صحيحة', [], 422);
}
try {
    mysqli_begin_transaction($conn);
    $stmt = mysqli_prepare($conn, "SELECT slot_id FROM appointment WHERE appointment_id = ? AND customer_id = ? AND status = 'pending' LIMIT 1 FOR UPDATE");
    mysqli_stmt_bind_param($stmt, 'ii', $appointmentId, $customerId);
    mysqli_stmt_execute($stmt);
    $oldAppt = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$oldAppt) throw new DomainException('لا يمكن تعديل هذا الموعد');
    $oldSlotId = (int)$oldAppt['slot_id'];

    $labStmt = mysqli_prepare($conn, 'SELECT lab_id FROM laboratory WHERE lab_name = ? LIMIT 1');
    mysqli_stmt_bind_param($labStmt, 's', $labName);
    mysqli_stmt_execute($labStmt);
    $lab = mysqli_fetch_assoc(mysqli_stmt_get_result($labStmt));
    if (!$lab) throw new DomainException('المختبر غير موجود');
    $labId = (int)$lab['lab_id'];

    $testIds = [];
    $testStmt = mysqli_prepare($conn, 'SELECT test_type_id FROM test_type WHERE lab_id = ? AND test_name = ? LIMIT 1');
    foreach ($tests as $test) {
        mysqli_stmt_bind_param($testStmt, 'is', $labId, $test);
        mysqli_stmt_execute($testStmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($testStmt));
        if (!$row) throw new DomainException('تحليل غير متاح');
        $testIds[] = (int)$row['test_type_id'];
    }

    // First lock the target slot. Keep the original reserved unless the new one is secured.
    $slotStmt = mysqli_prepare($conn, 'SELECT slot_id, is_available FROM time_slot WHERE lab_id = ? AND slot_date = ? AND slot_time = ? LIMIT 1 FOR UPDATE');
    mysqli_stmt_bind_param($slotStmt, 'iss', $labId, $date, $time);
    mysqli_stmt_execute($slotStmt);
    $slot = mysqli_fetch_assoc(mysqli_stmt_get_result($slotStmt));
    if (!$slot) throw new DomainException('الموعد الجديد غير متاح');
    $newSlotId = (int)$slot['slot_id'];
    if ($newSlotId !== $oldSlotId) {
        if ((int)$slot['is_available'] !== 1) throw new DomainException('الموعد الجديد محجوز');
        $reserve = mysqli_prepare($conn, 'UPDATE time_slot SET is_available = 0 WHERE slot_id = ? AND is_available = 1');
        mysqli_stmt_bind_param($reserve, 'i', $newSlotId);
        if (!mysqli_stmt_execute($reserve) || mysqli_stmt_affected_rows($reserve) !== 1) throw new DomainException('الموعد الجديد محجوز');
    }

    $upd = mysqli_prepare($conn, 'UPDATE appointment SET slot_id = ?, lab_id = ? WHERE appointment_id = ? AND customer_id = ?');
    mysqli_stmt_bind_param($upd, 'iiii', $newSlotId, $labId, $appointmentId, $customerId);
    if (!mysqli_stmt_execute($upd)) throw new RuntimeException('appointment update failed');

    $delete = mysqli_prepare($conn, 'DELETE FROM appointment_test_type WHERE appointment_id = ?');
    mysqli_stmt_bind_param($delete, 'i', $appointmentId);
    if (!mysqli_stmt_execute($delete)) throw new RuntimeException('test replacement failed');
    $link = mysqli_prepare($conn, 'INSERT INTO appointment_test_type (appointment_id, test_type_id) VALUES (?, ?)');
    foreach ($testIds as $testId) {
        mysqli_stmt_bind_param($link, 'ii', $appointmentId, $testId);
        if (!mysqli_stmt_execute($link)) throw new RuntimeException('test replacement failed');
    }
    if ($newSlotId !== $oldSlotId) {
        $free = mysqli_prepare($conn, 'UPDATE time_slot SET is_available = 1 WHERE slot_id = ?');
        mysqli_stmt_bind_param($free, 'i', $oldSlotId);
        if (!mysqli_stmt_execute($free)) throw new RuntimeException('old slot release failed');
    }
    mysqli_commit($conn);
    reply(true, 'تم تعديل الموعد', ['appointment_id' => $appointmentId]);
} catch (DomainException $e) {
    mysqli_rollback($conn);
    reply(false, $e->getMessage(), [], 409);
} catch (Throwable $e) {
    mysqli_rollback($conn);
    error_log('NARAK appointment update failed: ' . $e->getMessage());
    reply(false, 'تعذر تعديل الموعد', [], 500);
}

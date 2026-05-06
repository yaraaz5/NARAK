<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit;
}

$customerId    = (int) $_SESSION['user_id'];
$appointmentId = (int) ($_POST['appointment_id'] ?? 0);
$labName       = trim($_POST['lab_name'] ?? '');
$date          = trim($_POST['date']     ?? '');
$time          = trim($_POST['time']     ?? '');
$testsJson     = trim($_POST['tests']    ?? '');

if (!$appointmentId || !$labName || !$date || !$time || !$testsJson) {
    echo json_encode(['success' => false, 'message' => 'بيانات ناقصة']);
    exit;
}

$tests = json_decode($testsJson, true);
if (!is_array($tests) || empty($tests)) {
    echo json_encode(['success' => false, 'message' => 'لم يتم اختيار أي تحليل']);
    exit;
}

// Verify this appointment belongs to the logged-in customer and is still pending
$stmtCheck = mysqli_prepare($conn,
    "SELECT a.appointment_id, a.slot_id FROM appointment a
     WHERE a.appointment_id = ? AND a.customer_id = ? AND a.status = 'pending'
     LIMIT 1"
);
mysqli_stmt_bind_param($stmtCheck, 'ii', $appointmentId, $customerId);
mysqli_stmt_execute($stmtCheck);
$apptRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtCheck));

if (!$apptRow) {
    echo json_encode(['success' => false, 'message' => 'الموعد غير موجود أو لا يمكن تعديله']);
    exit;
}

$oldSlotId = (int) $apptRow['slot_id'];

// Get lab_id
$stmtLab = mysqli_prepare($conn, "SELECT lab_id FROM laboratory WHERE lab_name = ? LIMIT 1");
mysqli_stmt_bind_param($stmtLab, 's', $labName);
mysqli_stmt_execute($stmtLab);
$labRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtLab));
if (!$labRow) {
    echo json_encode(['success' => false, 'message' => 'المختبر غير موجود']);
    exit;
}
$labId = (int) $labRow['lab_id'];

// Validate time format
$timeParsed = $time;
if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $timeParsed)) {
    echo json_encode(['success' => false, 'message' => 'صيغة الوقت غير صحيحة']);
    exit;
}
if (strlen($timeParsed) === 5) $timeParsed .= ':00';

// Free the old slot
mysqli_query($conn, "UPDATE time_slot SET is_available = 1 WHERE slot_id = $oldSlotId");

// Find or create the new slot
$stmtSlot = mysqli_prepare($conn,
    "SELECT slot_id FROM time_slot
     WHERE lab_id = ? AND slot_date = ? AND slot_time = ? AND is_available = 1
     LIMIT 1"
);
mysqli_stmt_bind_param($stmtSlot, 'iss', $labId, $date, $timeParsed);
mysqli_stmt_execute($stmtSlot);
$slotRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtSlot));

if ($slotRow) {
    $newSlotId = (int) $slotRow['slot_id'];
    mysqli_query($conn, "UPDATE time_slot SET is_available = 0 WHERE slot_id = $newSlotId");
} else {
    $stmtNewSlot = mysqli_prepare($conn,
        "INSERT INTO time_slot (lab_id, slot_date, slot_time, is_available) VALUES (?, ?, ?, 0)"
    );
    mysqli_stmt_bind_param($stmtNewSlot, 'iss', $labId, $date, $timeParsed);
    mysqli_stmt_execute($stmtNewSlot);
    $newSlotId = (int) mysqli_insert_id($conn);
}

// Update the appointment's slot
$stmtUpdate = mysqli_prepare($conn,
    "UPDATE appointment SET slot_id = ? WHERE appointment_id = ?"
);
mysqli_stmt_bind_param($stmtUpdate, 'ii', $newSlotId, $appointmentId);
mysqli_stmt_execute($stmtUpdate);

// Replace tests: delete old, insert new
mysqli_query($conn, "DELETE FROM appointment_test_type WHERE appointment_id = $appointmentId");

$stmtTest = mysqli_prepare($conn,
    "SELECT test_type_id FROM test_type WHERE test_name = ? AND lab_id = ? LIMIT 1"
);
$stmtLink = mysqli_prepare($conn,
    "INSERT INTO appointment_test_type (appointment_id, test_type_id) VALUES (?, ?)"
);

foreach ($tests as $testName) {
    $testName = trim($testName);
    mysqli_stmt_bind_param($stmtTest, 'si', $testName, $labId);
    mysqli_stmt_execute($stmtTest);
    $testRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtTest));
    if ($testRow) {
        $testTypeId = (int) $testRow['test_type_id'];
        mysqli_stmt_bind_param($stmtLink, 'ii', $appointmentId, $testTypeId);
        mysqli_stmt_execute($stmtLink);
    }
}

echo json_encode(['success' => true, 'appointment_id' => $appointmentId]);
exit;
?>

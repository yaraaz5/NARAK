<?php
session_start();
require_once 'db.php';
date_default_timezone_set('Asia/Riyadh');

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit;
}

$customerId    = (int) $_SESSION['user_id'];
$appointmentId = (int) ($_POST['appointment_id'] ?? 0);

if (!$appointmentId) {
    echo json_encode(['success' => false, 'message' => 'معرّف الموعد مفقود']);
    exit;
}

mysqli_begin_transaction($conn);

// Verify the appointment belongs to this customer and is cancellable
$stmt = mysqli_prepare($conn,
    "SELECT slot_id FROM appointment
     WHERE appointment_id = ? AND customer_id = ? AND status IN ('pending','delayed','overdue')
     LIMIT 1 FOR UPDATE"
);
mysqli_stmt_bind_param($stmt, 'ii', $appointmentId, $customerId);
mysqli_stmt_execute($stmt);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$row) {
    mysqli_rollback($conn);
    echo json_encode(['success' => false, 'message' => 'لا يمكن إلغاء هذا الموعد']);
    exit;
}

$slotId = (int) $row['slot_id'];

// Cancel the appointment
$stmtCancel = mysqli_prepare($conn,
    "UPDATE appointment SET status = 'cancelled' WHERE appointment_id = ?"
);
mysqli_stmt_bind_param($stmtCancel, 'i', $appointmentId);
if (!mysqli_stmt_execute($stmtCancel)) {
    mysqli_rollback($conn);
    echo json_encode(['success' => false, 'message' => 'فشل إلغاء الموعد']);
    exit;
}

// Free the time slot so others can book it
$stmtSlot = mysqli_prepare($conn,
    "UPDATE time_slot SET is_available = 1 WHERE slot_id = ?"
);
mysqli_stmt_bind_param($stmtSlot, 'i', $slotId);
if (!mysqli_stmt_execute($stmtSlot)) {
    mysqli_rollback($conn);
    echo json_encode(['success' => false, 'message' => 'تعذر تحرير الموعد']);
    exit;
}
mysqli_commit($conn);

echo json_encode(['success' => true]);
exit;
?>

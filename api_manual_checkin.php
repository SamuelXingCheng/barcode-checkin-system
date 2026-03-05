<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'config.php';

$input = json_decode(file_get_contents('php://input'), true);
$student_id = isset($input['student_id']) ? intval($input['student_id']) : 0;
$week_no = isset($input['week_no']) ? intval($input['week_no']) : 1;
$action = isset($input['action']) ? $input['action'] : ''; 

if ($student_id <= 0 || !in_array($action, ['checkin', 'checkout'])) {
    echo json_encode(['status' => 'error', 'message' => '傳遞參數無效']);
    exit;
}

try {
    $stmt = $pdo->query("SELECT id FROM terms WHERE is_active = 1 LIMIT 1");
    $active_term = $stmt->fetch();
    if (!$active_term) {
        echo json_encode(['status' => 'error', 'message' => '系統尚未設定開放的期別']);
        exit;
    }
    $term_id = $active_term['id'];

    if ($action === 'checkin') {
        $stmt = $pdo->prepare("SELECT id FROM barcode_checkin_log WHERE student_id = :student_id AND term_id = :term_id AND week_no = :week_no");
        $stmt->execute([':student_id' => $student_id, ':term_id' => $term_id, ':week_no' => $week_no]);
        
        if (!$stmt->fetch()) {
            $insertStmt = $pdo->prepare("INSERT INTO barcode_checkin_log (student_id, term_id, week_no, scan_time, is_manual) VALUES (:student_id, :term_id, :week_no, NOW(), 1)");
            $insertStmt->execute([':student_id' => $student_id, ':term_id' => $term_id, ':week_no' => $week_no]);
        }
    } else if ($action === 'checkout') {
        $deleteStmt = $pdo->prepare("DELETE FROM barcode_checkin_log WHERE student_id = :student_id AND term_id = :term_id AND week_no = :week_no");
        $deleteStmt->execute([':student_id' => $student_id, ':term_id' => $term_id, ':week_no' => $week_no]);
    }

    echo json_encode(['status' => 'success', 'message' => '出勤狀態已更新']);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => '資料庫處理失敗：' . $e->getMessage()]);
}
?>
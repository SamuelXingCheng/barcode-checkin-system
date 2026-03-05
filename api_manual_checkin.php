<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'config.php';

// 接收前端以 JSON 格式傳遞的資料
$input = json_decode(file_get_contents('php://input'), true);
$student_id = isset($input['student_id']) ? intval($input['student_id']) : 0;
$action = isset($input['action']) ? $input['action'] : ''; // 預期值為 'checkin' 或 'checkout'

if ($student_id <= 0 || !in_array($action, ['checkin', 'checkout'])) {
    echo json_encode(['status' => 'error', 'message' => '傳遞參數無效']);
    exit;
}

try {
    // 取得當前運行中的期別
    $stmt = $pdo->query("SELECT id FROM terms WHERE is_active = 1 LIMIT 1");
    $active_term = $stmt->fetch();
    
    if (!$active_term) {
        echo json_encode(['status' => 'error', 'message' => '系統尚未設定開放的期別']);
        exit;
    }
    $term_id = $active_term['id'];

    if ($action === 'checkin') {
        // 檢查是否已存在今日紀錄，若無則新增 (is_manual = 1 代表手動補簽)
        $stmt = $pdo->prepare("SELECT id FROM barcode_checkin_log WHERE student_id = :student_id AND term_id = :term_id AND DATE(scan_time) = CURDATE()");
        $stmt->execute([':student_id' => $student_id, ':term_id' => $term_id]);
        
        if (!$stmt->fetch()) {
            $insertStmt = $pdo->prepare("INSERT INTO barcode_checkin_log (student_id, term_id, scan_time, is_manual) VALUES (:student_id, :term_id, NOW(), 1)");
            $insertStmt->execute([':student_id' => $student_id, ':term_id' => $term_id]);
        }
    } else if ($action === 'checkout') {
        // 取消簽到：刪除該名學生今日的報到紀錄
        $deleteStmt = $pdo->prepare("DELETE FROM barcode_checkin_log WHERE student_id = :student_id AND term_id = :term_id AND DATE(scan_time) = CURDATE()");
        $deleteStmt->execute([':student_id' => $student_id, ':term_id' => $term_id]);
    }

    echo json_encode(['status' => 'success', 'message' => '出勤狀態已更新']);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => '資料庫處理失敗：' . $e->getMessage()]);
}
?>
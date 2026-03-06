<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['status' => 'error', 'message' => '請先登入']);
    exit;
}

require_once 'config.php';

$input = json_decode(file_get_contents('php://input'), true);
$student_id = intval($input['student_id'] ?? 0);
$action = $input['action'] ?? ''; // 'checkin_ontime', 'checkin_late', 'checkout'
$week_no = intval($input['week_no'] ?? 0);

if ($student_id <= 0 || $week_no <= 0 || empty($action)) {
    echo json_encode(['status' => 'error', 'message' => '參數錯誤']);
    exit;
}

try {
    $stmtTerm = $pdo->query("SELECT id FROM terms WHERE is_active = 1 LIMIT 1");
    $term = $stmtTerm->fetch();
    if (!$term) {
        echo json_encode(['status' => 'error', 'message' => '尚未啟用期別']);
        exit;
    }
    $term_id = $term['id'];

    if ($action === 'checkout') {
        // 取消報到
        $stmt = $pdo->prepare("DELETE FROM barcode_checkin_log WHERE student_id = :student_id AND term_id = :term_id AND week_no = :week_no");
        $stmt->execute([':student_id' => $student_id, ':term_id' => $term_id, ':week_no' => $week_no]);
        echo json_encode(['status' => 'success', 'message' => '已取消報到']);
    } else {
        // 報到 (防呆：先檢查是否已經簽過了)
        $stmtCheck = $pdo->prepare("SELECT id FROM barcode_checkin_log WHERE student_id = :student_id AND term_id = :term_id AND week_no = :week_no");
        $stmtCheck->execute([':student_id' => $student_id, ':term_id' => $term_id, ':week_no' => $week_no]);
        
        if (!$stmtCheck->fetch()) {
            $is_late = ($action === 'checkin_late') ? 1 : 0;
            $stmtInsert = $pdo->prepare("INSERT INTO barcode_checkin_log (student_id, term_id, week_no, scan_time, is_manual, is_late) VALUES (:student_id, :term_id, :week_no, NOW(), 1, :is_late)");
            $stmtInsert->execute([
                ':student_id' => $student_id, 
                ':term_id' => $term_id, 
                ':week_no' => $week_no, 
                ':is_late' => $is_late
            ]);
        }
        echo json_encode(['status' => 'success', 'message' => '報到成功']);
    }
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => '資料庫錯誤：' . $e->getMessage()]);
}
?>
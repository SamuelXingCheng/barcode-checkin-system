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
$action = $input['action'] ?? ''; 
$week_no = intval($input['week_no'] ?? 0);

if ($student_id <= 0 || $week_no <= 0 || empty($action)) {
    echo json_encode(['status' => 'error', 'message' => '參數錯誤']);
    exit;
}

try {
    $stmtTerm = $pdo->query("SELECT id FROM terms WHERE is_active = 1 LIMIT 1");
    $term = $stmtTerm->fetch();
    if (!$term) {
        echo json_encode(['status' => 'error', 'message' => '尚未啟用期別']); exit;
    }
    $term_id = $term['id'];

    if ($action === 'checkout') {
        // 取消報到與作業紀錄
        $stmt = $pdo->prepare("DELETE FROM barcode_checkin_log WHERE student_id = ? AND term_id = ? AND week_no = ?");
        $stmt->execute([$student_id, $term_id, $week_no]);
        echo json_encode(['status' => 'success', 'message' => '已取消所有狀態']);
    } 
    elseif ($action === 'update_hw') {
        $hw_type = $input['hw_type']; 
        $hw_val = intval($input['hw_val']);
        
        $stmtCheck = $pdo->prepare("SELECT id FROM barcode_checkin_log WHERE student_id = ? AND term_id = ? AND week_no = ?");
        $stmtCheck->execute([$student_id, $term_id, $week_no]);
        $row = $stmtCheck->fetch();

        if ($row) {
            $stmtUpdate = $pdo->prepare("UPDATE barcode_checkin_log SET {$hw_type} = ? WHERE id = ?");
            $stmtUpdate->execute([$hw_val, $row['id']]);
            echo json_encode(['status' => 'success', 'message' => '作業狀態已更新']);
        } else {
            // 👉 防呆機制：如果還沒報到，直接阻擋並回報錯誤
            echo json_encode(['status' => 'error', 'message' => '請先標記出勤狀態（準時/已到/請假），才能註記作業喔！']);
        }
    }
    else {
        // 處理：checkin_ontime (準時), checkin_late (已到), set_leave (請假)
        $is_late = ($action === 'checkin_late') ? 1 : 0;
        $is_leave = ($action === 'set_leave') ? 1 : 0;

        $stmtCheck = $pdo->prepare("SELECT id FROM barcode_checkin_log WHERE student_id = ? AND term_id = ? AND week_no = ?");
        $stmtCheck->execute([$student_id, $term_id, $week_no]);
        $row = $stmtCheck->fetch();

        if ($row) {
            $stmtUpdate = $pdo->prepare("UPDATE barcode_checkin_log SET is_late = ?, is_leave = ? WHERE id = ?");
            $stmtUpdate->execute([$is_late, $is_leave, $row['id']]);
        } else {
            $stmtInsert = $pdo->prepare("INSERT INTO barcode_checkin_log (student_id, term_id, week_no, scan_time, is_manual, is_late, is_leave) VALUES (?, ?, ?, NOW(), 1, ?, ?)");
            $stmtInsert->execute([$student_id, $term_id, $week_no, $is_late, $is_leave]);
        }
        echo json_encode(['status' => 'success', 'message' => '出勤狀態已更新']);
    }
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => '資料庫錯誤：' . $e->getMessage()]);
}
?>
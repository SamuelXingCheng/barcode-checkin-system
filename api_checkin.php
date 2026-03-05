<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'config.php'; 

$student_no = isset($_GET['student_no']) ? trim($_GET['student_no']) : '';
$week_no = isset($_GET['week_no']) ? intval($_GET['week_no']) : 1;

if (empty($student_no)) {
    echo json_encode(['status' => 'error', 'message' => '未讀取到條碼']);
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

    $stmt = $pdo->prepare("SELECT id, name FROM students WHERE student_no = :student_no LIMIT 1");
    $stmt->execute([':student_no' => $student_no]);
    $student = $stmt->fetch();
    if (!$student) {
        echo json_encode(['status' => 'error', 'message' => '條碼無效：找不到此學號的學生']);
        exit;
    }
    $student_id = $student['id'];
    $student_name = $student['name'];

    // 檢查該週是否已簽到過
    $stmt = $pdo->prepare("SELECT id FROM barcode_checkin_log WHERE student_id = :student_id AND term_id = :term_id AND week_no = :week_no");
    $stmt->execute([':student_id' => $student_id, ':term_id' => $term_id, ':week_no' => $week_no]);
    
    if ($stmt->fetch()) {
        echo json_encode(['status' => 'warning', 'message' => "重複報到：{$student_name} 於第 {$week_no} 週已簽到過"]);
        exit;
    }

    // 寫入包含 week_no 的報到紀錄
    $stmt = $pdo->prepare("INSERT INTO barcode_checkin_log (student_id, term_id, week_no, scan_time, is_manual) VALUES (:student_id, :term_id, :week_no, NOW(), 0)");
    $stmt->execute([':student_id' => $student_id, ':term_id' => $term_id, ':week_no' => $week_no]);

    echo json_encode(['status' => 'success', 'message' => '報到成功', 'name' => $student_name]);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => '系統錯誤：' . $e->getMessage()]);
}
?>
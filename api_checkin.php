<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

// 1. 登入狀態檢查
if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['status' => 'error', 'message' => '登入已過期，請重新登入系統。']);
    exit;
}

require_once 'config.php';

$is_super = ($_SESSION['role'] === 'super');
$admin_class_id = $_SESSION['class_id'] ?? 0;

// 2. 接收前端資料 (完全相容原有的 GET 傳輸方式)
$student_no = isset($_REQUEST['student_no']) ? trim($_REQUEST['student_no']) : '';
$week_no = isset($_REQUEST['week_no']) ? intval($_REQUEST['week_no']) : 1;

if (empty($student_no)) {
    echo json_encode(['status' => 'error', 'message' => '未讀取到條碼，請重新掃描。']);
    exit;
}

try {
    // 取得當前期別
    $stmtTerm = $pdo->query("SELECT id FROM terms WHERE is_active = 1 LIMIT 1");
    $active_term = $stmtTerm->fetch();
    if (!$active_term) {
        echo json_encode(['status' => 'error', 'message' => '系統尚未設定開放的期別']);
        exit;
    }
    $term_id = $active_term['id'];

    // 3. 反查該學生的資料、所屬班級與該班上課時間
    $sqlStudent = "
        SELECT s.id, s.name, stc.class_id, c.class_name, c.start_time
        FROM students s
        LEFT JOIN student_term_class stc ON s.id = stc.student_id AND stc.term_id = :term_id
        LEFT JOIN classes c ON stc.class_id = c.id
        WHERE s.student_no = :student_no
        LIMIT 1
    ";
    $stmtStudent = $pdo->prepare($sqlStudent);
    $stmtStudent->execute([
        ':student_no' => $student_no,
        ':term_id' => $term_id
    ]);
    $student = $stmtStudent->fetch();

    if (!$student) {
        echo json_encode(['status' => 'error', 'message' => '條碼無效：找不到此學號的學生。']);
        exit;
    }

    if (empty($student['class_id'])) {
        echo json_encode(['status' => 'error', 'message' => "報到阻擋：【{$student['name']}】尚未分發至任何班級。"]);
        exit;
    }

    $student_id = $student['id'];
    $student_class_id = $student['class_id'];
    $student_name = $student['name'];

    // 4. 嚴格權限防護：如果是一般班級管理員，只能幫自己班的學生簽到
    if (!$is_super && $student_class_id !== $admin_class_id) {
        echo json_encode(['status' => 'error', 'message' => "跨班阻擋：【{$student_name}】屬於「{$student['class_name']}」，非您的管轄班級！"]);
        exit;
    }

    // 5. 檢查本週是否已簽到過
    $stmtCheckLog = $pdo->prepare("
        SELECT id FROM barcode_checkin_log 
        WHERE student_id = :student_id AND term_id = :term_id AND week_no = :week_no
    ");
    $stmtCheckLog->execute([
        ':student_id' => $student_id,
        ':term_id' => $term_id,
        ':week_no' => $week_no
    ]);
    
    if ($stmtCheckLog->fetch()) {
        echo json_encode(['status' => 'warning', 'message' => "重複報到：{$student_name} 於第 {$week_no} 週已簽到過。"]);
        exit;
    }

    // 6. 遲到判定邏輯
    $is_late = 0;
    $current_time = date('H:i:s');
    // 如果班級有設定上課時間，且現在時間大於上課時間，則標記遲到
    if (!empty($student['start_time']) && $current_time > $student['start_time']) {
        $is_late = 1;
    }

    // 7. 寫入包含遲到狀態的報到紀錄 (相容舊版，補簽預設為0)
    $stmtInsert = $pdo->prepare("
        INSERT INTO barcode_checkin_log (student_id, term_id, week_no, scan_time, is_manual, is_late) 
        VALUES (:student_id, :term_id, :week_no, NOW(), 0, :is_late)
    ");
    $stmtInsert->execute([
        ':student_id' => $student_id,
        ':term_id' => $term_id,
        ':week_no' => $week_no,
        ':is_late' => $is_late
    ]);

    $late_text = $is_late ? " (遲到)" : "";
    
    // 回傳前端需要的格式，確保前端 JS 不會報錯
    echo json_encode([
        'status' => 'success', 
        'message' => "報到成功{$late_text}", 
        'name' => $student_name
    ]);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => '系統錯誤：' . $e->getMessage()]);
}
?>
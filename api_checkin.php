<?php
// 宣告這支 API 回傳的是 JSON 格式，並設定編碼
header('Content-Type: application/json; charset=utf-8');

// 引入資料庫連線設定 (如果連線失敗，程式會在這裡停止)
require_once 'config.php'; 

// 1. 接收前端傳來的學號 (條碼內容)
// 這裡使用 GET 方式接收，方便我們等一下直接在瀏覽器網址列測試
$student_no = isset($_GET['student_no']) ? trim($_GET['student_no']) : '';

// 檢查有沒有傳入學號
if (empty($student_no)) {
    echo json_encode(['status' => 'error', 'message' => '未讀取到條碼']);
    exit;
}

try {
    // 2. 取得「當前運行中」的期別 (找 is_active = 1 的那筆)
    $stmt = $pdo->query("SELECT id, term_name FROM terms WHERE is_active = 1 LIMIT 1");
    $active_term = $stmt->fetch();
    
    if (!$active_term) {
        echo json_encode(['status' => 'error', 'message' => '系統尚未設定開放的期別']);
        exit;
    }
    $term_id = $active_term['id'];

    // 3. 查詢該學號對應的學生資料
    $stmt = $pdo->prepare("SELECT id, name FROM students WHERE student_no = :student_no LIMIT 1");
    $stmt->execute([':student_no' => $student_no]);
    $student = $stmt->fetch();

    if (!$student) {
        echo json_encode(['status' => 'error', 'message' => '條碼無效：找不到此學號的學生']);
        exit;
    }
    $student_id = $student['id'];
    $student_name = $student['name'];

    // 4. 防呆機制：檢查今天是否已經簽到過
    // CURDATE() 會取得今天的日期 (例如 2026-03-05)，用來跟資料庫的時間比對
    $stmt = $pdo->prepare("
        SELECT id FROM barcode_checkin_log 
        WHERE student_id = :student_id 
          AND term_id = :term_id 
          AND DATE(scan_time) = CURDATE()
    ");
    $stmt->execute([
        ':student_id' => $student_id,
        ':term_id' => $term_id
    ]);
    
    if ($stmt->fetch()) {
        // 如果有撈到資料，代表今天已經「嗶」過了
        echo json_encode([
            'status' => 'warning', 
            'message' => '重複報到：' . $student_name . ' 同學今天已經簽到過囉！'
        ]);
        exit;
    }

    // 5. 寫入簽到紀錄 (is_manual=0 代表是掃碼報到)
    $stmt = $pdo->prepare("
        INSERT INTO barcode_checkin_log (student_id, term_id, scan_time, is_manual) 
        VALUES (:student_id, :term_id, NOW(), 0)
    ");
    $stmt->execute([
        ':student_id' => $student_id,
        ':term_id' => $term_id
    ]);

    // 6. 回傳「報到成功」的訊息給前端網頁
    echo json_encode([
        'status' => 'success', 
        'message' => '報到成功', 
        'name' => $student_name
    ]);

} catch (PDOException $e) {
    // 如果資料庫發生錯誤，回傳錯誤訊息
    echo json_encode(['status' => 'error', 'message' => '系統錯誤：' . $e->getMessage()]);
}
?>
<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'config.php';

try {
    // 查詢所有班級，並依照 ID 排序
    $stmt = $pdo->query("SELECT id, class_name FROM classes ORDER BY id ASC");
    $classes = $stmt->fetchAll();

    // 回傳 JSON 格式的成功狀態與資料
    echo json_encode([
        'status' => 'success',
        'data' => $classes
    ]);

} catch (PDOException $e) {
    // 錯誤處理
    echo json_encode([
        'status' => 'error',
        'message' => '無法讀取班級資料：' . $e->getMessage()
    ]);
}
?>
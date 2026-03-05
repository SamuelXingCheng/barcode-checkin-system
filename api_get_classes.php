<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once 'config.php';

try {
    // 若為各班管理員，只撈取自己所屬的班級
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'class' && isset($_SESSION['class_id'])) {
        $stmt = $pdo->prepare("SELECT id, class_name FROM classes WHERE id = :class_id");
        $stmt->execute([':class_id' => $_SESSION['class_id']]);
    } else {
        // 總管理員 (super) 撈取全部
        $stmt = $pdo->query("SELECT id, class_name FROM classes ORDER BY id ASC");
    }
    
    $classes = $stmt->fetchAll();
    echo json_encode(['status' => 'success', 'data' => $classes]);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => '無法讀取班級資料：' . $e->getMessage()]);
}
?>
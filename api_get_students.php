<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'config.php';

$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$week_no = isset($_GET['week_no']) ? intval($_GET['week_no']) : 1;

if ($class_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => '無效的班級參數']);
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

    // 使用 week_no 進行 LEFT JOIN 比對
    $sql = "
        SELECT s.id AS student_id, s.student_no, s.name, IF(log.id IS NOT NULL, 1, 0) AS is_attended, log.scan_time, log.is_manual
        FROM students s
        INNER JOIN student_term_class stc ON s.id = stc.student_id
        LEFT JOIN barcode_checkin_log log ON s.id = log.student_id AND log.term_id = stc.term_id AND log.week_no = :week_no
        WHERE stc.term_id = :term_id AND stc.class_id = :class_id
        ORDER BY s.student_no ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':term_id' => $term_id, ':class_id' => $class_id, ':week_no' => $week_no]);
    $students = $stmt->fetchAll();

    $total_count = count($students);
    $attend_count = 0;
    foreach ($students as $student) {
        if ($student['is_attended'] == 1) {
            $attend_count++;
        }
    }

    echo json_encode([
        'status' => 'success',
        'data' => [
            'students' => $students,
            'summary' => ['total' => $total_count, 'attended' => $attend_count]
        ]
    ]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => '資料庫讀取失敗：' . $e->getMessage()]);
}
?>
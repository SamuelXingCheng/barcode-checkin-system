<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['status' => 'error', 'message' => '請先登入']);
    exit;
}

require_once 'config.php';
$class_id = intval($_GET['class_id'] ?? 0);
$week_no = intval($_GET['week_no'] ?? 0);

if ($class_id <= 0 || $week_no <= 0) {
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

    // 撈取名單時，同時附上 is_leave, hw_practice, hw_prophesy
    $sql = "
        SELECT s.id as student_id, s.student_no, s.name,
               IF(log.id IS NOT NULL, 1, 0) as is_attended,
               COALESCE(log.is_late, 0) as is_late,
               COALESCE(log.is_leave, 0) as is_leave,
               COALESCE(log.hw_practice, 0) as hw_practice,
               COALESCE(log.hw_prophesy, 0) as hw_prophesy
        FROM students s
        INNER JOIN student_term_class stc ON s.id = stc.student_id
        LEFT JOIN barcode_checkin_log log ON s.id = log.student_id AND log.term_id = :term_id AND log.week_no = :week_no
        WHERE stc.class_id = :class_id AND stc.term_id = :term_id
        ORDER BY s.student_no ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':class_id' => $class_id, ':term_id' => $term_id, ':week_no' => $week_no]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $attended = 0;
    $leaves = 0;
    foreach ($students as $s) {
        if ($s['is_attended'] == 1) {
            if ($s['is_leave'] == 1) $leaves++;
            else $attended++;
        }
    }

    echo json_encode([
        'status' => 'success',
        'data' => [
            'summary' => ['total' => count($students), 'attended' => $attended, 'leaves' => $leaves],
            'students' => $students
        ]
    ]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => '資料庫錯誤：' . $e->getMessage()]);
}
?>
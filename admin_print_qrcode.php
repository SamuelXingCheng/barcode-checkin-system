<?php
session_start();

// 1. 檢查是否有登入
if (!isset($_SESSION['admin_id'])) {
    die("拒絕存取：請先登入系統。");
}
require_once 'config.php';

// 2. 判斷身分與所屬班級
$is_super = ($_SESSION['role'] === 'super');
$admin_class_id = $_SESSION['class_id'] ?? 0;

$term_id = 0;
$active_term_name = '';
$students = [];

try {
    $stmt = $pdo->query("SELECT id, term_name FROM terms WHERE is_active = 1 LIMIT 1");
    $active_term = $stmt->fetch();
    if ($active_term) {
        $term_id = $active_term['id'];
        $active_term_name = $active_term['term_name'];
        
        // 接收前端傳來的條件
        $filter_class_name = $_GET['class_name'] ?? '';
        $filter_search = $_GET['search'] ?? '';
        $filter_class_id = $_GET['class_id'] ?? 0;

        $sql = "
            SELECT s.student_no, s.name 
            FROM students s
            INNER JOIN student_term_class stc ON s.id = stc.student_id
            INNER JOIN classes c ON stc.class_id = c.id
            WHERE stc.term_id = :term_id
        ";
        $params = [':term_id' => $term_id];

        // 👉 最關鍵的防護：各班管理員強制鎖死自己的班級！
        if (!$is_super) {
            $sql .= " AND stc.class_id = :admin_class_id";
            $params[':admin_class_id'] = $admin_class_id;
        } 
        // 👉 總管理員才允許依照網址傳來的條件過濾
        else {
            if ($filter_class_name !== '') {
                $sql .= " AND c.class_name = :class_name";
                $params[':class_name'] = $filter_class_name;
            } elseif ($filter_class_id > 0) {
                $sql .= " AND c.id = :class_id";
                $params[':class_id'] = $filter_class_id;
            }
        }

        // 姓名或學號搜尋條件 (所有人皆適用)
        if ($filter_search !== '') {
            $sql .= " AND (s.name LIKE :search OR s.student_no LIKE :search)";
            $params[':search'] = '%' . $filter_search . '%';
        }

        $sql .= " ORDER BY s.student_no ASC";
        
        $stmtStudents = $pdo->prepare($sql);
        $stmtStudents->execute($params);
        $students = $stmtStudents->fetchAll();
        
        // 若查無資料，顯示提示
        if (count($students) === 0) {
            die("<div style='text-align:center; padding:50px; font-family:sans-serif;'><h3>查無符合條件的學生名單，無法列印。</h3><button onclick='window.close()'>關閉視窗</button></div>");
        }
    } else {
        die("系統尚未啟用任何期別。");
    }
} catch (PDOException $e) {
    die("資料庫錯誤：" . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>高密度 QR Code 貼紙列印 | <?php echo htmlspecialchars($active_term_name); ?></title>
    <style>
        body { font-family: "微軟正黑體", "Segoe UI", sans-serif; margin: 0; padding: 0; background-color: #e9ecef; }
        .no-print { text-align: center; padding: 20px; background-color: #212529; color: white; margin-bottom: 20px; }
        .no-print button { padding: 10px 20px; font-size: 16px; cursor: pointer; background-color: #0d6efd; color: white; border: none; border-radius: 4px; }
        
        /* A4 網格設定 */
        .a4-page {
            width: 210mm; min-height: 297mm; padding: 5mm; margin: 0 auto 20px auto;
            background: white; box-shadow: 0 4px 12px rgba(0,0,0,0.15); box-sizing: border-box;
            display: grid; grid-template-columns: repeat(5, 1fr); grid-auto-rows: 40.5mm; gap: 0;
            border-top: 1px solid #000; border-left: 1px solid #000;
        }
        .card {
            border-right: 1px solid #000; border-bottom: 1px solid #000; box-sizing: border-box;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            padding: 2mm; background-color: #fff; overflow: hidden;
        }
        .card-qr { width: 32mm; height: 32mm; object-fit: contain; margin-bottom: 1mm; }
        .card-text { font-size: 11px; font-weight: bold; color: #000; letter-spacing: 0.5px; white-space: nowrap; }
        
        @media print {
            body { background-color: white; }
            .no-print { display: none !important; }
            .a4-page { margin: 0; box-shadow: none; page-break-after: always; }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <h2>高密度 QR Code 貼紙列印 (5x7)</h2>
        <p>為確保格線對齊，列印時請將「邊界」設定為「無」或「自訂(皆為0)」，並將「縮放比例」設為「100%」。</p>
        <button onclick="window.print()">列印 A4</button>
        <button onclick="window.close()" style="background-color: #6c757d; margin-left: 10px;">關閉視窗</button>
    </div>

    <?php
    $cards_per_page = 35; 
    $total_students = count($students);
    $pages = ceil($total_students / $cards_per_page);

    for ($page = 0; $page < $pages; $page++) {
        echo '<div class="a4-page">';
        for ($i = 0; $i < $cards_per_page; $i++) {
            $index = ($page * $cards_per_page) + $i;
            if ($index < $total_students) {
                $student = $students[$index];
                $qr_data = urlencode($student['student_no']);
                $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data={$qr_data}";
                
                echo '<div class="card">';
                echo '  <img src="' . $qr_url . '" class="card-qr" alt="QR Code">';
                echo '  <div class="card-text">' . htmlspecialchars($student['student_no']) . ' ' . htmlspecialchars($student['name']) . '</div>';
                echo '</div>';
            } else {
                echo '<div class="card"></div>';
            }
        }
        echo '</div>';
    }
    ?>
</body>
</html>
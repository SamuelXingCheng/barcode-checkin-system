<?php
session_start();
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'super') {
    die("拒絕存取：權限不足。");
}
require_once 'config.php';

$term_id = 0;
$active_term_name = '';
$students = [];

try {
    $stmt = $pdo->query("SELECT id, term_name FROM terms WHERE is_active = 1 LIMIT 1");
    $active_term = $stmt->fetch();
    
    if ($active_term) {
        $term_id = $active_term['id'];
        $active_term_name = $active_term['term_name'];
        
        // 接收前端傳來的篩選條件
        $filter_class = $_GET['class_name'] ?? '';
        $filter_search = $_GET['search'] ?? '';

        // 建立基礎 SQL 語法與參數陣列
        $sql = "
            SELECT s.student_no, s.name 
            FROM students s
            INNER JOIN student_term_class stc ON s.id = stc.student_id
            INNER JOIN classes c ON stc.class_id = c.id
            WHERE stc.term_id = :term_id
        ";
        $params = [':term_id' => $term_id];

        // 條件一：若有選擇班級，加入班級過濾條件
        if ($filter_class !== '') {
            $sql .= " AND c.class_name = :class_name";
            $params[':class_name'] = $filter_class;
        }

        // 條件二：若有輸入搜尋關鍵字，加入姓名或學號的模糊比對
        if ($filter_search !== '') {
            $sql .= " AND (s.name LIKE :search OR s.student_no LIKE :search)";
            // SQL 中的 LIKE 必須搭配 % 符號來代表模糊搜尋
            $params[':search'] = '%' . $filter_search . '%';
        }

        $sql .= " ORDER BY s.student_no ASC";

        $stmtStudents = $pdo->prepare($sql);
        $stmtStudents->execute($params);
        $students = $stmtStudents->fetchAll();
        
        // 如果篩選後沒有任何名單，中斷程式並提示使用者
        if (count($students) === 0) {
            die("<div style='text-align:center; padding:50px; font-family:sans-serif;'><h3>查無符合條件的學生名單，無法列印。</h3><button onclick='window.close()'>關閉視窗</button></div>");
        }
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
        
        /* A4 紙張配置 (210mm x 297mm) */
        .a4-page {
            width: 210mm;
            min-height: 297mm;
            padding: 5mm; /* 四周保留 5mm 邊距避免印表機裁切 */
            margin: 0 auto 20px auto;
            background: white;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            box-sizing: border-box;
            
            /* 5 欄 7 列網格系統 */
            display: grid;
            grid-template-columns: repeat(5, 1fr); 
            grid-auto-rows: 40.5mm; /* (297 - 10) / 7 約等於 41mm */
            gap: 0; /* 緊密排列 */
            
            /* 外部邊框 */
            border-top: 1px solid #000;
            border-left: 1px solid #000;
        }

        /* 單張貼紙設計 */
        .card {
            border-right: 1px solid #000;
            border-bottom: 1px solid #000;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2mm;
            background-color: #fff;
            overflow: hidden;
        }
        
        /* QR Code 圖片尺寸調整 */
        .card-qr { 
            width: 32mm; 
            height: 32mm; 
            object-fit: contain;
            margin-bottom: 1mm;
        }

        /* 底部文字排版 */
        .card-text { 
            font-size: 11px; 
            font-weight: bold; 
            color: #000; 
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        /* 列印時的覆蓋設定 */
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
    $cards_per_page = 35; // 一頁 35 張 (5x7)
    $total_students = count($students);
    $pages = ceil($total_students / $cards_per_page);

    for ($page = 0; $page < $pages; $page++) {
        echo '<div class="a4-page">';
        for ($i = 0; $i < $cards_per_page; $i++) {
            $index = ($page * $cards_per_page) + $i;
            if ($index < $total_students) {
                $student = $students[$index];
                $qr_data = urlencode($student['student_no']);
                // 為了加快大量載入，維持適當的解析度
                $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data={$qr_data}";
                
                echo '<div class="card">';
                echo '  <img src="' . $qr_url . '" class="card-qr" alt="QR Code">';
                echo '  <div class="card-text">' . htmlspecialchars($student['student_no']) . ' ' . htmlspecialchars($student['name']) . '</div>';
                echo '</div>';
            } else {
                // 補齊最後一頁的空白格子以維持網格形狀
                echo '<div class="card"></div>';
            }
        }
        echo '</div>';
    }
    ?>

</body>
</html>
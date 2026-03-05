<?php
session_start();
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'super') {
    header("Location: login.php");
    exit;
}
require_once 'config.php';

$message = '';
$message_type = '';

try {
    // 取得當前啟用期別
    $stmt = $pdo->query("SELECT id FROM terms WHERE is_active = 1 LIMIT 1");
    $active_term = $stmt->fetch();
    if (!$active_term) {
        throw new Exception("系統尚未設定開放的期別，無法匯入學生。");
    }
    $term_id = $active_term['id'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
        $file = $_FILES['csv_file'];
        
        if ($file['error'] === UPLOAD_ERR_OK) {
            $tmpName = $file['tmp_name'];
            $handle = fopen($tmpName, "r");
            
            if ($handle !== FALSE) {
                // 讀取第一行標題並忽略 (處理 UTF-8 BOM)
                $header = fgetcsv($handle);
                if (isset($header[0])) {
                    $header[0] = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $header[0]);
                }

                // 👉 啟動資料庫交易 (確保資料一致性)
                $pdo->beginTransaction();
                
                $imported_count = 0;
                $skipped_count = 0;

                // 取得目前最大學號數值，做為自動配發的基準
                $stmtMax = $pdo->query("SELECT MAX(CAST(SUBSTRING(student_no, 2) AS UNSIGNED)) FROM students");
                $max_num = $stmtMax->fetchColumn();
                $next_num = $max_num ? ($max_num + 1) : 1001;

                // 快取已存在的班級，避免頻繁查詢
                $classes_cache = [];
                $stmtClasses = $pdo->query("SELECT id, class_name FROM classes");
                while ($row = $stmtClasses->fetch()) {
                    $classes_cache[$row['class_name']] = $row['id'];
                }

                // 準備查重用的 SQL 語句
                $stmtCheckDup = $pdo->prepare("
                    SELECT s.id 
                    FROM students s
                    INNER JOIN student_term_class stc ON s.id = stc.student_id
                    WHERE s.name = :name AND stc.class_id = :class_id AND stc.term_id = :term_id
                    LIMIT 1
                ");

                // 逐行讀取 CSV 內容
                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    $name = trim($data[0] ?? '');
                    $class_name = trim($data[1] ?? '');

                    if (empty($name) || empty($class_name)) continue;

                    // 處理班級 ID (若不存在則自動新增)
                    if (!isset($classes_cache[$class_name])) {
                        $stmtInsertClass = $pdo->prepare("INSERT INTO classes (class_name) VALUES (:class_name)");
                        $stmtInsertClass->execute([':class_name' => $class_name]);
                        $class_id = $pdo->lastInsertId();
                        $classes_cache[$class_name] = $class_id;
                    } else {
                        $class_id = $classes_cache[$class_name];
                    }

                    // 檢查是否已存在同名且同班的學生
                    $stmtCheckDup->execute([
                        ':name' => $name,
                        ':class_id' => $class_id,
                        ':term_id' => $term_id
                    ]);
                    
                    if ($stmtCheckDup->fetch()) {
                        // 發現重複，略過此筆資料
                        $skipped_count++;
                        continue; 
                    }

                    // 產生新學號
                    $new_student_no = 'S' . $next_num;
                    $next_num++;

                    // 寫入學生主檔
                    $stmtInsertStudent = $pdo->prepare("INSERT INTO students (student_no, name) VALUES (:student_no, :name)");
                    $stmtInsertStudent->execute([':student_no' => $new_student_no, ':name' => $name]);
                    $student_id = $pdo->lastInsertId();

                    // 寫入分班紀錄
                    $stmtInsertTermClass = $pdo->prepare("INSERT INTO student_term_class (student_id, term_id, class_id) VALUES (:student_id, :term_id, :class_id)");
                    $stmtInsertTermClass->execute([':student_id' => $student_id, ':term_id' => $term_id, ':class_id' => $class_id]);

                    $imported_count++;
                }
                
                fclose($handle);
                // 👉 提交交易
                $pdo->commit();
                
                $message = "處理完成！成功匯入 {$imported_count} 筆新資料，並自動略過 {$skipped_count} 筆已存在的重複名單。";
                $message_type = "success";
            } else {
                throw new Exception("無法讀取上傳的檔案。");
            }
        } else {
            throw new Exception("檔案上傳失敗，請確認檔案格式是否正確。");
        }
    }
} catch (Exception $e) {
    // 發生錯誤時還原資料庫狀態
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $message = $e->getMessage();
    $message_type = "danger";
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>批次匯入名單 | 系統後台</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; font-family: "Segoe UI", sans-serif; }
        .sidebar { min-height: 100vh; background-color: #343a40; color: white; padding-top: 20px; }
        .sidebar a { color: #adb5bd; text-decoration: none; display: block; padding: 10px 20px; margin-bottom: 5px; border-radius: 4px; }
        .sidebar a:hover, .sidebar a.active { background-color: #495057; color: white; }
    </style>
</head>
<body>
<div class="container-fluid p-0">
    <div class="row g-0">
        <div class="col-md-2 sidebar px-3">
            <h5 class="px-2 mb-4">報到系統後台</h5>
            <a href="admin.php">今日出勤總覽</a>
            <a href="admin_students.php">學生名單管理</a>
            <a href="admin_import.php" class="active">批次匯入名單</a>
            <a href="admin_print_qrcode.php" target="_blank">列印 QR Code 貼紙</a>
            <hr class="text-secondary">
            <a href="checkin.php" class="text-info">返回報到櫃台</a>
            <a href="logout.php" class="text-danger">登出系統</a>
        </div>
        <div class="col-md-10 p-4">
            <h2>批次匯入學生名單 (CSV)</h2>
            <p class="text-muted">系統將會自動讀取名單，產生連號學號，並分發至對應班級。</p>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="card mt-4" style="max-width: 600px;">
                <div class="card-body">
                    <form method="POST" action="admin_import.php" enctype="multipart/form-data">
                        <div class="mb-4">
                            <label for="csv_file" class="form-label fw-bold">請選擇 CSV 檔案</label>
                            <input class="form-control" type="file" id="csv_file" name="csv_file" accept=".csv" required>
                            <div class="form-text mt-2">
                                <strong>格式要求：</strong>第一列必須是標題（不匯入），第一欄為「姓名」，第二欄為「班別」。
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">開始匯入並產生學號</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
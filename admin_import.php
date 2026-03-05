<?php
session_start();
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'super') {
    header("Location: login.php");
    exit;
}
require_once 'config.php';

$message = '';
$message_type = '';

// 取消匯入，清除暫存
if (isset($_POST['cancel_import'])) {
    unset($_SESSION['pending_import']);
    header("Location: admin_import.php");
    exit;
}

try {
    $stmt = $pdo->query("SELECT id FROM terms WHERE is_active = 1 LIMIT 1");
    $active_term = $stmt->fetch();
    if (!$active_term) {
        throw new Exception("系統尚未設定開放的期別，無法匯入資料。");
    }
    $term_id = $active_term['id'];

    // ==========================================
    // 階段二：使用者確認後，執行最終匯入
    // ==========================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_import'])) {
        if (!isset($_SESSION['pending_import'])) {
            throw new Exception("匯入工作階段已過期，請重新上傳檔案。");
        }
        
        $pending = $_SESSION['pending_import'];
        $safe_rows = $pending['safe_rows'];
        $conflict_rows = $pending['conflict_rows'];
        $actions = $_POST['conflict_action'] ?? [];

        $pdo->beginTransaction();

        $stmtMax = $pdo->query("SELECT MAX(CAST(SUBSTRING(student_no, 2) AS UNSIGNED)) FROM students");
        $max_num = $stmtMax->fetchColumn();
        $next_num = $max_num ? ($max_num + 1) : 1001;

        $classes_cache = [];
        $stmtClasses = $pdo->query("SELECT id, class_name FROM classes");
        while ($row = $stmtClasses->fetch()) {
            $classes_cache[$row['class_name']] = $row['id'];
        }

        $getClassId = function($class_name) use (&$classes_cache, &$pdo) {
            if (!isset($classes_cache[$class_name])) {
                $stmt = $pdo->prepare("INSERT INTO classes (class_name) VALUES (:class_name)");
                $stmt->execute([':class_name' => $class_name]);
                $id = $pdo->lastInsertId();
                $classes_cache[$class_name] = $id;
                return $id;
            }
            return $classes_cache[$class_name];
        };

        $stmtInsertStudent = $pdo->prepare("INSERT INTO students (student_no, name) VALUES (:student_no, :name)");
        $stmtInsertTermClass = $pdo->prepare("INSERT INTO student_term_class (student_id, term_id, class_id) VALUES (:student_id, :term_id, :class_id)");

        $new_count = 0;
        $reused_count = 0;
        $skipped_count = 0;

        // 處理沒有衝突的安全名單
        foreach ($safe_rows as $row) {
            $class_id = $getClassId($row['class_name']);
            $new_student_no = 'S' . $next_num++;
            $stmtInsertStudent->execute([':student_no' => $new_student_no, ':name' => $row['name']]);
            $student_id = $pdo->lastInsertId();
            $stmtInsertTermClass->execute([':student_id' => $student_id, ':term_id' => $term_id, ':class_id' => $class_id]);
            $new_count++;
        }

        // 處理有衝突的名單 (依據使用者的選擇)
        foreach ($conflict_rows as $idx => $row) {
            $action = $actions[$idx] ?? 'skip';
            $class_id = $getClassId($row['class_name']);

            if ($action === 'new') {
                // 建立為新生
                $new_student_no = 'S' . $next_num++;
                $stmtInsertStudent->execute([':student_no' => $new_student_no, ':name' => $row['name']]);
                $student_id = $pdo->lastInsertId();
                $stmtInsertTermClass->execute([':student_id' => $student_id, ':term_id' => $term_id, ':class_id' => $class_id]);
                $new_count++;
            } elseif (strpos($action, 'reuse_') === 0) {
                // 沿用舊生
                $student_id = (int) str_replace('reuse_', '', $action);
                
                // 檢查是否已在該班，避免重複加入
                $stmtCheck = $pdo->prepare("SELECT id FROM student_term_class WHERE student_id = ? AND term_id = ? AND class_id = ?");
                $stmtCheck->execute([$student_id, $term_id, $class_id]);
                
                if (!$stmtCheck->fetch()) {
                    $stmtInsertTermClass->execute([':student_id' => $student_id, ':term_id' => $term_id, ':class_id' => $class_id]);
                    $reused_count++;
                } else {
                    $skipped_count++;
                }
            } else {
                $skipped_count++;
            }
        }

        $pdo->commit();
        unset($_SESSION['pending_import']); // 清除暫存
        $message = "處理完成！成功新增 {$new_count} 名新學員，沿用舊生身分 {$reused_count} 名，略過 {$skipped_count} 筆。";
        $message_type = "success";
    }

    // ==========================================
    // 階段一：上傳 CSV 並進行衝突分析
    // ==========================================
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
        $file = $_FILES['csv_file'];
        if ($file['error'] === UPLOAD_ERR_OK) {
            $tmpName = $file['tmp_name'];
            $handle = fopen($tmpName, "r");
            if ($handle !== FALSE) {
                $header = fgetcsv($handle);
                if (isset($header[0])) {
                    $header[0] = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $header[0]);
                }

                $csv_data = [];
                $name_counts = [];
                
                // 讀取檔案
                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    $name = trim($data[0] ?? '');
                    $class_name = trim($data[1] ?? '');
                    if (empty($name) || empty($class_name)) continue;
                    
                    $csv_data[] = ['name' => $name, 'class_name' => $class_name];
                    $name_counts[$name] = ($name_counts[$name] ?? 0) + 1;
                }
                fclose($handle);

                // 1. 防呆：檢查上傳的 CSV 內部是否有同名同姓
                $internal_dups = [];
                foreach ($name_counts as $name => $count) {
                    if ($count > 1) $internal_dups[] = $name;
                }
                if (!empty($internal_dups)) {
                    $dup_str = implode(', ', $internal_dups);
                    throw new Exception("上傳終止：CSV 檔案本身包含重複的姓名（{$dup_str}）。請先在 Excel 中加上代號（例如：{$internal_dups[0]}A）以示區別後，再重新上傳。");
                }

                // 2. 分析：將名單與資料庫進行比對
                $safe_rows = [];
                $conflict_rows = [];
                $stmtFindStudent = $pdo->prepare("SELECT id, student_no, name FROM students WHERE name = :name");

                foreach ($csv_data as $idx => $row) {
                    $stmtFindStudent->execute([':name' => $row['name']]);
                    $matches = $stmtFindStudent->fetchAll();
                    
                    if (count($matches) > 0) {
                        // 發現資料庫已有同名者
                        $row['db_matches'] = $matches;
                        $conflict_rows[$idx] = $row;
                    } else {
                        // 全新名單
                        $safe_rows[$idx] = $row;
                    }
                }

                // 將分析結果存入 Session，準備進入階段二
                $_SESSION['pending_import'] = [
                    'safe_rows' => $safe_rows,
                    'conflict_rows' => $conflict_rows
                ];
                
            } else {
                throw new Exception("無法讀取上傳的檔案。");
            }
        } else {
            throw new Exception("檔案上傳失敗。");
        }
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $message = $e->getMessage();
    $message_type = "danger";
}

$pending = $_SESSION['pending_import'] ?? null;
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>智慧批次匯入 | 系統後台</title>
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
            <h5 class="px-2 mb-4 text-light">系統後台管理</h5>
            <a href="admin.php">出席數據總覽</a>
            <a href="admin_students.php">學生名單管理</a>
            <a href="admin_import.php" class="active">智慧批次匯入</a>
            <a href="admin_print_qrcode.php" target="_blank">列印全班標籤</a>
            <hr class="text-secondary">
            <a href="checkin.php" class="text-info">返回報到系統</a>
            <a href="logout.php" class="text-danger">登出系統</a>
        </div>
        <div class="col-md-10 p-4">
            <h2>智慧批次匯入系統</h2>
            
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show mt-3">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($pending): ?>
                <?php 
                    $safe_count = count($pending['safe_rows']);
                    $conflict_count = count($pending['conflict_rows']);
                ?>
                
                <div class="alert alert-info mt-4">
                    <strong>掃描完成！</strong> 即將自動為 <?php echo $safe_count; ?> 筆全新名單配發學號。
                    <?php if ($conflict_count > 0): ?>
                        <br><strong class="text-danger">注意：發現 <?php echo $conflict_count; ?> 筆同名同姓的資料，請於下方確認處置方式：</strong>
                    <?php endif; ?>
                </div>

                <form method="POST" action="admin_import.php" class="mt-4">
                    <?php if ($conflict_count > 0): ?>
                        <div class="card shadow-sm border-0 mb-4">
                            <table class="table mb-0 align-middle">
                                <thead class="table-dark">
                                    <tr>
                                        <th>CSV 匯入姓名</th>
                                        <th>指定分配班級</th>
                                        <th>系統已存在的同名舊生</th>
                                        <th>您的決定</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pending['conflict_rows'] as $idx => $row): ?>
                                        <tr>
                                            <td class="fw-bold"><?php echo htmlspecialchars($row['name']); ?></td>
                                            <td><?php echo htmlspecialchars($row['class_name']); ?></td>
                                            <td>
                                                <?php foreach($row['db_matches'] as $match): ?>
                                                    <span class="badge bg-secondary">學號: <?php echo $match['student_no']; ?></span>
                                                <?php endforeach; ?>
                                            </td>
                                            <td>
                                                <select name="conflict_action[<?php echo $idx; ?>]" class="form-select border-primary">
                                                    <?php foreach($row['db_matches'] as $match): ?>
                                                        <option value="reuse_<?php echo $match['id']; ?>">沿用此舊生身分 (<?php echo $match['student_no']; ?>)</option>
                                                    <?php endforeach; ?>
                                                    <option value="new">建立為同名新生 (配發新學號)</option>
                                                    <option value="skip">略過此筆不匯入</option>
                                                </select>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    
                    <button type="submit" name="confirm_import" class="btn btn-primary btn-lg px-4">確認無誤，執行匯入</button>
                    <button type="submit" name="cancel_import" class="btn btn-outline-secondary btn-lg ms-2">取消並重新上傳</button>
                </form>

            <?php else: ?>
                <p class="text-muted mt-2">系統將自動分析名單。若發現同名人員，將引導您進行人工確認以防止錯亂。</p>
                <div class="card mt-4 shadow-sm border-0" style="max-width: 600px;">
                    <div class="card-body p-4">
                        <form method="POST" action="admin_import.php" enctype="multipart/form-data">
                            <div class="mb-4">
                                <label for="csv_file" class="form-label fw-bold">選擇 CSV 檔案</label>
                                <input class="form-control" type="file" id="csv_file" name="csv_file" accept=".csv" required>
                                <div class="form-text mt-2">
                                    <strong>格式要求：</strong>第一列必須為標題，第一欄為「姓名」，第二欄為「班別」。
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">掃描並分析名單</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
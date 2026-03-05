<?php
session_start();

// 權限驗證：必須登入且角色為 super (總管理員)
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'super') {
    // 若無權限，強制導向報到櫃台或登入頁
    header("Location: checkin.php");
    exit;
}

require_once 'config.php';

$active_term_name = '無啟用期別';
$term_id = 0;
$today_logs = [];
$total_students = 0;
$today_attended = 0;

try {
    // 1. 取得當前運行中的期別
    $stmt = $pdo->query("SELECT id, term_name FROM terms WHERE is_active = 1 LIMIT 1");
    $active_term = $stmt->fetch();
    
    if ($active_term) {
        $term_id = $active_term['id'];
        $active_term_name = $active_term['term_name'];

        // 2. 取得該期別的總學生數
        $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM student_term_class WHERE term_id = :term_id");
        $stmtTotal->execute([':term_id' => $term_id]);
        $total_students = $stmtTotal->fetchColumn();

        // 3. 取得今日已報到人數
        $stmtAttended = $pdo->prepare("SELECT COUNT(DISTINCT student_id) FROM barcode_checkin_log WHERE term_id = :term_id AND DATE(scan_time) = CURDATE()");
        $stmtAttended->execute([':term_id' => $term_id]);
        $today_attended = $stmtAttended->fetchColumn();

        // 4. 取得今日報到詳細紀錄清單
        $sqlLogs = "
            SELECT 
                log.scan_time,
                c.class_name,
                s.student_no,
                s.name,
                s.meetinghall,
                log.is_manual
            FROM barcode_checkin_log log
            INNER JOIN students s ON log.student_id = s.id
            INNER JOIN student_term_class stc ON s.id = stc.student_id AND log.term_id = stc.term_id
            INNER JOIN classes c ON stc.class_id = c.id
            WHERE log.term_id = :term_id AND DATE(log.scan_time) = CURDATE()
            ORDER BY log.scan_time DESC
        ";
        $stmtLogs = $pdo->prepare($sqlLogs);
        $stmtLogs->execute([':term_id' => $term_id]);
        $today_logs = $stmtLogs->fetchAll();
    }
} catch (PDOException $e) {
    $error_msg = "資料庫讀取失敗：" . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>系統後台管理 | 班級報到系統</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; }
        .sidebar { min-height: 100vh; background-color: #343a40; color: white; padding-top: 20px; }
        .sidebar a { color: #adb5bd; text-decoration: none; display: block; padding: 10px 20px; margin-bottom: 5px; border-radius: 4px; }
        .sidebar a:hover, .sidebar a.active { background-color: #495057; color: white; }
        .content-area { padding: 30px; }
        .stat-card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border-left: 4px solid #0d6efd; }
        .stat-card.success { border-left-color: #198754; }
    </style>
</head>
<body>

<div class="container-fluid p-0">
    <div class="row g-0">
        <div class="col-md-2 sidebar px-3">
            <h5 class="px-2 mb-4">台中週中得勝班報到系統後台</h5>
            <a href="admin.php">今日出勤總覽</a>
            <a href="admin_students.php">學生名單管理</a>
            <a href="#">期別與班級設定 (建置中)</a>
            <a href="#">報表匯出 (建置中)</a>
            <hr class="text-secondary">
            <a href="checkin.php" class="text-info">返回報到櫃台</a>
            <a href="logout.php" class="text-danger">登出系統</a>
        </div>

        <div class="col-md-10 content-area">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>今日出勤總覽</h2>
                <span class="badge bg-primary fs-6">當前期別：<?php echo htmlspecialchars($active_term_name); ?></span>
            </div>

            <?php if (isset($error_msg)): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error_msg); ?></div>
            <?php endif; ?>

            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="stat-card">
                        <h6 class="text-muted mb-2">本期註冊總人數</h6>
                        <h3><?php echo $total_students; ?> 人</h3>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card success">
                        <h6 class="text-muted mb-2">今日已報到人數</h6>
                        <h3><?php echo $today_attended; ?> 人</h3>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card" style="border-left-color: #6c757d;">
                        <h6 class="text-muted mb-2">今日出席率</h6>
                        <h3>
                            <?php 
                                echo $total_students > 0 ? round(($today_attended / $total_students) * 100, 1) . '%' : '0%'; 
                            ?>
                        </h3>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0">今日即時報到紀錄</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>打卡時間</th>
                                    <th>班級</th>
                                    <th>學號</th>
                                    <th>姓名</th>
                                    <th>所屬會所</th>
                                    <th>報到方式</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($today_logs) > 0): ?>
                                    <?php foreach ($today_logs as $log): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($log['scan_time']); ?></td>
                                            <td><?php echo htmlspecialchars($log['class_name']); ?></td>
                                            <td><?php echo htmlspecialchars($log['student_no']); ?></td>
                                            <td><?php echo htmlspecialchars($log['name']); ?></td>
                                            <td><?php echo htmlspecialchars($log['meetinghall']); ?></td>
                                            <td>
                                                <?php if ($log['is_manual'] == 1): ?>
                                                    <span class="badge bg-warning text-dark">手動補簽</span>
                                                <?php else: ?>
                                                    <span class="badge bg-success">條碼掃描</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4 text-muted">今日尚無任何報到紀錄</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

</body>
</html>
<?php
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'config.php';

$is_super = ($_SESSION['role'] === 'super');
$admin_class_id = $_SESSION['class_id'] ?? 0;

$active_term_name = '無啟用期別';
$term_id = 0;
$logs = [];
$base_students = 0;
$expected_attendances = 0;
$attendance_rate = 0;

// 新增統計變數
$attended_count = 0;
$late_count = 0;
$ontime_count = 0;
$leave_count = 0;
$hw_prac_count = 0;
$hw_proph_count = 0;

$current_week = 1;
$total_weeks = 1;
$start_timestamp = 0;

try {
    $stmt = $pdo->query("SELECT id, term_name, start_date, total_weeks FROM terms WHERE is_active = 1 LIMIT 1");
    $active_term = $stmt->fetch();
    
    if ($active_term) {
        $term_id = $active_term['id'];
        $active_term_name = $active_term['term_name'];
        $total_weeks = intval($active_term['total_weeks']);

        if (!empty($active_term['start_date'])) {
            $start_timestamp = strtotime($active_term['start_date']);
            $now_timestamp = time();
            if ($now_timestamp >= $start_timestamp) {
                $days_diff = floor(($now_timestamp - $start_timestamp) / 86400);
                $current_week = floor($days_diff / 7) + 1;
            }
        }
        if ($current_week > $total_weeks) $current_week = $total_weeks;
        if ($current_week < 1) $current_week = 1;

        $filter_class_id = $is_super ? (isset($_GET['class_id']) ? intval($_GET['class_id']) : 0) : $admin_class_id;
        $filter_week_no = isset($_GET['week_no']) ? intval($_GET['week_no']) : $current_week;

        $stmtClasses = $pdo->query("SELECT id, class_name FROM classes ORDER BY id ASC");
        $classes = $stmtClasses->fetchAll();

        // 1. 計算母體總人數
        $sqlTotal = "SELECT COUNT(*) FROM student_term_class WHERE term_id = :term_id";
        $paramsTotal = [':term_id' => $term_id];
        if ($filter_class_id > 0) {
            $sqlTotal .= " AND class_id = :class_id";
            $paramsTotal[':class_id'] = $filter_class_id;
        }
        $stmtTotal = $pdo->prepare($sqlTotal);
        $stmtTotal->execute($paramsTotal);
        $base_students = $stmtTotal->fetchColumn();

        // 2. 智慧計算所有統計指標 (過濾請假，並計算作業)
        $paramsStats = [':term_id' => $term_id];
        $sqlStats = "
            SELECT 
                SUM(CASE WHEN log.is_leave = 0 THEN 1 ELSE 0 END) as attended_count,
                SUM(CASE WHEN log.is_leave = 0 AND log.is_late = 1 THEN 1 ELSE 0 END) as late_count,
                SUM(CASE WHEN log.is_leave = 1 THEN 1 ELSE 0 END) as leave_count,
                SUM(log.hw_practice) as hw_prac_count,
                SUM(log.hw_prophesy) as hw_proph_count
            FROM barcode_checkin_log log
            INNER JOIN student_term_class stc ON log.student_id = stc.student_id AND log.term_id = stc.term_id
            WHERE log.term_id = :term_id
        ";
        
        if ($filter_class_id > 0) {
            $sqlStats .= " AND stc.class_id = :class_id";
            $paramsStats[':class_id'] = $filter_class_id;
        }
        if ($filter_week_no > 0) {
            $sqlStats .= " AND log.week_no = :week_no";
            $paramsStats[':week_no'] = $filter_week_no;
            $expected_attendances = $base_students;
        } else {
            $expected_attendances = $base_students * $current_week;
        }

        $stmtStats = $pdo->prepare($sqlStats);
        $stmtStats->execute($paramsStats);
        $stats = $stmtStats->fetch(PDO::FETCH_ASSOC);

        if ($stats) {
            $attended_count = intval($stats['attended_count']);
            $late_count = intval($stats['late_count']);
            $ontime_count = $attended_count - $late_count;
            $leave_count = intval($stats['leave_count']);
            $hw_prac_count = intval($stats['hw_prac_count']);
            $hw_proph_count = intval($stats['hw_proph_count']);
        }

        if ($expected_attendances > 0) {
            $attendance_rate = round(($attended_count / $expected_attendances) * 100, 1);
        }

        // 3. 取得詳細打卡紀錄清單
        $sqlLogs = "
            SELECT 
                log.scan_time, log.week_no, c.class_name, s.student_no, s.name, 
                log.is_manual, log.is_late, log.is_leave, log.hw_practice, log.hw_prophesy
            FROM barcode_checkin_log log
            INNER JOIN students s ON log.student_id = s.id
            INNER JOIN student_term_class stc ON s.id = stc.student_id AND log.term_id = stc.term_id
            INNER JOIN classes c ON stc.class_id = c.id
            WHERE log.term_id = :term_id
        ";
        $paramsLogs = [':term_id' => $term_id];
        
        if ($filter_class_id > 0) {
            $sqlLogs .= " AND stc.class_id = :class_id";
            $paramsLogs[':class_id'] = $filter_class_id;
        }
        if ($filter_week_no > 0) {
            $sqlLogs .= " AND log.week_no = :week_no";
            $paramsLogs[':week_no'] = $filter_week_no;
        }
        
        $sqlLogs .= " ORDER BY log.scan_time DESC";
        $stmtLogs = $pdo->prepare($sqlLogs);
        $stmtLogs->execute($paramsLogs);
        $logs = $stmtLogs->fetchAll();
    }
} catch (PDOException $e) {
    $error_msg = "資料庫讀取失敗：" . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>出勤數據總覽 | 系統後台</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; font-family: "Segoe UI", sans-serif; }
        .sidebar { min-height: 100vh; background-color: #343a40; color: white; padding-top: 20px; }
        .sidebar a { color: #adb5bd; text-decoration: none; display: block; padding: 10px 20px; margin-bottom: 5px; border-radius: 4px; }
        .sidebar a:hover, .sidebar a.active { background-color: #495057; color: white; }
        .content-area { padding: 30px; }
        .stat-card { background: white; padding: 15px 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border-left: 4px solid #0d6efd; height: 100%; }
        .stat-card.success { border-left-color: #198754; }
        .stat-card.warning { border-left-color: #ffc107; }
        .stat-card.danger { border-left-color: #dc3545; }
        .stat-card.info { border-left-color: #0dcaf0; }
    </style>
</head>
<body>

<div class="container-fluid p-0">
    <div class="row g-0">
        <div class="col-md-2 sidebar px-3">
            <h5 class="px-2 mb-4">報到系統後台</h5>
            <a href="admin.php" class="active">出勤數據總覽</a>
            <a href="admin_students.php">學生名單管理</a>
            <?php if ($is_super): ?>
                <a href="admin_import.php">批次匯入名單</a>
                <a href="admin_settings.php">期別與班級設定</a>
            <?php endif; ?>
            <hr class="text-secondary">
            <a href="checkin.php" class="text-info">返回報到櫃台</a>
            <a href="logout.php" class="text-danger">登出系統</a>
        </div>

        <div class="col-md-10 content-area">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>出勤數據總覽</h2>
                <span class="badge bg-secondary fs-6">當前期別：<?php echo htmlspecialchars($active_term_name); ?></span>
            </div>

            <div class="card shadow-sm border-0 mb-4 bg-light">
                <div class="card-body">
                    <form method="GET" action="admin.php" id="filterForm" class="row g-3 align-items-end">
                        
                        <?php if ($is_super): ?>
                        <div class="col-md-4">
                            <label class="form-label text-muted">檢視班級</label>
                            <select name="class_id" class="form-select" onchange="document.getElementById('filterForm').submit();">
                                <option value="0">顯示全部班級</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>" <?php echo ($filter_class_id == $class['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['class_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <div class="col-md-4">
                            <label class="form-label text-muted">檢視期間</label>
                            <select name="week_no" class="form-select" onchange="document.getElementById('filterForm').submit();">
                                <option value="0" <?php echo ($filter_week_no == 0) ? 'selected' : ''; ?>>累計全學期 (依目前進度)</option>
                                <?php for($i = 1; $i <= $total_weeks; $i++): ?>
                                    <?php 
                                        $date_str = "";
                                        if ($start_timestamp > 0) {
                                            $date_str = " (" . date("m/d", $start_timestamp + (($i - 1) * 7 * 86400)) . ")";
                                        }
                                        $label = ($i === $current_week) ? "第 {$i} 週{$date_str} 【本週】" : "第 {$i} 週{$date_str}";
                                    ?>
                                    <option value="<?php echo $i; ?>" <?php echo ($filter_week_no == $i) ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label d-none d-md-block">&nbsp;</label>
                            <button type="submit" formaction="admin_export.php" class="btn btn-success w-100 shadow-sm">
                                下載 Excel 總表 (CSV)
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <h6 class="text-muted mb-3 fw-bold">基本出勤統計</h6>
            <div class="row mb-4 g-3">
                <div class="col-md-3">
                    <div class="stat-card">
                        <h6 class="text-muted mb-2">應到總人次</h6>
                        <h3 class="mb-0"><?php echo $expected_attendances; ?></h3>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card success">
                        <h6 class="text-muted mb-2">實到總人次 (準時+已到)</h6>
                        <h3 class="mb-0 text-success"><?php echo $attended_count; ?></h3>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card danger">
                        <h6 class="text-muted mb-2">請假總人次</h6>
                        <h3 class="mb-0 text-danger"><?php echo $leave_count; ?></h3>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card info">
                        <h6 class="text-muted mb-2">綜合出席率</h6>
                        <h3 class="mb-0 text-info"><?php echo $attendance_rate; ?> %</h3>
                    </div>
                </div>
            </div>

            <h6 class="text-muted mb-3 fw-bold">學習與準時品質</h6>
            <div class="row mb-4 g-3">
                <div class="col-md-4">
                    <div class="stat-card success">
                        <h6 class="text-muted mb-2">準時人次</h6>
                        <h3 class="mb-0 text-success"><?php echo $ontime_count; ?></h3>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card bg-white border-primary border-start">
                        <h6 class="text-primary mb-2">操練表 繳交總數</h6>
                        <h3 class="mb-0"><?php echo $hw_prac_count; ?> <small class="fs-6 text-muted">份</small></h3>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card bg-white border-info border-start">
                        <h6 class="text-info mb-2 text-dark">申言稿 繳交總數</h6>
                        <h3 class="mb-0"><?php echo $hw_proph_count; ?> <small class="fs-6 text-muted">份</small></h3>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">打卡與作業紀錄明細</h5>
                    <small class="text-muted">共 <?php echo count($logs); ?> 筆紀錄</small>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>打卡時間</th>
                                    <th>週次</th>
                                    <th>班級</th>
                                    <th>學號</th>
                                    <th>姓名</th>
                                    <th>報到狀態</th>
                                    <th>作業繳交</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($logs) > 0): ?>
                                    <?php foreach ($logs as $log): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($log['scan_time']); ?></td>
                                            <td><span class="badge bg-secondary">第 <?php echo htmlspecialchars($log['week_no']); ?> 週</span></td>
                                            <td><?php echo htmlspecialchars($log['class_name']); ?></td>
                                            <td class="font-monospace"><?php echo htmlspecialchars($log['student_no']); ?></td>
                                            <td class="fw-bold"><?php echo htmlspecialchars($log['name']); ?></td>
                                            <td>
                                                <?php if ($log['is_leave'] == 1): ?>
                                                    <span class="badge bg-danger">請假</span>
                                                <?php elseif ($log['is_late'] == 1): ?>
                                                    <span class="badge bg-warning text-dark">已到</span>
                                                <?php else: ?>
                                                    <span class="badge bg-success">準時</span>
                                                <?php endif; ?>
                                                
                                                <?php if ($log['is_manual'] == 1 && $log['is_leave'] == 0): ?>
                                                    <span class="badge bg-light text-secondary border ms-1">補簽</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($log['hw_practice'] == 1): ?>
                                                    <span class="badge bg-primary">操練表</span>
                                                <?php endif; ?>
                                                <?php if ($log['hw_prophesy'] == 1): ?>
                                                    <span class="badge bg-info text-dark">申言稿</span>
                                                <?php endif; ?>
                                                <?php if ($log['hw_practice'] == 0 && $log['hw_prophesy'] == 0): ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4 text-muted">此篩選條件下尚無任何紀錄</td>
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
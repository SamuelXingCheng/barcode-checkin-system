<?php
session_start();

// 權限驗證
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'config.php';

$is_super = ($_SESSION['role'] === 'super');
$admin_class_id = $_SESSION['class_id'] ?? 0;

try {
    // 取得當前啟用期別
    $stmtTerm = $pdo->query("SELECT id, term_name, start_date, total_weeks FROM terms WHERE is_active = 1 LIMIT 1");
    $term = $stmtTerm->fetch();
    if (!$term) {
        die("系統尚未設定啟用的期別，無法匯出。");
    }
    
    $term_id = $term['id'];
    $term_name = $term['term_name'];
    $total_weeks = intval($term['total_weeks']);

    // 智慧動態週次推算
    $current_week = 1;
    if (!empty($term['start_date'])) {
        $start_timestamp = strtotime($term['start_date']);
        $now_timestamp = time();
        if ($now_timestamp >= $start_timestamp) {
            $days_diff = floor(($now_timestamp - $start_timestamp) / 86400);
            $current_week = floor($days_diff / 7) + 1;
        }
    }
    if ($current_week > $total_weeks) $current_week = $total_weeks;
    if ($current_week < 1) $current_week = 1;

    // 接收篩選參數
    $filter_class_id = $is_super ? (isset($_GET['class_id']) ? intval($_GET['class_id']) : 0) : $admin_class_id;

    // 👉 檔名設計：判斷目前是匯出全校還是單一班級
    $export_scope_name = "全校總表";
    if ($filter_class_id > 0) {
        $stmtClassName = $pdo->prepare("SELECT class_name FROM classes WHERE id = ?");
        $stmtClassName->execute([$filter_class_id]);
        $resClass = $stmtClassName->fetch();
        if ($resClass) {
            $export_scope_name = $resClass['class_name'];
        }
    }

    // 取得學生名單
    $sqlStudents = "
        SELECT s.id as student_id, s.student_no, s.name, c.class_name
        FROM students s
        INNER JOIN student_term_class stc ON s.id = stc.student_id
        INNER JOIN classes c ON stc.class_id = c.id
        WHERE stc.term_id = :term_id
    ";
    $params = [':term_id' => $term_id];
    if ($filter_class_id > 0) {
        $sqlStudents .= " AND stc.class_id = :class_id";
        $params[':class_id'] = $filter_class_id;
    }
    $sqlStudents .= " ORDER BY c.id ASC, s.student_no ASC";
    
    $stmtStudents = $pdo->prepare($sqlStudents);
    $stmtStudents->execute($params);
    $students = $stmtStudents->fetchAll(PDO::FETCH_ASSOC);

    // 取得打卡紀錄
    $sqlLogs = "SELECT student_id, week_no, is_late FROM barcode_checkin_log WHERE term_id = :term_id";
    $stmtLogs = $pdo->prepare($sqlLogs);
    $stmtLogs->execute([':term_id' => $term_id]);
    $logs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);

    // 整理成二維陣列
    $attendance_matrix = [];
    foreach ($logs as $log) {
        $status = ($log['is_late'] == 1) ? '已到' : '準時';
        $attendance_matrix[$log['student_id']][$log['week_no']] = $status;
    }

    // 計算整體與各班的指標
    $overall_total_students = count($students);
    $overall_expected = $overall_total_students * $current_week;
    $overall_attended = 0;
    $overall_ontime = 0;
    $overall_perfect_count = 0;
    
    $class_stats = [];

    foreach ($students as $student) {
        $c_name = $student['class_name'];
        if (!isset($class_stats[$c_name])) {
            $class_stats[$c_name] = [
                'total_students' => 0, 
                'attended' => 0, 
                'ontime' => 0,
                'perfect_count' => 0
            ];
        }
        $class_stats[$c_name]['total_students'] += 1;

        $student_attend_count = 0;
        $student_ontime_count = 0;

        for ($i = 1; $i <= $total_weeks; $i++) {
            if (isset($attendance_matrix[$student['student_id']][$i])) {
                $status = $attendance_matrix[$student['student_id']][$i];
                $overall_attended++;
                $class_stats[$c_name]['attended']++;
                $student_attend_count++;
                
                if ($status === '準時') {
                    $overall_ontime++;
                    $class_stats[$c_name]['ontime']++;
                    $student_ontime_count++;
                }
            }
        }
        
        if ($student_attend_count >= $current_week) {
            $overall_perfect_count++;
            $class_stats[$c_name]['perfect_count']++;
        }
    }

    // 👉 設定 CSV 檔案智慧檔名
    // 👉 設定 CSV 檔案智慧檔名
    $date_str = date('Ymd_Hi'); // 格式：YYYYMMDD_HHMM
    $filename = "[{$term_name}]_{$export_scope_name}_出勤統計_截至第{$current_week}週_{$date_str}.csv";
    $encoded_filename = rawurlencode($filename); // 解決中文檔名亂碼的關鍵
    
    header('Content-Type: text/csv; charset=utf-8');
    // 加上 filename*=UTF-8'' 讓所有瀏覽器都能完美解析中文檔名
    header('Content-Disposition: attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . $encoded_filename);
    
    // 輸出 UTF-8 BOM
    echo "\xEF\xBB\xBF";
    
    $output = fopen('php://output', 'w');
    
    // ======== 第一區塊：報表標題 ========
    fputcsv($output, ["【{$term_name}】{$export_scope_name} 出勤與準時大表", "匯出時間：" . date('Y-m-d H:i')]);
    fputcsv($output, ["(註：各項比率皆依據當前開課進度「第 {$current_week} 週」為基礎進行計算)"]);
    fputcsv($output, []); 
    
    // ======== 第二區塊：整體統計 ========
    $overall_attend_rate = ($overall_expected > 0) ? round(($overall_attended / $overall_expected) * 100, 1) . '%' : '0%';
    $overall_ontime_rate = ($overall_expected > 0) ? round(($overall_ontime / $overall_expected) * 100, 1) . '%' : '0%';
    
    fputcsv($output, ['【整體統計】']);
    fputcsv($output, ['總人數', '整體出席率', '整體準時率', '全勤人數', '目前應到總人次', '實到總人次', '準時總人次']);
    fputcsv($output, [$overall_total_students, $overall_attend_rate, $overall_ontime_rate, $overall_perfect_count, $overall_expected, $overall_attended, $overall_ontime]);
    fputcsv($output, []); 

    // ======== 第三區塊：各班統計 ========
    if (count($class_stats) > 0) {
        fputcsv($output, ['【各班統計明細】']);
        fputcsv($output, ['班級名稱', '班級總人數', '班級出席率', '班級準時率', '班級全勤人數', '目前應到人次', '實到人次', '準時人次']);
        foreach ($class_stats as $c_name => $c_stat) {
            $c_expected = $c_stat['total_students'] * $current_week;
            $c_attend_rate = ($c_expected > 0) ? round(($c_stat['attended'] / $c_expected) * 100, 1) . '%' : '0%';
            $c_ontime_rate = ($c_expected > 0) ? round(($c_stat['ontime'] / $c_expected) * 100, 1) . '%' : '0%';
            fputcsv($output, [$c_name, $c_stat['total_students'], $c_attend_rate, $c_ontime_rate, $c_stat['perfect_count'], $c_expected, $c_stat['attended'], $c_stat['ontime']]);
        }
        fputcsv($output, []); 
    }

    // ======== 第四區塊：學生出勤矩陣 ========
    fputcsv($output, ['【學生出勤打卡明細】']);
    $headers = ['班級', '學號', '姓名'];
    for ($i = 1; $i <= $total_weeks; $i++) {
        $headers[] = "第{$i}週";
    }
    $headers[] = '累計出席 (次)';
    $headers[] = '累計準時 (次)';
    $headers[] = '個人出席率';
    $headers[] = '個人準時率';
    $headers[] = '目前是否全勤';
    fputcsv($output, $headers);
    
    // 輸出學生明細資料
    foreach ($students as $student) {
        $row = [
            $student['class_name'],
            $student['student_no'],
            $student['name']
        ];
        
        $attend_count = 0;
        $ontime_count = 0;
        
        for ($i = 1; $i <= $total_weeks; $i++) {
            if (isset($attendance_matrix[$student['student_id']][$i])) {
                $status = $attendance_matrix[$student['student_id']][$i];
                $row[] = $status;
                $attend_count++;
                if ($status === '準時') {
                    $ontime_count++;
                }
            } else {
                $row[] = ''; // 未出席留空
            }
        }
        
        $attend_rate = ($current_week > 0) ? round(($attend_count / $current_week) * 100, 1) . '%' : '0%';
        $ontime_rate = ($current_week > 0) ? round(($ontime_count / $current_week) * 100, 1) . '%' : '0%';
        $is_perfect = ($attend_count >= $current_week) ? '★ 全勤' : '';

        $row[] = $attend_count;
        $row[] = $ontime_count;
        $row[] = $attend_rate;
        $row[] = $ontime_rate;
        $row[] = $is_perfect;
        
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit;

} catch (PDOException $e) {
    die("資料庫錯誤：" . $e->getMessage());
}
?>
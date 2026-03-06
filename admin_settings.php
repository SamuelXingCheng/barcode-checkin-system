<?php
session_start();

// 嚴格權限防護：只有 super (總管理員) 可以進入此頁面
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'super') {
    header("Location: login.php");
    exit;
}

require_once 'config.php';

$message = '';
$message_type = '';

// 處理所有的表單送出動作
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'set_active_term') {
            // 切換啟用期別
            $term_id = intval($_POST['term_id']);
            $pdo->beginTransaction();
            $pdo->query("UPDATE terms SET is_active = 0"); 
            $stmt = $pdo->prepare("UPDATE terms SET is_active = 1 WHERE id = ?");
            $stmt->execute([$term_id]);
            $pdo->commit();
            $message = "已成功切換系統當前啟用期別！";
            $message_type = "success";
        } 
        elseif ($action === 'add_term' || $action === 'edit_term') {
            // 新增或編輯期別
            $term_id = intval($_POST['term_id'] ?? 0);
            $term_name = trim($_POST['term_name']);
            $start_date = $_POST['start_date'];
            $total_weeks = intval($_POST['total_weeks']);
            $copy_previous = isset($_POST['copy_previous']) ? 1 : 0;

            if (!empty($term_name) && $total_weeks > 0) {
                if ($action === 'add_term') {
                    $pdo->beginTransaction();
                    
                    // 抓取目前最新的期別 ID (作為舊生資料來源)
                    $stmtLast = $pdo->query("SELECT id FROM terms ORDER BY id DESC LIMIT 1");
                    $last_term = $stmtLast->fetch();
                    
                    $stmt = $pdo->prepare("INSERT INTO terms (term_name, start_date, total_weeks, is_active) VALUES (?, ?, ?, 0)");
                    $stmt->execute([$term_name, $start_date, $total_weeks]);
                    $new_term_id = $pdo->lastInsertId();
                    
                    $copy_msg = "";
                    // 如果有勾選複製，且存在上一期資料，則執行無縫轉移
                    if ($copy_previous && $last_term) {
                        $last_term_id = $last_term['id'];
                        $stmtCopy = $pdo->prepare("
                            INSERT INTO student_term_class (student_id, term_id, class_id) 
                            SELECT student_id, ?, class_id 
                            FROM student_term_class 
                            WHERE term_id = ?
                        ");
                        $stmtCopy->execute([$new_term_id, $last_term_id]);
                        $copy_count = $stmtCopy->rowCount();
                        $copy_msg = "，並成功為您無縫轉移了 {$copy_count} 位舊生資料";
                    }

                    $pdo->commit();
                    $message = "期別新增成功{$copy_msg}！若要啟用請點擊列表中的「設為當前」。";
                } else {
                    $stmt = $pdo->prepare("UPDATE terms SET term_name = ?, start_date = ?, total_weeks = ? WHERE id = ?");
                    $stmt->execute([$term_name, $start_date, $total_weeks, $term_id]);
                    $message = "期別設定更新成功！";
                }
                $message_type = "success";
            } else {
                $message = "期別名稱與總週數為必填欄位。";
                $message_type = "danger";
            }
        }
        elseif ($action === 'delete_term') {
            // 👉 刪除期別功能
            $term_id = intval($_POST['term_id'] ?? 0);
            if ($term_id > 0) {
                // 第一道防線：檢查是否為啟用中的期別
                $stmtCheck = $pdo->prepare("SELECT is_active, term_name FROM terms WHERE id = ?");
                $stmtCheck->execute([$term_id]);
                $term = $stmtCheck->fetch();

                if ($term && $term['is_active'] == 1) {
                    $message = "操作拒絕：您無法刪除「當前啟用中」的期別。請先將其他期別設為當前，再進行刪除作業。";
                    $message_type = "danger";
                } elseif ($term) {
                    // 執行深度清理刪除
                    $pdo->beginTransaction();
                    $pdo->prepare("DELETE FROM barcode_checkin_log WHERE term_id = ?")->execute([$term_id]);
                    $pdo->prepare("DELETE FROM student_term_class WHERE term_id = ?")->execute([$term_id]);
                    $pdo->prepare("DELETE FROM terms WHERE id = ?")->execute([$term_id]);
                    $pdo->commit();

                    $message = "期別「{$term['term_name']}」及其相關之分班與出勤紀錄已永久刪除。";
                    $message_type = "success";
                }
            }
        }
        elseif ($action === 'add_class' || $action === 'edit_class') {
            // 新增或編輯班級
            $class_id = intval($_POST['class_id'] ?? 0);
            $class_name = trim($_POST['class_name']);
            $start_time = $_POST['start_time']; 

            if (!empty($class_name)) {
                if (empty($start_time)) $start_time = '19:30:00'; 

                if ($action === 'add_class') {
                    $stmt = $pdo->prepare("INSERT INTO classes (class_name, start_time) VALUES (?, ?)");
                    $stmt->execute([$class_name, $start_time]);
                    $message = "班級新增成功！";
                } else {
                    $stmt = $pdo->prepare("UPDATE classes SET class_name = ?, start_time = ? WHERE id = ?");
                    $stmt->execute([$class_name, $start_time, $class_id]);
                    $message = "班級設定更新成功！";
                }
                $message_type = "success";
            } else {
                $message = "班級名稱不可為空白。";
                $message_type = "danger";
            }
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $message = "資料庫執行異常：" . $e->getMessage();
        $message_type = "danger";
    }
}

// 讀取所有期別與班級資料以供顯示
$terms = $pdo->query("SELECT * FROM terms ORDER BY id DESC")->fetchAll();
$classes = $pdo->query("SELECT * FROM classes ORDER BY id ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>系統設定與維護 | 系統後台</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; font-family: "Segoe UI", sans-serif; }
        .sidebar { min-height: 100vh; background-color: #343a40; color: white; padding-top: 20px; }
        .sidebar a { color: #adb5bd; text-decoration: none; display: block; padding: 10px 20px; margin-bottom: 5px; border-radius: 4px; }
        .sidebar a:hover, .sidebar a.active { background-color: #495057; color: white; }
        .content-area { padding: 30px; }
    </style>
</head>
<body>

<div class="container-fluid p-0">
    <div class="row g-0">
        <div class="col-md-2 sidebar px-3">
            <h5 class="px-2 mb-4 text-light">系統後台管理</h5>
            <a href="admin.php">出勤數據總覽</a>
            <a href="admin_students.php">學生名單管理</a>
            <a href="admin_import.php">批次匯入名單</a>
            <a href="admin_print_qrcode.php" target="_blank">列印全班標籤</a>
            <hr class="text-secondary">
            <a href="admin_settings.php" class="active text-warning">期別與班級設定</a>
            <hr class="text-secondary">
            <a href="checkin.php" class="text-info">返回報到系統</a>
            <a href="logout.php" class="text-danger">登出系統</a>
        </div>

        <div class="col-md-10 content-area">
            <h2 class="mb-4">系統設定與維護</h2>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show shadow-sm">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-lg-7 mb-4">
                    <div class="card shadow-sm border-0 h-100">
                        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center py-3">
                            <h5 class="mb-0">期別管理 (Term Settings)</h5>
                            <button class="btn btn-sm btn-primary" onclick="openTermModal()">新增期別</button>
                        </div>
                        <div class="card-body p-0">
                            <table class="table table-hover mb-0 align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>狀態</th>
                                        <th>期別名稱</th>
                                        <th>開課日</th>
                                        <th>總週數</th>
                                        <th class="text-end" style="min-width: 140px;">操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($terms as $term): ?>
                                        <tr>
                                            <td>
                                                <?php if ($term['is_active']): ?>
                                                    <span class="badge bg-success">當前啟用</span>
                                                <?php else: ?>
                                                    <form method="POST" style="display:inline;">
                                                        <input type="hidden" name="action" value="set_active_term">
                                                        <input type="hidden" name="term_id" value="<?php echo $term['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-secondary" onclick="return confirm('確定要切換到此期別嗎？\n系統的出勤與名單將立即切換，舊期別資料將被安全封存。');">設為當前</button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                            <td class="fw-bold"><?php echo htmlspecialchars($term['term_name']); ?></td>
                                            <td><?php echo htmlspecialchars($term['start_date'] ?? '未設定'); ?></td>
                                            <td><?php echo $term['total_weeks']; ?> 週</td>
                                            <td class="text-end">
                                                <button class="btn btn-sm btn-outline-primary" 
                                                    onclick="editTerm(<?php echo $term['id']; ?>, '<?php echo htmlspecialchars($term['term_name']); ?>', '<?php echo $term['start_date']; ?>', <?php echo $term['total_weeks']; ?>)">
                                                    編輯
                                                </button>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('警告：確定要刪除「<?php echo htmlspecialchars($term['term_name']); ?>」嗎？\n\n這將會【永久刪除】該期別所有的分班名單與出勤紀錄，且無法復原！\n(若只是換學期，請勿刪除，只要把新期別「設為當前」即可)');">
                                                    <input type="hidden" name="action" value="delete_term">
                                                    <input type="hidden" name="term_id" value="<?php echo $term['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger ms-1" <?php echo $term['is_active'] ? 'disabled' : ''; ?>>刪除</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5 mb-4">
                    <div class="card shadow-sm border-0 h-100">
                        <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center py-3">
                            <h5 class="mb-0">班級與時間設定 (Classes)</h5>
                            <button class="btn btn-sm btn-light text-dark" onclick="openClassModal()">新增班級</button>
                        </div>
                        <div class="card-body p-0">
                            <table class="table table-hover mb-0 align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>班級名稱</th>
                                        <th>規定上課時間</th>
                                        <th class="text-end">操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($classes as $cls): ?>
                                        <tr>
                                            <td class="fw-bold"><span class="badge bg-info text-dark fs-6"><?php echo htmlspecialchars($cls['class_name']); ?></span></td>
                                            <td class="text-danger font-monospace">
                                                <?php echo htmlspecialchars(substr($cls['start_time'], 0, 5)); ?>
                                            </td>
                                            <td class="text-end">
                                                <button class="btn btn-sm btn-outline-secondary" 
                                                    onclick="editClass(<?php echo $cls['id']; ?>, '<?php echo htmlspecialchars($cls['class_name']); ?>', '<?php echo substr($cls['start_time'], 0, 5); ?>')">
                                                    編輯
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="termModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" action="admin_settings.php">
          <input type="hidden" name="action" id="term_action" value="add_term">
          <input type="hidden" name="term_id" id="modal_term_id">
          
          <div class="modal-header bg-dark text-white">
            <h5 class="modal-title" id="termModalTitle">新增期別</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
                <label class="form-label text-muted">期別名稱 (如：26秋季班)</label>
                <input type="text" class="form-control" name="term_name" id="modal_term_name" required>
            </div>
            <div class="mb-3">
                <label class="form-label text-muted">開課日期 (用於自動推算目前週次)</label>
                <input type="date" class="form-control" name="start_date" id="modal_start_date" required>
            </div>
            <div class="mb-3">
                <label class="form-label text-muted">總週數</label>
                <input type="number" class="form-control" name="total_weeks" id="modal_total_weeks" min="1" max="52" value="18" required>
            </div>
            <div class="mb-3" id="copy_previous_wrapper">
                <div class="form-check p-3 bg-light border rounded">
                    <input class="form-check-input ms-1" type="checkbox" name="copy_previous" id="modal_copy_previous" value="1" checked>
                    <label class="form-check-label fw-bold text-primary ms-2" for="modal_copy_previous">
                        自動將上一期的學生與分班複製到新期別
                    </label>
                    <div class="form-text mt-2 ms-2 text-muted">
                        打勾後，上一期的所有學生將直接延續至新學期，無需重新匯入。若有不上課的學生，請於切換期別後，至「學生名單管理」手動移除即可。
                    </div>
                </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
            <button type="submit" class="btn btn-primary">儲存設定</button>
          </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="classModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" action="admin_settings.php">
          <input type="hidden" name="action" id="class_action" value="add_class">
          <input type="hidden" name="class_id" id="modal_class_id">
          
          <div class="modal-header bg-secondary text-white">
            <h5 class="modal-title" id="classModalTitle">新增班級</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
                <label class="form-label text-muted">班級名稱 (如：復興班)</label>
                <input type="text" class="form-control" name="class_name" id="modal_class_name" required>
            </div>
            <div class="mb-3">
                <label class="form-label text-muted">規定上課時間 (超過此時間刷卡即標記為遲到/已到)</label>
                <input type="time" class="form-control font-monospace" name="start_time" id="modal_start_time" required>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
            <button type="submit" class="btn btn-primary">儲存設定</button>
          </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const termModal = new bootstrap.Modal(document.getElementById('termModal'));
    const classModal = new bootstrap.Modal(document.getElementById('classModal'));

    function openTermModal() {
        document.getElementById('termModalTitle').innerText = '新增期別';
        document.getElementById('term_action').value = 'add_term';
        document.getElementById('modal_term_id').value = '';
        document.getElementById('modal_term_name').value = '';
        document.getElementById('modal_start_date').value = '';
        document.getElementById('modal_total_weeks').value = '18';
        document.getElementById('copy_previous_wrapper').style.display = 'block';
        termModal.show();
    }
    
    function editTerm(id, name, startDate, weeks) {
        document.getElementById('termModalTitle').innerText = '編輯期別設定';
        document.getElementById('term_action').value = 'edit_term';
        document.getElementById('modal_term_id').value = id;
        document.getElementById('modal_term_name').value = name;
        document.getElementById('modal_start_date').value = startDate;
        document.getElementById('modal_total_weeks').value = weeks;
        document.getElementById('copy_previous_wrapper').style.display = 'none';
        termModal.show();
    }

    function openClassModal() {
        document.getElementById('classModalTitle').innerText = '新增班級';
        document.getElementById('class_action').value = 'add_class';
        document.getElementById('modal_class_id').value = '';
        document.getElementById('modal_class_name').value = '';
        document.getElementById('modal_start_time').value = '19:30';
        classModal.show();
    }
    
    function editClass(id, name, time) {
        document.getElementById('classModalTitle').innerText = '編輯班級設定';
        document.getElementById('class_action').value = 'edit_class';
        document.getElementById('modal_class_id').value = id;
        document.getElementById('modal_class_name').value = name;
        document.getElementById('modal_start_time').value = time;
        classModal.show();
    }
</script>
</body>
</html>
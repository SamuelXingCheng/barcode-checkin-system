<?php
session_start();

// 權限驗證
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'super') {
    header("Location: login.php");
    exit;
}

require_once 'config.php';

$message = '';
$message_type = '';
$term_id = 0;
$active_term_name = '無啟用期別';
$classes = [];
$students = [];

try {
    // 1. 取得當前運行中的期別
    $stmt = $pdo->query("SELECT id, term_name FROM terms WHERE is_active = 1 LIMIT 1");
    $active_term = $stmt->fetch();
    
    if ($active_term) {
        $term_id = $active_term['id'];
        $active_term_name = $active_term['term_name'];

        // 2. 處理表單送出 (新增、修改或刪除學生)
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            
            // 判斷是否為「刪除」操作
            if (isset($_POST['action']) && $_POST['action'] === 'delete') {
                $delete_id = intval($_POST['delete_student_id']);
                if ($delete_id > 0) {
                    $pdo->beginTransaction();
                    try {
                        // 依序刪除：打卡紀錄 -> 分班紀錄 -> 學生主檔，以符合關聯限制
                        $pdo->prepare("DELETE FROM barcode_checkin_log WHERE student_id = ?")->execute([$delete_id]);
                        $pdo->prepare("DELETE FROM student_term_class WHERE student_id = ?")->execute([$delete_id]);
                        $pdo->prepare("DELETE FROM students WHERE id = ?")->execute([$delete_id]);
                        
                        $pdo->commit();
                        $message = "該學生資料及其出勤紀錄已永久刪除。";
                        $message_type = "success";
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $message = "刪除失敗：" . $e->getMessage();
                        $message_type = "danger";
                    }
                }
            } 
            // 否則為「新增/修改」操作
            else {
                $student_no = trim($_POST['student_no'] ?? '');
                $name = trim($_POST['name'] ?? '');
                $meetinghall = trim($_POST['meetinghall'] ?? '');
                $district = trim($_POST['district'] ?? '');
                $class_id = intval($_POST['class_id'] ?? 0);

                if (!empty($name) && $class_id > 0) {
                    $pdo->beginTransaction();
                    try {
                        $student_id = 0;

                        if (!empty($student_no)) {
                            // 編輯既有學生
                            $stmtCheck = $pdo->prepare("SELECT id FROM students WHERE student_no = :student_no LIMIT 1");
                            $stmtCheck->execute([':student_no' => $student_no]);
                            $existing_student = $stmtCheck->fetch();

                            if ($existing_student) {
                                $student_id = $existing_student['id'];
                                $stmtUpdate = $pdo->prepare("UPDATE students SET name = :name, meetinghall = :meetinghall, district = :district WHERE id = :id");
                                $stmtUpdate->execute([':name' => $name, ':meetinghall' => $meetinghall, ':district' => $district, ':id' => $student_id]);
                            }
                        } else {
                            // 新增學生 (自動產生學號)
                            $stmtMax = $pdo->query("SELECT MAX(CAST(SUBSTRING(student_no, 2) AS UNSIGNED)) FROM students");
                            $max_num = $stmtMax->fetchColumn();
                            
                            $next_num = $max_num ? ($max_num + 1) : 1001;
                            $new_student_no = 'S' . $next_num;

                            $stmtInsert = $pdo->prepare("INSERT INTO students (student_no, name, meetinghall, district) VALUES (:student_no, :name, :meetinghall, :district)");
                            $stmtInsert->execute([':student_no' => $new_student_no, ':name' => $name, ':meetinghall' => $meetinghall, ':district' => $district]);
                            $student_id = $pdo->lastInsertId();
                        }

                        if ($student_id === 0) {
                            throw new Exception("學生主檔處理失敗。");
                        }

                        // 處理分班表
                        $stmtCheckClass = $pdo->prepare("SELECT id FROM student_term_class WHERE student_id = :student_id AND term_id = :term_id LIMIT 1");
                        $stmtCheckClass->execute([':student_id' => $student_id, ':term_id' => $term_id]);
                        
                        if ($stmtCheckClass->fetch()) {
                            $stmtUpdateClass = $pdo->prepare("UPDATE student_term_class SET class_id = :class_id WHERE student_id = :student_id AND term_id = :term_id");
                            $stmtUpdateClass->execute([':class_id' => $class_id, ':student_id' => $student_id, ':term_id' => $term_id]);
                        } else {
                            $stmtInsertClass = $pdo->prepare("INSERT INTO student_term_class (student_id, term_id, class_id) VALUES (:student_id, :term_id, :class_id)");
                            $stmtInsertClass->execute([':student_id' => $student_id, ':term_id' => $term_id, ':class_id' => $class_id]);
                        }

                        $pdo->commit();
                        $message = "學生資料儲存成功。";
                        $message_type = "success";

                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $message = "資料儲存失敗：" . $e->getMessage();
                        $message_type = "danger";
                    }
                } else {
                    $message = "姓名與班級為必填欄位。";
                    $message_type = "warning";
                }
            }
        }

        // 3. 取得下拉選單所需的班級列表
        $stmtClasses = $pdo->query("SELECT id, class_name FROM classes ORDER BY id ASC");
        $classes = $stmtClasses->fetchAll();

        // 4. 取得當前期別的所有學生名單
        $sqlStudents = "
            SELECT 
                s.id as student_id, s.student_no, s.name, s.meetinghall, s.district, 
                c.id as class_id, c.class_name 
            FROM students s
            INNER JOIN student_term_class stc ON s.id = stc.student_id
            INNER JOIN classes c ON stc.class_id = c.id
            WHERE stc.term_id = :term_id
            ORDER BY c.id ASC, s.student_no ASC
        ";
        $stmtStudents = $pdo->prepare($sqlStudents);
        $stmtStudents->execute([':term_id' => $term_id]);
        $students = $stmtStudents->fetchAll();
    } else {
        $message = "系統尚未設定或啟用任何期別，無法管理名單。";
        $message_type = "danger";
    }
} catch (PDOException $e) {
    $message = "系統發生錯誤：" . $e->getMessage();
    $message_type = "danger";
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>學生名單管理 | 系統後台</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; }
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
            <h5 class="px-2 mb-4">報到系統後台</h5>
            <a href="admin.php">今日出勤總覽</a>
            <a href="admin_students.php" class="active">學生名單管理</a>
            <a href="#">期別與班級設定 (建置中)</a>
            <a href="#">報表匯出 (建置中)</a>
            <a href="admin_import.php">批次匯入名單</a>
            <a href="admin_print_qrcode.php" target="_blank">列印 QR Code 貼紙</a>
            <hr class="text-secondary">
            <a href="checkin.php" class="text-info">返回報到櫃台</a>
            <a href="logout.php" class="text-danger">登出系統</a>
        </div>

        <div class="col-md-10 content-area">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>學生名單與分班管理</h2>
                <div class="row mb-3 mt-4 g-2 align-items-center">
                    <div class="col-auto">
                        <select id="classFilter" class="form-select" style="min-width: 150px;">
                            <option value="">顯示所有班級</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo htmlspecialchars($class['class_name']); ?>">
                                    <?php echo htmlspecialchars($class['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col">
                        <input type="text" id="studentSearch" class="form-control" placeholder="輸入姓名或學號搜尋...">
                    </div>
                </div>
                <div>
                    <span class="badge bg-secondary fs-6 me-2">當前期別：<?php echo htmlspecialchars($active_term_name); ?></span>
                    <button class="btn btn-primary" onclick="openStudentModal()">+ 新增/編輯學生</button>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="card shadow-sm border-0">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover mb-0 align-middle">
                            <thead class="table-dark">
                                <tr>
                                    <th>班級</th>
                                    <th>條碼 / 學號</th>
                                    <th>姓名</th>
                                    <th>所屬會所</th>
                                    <th>區</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($students) > 0): ?>
                                    <?php foreach ($students as $student): ?>
                                        <tr>
                                            <td><span class="badge bg-info text-dark"><?php echo htmlspecialchars($student['class_name']); ?></span></td>
                                            <td class="font-monospace fw-bold"><?php echo htmlspecialchars($student['student_no']); ?></td>
                                            <td><?php echo htmlspecialchars($student['name']); ?></td>
                                            <td><?php echo htmlspecialchars($student['meetinghall']); ?></td>
                                            <td><?php echo htmlspecialchars($student['district']); ?></td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-secondary me-1" 
                                                    onclick="editStudent('<?php echo $student['student_no']; ?>', '<?php echo $student['name']; ?>', '<?php echo $student['meetinghall']; ?>', '<?php echo $student['district']; ?>', '<?php echo $student['class_id']; ?>')">
                                                    編輯
                                                </button>
                                                <form method="POST" action="admin_students.php" style="display:inline;" onsubmit="return confirm('警告：確定要永久刪除【<?php echo htmlspecialchars($student['name']); ?>】嗎？\n這將會一併刪除該學生的所有出勤紀錄且無法復原！');">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="delete_student_id" value="<?php echo $student['student_id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">刪除</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4 text-muted">本期別目前尚無學生資料</td>
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

<div class="modal fade" id="studentModal" tabindex="-1" aria-labelledby="studentModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" action="admin_students.php">
          <div class="modal-header bg-primary text-white">
            <h5 class="modal-title" id="studentModalLabel">學生資料維護</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
                <label class="form-label text-muted">條碼編號 / 學號</label>
                <input type="text" class="form-control font-monospace" name="student_no" id="modal_student_no" readonly placeholder="系統將於儲存時自動配發">
                <div class="form-text">新增學生時請留空，系統將自動產生連續學號（如 S1003）。</div>
            </div>
            <div class="mb-3">
                <label class="form-label text-muted">學生姓名 (必填)</label>
                <input type="text" class="form-control" name="name" id="modal_name" required>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label text-muted">所屬會所</label>
                    <input type="text" class="form-control" name="meetinghall" id="modal_meetinghall">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label text-muted">區</label>
                    <input type="text" class="form-control" name="district" id="modal_district">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label text-muted">分配班級 (本期別)</label>
                <select class="form-select" name="class_id" id="modal_class_id" required>
                    <option value="">請選擇班級...</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
            <button type="submit" class="btn btn-primary">儲存資料</button>
          </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const studentModal = new bootstrap.Modal(document.getElementById('studentModal'));

    // 開啟新增模式
    function openStudentModal() {
        document.getElementById('modal_student_no').value = '';
        document.getElementById('modal_student_no').readOnly = false; // 允許輸入
        document.getElementById('modal_name').value = '';
        document.getElementById('modal_meetinghall').value = '';
        document.getElementById('modal_district').value = '';
        document.getElementById('modal_class_id').value = '';
        studentModal.show();
    }

    // 開啟編輯模式並帶入既有資料
    function editStudent(student_no, name, meetinghall, district, class_id) {
        document.getElementById('modal_student_no').value = student_no;
        document.getElementById('modal_student_no').readOnly = true; // 編輯時鎖定學號，防止改錯
        document.getElementById('modal_name').value = name;
        document.getElementById('modal_meetinghall').value = meetinghall;
        document.getElementById('modal_district').value = district;
        document.getElementById('modal_class_id').value = class_id;
        studentModal.show();
    }
    // 名單即時篩選邏輯
    function filterTable() {
        const classVal = document.getElementById('classFilter').value;
        const searchVal = document.getElementById('studentSearch').value.toUpperCase();
        const rows = document.querySelectorAll('tbody tr');

        rows.forEach(row => {
            // 確保不是「目前無資料」的提示列
            if (row.cells.length < 5) return; 

            const className = row.cells[0].textContent || row.cells[0].innerText;
            const studentNo = row.cells[1].textContent || row.cells[1].innerText;
            const studentName = row.cells[2].textContent || row.cells[2].innerText;

            const isClassMatch = classVal === "" || className.includes(classVal);
            const isSearchMatch = searchVal === "" || studentNo.includes(searchVal) || studentName.toUpperCase().includes(searchVal);

            row.style.display = (isClassMatch && isSearchMatch) ? "" : "none";
        });
    }

    document.getElementById('classFilter').addEventListener('change', filterTable);
    document.getElementById('studentSearch').addEventListener('input', filterTable);
</script>

</body>
</html>
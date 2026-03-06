<?php
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}
$is_super = ($_SESSION['role'] === 'super');
$admin_class_id = $_SESSION['class_id'] ?? 0;

require_once 'config.php';

$message = '';
$message_type = '';
$term_id = 0;
$active_term_name = '無啟用期別';
$classes = [];
$students = [];

try {
    $stmt = $pdo->query("SELECT id, term_name FROM terms WHERE is_active = 1 LIMIT 1");
    $active_term = $stmt->fetch();
    
    if ($active_term) {
        $term_id = $active_term['id'];
        $active_term_name = $active_term['term_name'];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            
            // 處理批次移除 (軟刪除，保留主檔)
            if (isset($_POST['action']) && $_POST['action'] === 'batch_delete') {
                $delete_ids = $_POST['delete_ids'] ?? [];
                if (!empty($delete_ids) && is_array($delete_ids)) {
                    $pdo->beginTransaction();
                    try {
                        $placeholders = implode(',', array_fill(0, count($delete_ids), '?'));
                        $pdo->prepare("DELETE FROM barcode_checkin_log WHERE student_id IN ($placeholders)")->execute($delete_ids);
                        $pdo->prepare("DELETE FROM student_term_class WHERE student_id IN ($placeholders)")->execute($delete_ids);
                        
                        $pdo->commit();
                        $message = "成功移除 " . count($delete_ids) . " 筆學生資料與出勤紀錄。";
                        $message_type = "success";
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $message = "批次移除失敗：" . $e->getMessage();
                        $message_type = "danger";
                    }
                }
            }
            // 處理單筆移除 (軟刪除，保留主檔)
            elseif (isset($_POST['action']) && $_POST['action'] === 'delete') {
                $delete_id = intval($_POST['delete_student_id']);
                if ($delete_id > 0) {
                    $pdo->beginTransaction();
                    try {
                        $pdo->prepare("DELETE FROM barcode_checkin_log WHERE student_id = ?")->execute([$delete_id]);
                        $pdo->prepare("DELETE FROM student_term_class WHERE student_id = ?")->execute([$delete_id]);
                        
                        $pdo->commit();
                        $message = "該學生資料已成功移除。";
                        $message_type = "success";
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $message = "移除失敗：" . $e->getMessage();
                        $message_type = "danger";
                    }
                }
            } 
            // 處理新增或修改
            else {
                $student_no = trim($_POST['student_no'] ?? '');
                $name = trim($_POST['name'] ?? '');
                $meetinghall = trim($_POST['meetinghall'] ?? '');
                $district = trim($_POST['district'] ?? '');
                if (!$is_super) {
                    $class_id = $admin_class_id;
                } else {
                    $class_id = intval($_POST['class_id'] ?? 0);
                }

                if (!empty($name) && $class_id > 0) {
                    
                    // 👉 全局防呆攔截：檢查系統中是否已有同名且「非本次編輯學號」的紀錄
                    $stmtCheckName = $pdo->prepare("SELECT student_no FROM students WHERE name = :name AND student_no != :current_no LIMIT 1");
                    $stmtCheckName->execute([':name' => $name, ':current_no' => $student_no]);
                    $dup_student = $stmtCheckName->fetch();
                    
                    if ($dup_student) {
                        // 發現撞名，設定錯誤訊息並阻擋下方寫入
                        $message = "【重複建立阻擋】系統中已有姓名為「{$name}」的紀錄（舊學號：{$dup_student['student_no']}）。<br>．若為同一人，請在上方學號欄位手動輸入 {$dup_student['student_no']} 以沿用身分。<br>．若為同名之新生，請於姓名後加上辨識碼（例如：{$name}B）再次儲存。";
                        $message_type = "danger";
                    }

                    // 只有在「沒有撞名」的情況下，才允許執行資料庫操作
                    if (empty($message)) {
                        $pdo->beginTransaction();
                        try {
                            $student_id = 0;

                            if (!empty($student_no)) {
                                // 更新舊資料或喚醒復學
                                $stmtCheck = $pdo->prepare("SELECT id FROM students WHERE student_no = :student_no LIMIT 1");
                                $stmtCheck->execute([':student_no' => $student_no]);
                                $existing_student = $stmtCheck->fetch();

                                if ($existing_student) {
                                    $student_id = $existing_student['id'];
                                    $stmtUpdate = $pdo->prepare("UPDATE students SET name = :name, meetinghall = :meetinghall, district = :district WHERE id = :id");
                                    $stmtUpdate->execute([':name' => $name, ':meetinghall' => $meetinghall, ':district' => $district, ':id' => $student_id]);
                                } else {
                                    throw new Exception("查無此學號，無法更新或喚醒資料。");
                                }
                            } else {
                                // 全新配發學號
                                $stmtMax = $pdo->query("SELECT MAX(CAST(SUBSTRING(student_no, 2) AS UNSIGNED)) FROM students");
                                $max_num = $stmtMax->fetchColumn();
                                $next_num = $max_num ? ($max_num + 1) : 1001;
                                $new_student_no = 'S' . $next_num;

                                $stmtInsert = $pdo->prepare("INSERT INTO students (student_no, name, meetinghall, district) VALUES (:student_no, :name, :meetinghall, :district)");
                                $stmtInsert->execute([':student_no' => $new_student_no, ':name' => $name, ':meetinghall' => $meetinghall, ':district' => $district]);
                                $student_id = $pdo->lastInsertId();
                            }

                            if ($student_id === 0) throw new Exception("學生主檔處理失敗。");

                            // 處理分班
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
                            $message = "資料儲存成功。";
                            $message_type = "success";

                        } catch (Exception $e) {
                            $pdo->rollBack();
                            $message = "資料儲存失敗：" . $e->getMessage();
                            $message_type = "danger";
                        }
                    }
                } else {
                    $message = "姓名與班級為必填欄位。";
                    $message_type = "warning";
                }
            }
        }

        $stmtClasses = $pdo->query("SELECT id, class_name FROM classes ORDER BY id ASC");
        $classes = $stmtClasses->fetchAll();

        $sqlStudents = "
            SELECT 
                s.id as student_id, s.student_no, s.name, s.meetinghall, s.district, 
                c.id as class_id, c.class_name 
            FROM students s
            INNER JOIN student_term_class stc ON s.id = stc.student_id
            INNER JOIN classes c ON stc.class_id = c.id
            WHERE stc.term_id = :term_id
        ";
        $paramsStudents = [':term_id' => $term_id];

        if (!$is_super) {
            $sqlStudents .= " AND stc.class_id = :admin_class_id";
            $paramsStudents[':admin_class_id'] = $admin_class_id;
        }

        $sqlStudents .= " ORDER BY c.id ASC, s.student_no ASC";
        
        $stmtStudents = $pdo->prepare($sqlStudents);
        $stmtStudents->execute($paramsStudents);
        $students = $stmtStudents->fetchAll();
    } else {
        $message = "系統尚未設定或啟用任何期別。";
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
    <title>學生名單管理 | 系統後台</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; font-family: "Segoe UI", sans-serif; }
        .sidebar { min-height: 100vh; background-color: #343a40; color: white; padding-top: 20px; }
        .sidebar a { color: #adb5bd; text-decoration: none; display: block; padding: 10px 20px; margin-bottom: 5px; border-radius: 4px; }
        .sidebar a:hover, .sidebar a.active { background-color: #495057; color: white; }
        .content-area { padding: 30px; }
        .table-responsive { max-height: 65vh; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 4px; }
        thead th { position: sticky; top: 0; z-index: 1; background-color: #212529; color: white; }
    </style>
</head>
<body>

<div class="container-fluid p-0">
    <div class="row g-0">
        <div class="col-md-2 sidebar px-3">
            <h5 class="px-2 mb-4 text-light">系統後台管理</h5>
            <a href="admin.php">出席數據總覽</a>
            <a href="admin_students.php" class="active">學生名單管理</a>
            <?php if ($is_super): ?>
                <a href="admin_import.php">批次匯入名單</a>
            <?php endif; ?>
            <hr class="text-secondary">
            <a href="checkin.php" class="text-info">返回報到系統</a>
            <a href="logout.php" class="text-danger">登出系統</a>
        </div>

        <div class="col-md-10 content-area">
            
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="mb-0">學生名單與分班管理</h2>
                <div>
                    <span class="badge bg-secondary fs-6 me-3">當前期別：<?php echo htmlspecialchars($active_term_name); ?></span>
                    
                    <div class="btn-group me-2" role="group">
                        <button type="button" class="btn btn-outline-success" onclick="batchPrint()">列印勾選名單</button>
                        <button type="button" class="btn btn-outline-danger" onclick="batchDelete()">刪除勾選名單</button>
                    </div>

                    <button type="button" class="btn btn-outline-dark me-2" onclick="printFilteredQRCodes()">列印全部或篩選結果</button>
                    <button type="button" class="btn btn-primary" onclick="openStudentModal()">新增學生資料</button>
                </div>
            </div>

            <div class="card shadow-sm border-0 mb-4 bg-light">
                <div class="card-body py-2 px-3">
                    <div class="row g-2 align-items-center">
                        <?php if ($is_super): ?>
                        <div class="col-md-3">
                            <select id="classFilter" class="form-select">
                                <option value="">顯示所有班級</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo htmlspecialchars($class['class_name']); ?>">
                                        <?php echo htmlspecialchars($class['class_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="col">
                            <input type="text" id="studentSearch" class="form-control" placeholder="輸入姓名或學號搜尋...">
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                    <?php echo $message; // 輸出 HTML 以呈現分行排版 ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="card shadow-sm border-0">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th style="width: 50px;" class="text-center">
                                        <input class="form-check-input" type="checkbox" id="selectAll">
                                    </th>
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
                                            <td class="text-center">
                                                <input class="form-check-input student-checkbox" type="checkbox" value="<?php echo $student['student_id']; ?>" data-no="<?php echo htmlspecialchars($student['student_no']); ?>">
                                            </td>
                                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($student['class_name']); ?></span></td>
                                            <td class="font-monospace fw-bold"><?php echo htmlspecialchars($student['student_no']); ?></td>
                                            <td><?php echo htmlspecialchars($student['name']); ?></td>
                                            <td><?php echo htmlspecialchars($student['meetinghall']); ?></td>
                                            <td><?php echo htmlspecialchars($student['district']); ?></td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-secondary me-1" 
                                                    onclick="editStudent('<?php echo $student['student_no']; ?>', '<?php echo $student['name']; ?>', '<?php echo $student['meetinghall']; ?>', '<?php echo $student['district']; ?>', '<?php echo $student['class_id']; ?>')">
                                                    編輯
                                                </button>
                                                <form method="POST" action="admin_students.php" style="display:inline;" onsubmit="return confirm('確認移除此學生資料？');">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="delete_student_id" value="<?php echo $student['student_id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">移除</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4 text-muted">目前尚無學生資料</td>
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

<div class="modal fade" id="studentModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" action="admin_students.php">
          <div class="modal-header bg-dark text-white">
            <h5 class="modal-title">學生資料維護</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
                <label class="form-label text-muted">學號</label>
                <input type="text" class="form-control font-monospace" name="student_no" id="modal_student_no" placeholder="系統將於儲存時自動配發">
                <div class="form-text">新增資料時請留空；若為舊生復學，請手動輸入舊學號以利系統連結。</div>
            </div>
            <div class="mb-3">
                <label class="form-label text-muted">姓名 (必填)</label>
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
                <label class="form-label text-muted">分配班級</label>
                <?php if ($is_super): ?>
                    <select class="form-select" name="class_id" id="modal_class_id" required>
                        <option value="">請選擇班級...</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['class_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="hidden" name="class_id" id="modal_class_id" value="<?php echo $admin_class_id; ?>">
                    <input type="text" class="form-control" value="您的專屬班級" disabled>
                <?php endif; ?>
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

    function openStudentModal() {
        document.getElementById('modal_student_no').value = '';
        document.getElementById('modal_student_no').readOnly = false; 
        document.getElementById('modal_name').value = '';
        document.getElementById('modal_meetinghall').value = '';
        document.getElementById('modal_district').value = '';
        document.getElementById('modal_class_id').value = '';
        studentModal.show();
    }

    function editStudent(student_no, name, meetinghall, district, class_id) {
        document.getElementById('modal_student_no').value = student_no;
        document.getElementById('modal_student_no').readOnly = true; 
        document.getElementById('modal_name').value = name;
        document.getElementById('modal_meetinghall').value = meetinghall;
        document.getElementById('modal_district').value = district;
        document.getElementById('modal_class_id').value = class_id;
        studentModal.show();
    }

    const selectAllCb = document.getElementById('selectAll');
    if (selectAllCb) {
        selectAllCb.addEventListener('change', function(e) {
            const checkboxes = document.querySelectorAll('.student-checkbox');
            checkboxes.forEach(cb => {
                if (cb.closest('tr').style.display !== 'none') {
                    cb.checked = e.target.checked;
                }
            });
        });
    }

    function batchPrint() {
        const checked = document.querySelectorAll('.student-checkbox:checked');
        if (checked.length === 0) {
            alert('請先於列表中勾選需要列印的資料。');
            return;
        }
        let nos = [];
        checked.forEach(cb => nos.push(cb.getAttribute('data-no')));
        window.open('admin_print_qrcode.php?selected=' + encodeURIComponent(nos.join(',')), '_blank');
    }

    function batchDelete() {
        const checked = document.querySelectorAll('.student-checkbox:checked');
        if (checked.length === 0) {
            alert('請先於列表中勾選需要移除的資料。');
            return;
        }
        if (!confirm('確認移除選定的 ' + checked.length + ' 筆資料？')) {
            return;
        }

        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'admin_students.php';

        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'batch_delete';
        form.appendChild(actionInput);

        checked.forEach(cb => {
            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'delete_ids[]';
            idInput.value = cb.value; 
            form.appendChild(idInput);
        });

        document.body.appendChild(form);
        form.submit();
    }

    function printFilteredQRCodes() {
        const classFilterElem = document.getElementById('classFilter');
        const classVal = classFilterElem ? classFilterElem.value : '';
        const searchVal = document.getElementById('studentSearch').value;
        
        const isSuper = <?php echo $is_super ? 'true' : 'false'; ?>;
        const adminClassId = '<?php echo $admin_class_id; ?>';
        const params = new URLSearchParams();
        
        if (isSuper) {
            if (classVal) params.append('class_name', classVal);
        } else {
            params.append('class_id', adminClassId);
        }
        if (searchVal) params.append('search', searchVal);

        window.open('admin_print_qrcode.php?' + params.toString(), '_blank');
    }

    function filterTable() {
        const classFilterElem = document.getElementById('classFilter');
        const classVal = classFilterElem ? classFilterElem.value : '';
        const searchVal = document.getElementById('studentSearch').value.toUpperCase();
        const rows = document.querySelectorAll('tbody tr');

        rows.forEach(row => {
            if (row.cells.length < 5) return; 
            const className = row.cells[1].textContent || row.cells[1].innerText;
            const studentNo = row.cells[2].textContent || row.cells[2].innerText;
            const studentName = row.cells[3].textContent || row.cells[3].innerText;

            const isClassMatch = classVal === "" || className.includes(classVal);
            const isSearchMatch = searchVal === "" || studentNo.includes(searchVal) || studentName.toUpperCase().includes(searchVal);

            row.style.display = (isClassMatch && isSearchMatch) ? "" : "none";
            
            if (row.style.display === "none") {
                const cb = row.querySelector('.student-checkbox');
                if (cb) cb.checked = false;
            }
        });
    }

    const classFilterNode = document.getElementById('classFilter');
    if (classFilterNode) classFilterNode.addEventListener('change', filterTable);
    document.getElementById('studentSearch').addEventListener('input', filterTable);
</script>
</body>
</html>
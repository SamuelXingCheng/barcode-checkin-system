<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}
require_once 'config.php';

// 自動計算當前週次與取得開課日
$current_week = 1;
$total_weeks = 1;
$start_timestamp = 0; // 新增：用於儲存開課日的時間戳記

try {
    $stmt = $pdo->query("SELECT start_date, total_weeks FROM terms WHERE is_active = 1 LIMIT 1");
    $active_term = $stmt->fetch();
    if ($active_term) {
        $total_weeks = intval($active_term['total_weeks']);
        if (!empty($active_term['start_date'])) {
            $start_timestamp = strtotime($active_term['start_date']);
            $now_timestamp = time();
            
            // 如果開課日已到或已過，計算天數差異來推算週次
            if ($now_timestamp >= $start_timestamp) {
                $days_diff = floor(($now_timestamp - $start_timestamp) / 86400);
                $current_week = floor($days_diff / 7) + 1;
            }
        }
        // 確保週次不超過總週數，且不小於 1
        if ($current_week > $total_weeks) $current_week = $total_weeks;
        if ($current_week < 1) $current_week = 1;
    }
} catch (PDOException $e) {
    // 忽略錯誤，採用預設值
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>台中週中得勝班報到系統</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://unpkg.com/html5-qrcode"></script>
    <style>
        body { background-color: #f4f6f9; font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; }
        .navbar-brand { font-weight: 600; }
        .card { border: none; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075); margin-bottom: 1.5rem; }
        .table-container { max-height: 65vh; overflow-y: auto; }
        #reader { width: 100%; border: 1px solid #dee2e6; border-radius: 4px; }
        .status-badge { width: 60px; display: inline-block; text-align: center; }
    </style>
</head>
<body>
    <nav class="navbar navbar-dark bg-dark mb-4">
        <div class="container-fluid px-4">
            <span class="navbar-brand mb-0 h1">台中週中得勝班報到系統</span>
            <div>
                <span class="text-light me-3" id="systemTime"></span>
                <span class="text-secondary me-3">|</span>
                <a href="admin.php" class="btn btn-outline-info btn-sm me-2">後台管理</a>
                <a href="logout.php" class="btn btn-outline-danger btn-sm">登出</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-4">
        <div class="row">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header bg-primary text-white">操作設定與掃描區</div>
                    <div class="card-body">
                        <div class="row mb-4">
                            <div class="col-12 mb-3">
                                <label for="weekSelector" class="form-label text-muted">1. 選擇報到週次</label>
                                <select id="weekSelector" class="form-select">
                                    <?php for($i = 1; $i <= $total_weeks; $i++): ?>
                                        <?php 
                                            $date_string = '';
                                            if ($start_timestamp > 0) {
                                                $target_time = $start_timestamp + (($i - 1) * 7 * 86400);
                                                $date_string = " (" . date("m/d", $target_time) . ")";
                                            }
                                        ?>
                                        <option value="<?php echo $i; ?>" <?php echo ($i === $current_week) ? 'selected' : ''; ?>>
                                            第 <?php echo $i; ?> 週<?php echo $date_string; ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label for="classSelector" class="form-label text-muted">2. 選擇操作班級</label>
                                <select id="classSelector" class="form-select">
                                    <option value="">資料載入中...</option>
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-muted">3. 條碼感應區</label>
                            <div id="reader"></div>
                        </div>
                        <div id="scanResult" class="alert alert-secondary mt-3 text-center" style="display:none; font-size: 1.1em; font-weight: 500;">
                            等待掃描中...
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
                        <span>目前出勤名單</span>
                        <span class="badge bg-light text-dark fs-6">
                            實到: <span id="attendCount" class="text-success fw-bold">0</span> / 
                            請假: <span id="leaveCount" class="text-danger fw-bold">0</span> / 
                            應到: <span id="totalCount">0</span>
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="input-group mb-3">
                            <span class="input-group-text bg-light">搜尋</span>
                            <input type="text" id="searchInput" class="form-control" placeholder="請輸入姓名或學號進行篩選...">
                        </div>
                        <div class="table-container border rounded">
                            <table class="table table-hover align-middle mb-0" id="studentTable">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th style="width: 15%;">狀態</th>
                                        <th style="width: 25%;">學號</th>
                                        <th style="width: 35%;">姓名</th>
                                        <th style="width: 25%; text-align: center;">手動報到</th>
                                    </tr>
                                </thead>
                                <tbody id="studentListBody">
                                    <tr><td colspan="4" class="text-center text-muted py-4">請先於左側選擇班級以載入名單</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function updateTime() {
            const now = new Date();
            document.getElementById('systemTime').innerText = now.toLocaleString('zh-TW', { hour12: false });
        }
        setInterval(updateTime, 1000); updateTime();

        // 頁面載入時初始化並讀取記憶設定
        document.addEventListener('DOMContentLoaded', async function() {
            try {
                const response = await fetch('api_get_classes.php');
                const result = await response.json();
                const selector = document.getElementById('classSelector');
                selector.innerHTML = '<option value="">請選擇班級...</option>';
                
                if (result.status === 'success') {
                    result.data.forEach(cls => {
                        selector.appendChild(new Option(cls.class_name, cls.id));
                    });
                    if (result.data.length === 1) {
                        selector.value = result.data[0].id;
                        selector.disabled = true; 
                        localStorage.setItem('checkin_class', result.data[0].id);
                    }
                }

                // 讀取 localStorage 中的記憶設定
                const savedWeek = localStorage.getItem('checkin_week');
                const savedClass = localStorage.getItem('checkin_class');

                if (savedWeek) {
                    document.getElementById('weekSelector').value = savedWeek;
                }
                
                if (savedClass) {
                    selector.value = savedClass;
                    // 若有記憶班級，自動載入名單
                    loadStudentList(savedClass);
                }
            } catch (error) { 
                console.error(error); 
            }
        });

        async function loadStudentList(classId) {
            const weekNo = document.getElementById('weekSelector').value;
            const tbody = document.getElementById('studentListBody');
            try {
                const response = await fetch(`api_get_students.php?class_id=${classId}&week_no=${weekNo}`);
                const result = await response.json();
                if (result.status === 'success') {
                    // 更新右上角統計
                    document.getElementById('attendCount').textContent = result.data.summary.attended;
                    document.getElementById('leaveCount').textContent = result.data.summary.leaves || 0;
                    document.getElementById('totalCount').textContent = result.data.summary.total;
                    
                    if (result.data.students.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">該班級目前無學生資料</td></tr>';
                        return;
                    }
                    
                    // 重新定義表頭以納入作業區塊
                    document.querySelector('#studentTable thead tr').innerHTML = `
                        <th style="width: 12%;">狀態</th>
                        <th style="width: 15%;">學號</th>
                        <th style="width: 23%;">姓名</th>
                        <th style="width: 25%;">作業繳交</th>
                        <th style="width: 25%; text-align: center;">出勤操作</th>
                    `;

                    let html = '';
                    result.data.students.forEach(student => {
                        const isAttended = parseInt(student.is_attended) === 1;
                        const isLate = parseInt(student.is_late) === 1;
                        const isLeave = parseInt(student.is_leave) === 1;
                        
                        let statusBadge = '';
                        let actionButtons = '';

                        if (!isAttended) {
                            statusBadge = '<span class="badge bg-secondary status-badge">未到</span>';
                            actionButtons = `
                                <div class="d-flex justify-content-center gap-1">
                                    <button class="btn btn-sm btn-outline-success px-2" onclick="doManualCheckin(${student.student_id}, 'checkin_ontime')">準時</button>
                                    <button class="btn btn-sm btn-outline-warning px-2" onclick="doManualCheckin(${student.student_id}, 'checkin_late')">已到</button>
                                    <button class="btn btn-sm btn-outline-danger px-2" onclick="doManualCheckin(${student.student_id}, 'set_leave')">請假</button>
                                </div>
                            `;
                        } else {
                            if (isLeave) {
                                statusBadge = '<span class="badge bg-danger status-badge">請假</span>';
                            } else if (isLate) {
                                statusBadge = '<span class="badge bg-warning text-dark status-badge">已到</span>';
                            } else {
                                statusBadge = '<span class="badge bg-success status-badge">準時</span>';
                            }
                            actionButtons = `
                                <div class="d-flex justify-content-center">
                                    <button class="btn btn-sm btn-outline-secondary" onclick="doManualCheckin(${student.student_id}, 'checkout')">清除狀態</button>
                                </div>
                            `;
                        }

                        // 作業繳交的 Checkbox 區塊
                        const hwPracticeChecked = (parseInt(student.hw_practice) === 1) ? 'checked' : '';
                        const hwProphesyChecked = (parseInt(student.hw_prophesy) === 1) ? 'checked' : '';
                        
                        const hwCheckboxes = `
                            <div class="form-check form-check-inline mb-0">
                                <input class="form-check-input border-primary" type="checkbox" id="prac_${student.student_id}" onchange="toggleHW(${student.student_id}, 'hw_practice', this.checked, this)" ${hwPracticeChecked}>
                                <label class="form-check-label text-muted" for="prac_${student.student_id}">操練表</label>
                            </div>
                            <div class="form-check form-check-inline mb-0">
                                <input class="form-check-input border-info" type="checkbox" id="proph_${student.student_id}" onchange="toggleHW(${student.student_id}, 'hw_prophesy', this.checked, this)" ${hwProphesyChecked}>
                                <label class="form-check-label text-muted" for="proph_${student.student_id}">申言稿</label>
                            </div>
                        `;

                        html += `
                            <tr>
                                <td>${statusBadge}</td>
                                <td class="font-monospace">${student.student_no}</td>
                                <td class="fw-bold">${student.name}</td>
                                <td>${hwCheckboxes}</td>
                                <td>${actionButtons}</td>
                            </tr>`;
                    });
                    tbody.innerHTML = html;
                }
            } catch (error) { console.error(error); }
        }

        window.doManualCheckin = async function(studentId, action) {
            const weekNo = document.getElementById('weekSelector').value;
            const classId = document.getElementById('classSelector').value;
            try {
                const response = await fetch('api_manual_checkin.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ student_id: studentId, action: action, week_no: weekNo })
                });
                const result = await response.json();
                if (result.status === 'success') {
                    playBeep('success');
                    loadStudentList(classId); 
                } else {
                    playBeep('error'); alert(result.message);
                }
            } catch (error) { playBeep('error'); alert('系統連線異常'); }
        };

        // 修正後的作業更新函數 (包含自動復原機制)
        window.toggleHW = async function(studentId, hwType, isChecked, checkboxElem) {
            const weekNo = document.getElementById('weekSelector').value;
            const classId = document.getElementById('classSelector').value;
            try {
                const response = await fetch('api_manual_checkin.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        student_id: studentId, 
                        action: 'update_hw', 
                        week_no: weekNo,
                        hw_type: hwType,
                        hw_val: isChecked ? 1 : 0
                    })
                });
                const result = await response.json();
                
                if (result.status === 'success') {
                    // 成功時不需重整表格，保留流暢感
                } else {
                    // 👉 觸發防呆錯誤時：跳出警告，並把剛才的打勾取消
                    playBeep('error');
                    alert(result.message);
                    checkboxElem.checked = !isChecked; 
                }
            } catch (error) { 
                playBeep('error');
                alert('系統連線異常'); 
                checkboxElem.checked = !isChecked; 
            }
        };

        // 班級選擇改變時：儲存設定並載入名單
        document.getElementById('classSelector').addEventListener('change', function() {
            localStorage.setItem('checkin_class', this.value);
            if (this.value) {
                loadStudentList(this.value);
            } else {
                document.getElementById('studentListBody').innerHTML = '<tr><td colspan="4" class="text-center text-muted py-4">請先於左側選擇班級</td></tr>';
            }
        });
        
        // 週次選擇改變時：儲存設定並載入名單
        document.getElementById('weekSelector').addEventListener('change', function() {
            localStorage.setItem('checkin_week', this.value);
            const classId = document.getElementById('classSelector').value;
            if (classId) {
                loadStudentList(classId);
            }
        });

        document.getElementById('searchInput').addEventListener('keyup', function() {
            const filter = this.value.toUpperCase();
            const rows = document.getElementById('studentListBody').getElementsByTagName('tr');
            for (let i = 0; i < rows.length; i++) {
                if (rows[i].getElementsByTagName('td').length === 1) continue; 
                const txtValue = rows[i].textContent || rows[i].innerText;
                rows[i].style.display = txtValue.toUpperCase().indexOf(filter) > -1 ? "" : "none";
            }
        });

        const html5QrcodeScanner = new Html5QrcodeScanner("reader", { 
            fps: 10, 
            qrbox: { width: 250, height: 250 }, // 調整為正方形掃描框，符合 QR Code 比例
            formatsToSupport: [ Html5QrcodeSupportedFormats.QR_CODE ] // 鎖定辨識二維碼以提升速度
        }, false);
        let lastScannedCode = ''; let lastScanTime = 0;

        async function onScanSuccess(decodedText) {
            const classId = document.getElementById('classSelector').value;
            const weekNo = document.getElementById('weekSelector').value;
            const resultBox = document.getElementById('scanResult');
            const currentTime = new Date().getTime();
            
            if (!classId) {
                resultBox.style.display = "block"; resultBox.className = "alert alert-danger mt-3 text-center"; resultBox.innerText = "請先選擇操作班級"; return;
            }
            if (decodedText === lastScannedCode && (currentTime - lastScanTime) < 3000) return; 
            lastScannedCode = decodedText; lastScanTime = currentTime;

            resultBox.style.display = "block"; resultBox.className = "alert alert-info mt-3 text-center"; resultBox.innerText = "處理中...";

            try {
                const response = await fetch(`api_checkin.php?student_no=${encodeURIComponent(decodedText)}&week_no=${weekNo}`);
                const result = await response.json();
                
                // 👉 在收到後端回應後，馬上呼叫音效！
                playBeep(result.status); 

                if (result.status === 'success') {
                    resultBox.className = "alert alert-success mt-3 text-center"; 
                    resultBox.innerText = result.message; // 訊息中已經包含姓名了
                    loadStudentList(classId);
                } else {
                    resultBox.className = "alert alert-warning mt-3 text-center"; 
                    resultBox.innerText = result.message;
                }
            } catch (error) { 
                // 網路斷線等嚴重錯誤，也發出錯誤音效
                playBeep('error');
                resultBox.className = "alert alert-danger mt-3 text-center"; 
                resultBox.innerText = "系統連線異常"; 
            }
        }
        html5QrcodeScanner.render(onScanSuccess, function() {});

        // --- 音效產生器模組 ---
        const audioCtx = new (window.AudioContext || window.webkitAudioContext)();

        function playBeep(type) {
            // 確保瀏覽器允許播放音效
            if (audioCtx.state === 'suspended') {
                audioCtx.resume();
            }
            const oscillator = audioCtx.createOscillator();
            const gainNode = audioCtx.createGain();
            
            oscillator.connect(gainNode);
            gainNode.connect(audioCtx.destination);

            if (type === 'success') {
                // 成功音效：清脆的高音 (1000Hz, 持續 0.15秒)
                oscillator.type = 'sine';
                oscillator.frequency.setValueAtTime(1000, audioCtx.currentTime);
                gainNode.gain.setValueAtTime(0.5, audioCtx.currentTime);
                oscillator.start();
                oscillator.stop(audioCtx.currentTime + 0.15);
            } else {
                // 錯誤/警告音效：低沉的長音 (300Hz, 持續 0.4秒)
                oscillator.type = 'square';
                oscillator.frequency.setValueAtTime(300, audioCtx.currentTime);
                gainNode.gain.setValueAtTime(0.2, audioCtx.currentTime);
                oscillator.start();
                oscillator.stop(audioCtx.currentTime + 0.4);
            }
        }
        // ----------------------
    </script>
</body>
</html>
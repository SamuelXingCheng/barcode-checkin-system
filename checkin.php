<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>班級報到管理系統</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://unpkg.com/html5-qrcode"></script>
    <style>
        body { 
            background-color: #f4f6f9; 
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; 
        }
        .navbar-brand { font-weight: 600; letter-spacing: 1px; }
        .card { 
            border: none; 
            box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075); 
            margin-bottom: 1.5rem; 
        }
        .card-header { font-weight: 500; }
        .table-container { 
            max-height: 65vh; 
            overflow-y: auto; 
        }
        #reader { width: 100%; border: 1px solid #dee2e6; border-radius: 4px; }
        .status-badge { width: 60px; display: inline-block; text-align: center; }
    </style>
</head>
<body>

    <nav class="navbar navbar-dark bg-dark mb-4">
        <div class="container-fluid px-4">
            <span class="navbar-brand mb-0 h1">班級報到管理系統</span>
            <div>
                <span class="text-light me-3" id="systemTime"></span>
                <a href="#" class="btn btn-outline-light btn-sm">系統後台管理</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-4">
        <div class="row">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        條碼掃描模組
                    </div>
                    <div class="card-body">
                        <div class="mb-4">
                            <label for="classSelector" class="form-label text-muted">1. 請選擇操作班級</label>
                            <select id="classSelector" class="form-select form-select-lg">
                                <option value="">資料載入中...</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-muted">2. 條碼感應區</label>
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
                        <span>今日出勤名單</span>
                        <span class="badge bg-light text-dark fs-6">
                            已報到: <span id="attendCount">0</span> / 應到: <span id="totalCount">0</span>
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
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">請先於左側選擇班級以載入名單</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // 顯示即時系統時間
        function updateTime() {
            const now = new Date();
            document.getElementById('systemTime').innerText = now.toLocaleString('zh-TW', { hour12: false });
        }
        setInterval(updateTime, 1000);
        updateTime();

        // 頁面載入時，向後端 API 請求班級清單
        document.addEventListener('DOMContentLoaded', async function() {
            try {
                const response = await fetch('api_get_classes.php');
                const result = await response.json();
                const selector = document.getElementById('classSelector');
                
                selector.innerHTML = '<option value="">請選擇班級...</option>';
                
                if (result.status === 'success') {
                    result.data.forEach(cls => {
                        let option = document.createElement('option');
                        option.value = cls.id;
                        option.textContent = cls.class_name;
                        selector.appendChild(option);
                    });
                } else {
                    selector.innerHTML = '<option value="">載入失敗</option>';
                    alert(result.message);
                }
            } catch (error) {
                console.error('API 請求失敗:', error);
            }
        });

        // 載入班級學生名單的函數
        async function loadStudentList(classId) {
            const tbody = document.getElementById('studentListBody');
            
            try {
                const response = await fetch(`api_get_students.php?class_id=${classId}`);
                const result = await response.json();

                if (result.status === 'success') {
                    // 更新右上角統計數字
                    document.getElementById('attendCount').textContent = result.data.summary.attended;
                    document.getElementById('totalCount').textContent = result.data.summary.total;

                    // 若該班級無學生
                    if (result.data.students.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-4">該班級目前無學生資料</td></tr>';
                        return;
                    }

                    // 重新產生表格內容
                    let html = '';
                    result.data.students.forEach(student => {
                        const isAttended = parseInt(student.is_attended) === 1;
                        const badgeClass = isAttended ? 'bg-success' : 'bg-secondary';
                        const badgeText = isAttended ? '已到' : '未到';
                        const checkedAttr = isAttended ? 'checked' : '';

                        html += `
                            <tr>
                                <td><span class="badge ${badgeClass} status-badge">${badgeText}</span></td>
                                <td>${student.student_no}</td>
                                <td>${student.name}</td>
                                <td>
                                    <div class="form-check form-switch d-flex justify-content-center">
                                        <input class="form-check-input manual-check-toggle" type="checkbox" role="switch" 
                                               data-student-id="${student.student_id}" ${checkedAttr}>
                                    </div>
                                </td>
                            </tr>
                        `;
                    });
                    tbody.innerHTML = html;
                } else {
                    tbody.innerHTML = `<tr><td colspan="4" class="text-center text-danger py-4">載入失敗：${result.message}</td></tr>`;
                }
            } catch (error) {
                console.error('API 請求錯誤:', error);
                tbody.innerHTML = '<tr><td colspan="4" class="text-center text-danger py-4">系統連線異常，無法載入名單</td></tr>';
            }
        }

        // 班級選擇變更事件
        document.getElementById('classSelector').addEventListener('change', function() {
            const classId = this.value;
            if (classId) {
                document.getElementById('studentListBody').innerHTML = '<tr><td colspan="4" class="text-center text-muted py-4">名單載入中...</td></tr>';
                loadStudentList(classId);
            } else {
                document.getElementById('studentListBody').innerHTML = '<tr><td colspan="4" class="text-center text-muted py-4">請先於左側選擇班級以載入名單</td></tr>';
                document.getElementById('attendCount').textContent = '0';
                document.getElementById('totalCount').textContent = '0';
            }
        });

        // 名單搜尋過濾功能
        document.getElementById('searchInput').addEventListener('keyup', function() {
            const filter = this.value.toUpperCase();
            const rows = document.getElementById('studentListBody').getElementsByTagName('tr');
            
            for (let i = 0; i < rows.length; i++) {
                // 忽略提示用的空列
                if (rows[i].getElementsByTagName('td').length === 1) continue; 
                
                const tdId = rows[i].getElementsByTagName('td')[1];
                const tdName = rows[i].getElementsByTagName('td')[2];
                if (tdId || tdName) {
                    const txtValueId = tdId.textContent || tdId.innerText;
                    const txtValueName = tdName.textContent || tdName.innerText;
                    if (txtValueId.toUpperCase().indexOf(filter) > -1 || txtValueName.toUpperCase().indexOf(filter) > -1) {
                        rows[i].style.display = "";
                    } else {
                        rows[i].style.display = "none";
                    }
                }       
            }
        });

        // 初始化掃描器 (鎖定 Code 128 格式)
        const formatsToSupport = [ Html5QrcodeSupportedFormats.CODE_128 ];
        const html5QrcodeScanner = new Html5QrcodeScanner("reader", { 
            fps: 10, 
            qrbox: {width: 300, height: 100}, 
            formatsToSupport: formatsToSupport 
        }, false);

        // 掃描成功後的處理邏輯
        // 手動報到開關的事件監聽 (使用事件委派，以應對動態產生的表格)
        document.getElementById('studentListBody').addEventListener('change', async function(e) {
            if (e.target && e.target.classList.contains('manual-check-toggle')) {
                const studentId = e.target.getAttribute('data-student-id');
                const isChecked = e.target.checked;
                const action = isChecked ? 'checkin' : 'checkout';
                const classId = document.getElementById('classSelector').value;

                try {
                    const response = await fetch('api_manual_checkin.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({ student_id: studentId, action: action })
                    });
                    
                    const result = await response.json();
                    if (result.status === 'success') {
                        // 狀態更新成功後，重新載入該班級名單以刷新統計數據與標籤
                        loadStudentList(classId);
                    } else {
                        alert('操作失敗：' + result.message);
                        e.target.checked = !isChecked; // 失敗時將開關切回原狀態
                    }
                } catch (error) {
                    console.error('API 請求異常:', error);
                    alert('系統連線異常，請稍後再試。');
                    e.target.checked = !isChecked;
                }
            }
        });

        // 掃描冷卻時間控制變數，避免相機在同一秒內重複送出多次相同條碼
        let lastScannedCode = '';
        let lastScanTime = 0;

        // 掃描成功後的處理邏輯
        async function onScanSuccess(decodedText) {
            const classId = document.getElementById('classSelector').value;
            const resultBox = document.getElementById('scanResult');
            const currentTime = new Date().getTime();
            
            // 檢查是否選擇班級
            if (!classId) {
                resultBox.style.display = "block";
                resultBox.className = "alert alert-danger mt-3 text-center";
                resultBox.innerText = "系統提示：請先選擇操作班級";
                return;
            }

            // 防重複掃描機制 (同一個條碼在 3 秒內不重複送出)
            if (decodedText === lastScannedCode && (currentTime - lastScanTime) < 3000) {
                return; 
            }
            lastScannedCode = decodedText;
            lastScanTime = currentTime;

            resultBox.style.display = "block";
            resultBox.className = "alert alert-info mt-3 text-center";
            resultBox.innerText = "處理中: " + decodedText + " ...";

            try {
                // 呼叫我們先前寫好的報到 API
                const response = await fetch(`api_checkin.php?student_no=${encodeURIComponent(decodedText)}`);
                const result = await response.json();

                if (result.status === 'success') {
                    resultBox.className = "alert alert-success mt-3 text-center";
                    resultBox.innerText = "讀取成功: " + result.name + " 已報到";
                    // 重新載入右側名單以顯示最新狀態
                    loadStudentList(classId);
                } else if (result.status === 'warning') {
                    resultBox.className = "alert alert-warning mt-3 text-center";
                    resultBox.innerText = result.message;
                } else {
                    resultBox.className = "alert alert-danger mt-3 text-center";
                    resultBox.innerText = "錯誤: " + result.message;
                }
            } catch (error) {
                console.error('掃描報到異常:', error);
                resultBox.className = "alert alert-danger mt-3 text-center";
                resultBox.innerText = "系統連線異常，報到失敗。";
            }
        }

        html5QrcodeScanner.render(onScanSuccess, function(error) {
            // 背景錯誤忽略，不影響操作
        });
    </script>
</body>
</html>
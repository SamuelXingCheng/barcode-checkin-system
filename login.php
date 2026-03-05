<?php
session_start();

// 若已登入，直接導向報到頁面
if (isset($_SESSION['admin_id'])) {
    header("Location: checkin.php");
    exit;
}

$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once 'config.php';
    
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!empty($username) && !empty($password)) {
        try {
            $stmt = $pdo->prepare("SELECT id, password, role, class_id FROM admins WHERE username = :username LIMIT 1");
            $stmt->execute([':username' => $username]);
            $admin = $stmt->fetch();

            // 驗證密碼是否與資料庫中的雜湊值相符
            if ($admin && password_verify($password, $admin['password'])) {
                // 登入成功，將資訊寫入 Session
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['role'] = $admin['role'];
                $_SESSION['class_id'] = $admin['class_id'];
                
                header("Location: checkin.php");
                exit;
            } else {
                $error_msg = '帳號或密碼輸入錯誤。';
            }
        } catch (PDOException $e) {
            $error_msg = '系統連線異常，請稍後再試。';
        }
    } else {
        $error_msg = '請輸入帳號與密碼。';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>系統登入 | 班級報到管理系統</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; font-family: "Segoe UI", sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .login-card { width: 100%; max-width: 400px; border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .login-header { background-color: #212529; color: white; text-align: center; padding: 20px; border-top-left-radius: 0.375rem; border-top-right-radius: 0.375rem; }
    </style>
</head>
<body>

<div class="card login-card">
    <div class="login-header">
        <h4 class="mb-0">班級報到管理系統</h4>
    </div>
    <div class="card-body p-4">
        <?php if ($error_msg): ?>
            <div class="alert alert-danger" role="alert">
                <?php echo htmlspecialchars($error_msg); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <div class="mb-3">
                <label for="username" class="form-label text-muted">管理員帳號</label>
                <input type="text" class="form-control" id="username" name="username" required autofocus>
            </div>
            <div class="mb-4">
                <label for="password" class="form-label text-muted">登入密碼</label>
                <input type="password" class="form-control" id="password" name="password" required>
            </div>
            <div class="d-grid">
                <button type="submit" class="btn btn-primary btn-lg">登入系統</button>
            </div>
        </form>
    </div>
</div>

</body>
</html>
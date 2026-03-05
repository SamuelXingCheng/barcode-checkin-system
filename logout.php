<?php
session_start();
// 清除所有 Session 變數
$_SESSION = array();

// 銷毀 Session
session_destroy();

// 導回登入頁面
header("Location: login.php");
exit;
?>
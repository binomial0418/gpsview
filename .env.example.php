<?php
/**
 * GPS 軌跡系統設定檔範本
 * 複製此檔案為 .env.php 並填入實際的資料庫設定
 */

// ===== 資料庫設定 =====
$is_localhost = false;
if (isset($_SERVER['HTTP_HOST'])) {
    // 檢查網址是否包含 localhost 或 127.0.0.1
    if (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false || strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false) {
        $is_localhost = true;
    }
}

if ($is_localhost) {
    // Docker 本機環境 (對應 docker-compose.yml 設定)
    define('DB_HOST', 'db');             // Docker 內部服務名稱
    define('DB_USER', 'root');           // Docker Compose 設定的 root 密碼
    define('DB_PASS', 'root');
    define('DB_NAME', 'gps_view_db');    // Docker Compose 設定的資料庫名稱
} else {
    // 正式環境 (請填入實際資料庫設定)
    define('DB_HOST', 'your_db_host');
    define('DB_USER', 'your_db_user');
    define('DB_PASS', 'your_db_password');
    define('DB_NAME', 'your_db_name');
}

define('DB_TABLE', 'gpslog');

// ===== 登入認證 =====
// 帳密驗證資料表設定
define('AUTH_DB_TABLE', 'userinfo');      // 使用者資料表名稱
define('AUTH_DB_USER_COLUMN', 'id');      // 帳號欄位名稱
define('AUTH_DB_PASS_COLUMN', 'pass');    // 密碼欄位名稱（明碼儲存）

// 登入設定
define('AUTH_MAX_ATTEMPTS', 5);           // 最多失敗嘗試次數
define('AUTH_LOCKOUT_MINUTES', 15);       // 鎖定時間（分鐘）
define('AUTH_SESSION_TIMEOUT', 3600);     // Session 過期時間（秒，1小時）
define('AUTH_ENABLE_BRUTE_FORCE_PROTECTION', true);  // 啟用暴力破解防護

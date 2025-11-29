<?php
/**
 * GPS 軌跡系統設定檔範本
 * 複製此檔案為 .env.php 並填入實際的帳密與雜湊值
 * 
 * 密碼雜湊產生方法，請執行 generate_password_hash.php:
 * php generate_password_hash.php your_password
 */

// ===== 登入認證 =====
// 帳號列表（可設多組帳號）
define('AUTH_USERS', [
    'admin' => [
        'hash' => 'password_hash_here_from_generate_script',
        'enabled' => true,
        'description' => '管理者帳號'
    ]
]);

// 登入設定
define('AUTH_MAX_ATTEMPTS', 5);           // 最多失敗嘗試次數
define('AUTH_LOCKOUT_MINUTES', 15);       // 鎖定時間（分鐘）
define('AUTH_SESSION_TIMEOUT', 3600);     // Session 過期時間（秒，1小時）
define('AUTH_ENABLE_BRUTE_FORCE_PROTECTION', true);  // 啟用暴力破解防護

// ===== 資料庫設定 =====
define('DB_HOST', '10.0.4.123');
define('DB_USER', 'duckegg');
define('DB_PASS', 'Binomial04!8');
define('DB_NAME', 'duckegg');
define('DB_TABLE', 'gpslog');

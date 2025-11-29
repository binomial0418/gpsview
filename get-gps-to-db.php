<?php
// GPS 資料接收端點
// 使用範例：
// http://your-server/get-gps-to-db.php?lat=24.239082&lng=120.564593&spd=55.5&device_id=TucsonL&cog=40&satcnt=4&gpstime=063052.45

// 載入設定檔
if (!file_exists(__DIR__ . '/.env.php')) {
    http_response_code(500);
    echo "錯誤：缺少 .env.php 設定檔";
    exit;
}
require_once __DIR__ . '/.env.php';

// 1. 取得 GET 參數並做基本檢查
$lat = isset($_GET['lat']) ? floatval($_GET['lat']) : null;
$lng = isset($_GET['lng']) ? floatval($_GET['lng']) : null;
$spd = isset($_GET['spd']) ? floatval($_GET['spd']) : null;
$cog = isset($_GET['cog']) ? floatval($_GET['cog']) : null;
$gpstime_raw = isset($_GET['gpstime']) ? $_GET['gpstime'] : null;
$satcnt = isset($_GET['satcnt']) ? intval($_GET['satcnt']) : null;
$device_id = isset($_GET['device_id']) ? trim($_GET['device_id']) : null;

// 轉換 gpstime: 如果是 Unix timestamp 則轉換為 datetime 格式
$gpstime = null;
if ($gpstime_raw !== null) {
    // 判斷是否為 Unix timestamp (純數字且長度 10 位)
    if (is_numeric($gpstime_raw) && strlen($gpstime_raw) == 10) {
        $gpstime = date('Y-m-d H:i:s', intval($gpstime_raw));
    } else {
        $gpstime = $gpstime_raw; // 假設已是正確格式
    }
}

if ($lat === null || $lng === null || $device_id === null) {
    http_response_code(400);
    echo "錯誤：缺少 lat/lng/device_id";
    exit;
}

// 2. 建立資料庫連線
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// 3. 檢查連線
if ($conn->connect_error) {
    http_response_code(500);
    echo "資料庫連線失敗: " . $conn->connect_error;
    exit;
}

// 4. 防止 SQL Injection，使用 prepared statement
$stmt = $conn->prepare("INSERT INTO " . DB_TABLE . " (dev_id, lat, lng, spd, cog, satcnt, log_tim) VALUES (?, ?, ?, ?, ?, ?, ?)");
if (!$stmt) {
    http_response_code(500);
    echo "準備 SQL 失敗: " . $conn->error;
    $conn->close();
    exit;
}

$stmt->bind_param("sddddis", $device_id, $lat, $lng, $spd, $cog, $satcnt, $gpstime);

// 5. 執行插入
if ($stmt->execute()) {
    echo "1";  // 成功回應
} else {
    http_response_code(500);
    echo "寫入資料失敗: " . $stmt->error;
}

// 6. 釋放資源並關閉連線
$stmt->close();
$conn->close();
?>
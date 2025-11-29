<?php
// 啟用安全的 Session 設定
ini_set('session.cookie_httponly', 1);        // 防止 JS 存取 Session Cookie

// Secure flag 設定：開發環境為 HTTP 時應設為 0，生產環境 HTTPS 時設為 1
$isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
            (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
ini_set('session.cookie_secure', $isSecure ? 1 : 0);

// Lax 支援大多數跨頁面情境（含手機）
ini_set('session.cookie_samesite', 'Lax');

session_start();

// 載入設定檔
if (!file_exists(__DIR__ . '/.env.php')) {
    die('錯誤：缺少 .env.php 設定檔，請參考 .env.example.php 建立');
}
require_once __DIR__ . '/.env.php';

// ===== 登入相關常數（若 .env.php 已定義可略過） =====
if (!defined('AUTH_MAX_ATTEMPTS')) {
    define('AUTH_MAX_ATTEMPTS', 5);           // 最多失敗嘗試次數
}
if (!defined('AUTH_LOCKOUT_MINUTES')) {
    define('AUTH_LOCKOUT_MINUTES', 15);       // 鎖定時間（分鐘）
}
if (!defined('AUTH_SESSION_TIMEOUT')) {
    define('AUTH_SESSION_TIMEOUT', 3600);     // Session 過期時間（秒，1小時）
}
if (!defined('AUTH_ENABLE_BRUTE_FORCE_PROTECTION')) {
    define('AUTH_ENABLE_BRUTE_FORCE_PROTECTION', true);  // 啟用暴力破解防護
}

// 是否啟用 IP 驗證：行動網路 / Wi-Fi 切換會換 IP，預設關閉
$ENABLE_IP_VERIFICATION = false;

/* ============================================================
 *   共用函式：IP／防暴力登入
 * ============================================================ */
function getClientIp() {
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

function getLoginAttemptKey($username) {
    return 'login_attempts_' . md5($username . '_' . getClientIp());
}

function recordFailedAttempt($username) {
    $key = getLoginAttemptKey($username);
    $_SESSION[$key] = ($_SESSION[$key] ?? 0) + 1;
    $_SESSION[$key . '_time'] = time();
}

function isAccountLocked($username) {
    if (!defined('AUTH_ENABLE_BRUTE_FORCE_PROTECTION') || !AUTH_ENABLE_BRUTE_FORCE_PROTECTION) {
        return false;
    }
    $key = getLoginAttemptKey($username);
    $attempts = $_SESSION[$key] ?? 0;
    $lastAttemptTime = $_SESSION[$key . '_time'] ?? 0;
    
    if ($attempts >= AUTH_MAX_ATTEMPTS) {
        $lockoutDuration = AUTH_LOCKOUT_MINUTES * 60;
        if (time() - $lastAttemptTime < $lockoutDuration) {
            return true;
        } else {
            // 鎖定時間已過，重設
            unset($_SESSION[$key], $_SESSION[$key . '_time']);
            return false;
        }
    }
    return false;
}

function clearLoginAttempts($username) {
    $key = getLoginAttemptKey($username);
    unset($_SESSION[$key], $_SESSION[$key . '_time']);
}

/* ============================================================
 *   共用函式：資料庫連線（重點修正：支援 socket + TCP）
 * ============================================================ */
function create_db_connection() {
    $mysqli = mysqli_init();
    $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
    // 🔍 Synology MariaDB socket 列表（依實際安裝排序）
    $SOCKETS = [
        '/run/mysqld/mysqld10.sock',    // MariaDB 10 (最常見)
        // '/run/mysqld/mysqld.sock',
        // '/tmp/mysql.sock1',
        // '/var/run/mysqld/mysqld.sock',
        '/run/mariadb10/mysql.sock'
    ];

    // 🥇 優先嘗試 socket（不受反向代理影響）
    foreach ($SOCKETS as $sock) {
        if (file_exists($sock)) {
            $ok = @$mysqli->real_connect(
                'localhost',            // ⚠ 必須是 localhost 才會啟用 socket 模式
                DB_USER,
                DB_PASS,
                DB_NAME,
                null,
                $sock
            );
            if ($ok) return $mysqli;
            error_log("[DB] socket connect fail ($sock): " . $mysqli->connect_error);
        }
    }

    // 🥈 改走 TCP fallback（如果 socket 失敗仍能讀取）
    $host = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
    $ok = @$mysqli->real_connect(
        $host,
        DB_USER,
        DB_PASS,
        DB_NAME
    );
    if ($ok) return $mysqli;

    error_log("[DB] TCP connect fail ($host): " . $mysqli->connect_error);
    return null;
}

/* ============================================================
 *   登入 / 登出流程
 * ============================================================ */

// 處理登入請求
$loginError = '';
$accountLocked = false;

if (isset($_POST['username']) && isset($_POST['password'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    // 檢查帳號是否被鎖定
    if (isAccountLocked($username)) {
        $accountLocked = true;
        $loginError = '登入嘗試過多，帳號已被暫時鎖定 ' . AUTH_LOCKOUT_MINUTES . ' 分鐘';
    } elseif (empty($username) || empty($password)) {
        $loginError = '帳號和密碼不能為空';
    } elseif (!isset(AUTH_USERS[$username])) {
        recordFailedAttempt($username);
        $loginError = '帳號不存在或密碼錯誤';
    } elseif (!AUTH_USERS[$username]['enabled']) {
        recordFailedAttempt($username);
        $loginError = '帳號已停用';
    } elseif (!password_verify($password, AUTH_USERS[$username]['hash'])) {
        recordFailedAttempt($username);
        $loginError = '帳號不存在或密碼錯誤';
    } else {
        // 登入成功
        clearLoginAttempts($username);
        $_SESSION['authenticated'] = true;
        $_SESSION['username'] = $username;
        $_SESSION['login_time'] = time();
        // 僅在啟用 IP 檢查時才記錄 IP
        global $ENABLE_IP_VERIFICATION;
        if ($ENABLE_IP_VERIFICATION) {
            $_SESSION['ip_address'] = getClientIp();
        }
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// 處理登出請求
if (isset($_GET['logout'])) {
    session_destroy();
    session_start();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// 檢查登入狀態、IP 一致性和過期時間
$sessionExpired = false;
$ipMismatch = false;

if (isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true) {
    global $ENABLE_IP_VERIFICATION;

    // IP 變更檢查（防止 Session 劫持，但會影響行動用戶網路切換）
    if ($ENABLE_IP_VERIFICATION && isset($_SESSION['ip_address']) && $_SESSION['ip_address'] !== getClientIp()) {
        $ipMismatch = true;
        session_destroy();
        session_start();
    }
    // Session 過期檢查
    elseif (isset($_SESSION['login_time'])) {
        $elapsedTime = time() - $_SESSION['login_time'];
        if ($elapsedTime > AUTH_SESSION_TIMEOUT) {
            session_destroy();
            session_start();
            $sessionExpired = true;
        }
    }
}

// 若尚未登入 → 顯示登入頁
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    ?>
    <!DOCTYPE html>
    <html lang="zh-TW">
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>GPS 即時軌跡 - 登入</title>
    <style>
    * { box-sizing: border-box; }
    html, body { margin:0; padding:0; height:100%; width:100%; font-family:-apple-system,BlinkMacSystemFont,"Helvetica Neue",Helvetica,Arial,sans-serif; }
    body { display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
    .login-box { background: white; padding: 40px; border-radius: 15px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); width: 90%; max-width: 350px; }
    h1 { font-size: 24px; margin: 0 0 25px; text-align: center; color: #333; }
    .form-group { margin-bottom: 20px; }
    label { display: block; margin-bottom: 8px; color: #555; font-size: 14px; }
    input[type="text"], input[type="password"] { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 16px; }
    input[type="text"]:focus, input[type="password"]:focus { outline: none; border-color: #667eea; }
    .btn { width: 100%; padding: 12px; font-size: 16px; background: #667eea; color: white; border: none; border-radius: 8px; cursor: pointer; }
    .btn:hover { background: #5568d3; }
    .btn:disabled { background: #ccc; cursor: not-allowed; }
    .error { color: #e74c3c; font-size: 14px; margin-top: 10px; text-align: center; padding: 10px; background: #fee; border-radius: 4px; }
    .warning { color: #f39c12; font-size: 12px; margin-top: 8px; text-align: center; }
    </style>
    </head>
    <body>
    <div class="login-box">
        <h1>🔒 GPS 即時軌跡</h1>
        <form method="post">
            <div class="form-group">
                <label for="username">帳號</label>
                <input type="text" id="username" name="username" required autofocus <?php echo $accountLocked ? 'disabled' : ''; ?>>
            </div>
            <div class="form-group">
                <label for="password">密碼</label>
                <input type="password" id="password" name="password" required <?php echo $accountLocked ? 'disabled' : ''; ?>>
            </div>
            <button type="submit" class="btn" <?php echo $accountLocked ? 'disabled' : ''; ?>>登入</button>
            
            <?php if ($loginError): ?>
                <div class="error"><?php echo htmlspecialchars($loginError); ?></div>
            <?php endif; ?>
            
            <?php if (!empty($sessionExpired)): ?>
                <div class="error">登入已過期，請重新登入</div>
            <?php endif; ?>
            
            <?php if (!empty($ipMismatch)): ?>
                <div class="error">IP 位址變更，請重新登入</div>
            <?php endif; ?>
        </form>
    </div>
    </body>
    </html>
    <?php
    exit;
}

/* ============================================================
 *   主程式（已通過登入）
 * ============================================================ */
date_default_timezone_set('Asia/Taipei');

/* ============================================================
 *   AJAX (回傳 JSON)
 * ============================================================ */
if (isset($_GET['ajax'])) {
    $mysqli = create_db_connection();
    if (!$mysqli) {
        http_response_code(500);
        echo json_encode(['err' => 'DB 連線失敗']);
        exit;
    }

    // 判斷查詢條件
    $cond = "log_tim >= NOW() - INTERVAL 8 HOUR"; // 預設
    if (isset($_GET['hour'])) {
        $hour = intval($_GET['hour']);
        if ($hour > 0 && $hour <= 48) {
            $cond = "log_tim >= NOW() - INTERVAL $hour HOUR";
        }
    }
    if (isset($_GET['date'])) {
        $date = $_GET['date'];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $cond = "DATE(log_tim) = '" . $mysqli->real_escape_string($date) . "'";
        }
    }

    $sql = "SELECT dev_id, lat, lng, log_tim, spd, cog, satcnt
            FROM " . DB_TABLE . "
            WHERE $cond
            ORDER BY log_tim";
    $res = $mysqli->query($sql);

    if (!$res) {
        error_log('DB QUERY ERROR: ' . $mysqli->error . ' / SQL=' . $sql);
        http_response_code(500);
        echo json_encode(['err' => 'DB 查詢失敗']);
        exit;
    }

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }

    // 如果沒抓到資料，補抓最後一筆
    if (count($rows) === 0) {
        $sql2 = "SELECT dev_id, lat, lng, log_tim, spd, cog, satcnt
                 FROM " . DB_TABLE . "
                 ORDER BY log_tim DESC
                 LIMIT 1";
        $res2 = $mysqli->query($sql2);
        if ($res2) {
            while ($row2 = $res2->fetch_assoc()) {
                $rows[] = $row2;
            }
        }
    }

    echo json_encode([
        'totalCount' => count($rows),
        'gpsPoints'  => $rows
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>GPS 即時軌跡</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css"/>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet.awesome-markers@2.0.4/dist/leaflet.awesome-markers.css"/>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.2/css/all.min.css"/>
<style>
* { box-sizing: border-box; }
html, body { margin:0; padding:0; height:100%; width:100%; font-family:-apple-system,BlinkMacSystemFont,"Helvetica Neue",Helvetica,Arial,sans-serif; font-size: 16px; }
body { display: flex; flex-direction: column; }
.container { flex: 1; display: flex; flex-direction: column; padding:8px; overflow-y: auto; }
.controls { flex-shrink: 0; }
h1 { font-size:20px; margin:8px 0 10px; text-align:center; position: relative; padding: 0 10px; }
.header-right { display: flex; align-items: center; justify-content: center; gap: 6px; flex-wrap: wrap; margin-bottom: 8px; }
#info { font-size:16px; margin:10px 0 }
.btn { padding:10px 16px; font-size:16px; background:#007aff; color:#fff; border:none; border-radius:8px; cursor: pointer; touch-action: manipulation; min-height: 44px; }
.btn:active { background:#005dcf; }
.btn-logout { padding: 8px 12px; font-size: 14px; background: #e74c3c; min-height: 40px; }
.btn-logout:active { background: #c0392b; }
#map { flex: 1; width:100%; border:1px solid #ccc; border-radius:10px; margin-top:10px; min-height:250px; }
#trackList { background: #f8f9fa; border-radius: 8px; padding: 8px; margin-bottom: 8px; max-height: 200px; overflow-y: auto; }
#trackList h3 { margin: 0 0 8px 0; font-size: 15px; color: #333; }
.track-item { padding: 8px 12px; margin: 3px 0; background: white; border: 1px solid #ddd; border-radius: 6px; cursor: pointer; transition: all 0.2s; min-height: 40px; display: flex; align-items: center; font-size: 14px; line-height: 1.4; }
.track-item:hover { background: #e3f2fd; border-color: #2196F3; }
.track-item.active { background: #0066FF; color: white; border-color: #0066FF; font-weight: bold; }
.control-row { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 8px; }
.control-row label { display: flex; align-items: center; gap: 4px; font-size: 15px; white-space: nowrap; }
.control-row select, .control-row input[type="date"] { padding: 8px; font-size: 15px; border: 1px solid #ddd; border-radius: 6px; min-height: 40px; }
.control-row input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; }
.play-controls { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 8px; padding: 10px; background: #f5f5f5; border-radius: 8px; }
.play-controls .btn { padding: 10px 14px; font-size: 15px; min-height: 44px; }
.play-controls label { font-size: 14px; }
.play-controls label:has(#trackListToggle) { display: none; }
.play-progress-container { flex: 1; min-width: 100px; display: flex; align-items: center; gap: 8px; }
.play-progress-container input[type="range"] { flex: 1; height: 40px; }
.play-progress-container span { font-size: 14px; white-space: nowrap; }

@media (max-width: 640px) {
  .container { padding: 6px; }
  h1 { font-size: 18px; }
  .header-right span { font-size: 13px; }
  .btn { padding: 10px 14px; font-size: 15px; }
  .control-row { gap: 6px; }
  .control-row label { font-size: 14px; }
  .control-row select, .control-row input[type="date"] { font-size: 14px; padding: 6px; }
  #trackList { max-height: 160px; }
  .track-item { padding: 6px 10px; font-size: 13px; min-height: 36px; }
  .play-controls { padding: 8px; gap: 6px; }
  .play-controls .btn { padding: 8px 12px; font-size: 14px; min-height: 40px; }
  #map { min-height: 200px; }
}
</style>
</head>
<body>
<div class="container">
  <div class="controls">
    <h1>GPS 即時軌跡</h1>
    <div class="header-right">
      <span style="color: #666;">帳號: <?php echo htmlspecialchars($_SESSION['username'] ?? 'unknown'); ?></span>
      <a href="?logout" class="btn btn-logout" onclick="return confirm('確定要登出嗎？');">登出</a>
    </div>

    <div class="control-row">
      <label for="hourSelect">最近
        <select id="hourSelect">
          <option value="2">2小時</option>
          <option value="4">4小時</option>
          <option value="6">6小時</option>
          <option value="8" selected>8小時</option>
          <option value="12">12小時</option>
          <option value="16">16小時</option>
          <option value="24">24小時</option>
        </select>
      </label>

      <label for="datePicker">日期
        <input type="date" id="datePicker"/>
      </label>

      <div style="font-size: 15px; color: #666;">共 <span id="cnt">0</span>筆</div>
    </div>

    <!-- 第二行控制列 -->
    <div class="control-row">
      <label>
        <input type="checkbox" id="autoChk" checked> 自動更新
      </label>

      <label>
        <select id="autoSec">
          <option value="5">5秒</option>
          <option value="10" selected>10秒</option>
          <option value="15">15秒</option>
          <option value="30">30秒</option>
          <option value="60">60秒</option>
        </select>
      </label>

      <label style="display: none;">
      <input type="checkbox" id="smoothChk" unchecked> 平滑
      </label>

      <label style="display: none;">
        <select id="zoomInput">
          <option value="12">Z12</option>
          <option value="13">Z13</option>
          <option value="14">Z14</option>
          <option value="15" selected>Z15</option>
          <option value="16">Z16</option>
          <option value="17">Z17</option>
          <option value="18">Z18</option>
          <option value="19">Z19</option>
        </select>
      </label>

      <button class="btn" id="btnReload">🔄 重整</button>
      <button class="btn" id="btnCenter">📍 定位</button>
    </div>

    <!-- 軌跡播放控制列 -->
    <div class="play-controls">
      <label>
        <input type="checkbox" id="trackListToggle"> 軌跡清單
      </label>

      <button class="btn" id="btnPlay" style="background: #28a745;">▶</button>
      <button class="btn" id="btnPause" style="background: #ffc107; display: none;">⏸</button>
      <button class="btn" id="btnStop" style="background: #dc3545;">⏹</button>
      
      <label>
        <select id="playSpeed">
          <option value="0.5">0.5x</option>
          <option value="1" selected>1x</option>
          <option value="2">2x</option>
          <option value="5">5x</option>
          <option value="10">10x</option>
          <option value="20">20x</option>
        </select>
      </label>

      <div class="play-progress-container">
        <input type="range" id="playProgress" min="0" max="100" value="0">
        <span id="playPosition">0/0</span>
      </div>
    </div>

    <!-- 軌跡清單 -->
    <div id="trackList" style="display: none;">
      <h3>📍 軌跡清單</h3>
      <div id="trackListItems"></div>
    </div>
  </div>

  <div id="map"></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/leaflet.awesome-markers@2.0.4/dist/leaflet.awesome-markers.min.js"></script>

<script>
const cntSpan  = document.getElementById('cnt');
const btn      = document.getElementById('btnReload');
const selfUrl  = location.pathname;
const hourSel  = document.getElementById('hourSelect');
const dateSel  = document.getElementById('datePicker');

const map = L.map('map').setView([24.2, 120.6], 13);

// 取得下拉元素
const zoomSelect = document.getElementById('zoomInput');

// 地圖縮放時同步更新下拉選單
map.on('zoomend', function () {
  const currentZoom = map.getZoom();
  if ([...zoomSelect.options].some(opt => opt.value == currentZoom)) {
    zoomSelect.value = currentZoom;
  } else {
    zoomSelect.value = currentZoom;
  }

  // 更新車輛圖標大小
  if (lastCar && lastCarPosition) {
    map.removeLayer(lastCar);
    lastCar = L.marker(lastCarPosition, {icon: getCarIcon(currentZoom)})
      .bindTooltip(lastCar.getTooltip()._content, {sticky: true, direction: 'top', opacity: 0.9})
      .bindPopup(lastCar.getPopup()._content)
      .addTo(map);
  }
});

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
  attribution:'&copy; OpenStreetMap contributors'
}).addTo(map);   

let markers = [], tracks = [], lastCar;
let isFirstLoad = true; // 記錄是否為第一次載入
let lastCarPosition = null; // 儲存最後的車輛位置
let highlightedTrack = null; // 當前高亮的軌跡
let allSegments = []; // 儲存所有軌跡段資料，用於更新箭頭顏色
let enableSmoothing = false; // 是否啟用軌跡平滑化

// ===== 播放功能變數 =====
let playData = []; // 儲存所有軌跡點
let playIndex = 0; // 當前播放位置
let playTimer = null; // 播放計時器
let isPlaying = false; // 播放狀態
let playMarkers = []; // 播放時的標記點
let playTracks = []; // 播放時的軌跡線（多段）
let playCar = null; // 播放時的車輛圖標
let playSpeedLabel = null; // 播放時的速度標籤

// 根據方向角 cog 創建箭頭圖示（使用 Font Awesome 圖標）
function getArrowIcon(cog, color = 'red') {
  const rotation = (cog || 0) - 40;
  
  return L.divIcon({
    className: 'arrow-marker',
    html: `<div style="
      font-size: 16px;
      color: ${color};
      text-shadow: 0 0 3px white, 0 0 5px white;
      transform: rotate(${rotation}deg);
      display: flex;
      align-items: center;
      justify-content: center;
    ">
      <i class="fas fa-location-arrow"></i>
    </div>`,
    iconSize: [24, 24],
    iconAnchor: [12, 12]
  });
}

// 根據縮放層級動態產生車輛圖標
function getCarIcon(zoom) {
  const minZoom = 12, maxZoom = 19;
  const minSize = 60, maxSize = 90;
  const size = Math.round(minSize + (zoom - minZoom) * (maxSize - minSize) / (maxZoom - minZoom));
  const clampedSize = Math.max(minSize, Math.min(maxSize, size));
  
  return L.icon({
    iconUrl: 'tucsonl.png',
    iconSize: [clampedSize, clampedSize*0.85],
    iconAnchor: [clampedSize / 2, clampedSize / 2],
    popupAnchor: [0, -clampedSize / 2]
  });
}

// ===== 自動更新控制 =====
const autoChk = document.getElementById('autoChk');
const autoSec = document.getElementById('autoSec');

let timerId = null;
let inflight = false; // 避免請求重疊

// 包一層，避免 interval 還在跑時重複進來
function safeRefresh(){
  if (inflight) return;
  inflight = true;
  refresh();
}

// AJAX 取得資料
function refresh(){
  const hourVal = hourSel.value;
  const dateVal = dateSel.value;
  let query = dateVal ? ('date=' + encodeURIComponent(dateVal))
                      : ('hour=' + encodeURIComponent(hourVal));

  fetch(selfUrl + '?ajax=1&' + query + '&t=' + Date.now())
    .then(r => {
      if (!r.ok) {
        throw new Error('HTTP ' + r.status);
      }
      return r.json();
    })
    .then(draw)
    .catch(e => {
      console.error(e);
    })
    .finally(() => { inflight = false; });
}

// 啟停 Interval
function applyAuto(){
  const wantAuto = autoChk.checked && !dateSel.value;
  const ms = Math.max(1000, parseInt(autoSec.value,10)*1000);

  if (timerId){ clearInterval(timerId); timerId = null; }
  if (wantAuto){
    safeRefresh();
    timerId = setInterval(safeRefresh, ms);
  }
}

// 當頁籤隱藏時暫停，回來再恢復
document.addEventListener('visibilitychange', () => {
  if (document.hidden){
    if (timerId){ clearInterval(timerId); timerId = null; }
  } else {
    applyAuto();
  }
});

// 事件：勾選自動更新
autoChk.addEventListener('change', () => {
  applyAuto();
  safeRefresh();
});

// 事件：更改更新間隔
autoSec.addEventListener('change', () => {
  applyAuto();
  safeRefresh();
});

// 事件：更改縮放層級
document.getElementById('zoomInput').addEventListener('change', () => {
  safeRefresh();
});

// 事件：切換軌跡平滑
document.getElementById('smoothChk').addEventListener('change', function() {
  enableSmoothing = this.checked;
  safeRefresh();
});

// 事件：選日期就停自動、選小時就開自動
hourSel.addEventListener('change', () => { 
  dateSel.value=''; 
  applyAuto(); 
  safeRefresh();
});
dateSel.addEventListener('change', () => { 
  autoChk.checked = false; 
  applyAuto(); 
  safeRefresh();
});

// 重新整理按鈕
btn.onclick = () => safeRefresh();

// 初始啟動一次
safeRefresh();
applyAuto();

function fmtTime(s){
  if (!s) return '';
  if (/^\d{4}-\d{2}-\d{2}/.test(s)) return s.replace('T',' ').slice(0,19);
  const d = new Date(s);
  if (isNaN(d.getTime())) return String(s);
  const pad = n => (n<10?'0':'')+n;
  return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())} ` +
         `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}

// 更新指定軌跡段的所有箭頭顏色
function updateSegmentArrows(segmentIndex, color) {
  if (!allSegments[segmentIndex]) return;
  
  const segment = allSegments[segmentIndex];
  const segmentTimes = segment.map(p => p.log_tim);
  
  // 遍歷所有標記，找出屬於該軌跡段的箭頭並更新顏色
  markers.forEach(marker => {
    if (marker._pointData && segmentTimes.includes(marker._pointData.log_tim)) {
      const p = marker._pointData;
      if (p.cog !== null && p.cog !== undefined && p.cog !== '') {
        marker.setIcon(getArrowIcon(+p.cog, color));
      }
    }
  });
}

// 更新軌跡清單
function updateTrackList(trackSegments) {
  const trackListContainer = document.getElementById('trackList');
  const trackListItems = document.getElementById('trackListItems');
  const trackListToggle = document.getElementById('trackListToggle');
  
  if (!trackSegments || trackSegments.length === 0) {
    trackListContainer.style.display = 'none';
    trackListToggle.style.display = 'none';
    return;
  }
  
  if (trackSegments.length === 1) {
    // 只有一段軌跡，不顯示清單
    trackListContainer.style.display = 'none';
    trackListToggle.style.display = 'none';
    return;
  }
  
  // 顯示切換按鈕
  trackListToggle.parentElement.style.display = 'flex';
  
  // 根據勾選狀態決定是否顯示清單
  if (trackListToggle.checked) {
    trackListContainer.style.display = 'block';
  } else {
    trackListContainer.style.display = 'none';
  }
  
  trackListItems.innerHTML = '';
  
  trackSegments.forEach((segment, index) => {
    if (segment.length === 0) return;
    
    const startTime = fmtTime(segment[0].log_tim);
    const endTime = fmtTime(segment[segment.length - 1].log_tim);
    
    // 計算總距離
    let totalDistance = 0;
    for (let i = 1; i < segment.length; i++) {
      totalDistance += calcDistance(
        +segment[i-1].lat, +segment[i-1].lng,
        +segment[i].lat, +segment[i].lng
      );
    }
    
    const item = document.createElement('div');
    item.className = 'track-item';
    item.dataset.segmentIndex = index;
    item.innerHTML = `
      <strong>軌跡 ${index + 1}</strong> ${startTime.split(' ')[1]} - ${endTime.split(' ')[1]} · ${totalDistance.toFixed(2)} km · ${segment.length} 點
    `;
    
    item.addEventListener('click', function() {
      const segmentIdx = parseInt(this.dataset.segmentIndex);
      
      // 設定選中的軌跡段用於播放
      if (tracks[segmentIdx]) {
        highlightedTrack = tracks[segmentIdx];
      }
      
      // 更新清單選中狀態
      document.querySelectorAll('.track-item').forEach(i => i.classList.remove('active'));
      this.classList.add('active');
      
      // 定位到該軌跡
      const zoomLevel = parseInt(document.getElementById('zoomInput').value, 10) || 15;
      map.setView([+segment[0].lat, +segment[0].lng], zoomLevel);
    });
    
    trackListItems.appendChild(item);
  });
}

// 計算兩點之間的距離 (公里)
function calcDistance(lat1, lng1, lat2, lng2) {
  const R = 6371;
  const dLat = (lat2 - lat1) * Math.PI / 180;
  const dLng = (lng2 - lng1) * Math.PI / 180;
  const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
            Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
            Math.sin(dLng/2) * Math.sin(dLng/2);
  const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
  return R * c;
}

// 計算時速
function calcSpeed(lat1, lng1, time1, lat2, lng2, time2) {
  const dist = calcDistance(lat1, lng1, lat2, lng2);
  const t1 = new Date(time1).getTime();
  const t2 = new Date(time2).getTime();
  const hours = (t2 - t1) / (1000 * 60 * 60);
  if (hours <= 0) return 0;
  return dist / hours;
}

// 過濾異常 GPS 點（速度過快或距離過遠）
function filterOutliers(points, maxSpeed = 200, maxDistance = 1) {
  if (points.length <= 1) return points;
  
  const filtered = [points[0]];
  
  for (let i = 1; i < points.length; i++) {
    const prev = filtered[filtered.length - 1];
    const curr = points[i];
    
    const distance = calcDistance(+prev.lat, +prev.lng, +curr.lat, +curr.lng);
    const speed = calcSpeed(
      +prev.lat, +prev.lng, prev.log_tim,
      +curr.lat, +curr.lng, curr.log_tim
    );
    
    // 如果距離和速度都在合理範圍內，才加入
    if (distance <= maxDistance && speed <= maxSpeed) {
      filtered.push(curr);
    }
  }
  
  return filtered;
}

// 使用移動平均平滑座標
function smoothCoordinates(points, windowSize = 3) {
  if (points.length <= windowSize) return points;
  
  const smoothed = [];
  
  for (let i = 0; i < points.length; i++) {
    if (i < Math.floor(windowSize / 2) || i >= points.length - Math.floor(windowSize / 2)) {
      // 邊界點保持原樣
      smoothed.push(points[i]);
    } else {
      // 計算移動平均
      let sumLat = 0, sumLng = 0;
      for (let j = i - Math.floor(windowSize / 2); j <= i + Math.floor(windowSize / 2); j++) {
        sumLat += +points[j].lat;
        sumLng += +points[j].lng;
      }
      smoothed.push({
        ...points[i],
        lat: sumLat / windowSize,
        lng: sumLng / windowSize
      });
    }
  }
  
  return smoothed;
}

// 根據時間間隔分割軌跡（超過指定分鐘數視為不同軌跡）
function splitTracksByTimeGap(points, gapMinutes = 20) {
  if (points.length <= 1) return [points];
  
  const tracks = [];
  let currentTrack = [points[0]];
  
  for (let i = 1; i < points.length; i++) {
    const prevTime = new Date(points[i - 1].log_tim).getTime();
    const currTime = new Date(points[i].log_tim).getTime();
    const gapMinutesActual = (currTime - prevTime) / (1000 * 60);
    
    if (gapMinutesActual > gapMinutes) {
      // 時間間隔超過閾值，開始新軌跡
      tracks.push(currentTrack);
      currentTrack = [points[i]];
    } else {
      currentTrack.push(points[i]);
    }
  }
  
  // 加入最後一段軌跡
  if (currentTrack.length > 0) {
    tracks.push(currentTrack);
  }
  
  return tracks;
}

function draw(data){
  const zoomLevel = parseInt(document.getElementById('zoomInput').value, 10) || 15;

  cntSpan.textContent = data.totalCount ?? 0;

  // 清圖層
  markers.forEach(m => map.removeLayer(m)); markers = [];
  tracks.forEach(t => map.removeLayer(t)); tracks = [];
  if (lastCar) { map.removeLayer(lastCar); lastCar = null; }
  
  // 重新整理時清除高亮狀態
  highlightedTrack = null;
  allSegments = []; // 清空軌跡段資料

  let pts = data.gpsPoints || [];
  if (pts.length === 0) return;

  // 根據設定決定是否平滑處理
  if (enableSmoothing) {
    // 過濾異常點並平滑處理
    pts = filterOutliers(pts);
    if (pts.length >= 5) {
      pts = smoothCoordinates(pts, 1);
    }
  }

  // 根據時間間隔分割軌跡
  const trackSegments = splitTracksByTimeGap(pts, 20);
  allSegments = trackSegments; // 儲存所有軌跡段
  
  // 不再繪製軌跡線
  trackSegments.forEach((segment, segmentIndex) => {
      const latlngs = segment.map(p => [+p.lat, +p.lng]);
    if (latlngs.length >= 2) {
      // 軌跡線已移除，保留段落資訊用於清單顯示
      const track = { _segmentIndex: segmentIndex, _segmentData: segment };
      
      // 計算軌跡段的時間範圍和距離（用於清單顯示）
      const startTime = fmtTime(segment[0].log_tim);
      const endTime = fmtTime(segment[segment.length - 1].log_tim);
      let totalDistance = 0;
      for (let i = 1; i < segment.length; i++) {
        totalDistance += calcDistance(
          +segment[i-1].lat, +segment[i-1].lng,
          +segment[i].lat, +segment[i].lng
        );
      }
      
      // 軌跡線已移除，不需要事件處理
      tracks.push(track);
    }
  });
  
  // 更新軌跡清單
  updateTrackList(trackSegments);

  pts.slice(0, -1).forEach((p, idx) => {
    const t = fmtTime(p.log_tim);
    let speedInfo = '';
    let speed = 0;
    
    if (p.spd && +p.spd > 0) {
      speed = +p.spd;
      speedInfo = `<br>時速: ${speed.toFixed(1)} km/h`;
    } else if (idx > 0) {
      const prevP = pts[idx - 1];
      speed = calcSpeed(
        +prevP.lat, +prevP.lng, prevP.log_tim,
        +p.lat, +p.lng, p.log_tim
      );
      speedInfo = `<br>時速: ${speed.toFixed(1)} km/h`;
    }
    
    const satInfo = p.satcnt ? `<br>GPS: ${p.satcnt}` : '';
    
    let m;
    if (p.cog !== null && p.cog !== undefined && p.cog !== '') {
      m = L.marker([+p.lat, +p.lng], {icon: getArrowIcon(+p.cog, 'red')})
        .bindTooltip(t + (speedInfo ? `\n時速: ${speed.toFixed(1)} km/h` : '') + (satInfo ? `\nGPS: ${p.satcnt}` : ''), {sticky: true, direction: 'top', opacity: 0.9})
        .bindPopup(`<div style="font-family:monospace">${t}${speedInfo}${satInfo}</div>`)
        .addTo(map);
    } else {
      // 改為空心圓
      m = L.circleMarker([+p.lat, +p.lng], {
        radius: 5, color: 'red', fillColor: 'white', fillOpacity: 1, weight: 2
      })
      .bindTooltip(t + (speedInfo ? `\n時速: ${speed.toFixed(1)} km/h` : '') + (satInfo ? `\nGPS: ${p.satcnt}` : ''), {sticky: true, direction: 'top', opacity: 0.9})
      .bindPopup(`<div style="font-family:monospace">${t}${speedInfo}${satInfo}</div>`)
      .addTo(map);
    }
    
    // 儲存標記所屬的原始點資料（用於辨識所屬軌跡段）
    m._pointData = p;
    markers.push(m);
  });

  const lastP  = pts[pts.length - 1];
  const lastLL = [+lastP.lat, +lastP.lng];
  const tLast  = fmtTime(lastP.log_tim);
  
  let lastSpeedInfo = '';
  let lastSpeed = 0;
  if (lastP.spd && +lastP.spd > 0) {
    lastSpeed = +lastP.spd;
    lastSpeedInfo = `<br>時速: ${lastSpeed.toFixed(1)} km/h`;
  } else if (pts.length >= 2) {
    const prevP = pts[pts.length - 2];
    lastSpeed = calcSpeed(
      +prevP.lat, +prevP.lng, prevP.log_tim,
      +lastP.lat, +lastP.lng, lastP.log_tim
    );
    lastSpeedInfo = `<br>時速: ${lastSpeed.toFixed(1)} km/h`;
  }
  
  const lastSatInfo = lastP.satcnt ? `<br>GPS: ${lastP.satcnt}` : '';
  
  if (!isPlaying) {
    const currentZoom = map.getZoom();
    lastCar = L.marker(lastLL, {icon: getCarIcon(currentZoom)})
      .bindTooltip(tLast + (lastSpeedInfo ? `\n時速: ${lastSpeed.toFixed(1)} km/h` : '') + (lastSatInfo ? `\nGPS: ${lastP.satcnt}` : ''), {sticky: true, direction: 'top', opacity: 0.9})
      .bindPopup(`<div style="font-family:monospace">${tLast}${lastSpeedInfo}${lastSatInfo}</div>`)
      .addTo(map);
  }

  lastCarPosition = lastLL;

  if (isFirstLoad) {
    map.setView(lastLL, zoomLevel);
    isFirstLoad = false;
  }

  // 同步播放資料
  playData = pts;
}

// 定位到車輛按鈕
document.getElementById('btnCenter').onclick = function() {
  if (lastCarPosition) {
    const zoomLevel = parseInt(document.getElementById('zoomInput').value, 10) || 15;
    map.setView(lastCarPosition, zoomLevel);
  }
};

// ===== 播放功能實作 =====
const btnPlay = document.getElementById('btnPlay');
const btnPause = document.getElementById('btnPause');
const btnStop = document.getElementById('btnStop');
const playSpeed = document.getElementById('playSpeed');
const playProgress = document.getElementById('playProgress');
const playPosition = document.getElementById('playPosition');

// 開始播放
function startPlay() {
  // 如果有高亮軌跡，只播放該軌跡段
  if (highlightedTrack && highlightedTrack._segmentData) {
    playData = highlightedTrack._segmentData;
  }
  
  if (playData.length === 0) {
    alert('沒有軌跡資料可以播放');
    return;
  }
  
  isPlaying = true;
  btnPlay.style.display = 'none';
  btnPause.style.display = 'inline-block';
  
  // 關閉自動更新
  if (timerId) {
    clearInterval(timerId);
    timerId = null;
  }
  autoChk.checked = false;
  
  // 隱藏原始標記
  if (lastCar) {
    map.removeLayer(lastCar);
    lastCar = null;
  }
  markers.forEach(m => map.removeLayer(m));
  markers = [];
  tracks.forEach(t => map.removeLayer(t));
  tracks = [];
  
  playStep();
}

// 暫停播放
function pausePlay() {
  isPlaying = false;
  btnPlay.style.display = 'inline-block';
  btnPause.style.display = 'none';
  
  if (playTimer) {
    clearTimeout(playTimer);
    playTimer = null;
  }
}

// 停止播放
function stopPlay() {
  isPlaying = false;
  playIndex = 0;
  btnPlay.style.display = 'inline-block';
  btnPause.style.display = 'none';
  
  if (playTimer) {
    clearTimeout(playTimer);
    playTimer = null;
  }
  
  // 清除播放圖層
  playMarkers.forEach(m => map.removeLayer(m));
  playMarkers = [];
  playTracks.forEach(t => map.removeLayer(t));
  playTracks = [];
  if (playCar) {
    map.removeLayer(playCar);
    playCar = null;
  }
  if (playSpeedLabel) {
    map.removeLayer(playSpeedLabel);
    playSpeedLabel = null;
  }
  
  // 恢復原始顯示（重新整理以顯示完整資料）
  safeRefresh();
  
  updatePlayUI();
}

// 播放單步
function playStep() {
  if (!isPlaying || playIndex >= playData.length) {
    if (playIndex >= playData.length) {
      stopPlay();
    }
    return;
  }
  
  const currentPoint = playData[playIndex];
  const currentLL = [+currentPoint.lat, +currentPoint.lng];
  
  const passedPoints = playData.slice(0, playIndex + 1);
  
  playMarkers.forEach(m => map.removeLayer(m));
  playMarkers = [];
  playTracks.forEach(t => map.removeLayer(t));
  playTracks = [];
  if (playCar) map.removeLayer(playCar);
  if (playSpeedLabel) map.removeLayer(playSpeedLabel);
  
  // 不再繪製播放軌跡線
  
  passedPoints.slice(0, -1).forEach((p, idx) => {
    let m;
    if (p.cog !== null && p.cog !== undefined && p.cog !== '') {
      const blueArrowIcon = L.divIcon({
        className: 'arrow-marker',
        html: `<div style="
          font-size: 16px;
          color: #0066cc;
          text-shadow: 0 0 4px white, 0 0 8px white, 0 0 3px rgba(255,255,255,0.8);
          transform: rotate(${(+p.cog - 40)}deg);
          display: flex;
          align-items: center;
          justify-content: center;
          filter: drop-shadow(0 0 2px white);
        ">
          <i class="fas fa-location-arrow"></i>
        </div>`,
        iconSize: [20, 20],
        iconAnchor: [10, 10]
      });
      m = L.marker([+p.lat, +p.lng], {icon: blueArrowIcon}).addTo(map);
    } else {
      // 播放模式也改為空心圓
      m = L.circleMarker([+p.lat, +p.lng], {
        radius: 5,
        color: '#0066cc',
        fillColor: 'white',
        fillOpacity: 1,
        weight: 2
      }).addTo(map);
    }
    playMarkers.push(m);
  });
  
  const currentZoom = map.getZoom();
  const tCurrent = fmtTime(currentPoint.log_tim);
  
  let speedText = '';
  let currentSpeed = 0;
  if (currentPoint.spd && +currentPoint.spd > 0) {
    currentSpeed = +currentPoint.spd;
    speedText = `${currentSpeed.toFixed(1)} km/h`;
  } else if (playIndex > 0) {
    const prevP = playData[playIndex - 1];
    currentSpeed = calcSpeed(
      +prevP.lat, +prevP.lng, prevP.log_tim,
      +currentPoint.lat, +currentPoint.lng, currentPoint.log_tim
    );
    speedText = `${currentSpeed.toFixed(1)} km/h`;
  }
  
  const satText = currentPoint.satcnt ? `GPS: ${currentPoint.satcnt}` : '';
  const tooltipText = tCurrent + (speedText ? `\n時速: ${speedText}` : '') + (satText ? `\n${satText}` : '');
  
  playCar = L.marker(currentLL, {icon: getCarIcon(currentZoom)})
    .bindTooltip(tooltipText, {sticky: true, direction: 'top', opacity: 0.9})
    .addTo(map);
  
  let labelText = speedText;
  if (satText) {
    labelText = speedText ? `${speedText} | ${satText}` : satText;
  }
  if (labelText) {
    const speedIcon = L.divIcon({
      className: 'speed-label',
      html: `<div style="background: rgba(40, 167, 69, 0.9); color: white; padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 14px; white-space: nowrap; box-shadow: 0 2px 4px rgba(0,0,0,0.3);">${labelText}</div>`,
      iconSize: [140, 30],
      iconAnchor: [-20, 15]
    });
    playSpeedLabel = L.marker(currentLL, {icon: speedIcon}).addTo(map);
  }
  
  map.setView(currentLL, currentZoom);
  
  updatePlayUI();
  
  playIndex++;
  
  const playSpeedMultiplier = parseFloat(playSpeed.value);
  const baseDelay = 500;
  const delay = baseDelay / playSpeedMultiplier;
  
  playTimer = setTimeout(playStep, delay);
}

// 更新播放 UI
function updatePlayUI() {
  playPosition.textContent = `${playIndex}/${playData.length}`;
  playProgress.max = Math.max(0, playData.length - 1);
  playProgress.value = playIndex;
}

// 進度條拖動
playProgress.addEventListener('input', function() {
  playIndex = parseInt(this.value);
  if (isPlaying) {
    if (playTimer) clearTimeout(playTimer);
    playStep();
  } else {
    if (playData.length > 0 && playIndex < playData.length) {
      const currentPoint = playData[playIndex];
      const currentLL = [+currentPoint.lat, +currentPoint.lng];
      const currentZoom = map.getZoom();
      
      playMarkers.forEach(m => map.removeLayer(m));
      playMarkers = [];
      playTracks.forEach(t => map.removeLayer(t));
      playTracks = [];
      if (playCar) map.removeLayer(playCar);
      if (playSpeedLabel) map.removeLayer(playSpeedLabel);
      
      const passedPoints = playData.slice(0, playIndex + 1);
      
      // 不再繪製進度條拖動時的軌跡線
      
      let speedText = '';
      if (currentPoint.spd && +currentPoint.spd > 0) {
        speedText = `${(+currentPoint.spd).toFixed(1)} km/h`;
      } else if (playIndex > 0) {
        const prevP = playData[playIndex - 1];
        const speed = calcSpeed(
          +prevP.lat, +prevP.lng, prevP.log_tim,
          +currentPoint.lat, +currentPoint.lng, currentPoint.log_tim
        );
        speedText = `${speed.toFixed(1)} km/h`;
      }
      
      const satText = currentPoint.satcnt ? `GPS: ${currentPoint.satcnt}` : '';
      const tooltipText = fmtTime(currentPoint.log_tim) + (speedText ? `\n時速: ${speedText}` : '') + (satText ? `\n${satText}` : '');
      
      playCar = L.marker(currentLL, {icon: getCarIcon(currentZoom)})
        .bindTooltip(tooltipText, {sticky: true, direction: 'top', opacity: 0.9})
        .addTo(map);
      
      let labelText = speedText;
      if (satText) {
        labelText = speedText ? `${speedText} | ${satText}` : satText;
      }
      if (labelText) {
        const speedIcon = L.divIcon({
          className: 'speed-label',
          html: `<div style="background: rgba(40, 167, 69, 0.9); color: white; padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 14px; white-space: nowrap; box-shadow: 0 2px 4px rgba(0,0,0,0.3);">${labelText}</div>`,
          iconSize: [140, 30],
          iconAnchor: [-20, 15]
        });
        playSpeedLabel = L.marker(currentLL, {icon: speedIcon}).addTo(map);
      }
      
      map.setView(currentLL, currentZoom);
    }
    updatePlayUI();
  }
});

btnPlay.onclick = function() {
  if (playIndex === 0 || playIndex >= playData.length) {
    playIndex = 0;
    
    // 如果有高亮軌跡，只播放該軌跡段
    if (highlightedTrack && highlightedTrack._segmentData) {
      playData = highlightedTrack._segmentData;
    }
    // 否則使用全部軌跡資料（已在 draw() 中同步到 playData）
    
    updatePlayUI();
    startPlay();
  } else {
    startPlay();
  }
};

btnPause.onclick = pausePlay;
btnStop.onclick = stopPlay;

// 軌跡清單切換事件
document.getElementById('trackListToggle').addEventListener('change', function() {
  const trackListContainer = document.getElementById('trackList');
  if (this.checked) {
    trackListContainer.style.display = 'block';
  } else {
    trackListContainer.style.display = 'none';
  }
});
</script>
</body>
</html>
<?php
/**
 * GPS 軌跡系統 - V2
 */

// 1. 安全與 Session 設定
ini_set('session.cookie_httponly', 1);
$isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
            (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
ini_set('session.cookie_secure', $isSecure ? 1 : 0);
ini_set('session.cookie_samesite', 'Lax');
session_start();

// 2. 載入設定檔
if (!file_exists(__DIR__ . '/.env.php')) {
    die('錯誤：缺少 .env.php 設定檔');
}
require_once __DIR__ . '/.env.php';

// 3. 處理登出
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// 4. 處理登入 POST
$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'], $_POST['password'])) {
    $u = trim($_POST['username']);
    $p = $_POST['password'];
    
    if (isset(AUTH_USERS[$u]) && AUTH_USERS[$u]['enabled'] && password_verify($p, AUTH_USERS[$u]['hash'])) {
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        $_SESSION['username'] = $u;
        $_SESSION['login_time'] = time();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } else {
        $loginError = '帳號或密碼錯誤';
    }
}

$isLoggedIn = (isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true);

/* ============================================================
 * 後端 API 處理
 * ============================================================ */
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    if (!$isLoggedIn) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    
    $mysqli = mysqli_init();
    $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
    $sockets = [
        '/run/mysqld/mysqld10.sock', '/run/mysqld/mysqld.sock', 
        '/tmp/mysql.sock', '/var/run/mysqld/mysqld.sock', '/run/mariadb10/mysql.sock'
    ];
    $connected = false;
    foreach ($sockets as $sock) {
        if (file_exists($sock)) {
            if (@$mysqli->real_connect('localhost', DB_USER, DB_PASS, DB_NAME, null, $sock)) {
                $connected = true; break;
            }
        }
    }
    if (!$connected) {
        $host = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
        if (!$mysqli->real_connect($host, DB_USER, DB_PASS, DB_NAME)) {
            echo json_encode(['error' => 'DB Connection Failed']); exit;
        }
    }

    $action = $_GET['action'] ?? '';
    $rows = [];

    if ($action === 'realtime_init') {
        $sql = "SELECT dev_id, lat, lng, log_tim, spd, cog, satcnt 
                FROM " . DB_TABLE . " 
                WHERE log_tim >= NOW() - INTERVAL 2 HOUR 
                ORDER BY log_tim ASC";
        $res = $mysqli->query($sql);
        while ($row = $res->fetch_assoc()) $rows[] = $row;

        if (empty($rows)) {
            $sql = "SELECT dev_id, lat, lng, log_tim, spd, cog, satcnt 
                    FROM " . DB_TABLE . " 
                    ORDER BY log_tim DESC 
                    LIMIT 1";
            $res = $mysqli->query($sql);
            while ($row = $res->fetch_assoc()) $rows[] = $row;
        }

    } elseif ($action === 'realtime_update') {
        $lastTime = $_GET['last_time'] ?? '';
        if ($lastTime) {
            $lastTime = $mysqli->real_escape_string($lastTime);
            $sql = "SELECT dev_id, lat, lng, log_tim, spd, cog, satcnt 
                    FROM " . DB_TABLE . " 
                    WHERE log_tim > '$lastTime' 
                    ORDER BY log_tim ASC";
            $res = $mysqli->query($sql);
            while ($row = $res->fetch_assoc()) $rows[] = $row;
        }

    } elseif ($action === 'history') {
        $date = $_GET['date'] ?? '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = $mysqli->real_escape_string($date);
            $sql = "SELECT dev_id, lat, lng, log_tim, spd, cog, satcnt 
                    FROM " . DB_TABLE . " 
                    WHERE DATE(log_tim) = '$date' 
                    ORDER BY log_tim ASC";
            $res = $mysqli->query($sql);
            while ($row = $res->fetch_assoc()) $rows[] = $row;
        }
    }

    echo json_encode(['data' => $rows]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>GPS 智慧軌跡系統</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>
<style>
    :root {
        --primary-color: #007aff;
        --accent-color: #34c759;
        --bg-color: #f2f2f7;
        --text-color: #1c1c1e;
        --border-color: #e5e5ea;
    }
    
    body { margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: var(--bg-color); color: var(--text-color); height: 100vh; display: flex; flex-direction: column; overflow: hidden; }

    /* Login Overlay */
    #login-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255, 255, 255, 0.6); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); z-index: 9999; display: flex; justify-content: center; align-items: center; }
    .login-box { background: white; width: 90%; max-width: 320px; padding: 30px; border-radius: 20px; box-shadow: 0 10px 40px rgba(0,0,0,0.15); text-align: center; }
    .login-box h2 { margin: 0 0 20px; color: #333; font-size: 20px; }
    .login-input { width: 100%; padding: 12px; margin-bottom: 12px; border: 1px solid #ddd; border-radius: 10px; font-size: 16px; box-sizing: border-box; background: #f9f9f9; }
    .login-input:focus { outline: none; border-color: var(--primary-color); background: #fff; }
    .login-btn { width: 100%; padding: 12px; background: var(--primary-color); color: white; border: none; border-radius: 10px; font-size: 16px; font-weight: bold; cursor: pointer; margin-top: 10px; }
    .login-btn:active { opacity: 0.9; }
    .error-msg { color: #ff3b30; font-size: 14px; margin-top: 10px; }
    
    /* Main Content */
    #app-content { display: flex; flex-direction: column; height: 100%; width: 100%; transition: filter 0.3s; }
    .blurred { filter: blur(8px); pointer-events: none; user-select: none; }
    
    /* Layout */
    header { background: #fff; padding: 0 12px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); height: 44px; flex-shrink: 0; z-index: 1001; }
    header h1 { font-size: 15px; margin: 0; font-weight: 600; display: flex; align-items: center; gap: 6px; }
    .user-info { font-size: 12px; color: #666; display: flex; align-items: center; gap: 8px; }
    .btn-logout { background: #f2f2f7; color: #ff3b30; padding: 4px 8px; border-radius: 6px; text-decoration: none; font-weight: 500; font-size: 11px; border: none; cursor: pointer; }

    #top-panel { background: #fff; border-bottom: 1px solid #ccc; box-shadow: 0 2px 10px rgba(0,0,0,0.05); z-index: 1002; display: flex; flex-direction: column; flex-shrink: 0; }
    .panel-content { padding: 10px 12px; }

    .status-bar { background: #f9f9f9; color: #333; padding: 6px 10px; border-radius: 8px; font-size: 12px; font-family: monospace; font-weight: 600; text-align: center; display: flex; justify-content: space-around; align-items: center; border: 1px solid var(--border-color); margin-bottom: 8px; cursor: pointer; transition: background 0.2s; }
    .status-bar:active { background: #eef7ff; }
    .status-bar i { color: var(--primary-color); margin-right: 2px; }

    .mode-tabs { display: flex; background: #e5e5ea; border-radius: 8px; padding: 2px; margin-bottom: 8px; }
    .tab-btn { flex: 1; padding: 6px 0; border: none; background: transparent; font-size: 13px; font-weight: 500; color: #666; cursor: pointer; border-radius: 6px; transition: all 0.2s; white-space: nowrap; text-align: center; }
    .tab-btn.active { background: #fff; color: var(--primary-color); box-shadow: 0 1px 2px rgba(0,0,0,0.1); }

    select, input[type="date"] { padding: 0 8px; border: 1px solid #c6c6c8; border-radius: 6px; background: #fff; font-size: 13px; -webkit-appearance: none; height: 32px;}
    .btn { padding: 0 12px; height: 32px; border: none; border-radius: 6px; font-size: 13px; font-weight: 500; cursor: pointer; color: white; background: var(--primary-color); display: inline-flex; align-items: center; justify-content: center; gap: 4px; }
    
    .toggle-lock { cursor: pointer; font-size: 16px; color: #999; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 6px; background: #f2f2f7; border: 1px solid transparent; }
    input:checked + .toggle-lock { color: var(--primary-color); background: #eef7ff; border-color: #cce4ff; }

    #segmentPanel { margin-top: 6px; max-height: 180px; overflow-y: auto; border-top: 1px solid #eee; padding-top: 6px; }
    .segment-item { padding: 8px 10px; margin-bottom: 4px; background: #fff; border-radius: 6px; font-size: 12px; cursor: pointer; border: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; }
    .segment-item:hover { background: #f9f9f9; }
    .segment-item.active { border-color: var(--primary-color); background: #f0f7ff; color: var(--primary-color); font-weight: 600; }

    #player-card { display: flex; flex-direction: column; gap: 6px; }
    .player-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
    .btn-icon { background: #f2f2f7; border: none; width: 32px; height: 32px; border-radius: 50%; color: #333; display: flex; align-items: center; justify-content: center; cursor: pointer; }
    .btn-control { width: 38px; height: 38px; border-radius: 50%; border: none; display: flex; align-items: center; justify-content: center; font-size: 14px; color: white; background: var(--primary-color); cursor: pointer; transition: transform 0.1s; }
    .btn-control:active { transform: scale(0.95); }
    .btn-control.stop { background: #ff3b30; width: 32px; height: 32px; font-size: 12px; }
    .btn-control.playing { background: #ffcc00; color: #000; } 
    .slider-container { display: flex; align-items: center; gap: 8px; width: 100%; }
    input[type=range] { flex: 1; height: 4px; border-radius: 2px; background: #d1d1d6; outline: none; -webkit-appearance: none; }
    input[type=range]::-webkit-slider-thumb { -webkit-appearance: none; width: 18px; height: 18px; background: #fff; border-radius: 50%; box-shadow: 0 1px 3px rgba(0,0,0,0.3); cursor: pointer; margin-top: -7px; }

    #map-container { flex: 1; position: relative; width: 100%; min-height: 0; }
    #map { width: 100%; height: 100%; background: #ddd; z-index: 0; }
    
    .arrow-marker { display: flex; align-items: center; justify-content: center; text-shadow: 0 0 2px white; }
    .hidden { display: none !important; }
    .control-row { display: flex; align-items: center; gap: 8px; }
</style>
</head>
<body>

<?php if (!$isLoggedIn): ?>
    <div id="login-overlay">
        <div class="login-box">
            <h2>🔒 系統登入</h2>
            <form method="post">
                <input type="text" name="username" class="login-input" placeholder="帳號" required autofocus>
                <input type="password" name="password" class="login-input" placeholder="密碼" required>
                <button type="submit" class="login-btn">登入</button>
                <?php if ($loginError): ?>
                    <div class="error-msg"><?php echo htmlspecialchars($loginError); ?></div>
                <?php endif; ?>
            </form>
        </div>
    </div>
<?php endif; ?>

<div id="app-content" class="<?php echo $isLoggedIn ? '' : 'blurred'; ?>">
    <header>
        <h1><i class="fas fa-location-dot" style="color: var(--primary-color);"></i> GPS 智慧軌跡</h1>
        <div class="user-info">
            <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?></span>
            <a href="?logout" class="btn-logout" onclick="return confirm('登出？');">登出</a>
        </div>
    </header>

    <div id="top-panel">
        <div class="panel-content">
            <div class="status-bar" id="info-bar" title="點擊開啟 Google Maps">
                <span>等待資料...</span>
            </div>

            <div id="nav-group">
                <div class="mode-tabs">
                    <button class="tab-btn active" onclick="switchMode('realtime')"><i class="fas fa-satellite-dish"></i> 即時監控</button>
                    <button class="tab-btn" onclick="switchMode('history')"><i class="fas fa-clock-rotate-left"></i> 歷史回放</button>
                </div>

                <div id="panel-realtime" class="">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer;">
                            <input type="checkbox" id="rt-lock" checked hidden onchange="toggleLock('realtime', this.checked)">
                            <div class="toggle-lock"><i class="fas fa-crosshairs"></i></div>
                            <span>鎖定車輛</span>
                        </label>
                        <div style="font-size: 12px; color: #666;">
                            <i class="fas fa-rotate fa-spin hidden" id="loading-spinner"></i>
                            <span id="rt-status">準備就緒</span>
                        </div>
                    </div>
                </div>

                <div id="panel-history" class="hidden">
                    <div class="control-row" style="width: 100%;">
                        <input type="date" id="his-date" style="flex:1;" onchange="fetchHistory()">
                    </div>
                    <div id="segmentPanel"></div>
                </div>
            </div>

            <div id="player-card" class="hidden">
                <div class="player-row">
                    <button class="btn-icon" onclick="exitPlayerMode()"><i class="fas fa-arrow-left"></i></button>
                    <span id="player-title" style="font-weight: 600; font-size: 13px; flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; margin:0 4px;">路徑播放</span>
                    
                    <select id="play-speed" onchange="player.setSpeed(this.value)" style="height: 30px; width: 55px;">
                        <option value="1">1x</option>
                        <option value="2" selected>2x</option>
                        <option value="5">5x</option>
                        <option value="10">10x</option>
                        <option value="20">20x</option>
                    </select>
                    <label>
                        <input type="checkbox" id="his-lock" checked hidden onchange="toggleLock('history', this.checked)">
                        <div class="toggle-lock" style="width:30px; height:30px;"><i class="fas fa-crosshairs"></i></div>
                    </label>
                </div>

                <div class="player-row" style="margin-top: 4px;">
                    <button class="btn-control stop" onclick="player.stop()"><i class="fas fa-stop"></i></button>
                    <button class="btn-control" id="btn-toggle-play" onclick="player.toggle()"><i class="fas fa-play"></i></button>
                    <div class="slider-container">
                        <input type="range" id="play-slider" min="0" max="100" value="0" step="1">
                        <span style="font-size: 11px; color: #666; width: 28px; text-align: right;" id="play-time-display">0%</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="map-container">
        <div id="map"></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
// --- 全域變數 ---
let map;
let currentMode = 'realtime';
let rtInterval = null;
let lastLogTime = null;
let carMarker = null;
let polyline = null;
let trackLayer = L.layerGroup();
let mapZoom = 15;
let currentLat = null, currentLng = null;
const isLoggedIn = <?php echo $isLoggedIn ? 'true' : 'false'; ?>;

// --- 初始化 ---
function initApp() {
    map = L.map('map', { zoomControl: false }).setView([24.2, 120.6], mapZoom);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OSM' }).addTo(map);
    L.control.zoom({ position: 'bottomright' }).addTo(map);
    map.on('zoomend', () => { mapZoom = map.getZoom(); });
    trackLayer.addTo(map);
    
    if (isLoggedIn) {
        switchMode('realtime');
    }
}

function getCarIcon(zoom) {
    const size = Math.max(40, Math.min(80, 40 + (zoom - 12) * 5)); 
    return L.icon({
        iconUrl: 'tucsonl.png',
        iconSize: [size, size * 0.85],
        iconAnchor: [size / 2, size / 2],
        popupAnchor: [0, -size / 2]
    });
}

function getArrowIcon(cog) {
    const rotation = (cog || 0) - 40; 
    return L.divIcon({
        className: 'arrow-marker',
        html: `<div style="transform: rotate(${rotation}deg); color: #007aff; font-size: 16px;"><i class="fas fa-location-arrow"></i></div>`,
        iconSize: [20, 20],
        iconAnchor: [10, 10]
    });
}

function updateInfoBar(point) {
    if (!point) return;
    const speed = point.spd ? parseFloat(point.spd).toFixed(1) : 0;
    const time = point.log_tim ? point.log_tim.substring(11, 19) : '--:--:--';
    let displayTime = time;
    if (point.log_tim) {
        const dateStr = point.log_tim.substring(5, 10);
        if (new Date() - new Date(point.log_tim) > 86400000) displayTime = `${dateStr} ${time}`;
    }
    document.getElementById('info-bar').innerHTML = 
        `<span><i class="fas fa-clock"></i> ${displayTime}</span>
         <span><i class="fas fa-gauge-high"></i> ${speed} <small>km/h</small></span>
         <span><i class="fas fa-satellite"></i> ${point.satcnt || 0}</span>`;
    currentLat = parseFloat(point.lat);
    currentLng = parseFloat(point.lng);
}

document.getElementById('info-bar').addEventListener('click', function() {
    if (isValid(currentLat) && isValid(currentLng)) {
        window.open(`http://googleusercontent.com/maps.google.com/?q=${currentLat},${currentLng}`, '_blank');
    }
});

function smartPan(lat, lng, forceCenter = false) {
    if (!isValid(lat) || !isValid(lng)) return;
    const lockId = currentMode === 'realtime' ? 'rt-lock' : 'his-lock';
    const isLocked = document.getElementById(lockId).checked;
    const latlng = [lat, lng];
    if (forceCenter) { map.setView(latlng, mapZoom); return; }
    if (isLocked && !map.getBounds().contains(latlng)) { map.panTo(latlng); }
}

function toggleLock(mode, checked) {
    if (checked && isValid(currentLat) && isValid(currentLng)) {
        map.setView([currentLat, currentLng], mapZoom);
    }
}

function isValid(n) { return n !== null && n !== undefined && !isNaN(n) && n !== 0; }

function clearMapLayers() {
    if (carMarker) { map.removeLayer(carMarker); carMarker = null; }
    if (polyline) { map.removeLayer(polyline); polyline = null; }
    trackLayer.clearLayers();
}

function switchMode(mode) {
    currentMode = mode;
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelector(`button[onclick="switchMode('${mode}')"]`).classList.add('active');
    
    document.getElementById('panel-realtime').classList.toggle('hidden', mode !== 'realtime');
    document.getElementById('panel-history').classList.toggle('hidden', mode !== 'history');

    exitPlayerMode();
    clearMapLayers();
    if (rtInterval) clearInterval(rtInterval);

    if (mode === 'realtime') {
        initRealtime();
        setTimeout(() => map.invalidateSize(), 300);
    } else {
        const dateInput = document.getElementById('his-date');
        // 自動填入今天
        if (!dateInput.value) {
            dateInput.valueAsDate = new Date();
        }
        // 若無資料則自動查詢
        if (segments.length === 0) {
            fetchHistory();
        }
    }
}

function enterPlayerMode(title) {
    document.getElementById('nav-group').classList.add('hidden');
    document.getElementById('player-card').classList.remove('hidden');
    document.getElementById('player-title').innerText = title || "路徑播放";
}

function exitPlayerMode() {
    player.stop();
    document.getElementById('nav-group').classList.remove('hidden');
    document.getElementById('player-card').classList.add('hidden');
    document.querySelectorAll('.segment-item').forEach(el => el.classList.remove('active'));
    clearMapLayers();
}

function drawTrackPoints(points) {
    trackLayer.clearLayers();
    points.forEach(p => {
        const lat = parseFloat(p.lat);
        const lng = parseFloat(p.lng);
        if (!isValid(lat) || !isValid(lng)) return;
        let m;
        if (p.cog && parseFloat(p.cog) > 0) {
            m = L.marker([lat, lng], { icon: getArrowIcon(parseFloat(p.cog)) });
        } else {
            m = L.circleMarker([lat, lng], {
                radius: 4, color: 'red', fillColor: 'red', fillOpacity: 0.8, weight: 0
            });
        }
        trackLayer.addLayer(m);
    });
}

// --- Realtime ---
const Realtime = {
    pathCoords: [],
    init: async function() {
        if (!isLoggedIn) return;
        document.getElementById('rt-status').innerText = "載入中...";
        document.getElementById('loading-spinner').classList.remove('hidden');
        try {
            const res = await fetch('?ajax=1&action=realtime_init');
            if (res.status === 403) return; // Not authorized
            const json = await res.json();
            const data = json.data || [];
            this.pathCoords = [];
            lastLogTime = null;
            if (data.length > 0) this.processData(data, true);
            else document.getElementById('rt-status').innerText = "暫無資料";
            
            document.getElementById('rt-status').innerText = "監控中";
            if (rtInterval) clearInterval(rtInterval);
            rtInterval = setInterval(() => this.update(), 5000);
        } catch (e) { document.getElementById('rt-status').innerText = "連線錯誤"; } 
        finally { document.getElementById('loading-spinner').classList.add('hidden'); }
    },
    update: async function() {
        if (!lastLogTime) return;
        try {
            const res = await fetch(`?ajax=1&action=realtime_update&last_time=${encodeURIComponent(lastLogTime)}`);
            if (!res.ok) return;
            const json = await res.json();
            const data = json.data || [];
            if (data.length > 0) {
                this.processData(data, false);
                document.getElementById('rt-status').innerText = `+${data.length}`;
                setTimeout(() => document.getElementById('rt-status').innerText = "監控中", 2000);
            }
        } catch(e) {}
    },
    processData: function(points, isInit) {
        if (points.length === 0) return;
        lastLogTime = points[points.length - 1].log_tim;
        
        points.forEach(p => {
            const lat = parseFloat(p.lat);
            const lng = parseFloat(p.lng);
            if (isValid(lat) && isValid(lng)) this.pathCoords.push([lat, lng]);
        });
        if (this.pathCoords.length === 0) return;
        
        if (!polyline) polyline = L.polyline(this.pathCoords, { color: '#007aff', weight: 3, opacity: 0.6 }).addTo(map);
        else polyline.setLatLngs(this.pathCoords);
        
        points.forEach(p => {
             const lat = parseFloat(p.lat), lng = parseFloat(p.lng);
             if(!isValid(lat) || !isValid(lng)) return;
             if(p.cog && parseFloat(p.cog)>0) trackLayer.addLayer(L.marker([lat,lng], {icon: getArrowIcon(p.cog)}));
             else trackLayer.addLayer(L.circleMarker([lat,lng], {radius:4, color:'red', fillColor:'red', fillOpacity:0.8, weight:0}));
        });

        const lastP = points[points.length - 1];
        const lat = parseFloat(lastP.lat);
        const lng = parseFloat(lastP.lng);
        if (isValid(lat) && isValid(lng)) {
            if (carMarker) map.removeLayer(carMarker);
            carMarker = L.marker([lat, lng], { icon: getCarIcon(mapZoom), zIndexOffset: 1000 }).addTo(map);
            updateInfoBar(lastP);
            if (isInit || document.getElementById('rt-lock').checked) {
                smartPan(lat, lng, true);
            } else {
                smartPan(lat, lng, false);
            }
        }
    }
};

function initRealtime() { Realtime.init(); }

// --- History ---
let segments = [];
async function fetchHistory() {
    const date = document.getElementById('his-date').value;
    if (!date) return;
    document.getElementById('segmentPanel').innerHTML = '<div style="text-align:center;padding:10px;color:#999;"><i class="fas fa-spinner fa-spin"></i> 載入中...</div>';
    try {
        const res = await fetch(`?ajax=1&action=history&date=${date}`);
        const json = await res.json();
        const data = json.data || [];
        if (data.length === 0) {
            document.getElementById('segmentPanel').innerHTML = '<div style="text-align:center;padding:10px;color:#999;">無資料</div>';
        } else {
            processSegments(data);
        }
    } catch (e) { 
        document.getElementById('segmentPanel').innerHTML = '<div style="text-align:center;padding:10px;color:red;">載入錯誤</div>';
    }
}

function processSegments(points) {
    segments = [];
    if (points.length === 0) return;
    const valid = points.filter(p => isValid(parseFloat(p.lat)) && isValid(parseFloat(p.lng)));
    if (valid.length === 0) return;

    let cur = [valid[0]];
    for (let i = 1; i < valid.length; i++) {
        const diff = (new Date(valid[i].log_tim) - new Date(valid[i-1].log_tim)) / 60000;
        if (diff > 20) { segments.push(cur); cur = []; }
        cur.push(valid[i]);
    }
    if (cur.length > 0) segments.push(cur);
    renderList();
}

function renderList() {
    const p = document.getElementById('segmentPanel');
    p.innerHTML = '';
    segments.forEach((seg, i) => {
        const div = document.createElement('div');
        div.className = 'segment-item';
        const start = seg[0].log_tim.substring(11, 16);
        const end = seg[seg.length-1].log_tim.substring(11, 16);
        div.innerHTML = `<span>軌跡 ${i+1}</span> <span style="color:#666">${start}-${end} (${seg.length})</span>`;
        div.onclick = () => selectSegment(i);
        p.appendChild(div);
    });
}

function selectSegment(idx) {
    const seg = segments[idx];
    const latlngs = seg.map(p => [parseFloat(p.lat), parseFloat(p.lng)]);
    if (latlngs.length === 0) return;
    enterPlayerMode(`軌跡 ${idx+1}`);
    clearMapLayers();
    drawTrackPoints(seg);
    polyline = L.polyline(latlngs, { color: '#999', weight: 2, dashArray: '5, 5', opacity: 0.5 }).addTo(map);
    try { map.fitBounds(polyline.getBounds(), { padding: [20, 20] }); } catch(e){}
    player.load(seg);
}

// --- Player ---
const player = {
    data: [], idx: 0, timer: null, speed: 2, isPlaying: false,
    load: function(pts) {
        this.stop();
        this.data = pts;
        this.idx = 0;
        this.updateUI();
        if (this.data.length > 0) {
            const s = this.data[0];
            const lat = parseFloat(s.lat), lng = parseFloat(s.lng);
            carMarker = L.marker([lat, lng], { icon: getCarIcon(mapZoom) }).addTo(map);
            updateInfoBar(s);
        }
    },
    toggle: function() { this.isPlaying ? this.pause() : this.play(); },
    play: function() {
        if (this.data.length === 0) return;
        this.isPlaying = true;
        this.updateBtn();
        if (!this.timer) this.loop();
    },
    pause: function() {
        this.isPlaying = false;
        this.updateBtn();
        clearTimeout(this.timer);
        this.timer = null;
    },
    stop: function() {
        this.pause();
        this.idx = 0;
        this.updateUI();
        if (this.data.length > 0 && carMarker) {
            const s = this.data[0];
            const lat = parseFloat(s.lat), lng = parseFloat(s.lng);
            carMarker.setLatLng([lat, lng]);
            updateInfoBar(s);
            if(document.getElementById('his-lock').checked) smartPan(lat, lng, true);
        }
    },
    updateBtn: function() {
        const btn = document.getElementById('btn-toggle-play');
        btn.innerHTML = this.isPlaying ? '<i class="fas fa-pause"></i>' : '<i class="fas fa-play"></i>';
        if (this.isPlaying) btn.classList.add('playing'); else btn.classList.remove('playing');
    },
    setSpeed: function(v) { this.speed = parseInt(v); },
    loop: function() {
        if (!this.isPlaying) return;
        if (this.idx >= this.data.length - 1) { this.pause(); return; }
        this.idx++;
        this.updateStep();
        this.timer = setTimeout(() => this.loop(), 600 / this.speed);
    },
    updateStep: function() {
        const p = this.data[this.idx];
        const lat = parseFloat(p.lat), lng = parseFloat(p.lng);
        if (carMarker) {
            carMarker.setLatLng([lat, lng]);
            carMarker.setIcon(getCarIcon(mapZoom));
        }
        smartPan(lat, lng);
        updateInfoBar(p);
        this.updateUI();
    },
    updateUI: function() {
        const sl = document.getElementById('play-slider');
        const pct = this.data.length > 1 ? (this.idx / (this.data.length - 1)) * 100 : 0;
        sl.value = pct;
        document.getElementById('play-time-display').innerText = Math.round(pct) + '%';
    },
    seek: function(pct) {
        if (this.data.length === 0) return;
        this.idx = Math.floor((pct / 100) * (this.data.length - 1));
        this.updateStep();
    }
};

document.getElementById('play-slider').addEventListener('input', function() { player.seek(this.value); });

initApp();
</script>
</body>
</html>
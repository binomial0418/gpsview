# GPS 軌跡系統 - 安全認證說明

## 快速開始

### 1. 設定密碼

執行密碼雜湊產生器產生密碼：

```bash
php generate_password_hash.php yourSecurePassword123
```

輸出範例：
```
=== 密碼雜湊結果 ===
原密碼：        ***********************
雜湊值：        $2y$10$5f3Yo62vLLmW3JQX.jKM8OvKNVY1kL0QQqN7S2x8kLzZ9pKZxKPvO

=== 使用說明 ===
1. 複製上面的「雜湊值」
2. 貼入 .env.php 中的 'hash' => '...' 部分
3. 刪除此指令的紀錄（防止洩漏原始密碼）
```

### 2. 編輯 `.env.php`

複製 `.env.example.php` 為 `.env.php`，然後更新帳號與密碼雜湊：

```php
define('AUTH_USERS', [
    'admin' => [
        'hash' => '$2y$10$5f3Yo62vLLmW3JQX.jKM8OvKNVY1kL0QQqN7S2x8kLzZ9pKZxKPvO',  // 使用產生器的值
        'enabled' => true,
        'description' => '管理者帳號'
    ]
]);
```

### 3. 檔案權限設定

`.env.php` 包含敏感資訊，應設置適當的檔案權限：

```bash
chmod 600 .env.php
chmod 644 .env.example.php
chmod 755 generate_password_hash.php
```

### 4. 版本控制

**重要**：`.env.php` 不應提交至版本控制系統（git）。

編輯 `.gitignore`：
```
.env.php
```

## 安全特性

### 1. 密碼安全性

- **bcrypt 雜湊**：使用 PHP 內建 `password_hash()` 與 `password_verify()`
- **成本參數**：cost=10 提供好的安全性與效能平衡
- **無法逆向**：原始密碼無法從雜湊值復原

### 2. 暴力破解防護

- **失敗次數限制**：預設 5 次失敗後帳號被鎖定
- **鎖定時間**：15 分鐘內無法登入（可在 `.env.php` 調整）
- **IP 位址追蹤**：以客戶端 IP 追蹤登入嘗試

### 3. Session 安全

- **HttpOnly Cookie**：防止 JavaScript 存取 Session Cookie
- **Secure Flag**：HTTPS 時啟用（開發環境可在 `gps_view.php` 調整）
- **SameSite=Strict**：防止 CSRF 攻擊
- **IP 驗證**：登入後若 IP 變更自動登出
- **過期時間**：預設 1 小時（可調整 `AUTH_SESSION_TIMEOUT`）

### 4. 帳號管理

支援多組帳號，每組帳號可單獨啟用/停用：

```php
define('AUTH_USERS', [
    'admin' => [
        'hash' => 'bcrypt_hash_here',
        'enabled' => true,
        'description' => '管理者帳號'
    ],
    'viewer' => [
        'hash' => 'bcrypt_hash_here',
        'enabled' => true,
        'description' '觀看者帳號'
    ],
    'disabled_user' => [
        'hash' => 'bcrypt_hash_here',
        'enabled' => false,  // 帳號停用
        'description' => '已停用帳號'
    ]
]);
```

## 設定選項

編輯 `.env.php` 中的以下常數調整安全設定：

```php
// 登入設定
define('AUTH_MAX_ATTEMPTS', 5);              // 最多失敗嘗試次數
define('AUTH_LOCKOUT_MINUTES', 15);          // 鎖定時間（分鐘）
define('AUTH_SESSION_TIMEOUT', 3600);        // Session 過期時間（秒）
define('AUTH_ENABLE_BRUTE_FORCE_PROTECTION', true);  // 啟用暴力破解防護
```

## 故障排查

### 登入後自動登出

**可能原因**：IP 位址變更（若透過 VPN、WiFi 切換等）

**解決方案**：
- 在 `gps_view.php` 禁用 IP 檢查：
  ```php
  // 註解掉 IP 驗證部分
  // if (isset($_SESSION['ip_address']) && $_SESSION['ip_address'] !== getClientIp()) {
  //     $ipMismatch = true;
  //     ...
  // }
  ```

### 帳號被鎖定

帳號在失敗 5 次後會被鎖定 15 分鐘。等待時間後可重試。

或在 `.env.php` 調整鎖定參數：
```php
define('AUTH_MAX_ATTEMPTS', 10);      // 增加嘗試次數
define('AUTH_LOCKOUT_MINUTES', 5);    // 減少鎖定時間
```

## 密碼變更

### 変更現有帳號密碼

1. 使用產生器產生新密碼雜湊：
   ```bash
   php generate_password_hash.php newPassword456
   ```

2. 複製雜湊值到 `.env.php`

3. **重要**：刪除終端中的歷史指令記錄

### 新增帳號

1. 產生新密碼雜湊
2. 在 `.env.php` 的 `AUTH_USERS` 陣列中新增：
   ```php
   'newuser' => [
       'hash' => 'bcrypt_hash_from_generator',
       'enabled' => true,
       'description' => '新使用者帳號'
   ]
   ```

## 相關檔案

- **`gps_view.php`** - 主應用程式（已整合認證）
- **`.env.php`** - 帳號與密碼設定（敏感，不提交至版本控制）
- **`.env.example.php`** - 設定檔範本（示例）
- **`generate_password_hash.php`** - 密碼雜湊產生工具
- **`.github/copilot-instructions.md`** - 開發指南

## 注意事項

1. **備份**：定期備份 `.env.php` 以防遺失
2. **權限管理**：確保伺服器上的檔案權限正確（`.env.php` 應為 600）
3. **定期更新**：保持 PHP 版本最新以獲得最新的安全修補
4. **HTTPS**：生產環境應使用 HTTPS 並啟用 Secure Cookie flag
5. **日誌監控**：監控登入失敗日誌以發現異常活動

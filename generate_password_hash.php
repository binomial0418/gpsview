#!/usr/bin/env php
<?php
/**
 * 密碼雜湊產生器
 * 用法: php generate_password_hash.php password
 * 輸出：
 *   密碼的 bcrypt 雜湊值（可複製到 .env.php）
 *   及其驗證方法
 */

if (php_sapi_name() !== 'cli') {
    die("此腳本只能在命令列執行\n");
}

if ($argc < 2) {
    echo "使用方法: php generate_password_hash.php <password>\n";
    echo "例: php generate_password_hash.php mySecurePassword123\n";
    exit(1);
}

$password = $argv[1];

// 使用 bcrypt（PASSWORD_BCRYPT）產生雜湊
// cost=10 是預設值，提供好的安全性與性能平衡
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);

echo "\n";
echo "=== 密碼雜湊結果 ===\n";
echo "原密碼：        " . str_repeat('*', strlen($password)) . "\n";
echo "雜湊值：        " . $hash . "\n";
echo "\n";
echo "=== 使用說明 ===\n";
echo "1. 複製上面的「雜湊值」\n";
echo "2. 貼入 .env.php 中的 'hash' => '...' 部分\n";
echo "3. 刪除此指令的紀錄（防止洩漏原始密碼）\n";
echo "\n";
echo "=== 驗證 ===\n";
echo "密碼驗證: " . (password_verify($password, $hash) ? "✓ 成功" : "✗ 失敗") . "\n";
echo "\n";

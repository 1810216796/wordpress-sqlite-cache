<?php
/**
 * Front to the WordPress application – 加了全页缓存加速（带排除规则）
 */

// ---------- 加载 WordPress 核心（仅用于判断登录状态） ----------
require_once __DIR__ . '/wp-load.php';

// ---------- 定义不需要缓存的 URI 列表（支持部分匹配） ----------
$excluded_uris = [
    '/qq-login/',      // 你的 QQ 登录页面路径
    '/oauth/',         // 通用的 OAuth 回调路径
    '/wp-login.php',   // WordPress 登录页
    '/wp-admin/',      // 后台管理（虽然通常不走 index.php，但以防万一）
    '/register/',      // 注册页面
    '/checkout/',      // 支付/结账页面（如果有）
    // 你可以继续添加更多需要排除的路径
];

// 检查当前请求 URI 是否匹配排除列表
$current_uri = $_SERVER['REQUEST_URI'];
$skip_cache = false;
foreach ($excluded_uris as $uri) {
    if (strpos($current_uri, $uri) !== false) {
        $skip_cache = true;
        break;
    }
}

// 如果是登录用户，也跳过缓存（因为要显示个性化内容）
if (is_user_logged_in()) {
    $skip_cache = true;
}

// ---------- 游客且未排除，才使用全页缓存 ----------
if (!$skip_cache && !is_user_logged_in()) {
    // 引入你的 ApiCache 类
    require_once __DIR__ . '/wp-content/ApiCache.php';

    // 生成缓存键（基于当前 URL，区分 GET 参数）
    $cache_key = 'page_' . md5($_SERVER['REQUEST_URI']);

    // 调用全页缓存函数（命中则输出并退出，否则开始捕获）
    cache_page($cache_key, 'Mpage', 1, 86400);
    // 注意：cache_page 若命中会 exit，若未命中则会继续执行下面的代码
}

// ---------- 正常 WordPress 加载 ----------
define('WP_USE_THEMES', true);
require __DIR__ . '/wp-blog-header.php';

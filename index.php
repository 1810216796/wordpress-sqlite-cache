<?php
/**
 * Front to the WordPress application – 加了全页缓存加速
 */

// ---------- 游客全页缓存 ----------
// 先判断是否为登录用户（加载 wp-load 以便使用 is_user_logged_in()）
require_once __DIR__ . '/wp-load.php';

// 如果是未登录游客，尝试输出缓存页面
if (!is_user_logged_in()) {
    // 引入你的 ApiCache 类
    require_once __DIR__ . '/wp-content/ApiCache.php';

    // 生成缓存键（基于当前 URL，区分 GET 参数）
    $cache_key = 'page_' . md5($_SERVER['REQUEST_URI']);

    // 调用全页缓存函数（如果命中则直接输出并退出，否则开始捕获输出）
    cache_page($cache_key, 'Mpage', 1);
    // 注意：cache_page 若命中会 exit，若未命中则会继续执行下面的代码
}

// ---------- 正常 WordPress 加载 ----------
define('WP_USE_THEMES', true);
require __DIR__ . '/wp-blog-header.php';
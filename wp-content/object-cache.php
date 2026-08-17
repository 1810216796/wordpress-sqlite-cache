<?php
/**
 * 使用 SQLite + ApiCache 作为 WordPress 对象缓存后端
 * 完全替代 Redis 插件，专为 9.9 元服务器优化
 *
 * 放置路径：/wp-content/object-cache.php
 * 依赖：/wp-content/ApiCache.php（需包含 delete 方法）
 */

if (!defined('WP_USE_EXT_OBJECT_CACHE')) {
    define('WP_USE_EXT_OBJECT_CACHE', true);
}

// 引入你的 ApiCache 类（放在 /wp-content/ 下）
if (!class_exists('ApiCache')) {
    require_once dirname(__DIR__) . '/wp-content/ApiCache.php';
}

// 定义缓存根目录（常量仅在本文件内使用）
if (!defined('CACHE_ROOT')) {
    define('CACHE_ROOT', WP_CONTENT_DIR . '/Mcache');
}

// 如果未定义前缀，默认空
if (!defined('WP_REDIS_PREFIX')) {
    define('WP_REDIS_PREFIX', '');
}

/**
 * 主缓存类
 */
class WP_Object_Cache {

    /**
     * SQLite 缓存实例（ApiCache）
     * @var ApiCache
     */
    private $apiCache;

    /**
     * 内部内存缓存（同一请求内复用）
     * @var array
     */
    private $cache = [];

    /**
     * 全局组（多站点共享）
     * @var array
     */
    private $global_groups = [
        'blog-details',
        'blog-id-cache',
        'blog-lookup',
        'global-posts',
        'networks',
        'rss',
        'sites',
        'site-details',
        'site-lookup',
        'site-options',
        'site-transient',
        'users',
        'useremail',
        'userlogins',
        'usermeta',
        'user_meta',
        'userslugs',
    ];

    /**
     * 忽略组（不持久化，仅内存）
     * @var array
     */
    private $ignored_groups = [];

    /**
     * 多站点前缀
     */
    private $global_prefix = '';
    private $blog_prefix = 0;

    /**
     * 统计
     */
    public $cache_hits = 0;
    public $cache_misses = 0;

    /**
     * 构造函数
     */
    public function __construct() {
        global $blog_id, $table_prefix;

        // 初始化前缀（兼容多站点）
        if (function_exists('is_multisite')) {
            $this->global_prefix = is_multisite() ? '' : $table_prefix;
            $this->blog_prefix   = is_multisite() ? $blog_id : $table_prefix;
        } else {
            $this->global_prefix = $table_prefix;
            $this->blog_prefix   = $blog_id ?: 0;
        }

        // 创建 ApiCache 实例（分片模式1，目录 WpObject）
        $this->apiCache = get_cache_instance('WpObject', 1);

        // 若有配置忽略组，可在此添加（例如通过常量）
        if (defined('WP_REDIS_IGNORED_GROUPS') && is_array(WP_REDIS_IGNORED_GROUPS)) {
            $this->ignored_groups = array_map('strval', WP_REDIS_IGNORED_GROUPS);
        }
    }

    /**
     * 构建缓存键（遵循 WordPress 标准格式）
     */
    private function build_key($key, $group) {
        $salt = WP_REDIS_PREFIX;
        $prefix = $this->is_global_group($group) ? $this->global_prefix : $this->blog_prefix;
        return "{$salt}{$prefix}:{$group}:{$key}";
    }

    /**
     * 判断是否为全局组
     */
    private function is_global_group($group) {
        return in_array($group, $this->global_groups, true);
    }

    /**
     * 判断是否为忽略组（不持久化）
     */
    private function is_ignored_group($group) {
        return in_array($group, $this->ignored_groups, true);
    }

    // -------- 核心缓存方法 --------

    /**
     * 获取缓存
     */
    public function get($key, $group = 'default', $force = false, &$found = null) {
        $derived_key = $this->build_key($key, $group);

        // 内存命中
        if (array_key_exists($derived_key, $this->cache) && !$force) {
            $found = true;
            $this->cache_hits++;
            return $this->cache[$derived_key];
        }

        // 忽略组或持久层不可用
        if ($this->is_ignored_group($group)) {
            $found = false;
            $this->cache_misses++;
            return false;
        }

        // 从 SQLite 读取
        $value = $this->apiCache->get($derived_key);
        if ($value === false) {
            $found = false;
            $this->cache_misses++;
            return false;
        }

        // 反序列化（因为 set 时序列化了）
        $data = @unserialize($value);
        if ($data === false && $value !== serialize(false)) {
            // 如果反序列化失败，可能存的是非序列化字符串，直接返回原值
            $data = $value;
        }

        $found = true;
        $this->cache_hits++;
        $this->cache[$derived_key] = $data;
        return $data;
    }

    /**
     * 设置缓存
     */
    public function set($key, $value, $group = 'default', $expiration = 0) {
        $derived_key = $this->build_key($key, $group);

        // 忽略组仅存内存
        if ($this->is_ignored_group($group)) {
            $this->cache[$derived_key] = $value;
            return true;
        }

        // 序列化（支持任意类型）
        $serialized = serialize($value);

        // 写入 SQLite（忽略过期时间，由清理脚本管理）
        $success = $this->apiCache->set($derived_key, $serialized);
        if ($success) {
            $this->cache[$derived_key] = $value;
        }
        return $success;
    }

    /**
     * 删除缓存
     */
    public function delete($key, $group = 'default', $deprecated = false) {
        $derived_key = $this->build_key($key, $group);
        unset($this->cache[$derived_key]);

        if ($this->is_ignored_group($group)) {
            return true;
        }

        return $this->apiCache->delete($derived_key);
    }

    /**
     * 添加（仅当键不存在时）
     */
    public function add($key, $value, $group = 'default', $expiration = 0) {
        if (function_exists('wp_suspend_cache_addition') && wp_suspend_cache_addition()) {
            return false;
        }

        $derived_key = $this->build_key($key, $group);
        if (array_key_exists($derived_key, $this->cache)) {
            return false;
        }

        // 检查持久层是否存在（避免重复写入）
        if (!$this->is_ignored_group($group)) {
            $exists = $this->apiCache->get($derived_key);
            if ($exists !== false) {
                return false;
            }
        }

        return $this->set($key, $value, $group, $expiration);
    }

    /**
     * 替换（仅当键存在时）
     */
    public function replace($key, $value, $group = 'default', $expiration = 0) {
        $derived_key = $this->build_key($key, $group);
        if (!array_key_exists($derived_key, $this->cache) && $this->is_ignored_group($group)) {
            return false;
        }
        if (!$this->is_ignored_group($group)) {
            $exists = $this->apiCache->get($derived_key);
            if ($exists === false) {
                return false;
            }
        }
        return $this->set($key, $value, $group, $expiration);
    }

    /**
     * 清空所有缓存（谨慎使用，仅清内存，不删 SQLite 文件）
     */
    public function flush() {
        $this->cache = [];
        // 实际并不删除 SQLite 文件，依靠外部清理脚本
        return true;
    }

    /**
     * 清空运行时内存缓存
     */
    public function flush_runtime() {
        $this->cache = [];
        return true;
    }

    /**
     * 原子递增（不常用，但需实现）
     */
    public function increment($key, $offset = 1, $group = 'default') {
        $derived_key = $this->build_key($key, $group);
        $value = $this->get($key, $group);
        if ($value === false) {
            $value = 0;
        }
        if (!is_numeric($value)) {
            return false;
        }
        $new_value = (int)$value + $offset;
        $this->set($key, $new_value, $group);
        return $new_value;
    }

    /**
     * 原子递减
     */
    public function decrement($key, $offset = 1, $group = 'default') {
        return $this->increment($key, -$offset, $group);
    }

    /**
     * 多键获取（WordPress 5.5+）
     */
    public function get_multiple($keys, $group = 'default', $force = false) {
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->get($key, $group, $force);
        }
        return $results;
    }

    /**
     * 多键设置
     */
    public function set_multiple($data, $group = 'default', $expire = 0) {
        $results = [];
        foreach ($data as $key => $value) {
            $results[$key] = $this->set($key, $value, $group, $expire);
        }
        return $results;
    }

    /**
     * 多键删除
     */
    public function delete_multiple($keys, $group = 'default') {
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->delete($key, $group);
        }
        return $results;
    }

    /**
     * 多键添加
     */
    public function add_multiple($data, $group = 'default', $expire = 0) {
        $results = [];
        foreach ($data as $key => $value) {
            $results[$key] = $this->add($key, $value, $group, $expire);
        }
        return $results;
    }

    // -------- 组管理 --------
    public function add_global_groups($groups) {
        $this->global_groups = array_unique(array_merge($this->global_groups, (array)$groups));
    }

    public function add_non_persistent_groups($groups) {
        $this->ignored_groups = array_unique(array_merge($this->ignored_groups, (array)$groups));
    }

    // -------- 多站点切换 --------
    public function switch_to_blog($blog_id) {
        if (!function_exists('is_multisite') || !is_multisite()) {
            return false;
        }
        $this->blog_prefix = (int)$blog_id;
        return true;
    }

    // -------- 统计信息 --------
    public function stats() {
        // 简单输出，供 Debug Bar 等使用
        echo "<p><strong>Cache Hits:</strong> {$this->cache_hits}<br />";
        echo "<strong>Cache Misses:</strong> {$this->cache_misses}</p>";
    }

    public function info() {
        return (object)[
            'hits'   => $this->cache_hits,
            'misses' => $this->cache_misses,
            'ratio'  => ($this->cache_hits + $this->cache_misses) > 0
                ? round($this->cache_hits / ($this->cache_hits + $this->cache_misses) * 100, 1)
                : 100,
            'bytes'  => 0,
            'time'   => 0,
            'calls'  => 0,
            'groups' => (object)[
                'global'         => $this->global_groups,
                'non_persistent' => $this->ignored_groups,
            ],
            'errors' => null,
            'meta'   => ['Client' => 'SQLite + ApiCache'],
        ];
    }
}

// -------- 全局函数桥接（标准 WordPress 对象缓存接口） --------

function wp_cache_init() {
    global $wp_object_cache;
    if (!($wp_object_cache instanceof WP_Object_Cache)) {
        $wp_object_cache = new WP_Object_Cache();
    }
}

function wp_cache_add($key, $data, $group = '', $expire = 0) {
    global $wp_object_cache;
    return $wp_object_cache->add($key, $data, $group, $expire);
}

function wp_cache_set($key, $data, $group = '', $expire = 0) {
    global $wp_object_cache;
    return $wp_object_cache->set($key, $data, $group, $expire);
}

function wp_cache_get($key, $group = '', $force = false, &$found = null) {
    global $wp_object_cache;
    return $wp_object_cache->get($key, $group, $force, $found);
}

function wp_cache_delete($key, $group = '', $deprecated = 0) {
    global $wp_object_cache;
    return $wp_object_cache->delete($key, $group, $deprecated);
}

function wp_cache_replace($key, $data, $group = '', $expire = 0) {
    global $wp_object_cache;
    return $wp_object_cache->replace($key, $data, $group, $expire);
}

function wp_cache_flush() {
    global $wp_object_cache;
    return $wp_object_cache->flush();
}

function wp_cache_flush_runtime() {
    global $wp_object_cache;
    return $wp_object_cache->flush_runtime();
}

function wp_cache_incr($key, $offset = 1, $group = '') {
    global $wp_object_cache;
    return $wp_object_cache->increment($key, $offset, $group);
}

function wp_cache_decr($key, $offset = 1, $group = '') {
    global $wp_object_cache;
    return $wp_object_cache->decrement($key, $offset, $group);
}

function wp_cache_get_multiple($keys, $group = '', $force = false) {
    global $wp_object_cache;
    return $wp_object_cache->get_multiple($keys, $group, $force);
}

function wp_cache_set_multiple($data, $group = '', $expire = 0) {
    global $wp_object_cache;
    return $wp_object_cache->set_multiple($data, $group, $expire);
}

function wp_cache_delete_multiple($keys, $group = '') {
    global $wp_object_cache;
    return $wp_object_cache->delete_multiple($keys, $group);
}

function wp_cache_add_multiple($data, $group = '', $expire = 0) {
    global $wp_object_cache;
    return $wp_object_cache->add_multiple($data, $group, $expire);
}

function wp_cache_add_global_groups($groups) {
    global $wp_object_cache;
    $wp_object_cache->add_global_groups($groups);
}

function wp_cache_add_non_persistent_groups($groups) {
    global $wp_object_cache;
    $wp_object_cache->add_non_persistent_groups($groups);
}

function wp_cache_switch_to_blog($blog_id) {
    global $wp_object_cache;
    return $wp_object_cache->switch_to_blog($blog_id);
}

function wp_cache_supports($feature) {
    switch ($feature) {
        case 'add_multiple':
        case 'set_multiple':
        case 'get_multiple':
        case 'delete_multiple':
        case 'flush_runtime':
            return true;
        default:
            return false;
    }
}

function wp_cache_close() {
    return true;
}

// 初始化（WordPress 会在加载时自动调用 wp_cache_init）
wp_cache_init();
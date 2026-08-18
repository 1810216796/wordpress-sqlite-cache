<?php
/**
 * 使用新版 ApiCache（支持 TTL）作为 WordPress 对象缓存后端
 * 适配 expire_time 自动过期
 */

if (!defined('WP_USE_EXT_OBJECT_CACHE')) {
    define('WP_USE_EXT_OBJECT_CACHE', true);
}

// 引入你的新版 ApiCache（路径根据实际调整）
require_once __DIR__ . '/ApiCache.php';

// 缓存根目录（确保与 ApiCache 中一致）
if (!defined('CACHE_ROOT')) {
    define('CACHE_ROOT', WP_CONTENT_DIR . '/Mcache');
}

// 默认前缀（可留空）
if (!defined('WP_REDIS_PREFIX')) {
    define('WP_REDIS_PREFIX', '');
}

class WP_Object_Cache {
    private $apiCache;
    private $cache = [];          // 内存缓存
    private $global_groups = [
        'blog-details', 'blog-id-cache', 'blog-lookup', 'global-posts',
        'networks', 'rss', 'sites', 'site-details', 'site-lookup',
        'site-options', 'site-transient', 'users', 'useremail',
        'userlogins', 'usermeta', 'user_meta', 'userslugs'
    ];
    private $ignored_groups = [];
    private $global_prefix = '';
    private $blog_prefix = 0;
    public $cache_hits = 0;
    public $cache_misses = 0;

    public function __construct() {
        global $blog_id, $table_prefix;
        $this->global_prefix = is_multisite() ? '' : $table_prefix;
        $this->blog_prefix   = is_multisite() ? $blog_id : $table_prefix;
        // 分片模式 1（16×16），目录 WpObject
        $this->apiCache = get_cache_instance('WpObject', 1);
    }

    private function build_key($key, $group) {
        $salt = WP_REDIS_PREFIX;
        $prefix = $this->is_global_group($group) ? $this->global_prefix : $this->blog_prefix;
        return "{$salt}{$prefix}:{$group}:{$key}";
    }

    private function is_global_group($group) {
        return in_array($group, $this->global_groups);
    }

    private function is_ignored_group($group) {
        return in_array($group, $this->ignored_groups);
    }

    // -------- 核心方法 --------
    public function get($key, $group = 'default', $force = false, &$found = null) {
        $derived_key = $this->build_key($key, $group);

        // 内存命中
        if (array_key_exists($derived_key, $this->cache) && !$force) {
            $found = true;
            $this->cache_hits++;
            return $this->cache[$derived_key];
        }

        // 忽略组或缓存不可用
        if ($this->is_ignored_group($group)) {
            $found = false;
            $this->cache_misses++;
            return false;
        }

        // 从 SQLite 读取（get 内部会自动检查 expire_time）
        $value = $this->apiCache->get($derived_key);
        if ($value === false) {
            $found = false;
            $this->cache_misses++;
            return false;
        }

        // 反序列化
        $data = @unserialize($value);
        if ($data === false && $value !== serialize(false)) {
            $data = $value;
        }

        $found = true;
        $this->cache_hits++;
        $this->cache[$derived_key] = $data;
        return $data;
    }

    public function set($key, $value, $group = 'default', $expiration = 0) {
        $derived_key = $this->build_key($key, $group);

        if ($this->is_ignored_group($group)) {
            $this->cache[$derived_key] = $value;
            return true;
        }

        $serialized = serialize($value);
        // 将 WordPress 的过期秒数传给 ApiCache 的 $ttl
        $success = $this->apiCache->set($derived_key, $serialized, (int)$expiration);
        if ($success) {
            $this->cache[$derived_key] = $value;
        }
        return $success;
    }

    public function delete($key, $group = 'default', $deprecated = false) {
        $derived_key = $this->build_key($key, $group);
        unset($this->cache[$derived_key]);

        if ($this->is_ignored_group($group)) {
            return true;
        }
        return $this->apiCache->delete($derived_key);
    }

    public function add($key, $value, $group = 'default', $expiration = 0) {
        if (function_exists('wp_suspend_cache_addition') && wp_suspend_cache_addition()) {
            return false;
        }

        $derived_key = $this->build_key($key, $group);
        if (array_key_exists($derived_key, $this->cache)) {
            return false;
        }

        // 检查持久层是否存在
        if (!$this->is_ignored_group($group)) {
            $exists = $this->apiCache->get($derived_key);
            if ($exists !== false) {
                return false;
            }
        }

        return $this->set($key, $value, $group, $expiration);
    }

    public function replace($key, $value, $group = 'default', $expiration = 0) {
        $derived_key = $this->build_key($key, $group);
        if ($this->is_ignored_group($group)) {
            if (!array_key_exists($derived_key, $this->cache)) {
                return false;
            }
        } else {
            $exists = $this->apiCache->get($derived_key);
            if ($exists === false) {
                return false;
            }
        }
        return $this->set($key, $value, $group, $expiration);
    }

    public function flush() {
        $this->cache = [];
        // 不删除 SQLite 文件，由清理脚本管理
        return true;
    }

    public function flush_runtime() {
        $this->cache = [];
        return true;
    }

    public function increment($key, $offset = 1, $group = 'default') {
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

    public function decrement($key, $offset = 1, $group = 'default') {
        return $this->increment($key, -$offset, $group);
    }

    // 批量操作（简单实现）
    public function get_multiple($keys, $group = 'default', $force = false) {
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->get($key, $group, $force);
        }
        return $results;
    }

    public function set_multiple($data, $group = 'default', $expire = 0) {
        $results = [];
        foreach ($data as $key => $value) {
            $results[$key] = $this->set($key, $value, $group, $expire);
        }
        return $results;
    }

    public function delete_multiple($keys, $group = 'default') {
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->delete($key, $group);
        }
        return $results;
    }

    public function add_multiple($data, $group = 'default', $expire = 0) {
        $results = [];
        foreach ($data as $key => $value) {
            $results[$key] = $this->add($key, $value, $group, $expire);
        }
        return $results;
    }

    // 组管理
    public function add_global_groups($groups) {
        $this->global_groups = array_unique(array_merge($this->global_groups, (array)$groups));
    }

    public function add_non_persistent_groups($groups) {
        $this->ignored_groups = array_unique(array_merge($this->ignored_groups, (array)$groups));
    }

    public function switch_to_blog($blog_id) {
        if (!function_exists('is_multisite') || !is_multisite()) {
            return false;
        }
        $this->blog_prefix = (int)$blog_id;
        return true;
    }

    // 统计信息（可选）
    public function stats() {
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
            'groups' => (object)[
                'global' => $this->global_groups,
                'non_persistent' => $this->ignored_groups,
            ],
            'meta' => ['Client' => 'SQLite + ApiCache (TTL)'],
        ];
    }
}

// -------- 全局函数桥接 --------
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

// 自动初始化
wp_cache_init();

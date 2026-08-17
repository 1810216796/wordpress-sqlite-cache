<?php
// ApiCache.php - 完全版，包含 delete 方法

define('CACHE_ROOT', __DIR__ . '/Mcache');


if (!defined('CACHE_PROBABILITY')) {
    define('CACHE_PROBABILITY', 100);
}

class ApiCache
{
    private $dirName;
    private $root;
    private $level1;
    private $level2;
    private $dbPool = [];
    private $obStack = [];

    /**
     * @param string $dirName   缓存子目录名（如 'default', 'Mpage', 'WpObject'）
     * @param int    $shardType 分片类型：1=16×16, 2=16×256, 3=256×256
     */
    public function __construct($dirName, $shardType = 1) // 默认改为模式1
    {
        $this->dirName = $dirName;
        $this->root = rtrim(CACHE_ROOT, '/') . '/' . $dirName . '/';
        if (!is_dir($this->root)) {
            mkdir($this->root, 0755, true);
        }

        switch ($shardType) {
            case 1: $this->level1 = 1; $this->level2 = 2; break;
            case 2: $this->level1 = 1; $this->level2 = 3; break;
            case 3: $this->level1 = 2; $this->level2 = 4; break;
            default: throw new Exception("无效的分片类型，允许 1, 2, 3");
        }
    }

    // ---------- 基础读写 ----------
    public function get($key)
    {
        $md5 = md5($key);
        $db = $this->getDb($md5);
        if (!$db) return false;

        try {
            $stmt = $db->prepare("SELECT content, access_count FROM cache WHERE md5 = ?");
            $stmt->execute([$md5]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return false;

            $prob = (int) CACHE_PROBABILITY;
            if ($prob > 0 && ($prob >= 100 || mt_rand(1, 100) <= $prob)) {
                $upd = $db->prepare("UPDATE cache SET access_count = access_count + 1 WHERE md5 = ?");
                $upd->execute([$md5]);
            }

            $raw = @gzuncompress($row['content']);
            return ($raw !== false) ? $raw : $row['content'];
        } catch (Exception $e) {
            error_log("ApiCache get error: " . $e->getMessage());
            return false;
        }
    }

    public function set($key, $data)
    {
        $md5 = md5($key);
        $db = $this->getDb($md5);
        if (!$db) return false;

        $compressed = (strlen($data) < 200) ? $data : gzcompress($data, 6);

        try {
            $stmt = $db->prepare("UPDATE cache SET content = ? WHERE md5 = ?");
            $stmt->execute([$compressed, $md5]);
            if ($stmt->rowCount() > 0) return true;

            $stmt = $db->prepare("INSERT INTO cache (md5, content, access_count, first_time) VALUES (?, ?, 0, ?)");
            return $stmt->execute([$md5, $compressed, time()]);
        } catch (Exception $e) {
            error_log("ApiCache set error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 删除缓存条目（新增）
     * @param string $key 缓存键
     * @return bool 成功删除返回 true，否则 false
     */
    public function delete($key)
    {
        $md5 = md5($key);
        $db = $this->getDb($md5);
        if (!$db) return false;

        try {
            $stmt = $db->prepare("DELETE FROM cache WHERE md5 = ?");
            $stmt->execute([$md5]);
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            error_log("ApiCache delete error: " . $e->getMessage());
            return false;
        }
    }

    // ---------- 片段缓存 ----------
    public function start($key)
    {
        $content = $this->get($key);
        if ($content !== false) {
            echo $content;
            return true;
        }

        ob_start();
        $this->obStack[] = [
            'key'   => $key,
            'level' => ob_get_level()
        ];
        return false;
    }

    public function end()
    {
        if (empty($this->obStack)) {
            if (ob_get_level() > 0) ob_end_clean();
            return;
        }

        $info = array_pop($this->obStack);
        if (ob_get_level() != $info['level'] + 1) {
            echo ob_get_clean();
            return;
        }

        $content = ob_get_clean();
        $this->set($info['key'], $content);
        echo $content;
    }

    // ---------- 全页面缓存 ----------
    public function page($key)
    {
        $content = $this->get($key);
        if ($content !== false) {
            echo $content;
            exit;
        }

        ob_start(function($buffer) use ($key) {
            $this->set($key, $buffer);
            return $buffer;
        });
    }

    // ---------- 内部工具 ----------
    private function getDb($md5)
    {
        $dir1 = substr($md5, 0, $this->level1);
        $filePrefix = substr($md5, 0, $this->level2);
        $dirPath = $this->root . $dir1;
        $dbFile = $dirPath . '/' . $filePrefix . '.db';

        $poolKey = $this->root . '|' . $dir1 . '/' . $filePrefix;
        if (isset($this->dbPool[$poolKey])) {
            return $this->dbPool[$poolKey];
        }

        if (!is_dir($dirPath)) {
            if (!mkdir($dirPath, 0755, true)) return false;
        }

        try {
            $pdo = new PDO("sqlite:" . $dbFile);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec("CREATE TABLE IF NOT EXISTS cache (
                md5 TEXT PRIMARY KEY,
                content BLOB,
                access_count INTEGER DEFAULT 0,
                first_time INTEGER
            )");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_first_time ON cache(first_time)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_access_count ON cache(access_count)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_clean ON cache(access_count, first_time)");
            $pdo->exec("PRAGMA journal_mode = WAL");
            $pdo->exec("PRAGMA synchronous = OFF");

            $this->dbPool[$poolKey] = $pdo;
            return $pdo;
        } catch (PDOException $e) {
            error_log("SQLite open failed: " . $e->getMessage());
            return false;
        }
    }

    public function close()
    {
        $this->dbPool = [];
    }

    public function getRoot() { return $this->root; }
}

// ---------- 全局辅助函数 ----------
function get_cache_instance($dirName, $shardType = 1) {  // 默认模式改为1
    static $instances = [];
    $key = $dirName . '|' . $shardType;
    if (!isset($instances[$key])) {
        $instances[$key] = new ApiCache($dirName, $shardType);
    }
    return $instances[$key];
}

function cache_page($key, $dirName = 'Mpage', $shardType = 1) {
    $cache = get_cache_instance($dirName, $shardType);
    $cache->page($key);
}

function cstart($key, $dirName = 'default', $shardType = 1) {
    global $_cache_stack;
    if (!isset($_cache_stack) || !is_array($_cache_stack)) {
        $_cache_stack = [];
    }

    $cache = get_cache_instance($dirName, $shardType);
    $result = $cache->start($key);
    
    if (!$result) {
        $_cache_stack[] = [
            'dir'   => $dirName,
            'shard' => $shardType
        ];
    }
    return $result;
}

function cend() {
    global $_cache_stack;
    
    if (empty($_cache_stack)) {
        if (ob_get_level() > 0) ob_end_clean();
        return;
    }
    
    $info = array_pop($_cache_stack);
    $cache = get_cache_instance($info['dir'], $info['shard']);
    $cache->end();
}

#!/usr/bin/env php
<?php
// ClearCache.php - 适配你的目录结构，包含 WpObject 清理

if (php_sapi_name() !== 'cli') {
    die("仅允许 CLI 运行\n");
}

// ---------- 配置区域 ----------
// 直接指定缓存根目录（与 ApiCache.php 中 CACHE_ROOT 保持一致）
define('CACHE_ROOT', '/www/wwwroot/www.4s5.cn/wp-content/Mcache');

// 清理模式：'high' 使用 SQLite julianday（需版本≥3.8.0），'low' 使用 PHP 循环
define('SQLITE_CLEAN_MODE', 'low');

// 清理任务列表：每个任务指定要清理的目录路径（绝对路径）和清理参数
$CLEAN_TASKS = [
    // 对象缓存（替换 Redis 的数据）
    [
        'path'        => CACHE_ROOT . '/WpObject',
        'max_records' => 200000,    // 20万条（根据硬盘容量调整）
        'base_days'   => 7,
        'base_access' => 10,        // 访问阈值设低一些，因为对象缓存访问频繁
    ],
    // 全页面缓存（游客静态页）
    [
        'path'        => CACHE_ROOT . '/Mpage',
        'max_records' => 50000,     // 5万条足够（页面数量有限）
        'base_days'   => 1,
        'base_access' => 20,
    ],
    // 其他自定义缓存目录（如果你还有 default、Mlyric 等，可以继续添加）
    // [
    //     'path'        => CACHE_ROOT . '/default',
    //     'max_records' => 100000,
    //     'base_days'   => 7,
    //     'base_access' => 20,
    // ],
];

// ---------- 执行清理 ----------
$mode = (SQLITE_CLEAN_MODE === 'high') ? '高版本(SQL)' : '低版本(PHP)';
echo "[" . date('Y-m-d H:i:s') . "] 开始清理...（模式：$mode）\n";

$totalAllDeleted = 0;
$totalAllOverLimit = 0;

foreach ($CLEAN_TASKS as $task) {
    $path = $task['path'];
    echo "\n--- 清理任务: $path ---\n";
    if (!is_dir($path)) {
        echo "  目录不存在，跳过\n";
        continue;
    }

    $dbFiles = getDbFiles($path);
    if (empty($dbFiles)) {
        echo "  没有找到 .db 文件\n";
        continue;
    }
    echo "  发现 " . count($dbFiles) . " 个 .db 文件\n";

    // 第一步：低频淘汰
    $totalDeleted = 0;
    $fileCount = 0;
    foreach ($dbFiles as $dbFile) {
        $fileCount++;
        if (SQLITE_CLEAN_MODE === 'high') {
            $deleted = cleanSingleDbHigh($dbFile, $task['base_days'], $task['base_access']);
        } else {
            $deleted = cleanSingleDbLow($dbFile, $task['base_days'], $task['base_access']);
        }
        $totalDeleted += $deleted;
        if ($deleted > 0) {
            echo "  低频清理 $dbFile : 删除 $deleted 条\n";
        }
        // 每处理10个文件输出一次进度
        if ($fileCount % 10 == 0) {
            echo "  已处理 $fileCount 个文件...\n";
        }
    }
    echo "第一步完成，共删除低频缓存 $totalDeleted 条\n";
    $totalAllDeleted += $totalDeleted;

    // 第二步：超限删除
    $total = countRecords($dbFiles);
    $maxRecords = $task['max_records'];
    if ($total > $maxRecords) {
        $need = $total - $maxRecords;
        echo "记录数 $total 超过限制（$maxRecords），需删除 $need 条\n";
        $deleted = deleteLeastAccessedRecords($dbFiles, $need);
        echo "第二步删除 $deleted 条\n";
        $totalAllOverLimit += $deleted;
    } else {
        echo "记录数 $total 未超限（$maxRecords）\n";
    }

    removeEmptyDirs($path, false);
}

echo "\n[" . date('Y-m-d H:i:s') . "] 清理完成\n";
echo "汇总：低频清理 $totalAllDeleted 条，超限删除 $totalAllOverLimit 条\n";

// ---------- 辅助函数（全部自包含） ----------
function getDbFiles($root) {
    $files = [];
    if (!is_dir($root)) return $files;
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iter as $file) {
        if ($file->isFile() && $file->getExtension() === 'db') {
            $files[] = $file->getPathname();
        }
    }
    return $files;
}

function cleanSingleDbHigh($dbFile, $baseDays, $baseAccess) {
    try {
        $pdo = new PDO("sqlite:" . $dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $sql = "DELETE FROM cache 
                WHERE access_count < CAST(CEIL(((julianday('now') - julianday(first_time, 'unixepoch')) / :base_days) * :base_access) AS INT)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':base_days' => $baseDays, ':base_access' => $baseAccess]);
        $count = $stmt->rowCount();
        unset($pdo);
        return $count;
    } catch (Exception $e) {
        error_log("清理失败 $dbFile: " . $e->getMessage());
        return 0;
    }
}

function cleanSingleDbLow($dbFile, $baseDays, $baseAccess) {
    try {
        $pdo = new PDO("sqlite:" . $dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $now = time();
        $limit = 1000;
        $lastMd5 = '';
        $totalDeleted = 0;

        do {
            $stmt = $pdo->prepare("SELECT md5, first_time, access_count FROM cache WHERE md5 > ? ORDER BY md5 LIMIT ?");
            $stmt->execute([$lastMd5, $limit]);
            $rows = $stmt->fetchAll();
            if (empty($rows)) break;

            $toDelete = [];
            foreach ($rows as $row) {
                $days = ($now - $row['first_time']) / 86400;
                $required = ceil(($days / $baseDays) * $baseAccess);
                if ($row['access_count'] < $required) {
                    $toDelete[] = $row['md5'];
                }
            }
            if (!empty($toDelete)) {
                $in = implode(',', array_fill(0, count($toDelete), '?'));
                $del = $pdo->prepare("DELETE FROM cache WHERE md5 IN ($in)");
                $del->execute($toDelete);
                $totalDeleted += $del->rowCount();
            }
            $lastMd5 = end($rows)['md5'];
        } while (true);

        unset($pdo);
        return $totalDeleted;
    } catch (Exception $e) {
        error_log("清理失败 $dbFile: " . $e->getMessage());
        return 0;
    }
}

function countRecords($dbFiles) {
    $total = 0;
    foreach ($dbFiles as $dbFile) {
        try {
            $pdo = new PDO("sqlite:" . $dbFile);
            $total += (int)$pdo->query("SELECT COUNT(*) FROM cache")->fetchColumn();
            unset($pdo);
        } catch (Exception $e) {
            // 忽略损坏的数据库
        }
    }
    return $total;
}

function deleteLeastAccessedRecords($dbFiles, $need) {
    $dbStats = [];
    $totalRecords = 0;
    foreach ($dbFiles as $dbFile) {
        try {
            $pdo = new PDO("sqlite:" . $dbFile);
            $count = (int)$pdo->query("SELECT COUNT(*) FROM cache")->fetchColumn();
            $dbStats[$dbFile] = $count;
            $totalRecords += $count;
            unset($pdo);
        } catch (Exception $e) {
            // 忽略
        }
    }

    if ($totalRecords == 0) return 0;

    $deleted = 0;
    foreach ($dbStats as $dbFile => $count) {
        if ($count == 0) continue;
        $deleteCount = (int)ceil($need * ($count / $totalRecords));
        if ($deleteCount <= 0) continue;

        try {
            $pdo = new PDO("sqlite:" . $dbFile);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $del = $pdo->prepare("DELETE FROM cache ORDER BY access_count ASC, first_time ASC LIMIT ?");
            $del->execute([$deleteCount]);
            $deleted += $del->rowCount();
            unset($pdo);
        } catch (Exception $e) {
            // 忽略
        }
    }

    // 补删
    if ($deleted < $need) {
        $remaining = $need - $deleted;
        foreach ($dbFiles as $dbFile) {
            if ($remaining <= 0) break;
            try {
                $pdo = new PDO("sqlite:" . $dbFile);
                $del = $pdo->prepare("DELETE FROM cache ORDER BY access_count ASC, first_time ASC LIMIT 1");
                $del->execute();
                if ($del->rowCount() > 0) {
                    $deleted++;
                    $remaining--;
                }
                unset($pdo);
            } catch (Exception $e) {
                // 忽略
            }
        }
    }

    return $deleted;
}

function removeEmptyDirs($dir, $isRoot = true) {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            removeEmptyDirs($path, false);
        }
    }
    if (!$isRoot && count(scandir($dir)) == 2) {
        @rmdir($dir);
    }
}

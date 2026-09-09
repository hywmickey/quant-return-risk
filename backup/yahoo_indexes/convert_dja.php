<?php
/**
 * 把 measuringworth 的道琼斯日收盘数据（DJA.csv）转成回撤分析脚本用的标准格式。
 *
 * 数据源：https://www.measuringworth.com/datasets/DJA/
 * 该文件前几行是说明文字与引用，表头为 "Date","DJIA"，日期格式 M/D/YYYY，
 * 1896 年以前的点位官方已做过衔接调整，可以当成一条连续序列直接使用。
 *
 * 输出格式与 fetch_msci_usa50.php / fetch_yahoo_indexes.py 一致：
 * UTF-8 BOM + date,level,change_pct，日期升序，change_pct 为相对前一交易日的涨跌幅(%)。
 *
 * DJA 在 1992 年以后与 Yahoo 数据有约 18 处疑似数字错位的笔误（如 2002-09-25 记成 7481.82，
 * 实际 7841.82），且缺少几个近期交易日，所以默认再用 us30_yahoo.csv（由 fetch_yahoo_indexes.py
 * 抓取）覆盖重叠部分：长历史取 DJA，1992 年以后取 Yahoo。
 *
 * 用法：
 *   php convert_dja.php                                  # DJA.csv + us30_yahoo.csv -> us30_daily.csv
 *   php convert_dja.php --in=DJA.csv --out=xxx.csv
 *   php convert_dja.php --no-patch                        # 不打补丁，纯用 DJA 数据
 */

$opts = getopt('', ['in::', 'out::', 'patch::', 'no-patch', 'help']);

if (isset($opts['help'])) {
    echo "用法：php convert_dja.php [--in=DJA.csv] [--out=us30_daily.csv] [--patch=us30_yahoo.csv] [--no-patch]\n";
    exit(0);
}

$in_file    = $opts['in']  ?? __DIR__ . '/DJA.csv';
$out_file   = $opts['out'] ?? __DIR__ . '/us30_daily.csv';
$patch_file = isset($opts['no-patch']) ? '' : ($opts['patch'] ?? __DIR__ . '/us30_yahoo.csv');
foreach (['in_file', 'out_file', 'patch_file'] as $var) {
    if ($$var !== '' && !str_contains($$var, '/')) {
        $$var = __DIR__ . '/' . $$var;
    }
}

$fp = fopen($in_file, 'r');
if ($fp === false) {
    fwrite(STDERR, "无法打开输入文件：{$in_file}\n");
    exit(1);
}

$levels    = array();   // date => level，同一天出现多次时后者覆盖前者
$skipped   = 0;         // 说明文字、表头、空行
$duplicate = 0;

while (($cols = fgetcsv($fp, 0, ',', '"', '\\')) !== false) {
    $date  = trim((string) ($cols[0] ?? ''), " \t\xEF\xBB\xBF");
    $level = trim((string) ($cols[1] ?? ''));

    if (!preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $date, $m) || !is_numeric($level)) {
        $skipped++;
        continue;
    }

    $key = sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[1], (int) $m[2]);
    if (isset($levels[$key])) {
        $duplicate++;
    }
    $levels[$key] = (float) $level;
}
fclose($fp);

if (count($levels) < 2) {
    fwrite(STDERR, "有效数据不足：{$in_file}\n");
    exit(1);
}

// 用 Yahoo 数据覆盖重叠部分：DJA 在这段区间有笔误、也缺几个交易日
$patched = 0;
$added   = 0;
if ($patch_file !== '' && is_file($patch_file)) {
    $fp_patch = fopen($patch_file, 'r');
    while (($cols = fgetcsv($fp_patch, 0, ',', '"', '\\')) !== false) {
        $date = ltrim((string) ($cols[0] ?? ''), "\xEF\xBB\xBF");
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !is_numeric($cols[1] ?? '')) {
            continue;
        }
        $level = (float) $cols[1];
        if (!isset($levels[$date])) {
            $added++;
        } elseif (abs($levels[$date] - $level) > 1e-6) {
            $patched++;
        }
        $levels[$date] = $level;
    }
    fclose($fp_patch);
} elseif ($patch_file !== '') {
    fwrite(STDERR, "提示：补丁文件不存在，仅使用 DJA 数据：{$patch_file}\n");
}

ksort($levels);

$fp_out = fopen($out_file, 'w');
if ($fp_out === false) {
    fwrite(STDERR, "无法写入输出文件：{$out_file}\n");
    exit(1);
}

fwrite($fp_out, "\xEF\xBB\xBF");   // BOM，方便 Excel 直接打开
fputcsv($fp_out, ['date', 'level', 'change_pct'], ',', '"', '\\');

$prev = null;
foreach ($levels as $date => $level) {
    $pct = ($prev === null || $prev == 0) ? '' : round(($level / $prev - 1) * 100, 4);
    fputcsv($fp_out, [$date, $level, $pct], ',', '"', '\\');
    $prev = $level;
}
fclose($fp_out);

$dates = array_keys($levels);
printf("已写入 %s\n共 %d 条，%s ~ %s，最新 %s\n跳过非数据行 %d 行，重复日期 %d 个\n",
    $out_file, count($levels), $dates[0], $dates[count($dates) - 1],
    $levels[$dates[count($dates) - 1]], $skipped, $duplicate);
if ($patch_file !== '' && is_file($patch_file)) {
    printf("补丁 %s：修正 %d 个点位，补入 %d 个缺失交易日\n", basename($patch_file), $patched, $added);
}

<?php
/**
 * 把 doc/get_fund_data.php 抓到的基金净值原始数据转成回撤分析脚本用的标准格式。
 *
 * 输入：FSRQ 净值日期 / DWJZ 单位净值 / LJJZ 累计净值 / JZZZL 日增长率
 * 输出：UTF-8 BOM + date,level,change_pct（与 nasdaq100_daily.csv、msci_usa_50_GRTR_DAILY.csv 一致）
 *
 * level 是全收益（分红再投资）净值曲线，以首日单位净值为起点：
 *   level_t = level_{t-1} * (DWJZ_t + 每份分红_t) / DWJZ_{t-1}
 * 每份分红一律由累计净值的变化反推，不使用接口的 FHFCZ：
 *   分红_t = (LJJZ_t - LJJZ_{t-1}) - (DWJZ_t - DWJZ_{t-1})
 * 注意不能直接拿 LJJZ 当 level：累计净值只是把历史分红累加上去、没有再投资，
 * 例如 008114 在 2025-09-19 分红当天，LJJZ 环比 +0.29%，而真实全收益是 +0.30%（等于接口的 JZZZL）。
 *
 * 用法：
 *   php convert_fund.php --in=doc/fund_008114_full_data.csv
 *   php convert_fund.php --in=doc/fund_008114_full_data.csv --out=fund_008114_daily.csv
 */

$opts = getopt('', ['in:', 'out::', 'help']);

if ($opts === false || isset($opts['help'])) {
    echo "用法：php convert_fund.php --in=fund_008114_full_data.csv [--out=fund_008114_daily.csv]\n";
    echo "  --in   get_fund_data.php 产出的原始 CSV，必传；未带路径时按 data/fund/ 查找\n";
    echo "  --out  输出文件，默认按输入文件名推导为 data/daily/fund_{code}_daily.csv\n";
    exit($opts === false ? 1 : 0);
}

$in_file = (string) ($opts['in'] ?? '');
if ($in_file === '') {
    fwrite(STDERR, "缺少必传参数 --in，如 --in=fund_008114_full_data.csv\n");
    exit(1);
}
// 未带路径分隔符时按 data/fund/ 下查找，省去每次手写 data/fund/ 前缀
if (!str_contains($in_file, '/')) {
    $in_file = dirname(__DIR__) . '/data/fund/' . $in_file;
}

// 默认输出到 data/daily/，文件名按输入推导：fund_008114_full_data.csv -> data/daily/fund_008114_daily.csv
$default_out = preg_replace('/_full_data\.csv$/', '_daily.csv', basename($in_file));
$out_file    = (string) ($opts['out'] ?? '');
if ($out_file === '') {
    $out_file = dirname(__DIR__) . '/data/daily/' . $default_out;
} elseif (!str_contains($out_file, '/')) {
    $out_file = dirname(__DIR__) . '/data/daily/' . $out_file;
}

$fp = fopen($in_file, 'r');
if ($fp === false) {
    fwrite(STDERR, "无法打开输入文件：{$in_file}\n");
    exit(1);
}

$header = [];
$navs   = [];   // date => ['nav' => 单位净值, 'acc' => 累计净值, 'pct' => 接口日增长率]

while (($cols = fgetcsv($fp, 0, ',', '"', '\\')) !== false) {
    if ($cols === [null]) {
        continue;
    }

    if ($header === []) {
        $header = array_map(static fn($c): string => ltrim((string) $c, "\xEF\xBB\xBF"), $cols);
        continue;
    }
    if (count($cols) !== count($header)) {
        continue;
    }

    $row  = array_combine($header, $cols);
    $date = (string) ($row['FSRQ'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !is_numeric($row['DWJZ'] ?? '')) {
        continue;
    }

    $navs[$date] = [
        'nav' => (float) $row['DWJZ'],
        'acc' => is_numeric($row['LJJZ'] ?? '') ? (float) $row['LJJZ'] : null,
        'pct' => is_numeric($row['JZZZL'] ?? '') ? (float) $row['JZZZL'] : null,
    ];
}
fclose($fp);

if (count($navs) < 2) {
    fwrite(STDERR, "有效数据不足：{$in_file}\n");
    exit(1);
}

ksort($navs);

$fp_out = fopen($out_file, 'w');
if ($fp_out === false) {
    fwrite(STDERR, "无法写入输出文件：{$out_file}\n");
    exit(1);
}

fwrite($fp_out, "\xEF\xBB\xBF");   // BOM，方便 Excel 直接打开
fputcsv($fp_out, ['date', 'level', 'change_pct'], ',', '"', '\\');

$level      = null;   // 全收益净值
$prev       = null;   // 上一交易日的原始数据
$divCount   = 0;      // 分红次数
$divTotal   = 0.0;    // 每份累计分红
$mismatch   = 0;      // 与接口 JZZZL 对不上的天数（容差 0.02 个百分点）
$dates      = array_keys($navs);

foreach ($navs as $date => $cur) {
    if ($prev === null) {
        $level = $cur['nav'];                                    // 以首日单位净值为起点
        fputcsv($fp_out, [$date, round($level, 6), ''], ',', '"', '\\');
        $prev = $cur;
        continue;
    }

    // 每份分红由累计净值的变化反推，不使用 FHFCZ：
    //   分红_t = (LJJZ_t - LJJZ_{t-1}) - (DWJZ_t - DWJZ_{t-1})
    // 阈值 0.0005 滤掉四舍五入噪声
    $div = 0.0;
    if ($cur['acc'] !== null && $prev['acc'] !== null) {
        $guess = ($cur['acc'] - $prev['acc']) - ($cur['nav'] - $prev['nav']);
        if ($guess > 0.0005) {
            $div = round($guess, 4);
        }
    }

    if ($div > 0) {
        $divCount++;
        $divTotal += $div;
    }

    $ratio = $prev['nav'] > 0 ? ($cur['nav'] + $div) / $prev['nav'] : 1.0;
    $level *= $ratio;
    $pct   = ($ratio - 1) * 100;

    // 与接口给的日增长率交叉核对，偏差过大说明有拆分/份额变更等未处理的情况
    if ($cur['pct'] !== null && abs($pct - $cur['pct']) > 0.02) {
        $mismatch++;
        fwrite(STDERR, sprintf("%s 涨跌幅 %.4f%% 与接口 %.2f%% 不一致\n", $date, $pct, $cur['pct']));
    }

    fputcsv($fp_out, [$date, round($level, 6), round($pct, 4)], ',', '"', '\\');
    $prev = $cur;
}

fclose($fp_out);

$firstDate = $dates[0];
$lastDate  = $dates[count($dates) - 1];
$firstNav  = $navs[$firstDate]['nav'];

printf("已写入 %s\n共 %d 条，%s ~ %s\n", $out_file, count($navs), $firstDate, $lastDate);
printf("单位净值 %.4f -> %.4f，全收益 level %.4f -> %.4f，区间累计 %.2f%%\n",
    $firstNav, $navs[$lastDate]['nav'], $firstNav, $level, ($level / $firstNav - 1) * 100);
printf("分红 %d 次（由累计净值反推），每份累计分红 %.4f\n", $divCount, $divTotal);
if ($mismatch > 0) {
    printf("警告：%d 天与接口日增长率不一致，见上方 stderr 输出\n", $mismatch);
}

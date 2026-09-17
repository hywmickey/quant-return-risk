<?php
/**
 * 指数 / 基金的收益与历史回撤分析
 *
 * 回撤算法源自 doc/dropdown_analyze.php，只是把基金的「累计净值 lj_nav」
 * 换成指数的「收盘点位 level」：
 *   1) 逐日比较相邻两天的点位；
 *   2) 下跌且创出新低 → 更新低点，并回溯已记录的回撤，若存在「高点更高、低点也更高」
 *      的旧回撤，则把它合并成一段从更高峰值开始的大回撤（取高点最高的那段）；
 *      合并时额外要求该旧回撤的高点是「它的起点到当天」区间内的最高点位，否则会把中途
 *      涨得更高的行情吞进来，产出 high_level 并非区间真实高点的记录（原实现有此问题，
 *      在 S&P 500 的 1929→1954 长期水下期表现明显）；
 *   3) 上涨且高于当前低点 → 把 高点→低点 结算成一条回撤记录，然后把高点、低点重置为当天；
 *   4) 持平 → 若与低点同值，把低点日期顺延到当天。
 *
 * 注意：与原脚本一样，数据末尾尚未走完（还没反弹）的那段回撤不会被结算输出。
 *
 * 用法：
 *   php return_drawdown_analyze.php                                   # 读 msci_usa_50_GRTR_DAILY.csv
 *   php return_drawdown_analyze.php --in=xxx.csv --out=yyy.csv        # 指定输入输出
 *   php return_drawdown_analyze.php --min=5                           # 只保留回撤幅度 >= 5% 的记录
 *   php return_drawdown_analyze.php --top=0.2 --top-out=zzz.csv       # 取幅度最大的前 20%
 *
 * 输出 CSV 列：
 *   date_start    回撤起点日期（高点）
 *   high_level    高点点位
 *   date_end      回撤终点日期（低点）
 *   low_level     低点点位
 *   drawdown_pct  回撤幅度(%) = (high - low) / high * 100
 *   days          高点到低点的自然日天数
 *
 * 另外再输出一个「回撤幅度最大的前 10%」文件（默认 *_drawdown_top.csv），按日期升序排列，
 * 在上述列之后多两列：
 *   rank          该行回撤幅度在这批前 10% 数据中的名次（1 = 回撤最大；等价于它在全部回撤中的名次）
 *   rank_decile   该名次落在总名次中的第几个十分档 = ceil(rank / 总名次条数 * 10)，整数 1 ~ 10
 *
 * 同时按这批数据生成一个 HTML 表格（默认 *_drawdown_top.html），其中 rank_decile、drawdown_pct
 * 两列的单元格背景色按 rank_decile 档位取十档固定色，从 #ffbfbf（第 1 档，回撤最大）到 #bfffbf（第 10 档）。
 * HTML 里还额外多几列：rebound_pct 是从 low_level 涨回 high_level 所需的涨幅(%)；
 * days_rank 是回撤持续天数的名次（1 = 持续最久，天数相同则名次相同）；
 * 另外 high_level 若没有超过前面所有行（起点不是新高），该单元格用 #d9d9d9 灰底标记。
 * 最后三列取自输入数据：recover_date / recover_level 是 date_end（低点）之后第一次收盘点位
 * >= high_level 的日期与点位（即这段回撤被修复的时点），至今未修复则留空；recover_days 是
 * date_end 到 recover_date 的自然日天数；next_high_pct 是从本行 low_level 涨到之后第一个创新高的
 * high_level 所需的涨幅(%)；dca_positive_date / dca_days 是从 date_start 起每个交易日等额定投、
 * 越过 date_end 之后第一次累计收益率转正的日期与所经历的自然日天数；dca_low_pct 是同一笔定投
 * 走到 date_end（低点当天）时的收益率(%)。
 * days、recover_days、dca_days 超过 30 天的单元格带 title，鼠标悬停显示「Y年M月N天」
 * （1 年 = 365.25 天、1 月 = 30.4375 天，不足的单位不显示；不改变鼠标光标样式）。
 * high_level / low_level / recover_level 在 HTML 里统一显示两位小数（CSV 仍是 6 位）。
 */

/**
 * 回撤幅度(%)，与原脚本一致保留 2 位小数
 */
function calculate_dropdown_ratio(array $dropdown_record): float
{
    return round((($dropdown_record['high_level'] - $dropdown_record['low_level']) / $dropdown_record['high_level']) * 100, 2);
}

/**
 * rank_decile 对应的行背景色
 *
 * 十档固定取色（浅红 -> 浅绿），decile 直接作为序号取值：
 *   decile = 1  -> #ffbfbf（回撤最大的一档，红色最深）
 *   decile = 10 -> #bfffbf（回撤最小的一档，绿色最深）
 */
function decile_color(int $decile): string
{
    static $palette = [
        1  => '#ffbfbf',
        2  => '#ffcccc',
        3  => '#ffd9d9',
        4  => '#ffe6e6',
        5  => '#fff2f2',
        6  => '#f2fff2',
        7  => '#e6ffe6',
        8  => '#d9ffd9',
        9  => '#ccffcc',
        10 => '#bfffbf',
    ];

    return $palette[max(1, min(10, $decile))];
}

/**
 * 年度涨跌幅单元格的底色：涨用绿、跌用红，|涨跌幅| 越大颜色越深。
 *
 * 色相固定（绿 120°、红 0°）、明度固定 100%，只按 |涨跌幅| / 最大 |涨跌幅| 调饱和度：
 *   满格 -> hsv(h, 100%, 100%)，最小 -> hsv(h, 5%, 100%)，涨跌幅为 0 -> hsv(0, 0%, 100%) 即白色。
 * V = 100% 时 HSV 转 RGB 很直接：最大通道为 255，另两个通道为 255 * (1 - S)。
 */
function annual_return_color(float $pct, float $max_abs): string
{
    if ($pct == 0.0 || $max_abs <= 0) {
        return '#ffffff';
    }

    $sat  = 0.05 + 0.95 * min(1.0, abs($pct) / $max_abs);
    $rest = (int) round(255 * (1 - $sat));

    return $pct > 0
        ? sprintf('#%02x%02x%02x', $rest, 255, $rest)
        : sprintf('#%02x%02x%02x', 255, $rest, $rest);
}

/**
 * 把自然日天数写成「Y年M月N天」，供 days / recover_days / dca_days 的悬停提示使用。
 * 1 年按 365.25 天（计入闰年）、1 月按 30.4375 天（365.25 / 12）折算，整年数、整月数都取除法的商；
 * 不足一年不输出「Y年」，不足一个月不输出「M月」，剩余天数四舍五入到整数。
 */
function format_duration_text(int $days): string
{
    $days_per_year  = 365.25;
    $days_per_month = 30.4375;

    $years     = (int) floor($days / $days_per_year);
    $remainder = $days - $years * $days_per_year;
    $months    = (int) floor($remainder / $days_per_month);
    $rest_days = (int) round($remainder - $months * $days_per_month);

    $text = '';
    if ($years > 0) {
        $text .= $years . '年';
    }
    if ($months > 0) {
        $text .= $months . '月';
    }
    // 整年整月时剩余天数为 0，此时不再补「0天」；但若年、月都为 0，仍要把天数显示出来
    if ($rest_days > 0 || $text === '') {
        $text .= $rest_days . '天';
    }

    return $text;
}

/**
 * 按列统一小数位数：某一列只要有值带小数点，就把该列所有数值补齐到这一列最大的小数位数
 * （纯整数列、日期列保持原样）
 */
function align_column_decimals(array $rows, array $columns): array
{
    $decimals = array();
    foreach ($columns as $col) {
        $max = 0;
        foreach ($rows as $row) {
            $value = (string) $row[$col];
            if (!is_numeric($value)) {
                continue;
            }
            $dot = strpos($value, '.');
            if ($dot !== false) {
                $max = max($max, strlen($value) - $dot - 1);
            }
        }
        $decimals[$col] = $max;   // 0 表示该列没有小数
    }

    foreach ($rows as $i => $row) {
        foreach ($columns as $col) {
            $value = (string) $row[$col];
            if ($decimals[$col] > 0 && is_numeric($value)) {
                $rows[$i][$col] = number_format((float) $value, $decimals[$col], '.', '');
            }
        }
    }

    return $rows;
}

/**
 * 读取指数日线 CSV（列：date, level, change_pct）
 *
 * @return array<int, array{date: string, level: float}>
 */
function load_index_data(string $path): array
{
    $fp = fopen($path, 'r');
    if ($fp === false) {
        fwrite(STDERR, "无法打开输入文件：{$path}\n");
        exit(1);
    }

    $index_data = array();
    while (($cols = fgetcsv($fp, 0, ',', '"', '\\')) !== false) {
        $date = ltrim((string) ($cols[0] ?? ''), "\xEF\xBB\xBF");   // 首行可能带 BOM
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            continue;                                              // 跳过表头与空行
        }
        $index_data[] = array(
            'date'  => $date,
            'level' => floatval($cols[1]),
        );
    }
    fclose($fp);

    return $index_data;
}

// ------ 主逻辑 ------

require_once __DIR__ . '/config_loader.php';

$opts = getopt('', ['in::', 'out::', 'top-out::', 'html-out::', 'top::', 'min::', 'title::', 'help']);

if (isset($opts['help'])) {
    echo "用法：php return_drawdown_analyze.php [--in=us30_daily.csv] [--out=xxx.csv] [--top-out=yyy.csv] [--html-out=yyy.html] [--top=0.1] [--min=0.01] [--title=指数名]\n";
    echo "默认输入读 config.php 中 daily 目录下的 {in}.csv，回撤 CSV 落 drawdown 目录、HTML 落 html 目录，通常只需传 --in（和 --title）。\n";
    exit(0);
}

// 标准目录统一来自项目根目录 config.php（经 config_loader.php 解析）：
// 输入文件未带路径时按 daily 目录查找，回撤 CSV 写 drawdown 目录，HTML 写 html 目录
$config       = quant_config();
$daily_dir    = $config['daily_dir'];
$drawdown_dir = $config['drawdown_dir'];
$html_dir     = $config['html_dir'];

$in_file = (string) ($opts['in'] ?? '');
if ($in_file === '') {
    $in_file = $daily_dir . '/msci_usa_50_GRTR_DAILY.csv';
} elseif (!str_contains($in_file, '/')) {
    $in_file = $daily_dir . '/' . $in_file;
}

$in_base = preg_replace('/\.csv$/i', '', basename($in_file));

$out_file = (string) ($opts['out'] ?? '');
if ($out_file === '') {
    $out_file = $drawdown_dir . '/' . $in_base . '_drawdown.csv';
} elseif (!str_contains($out_file, '/')) {
    $out_file = $drawdown_dir . '/' . $out_file;
}

$top_file = (string) ($opts['top-out'] ?? '');
if ($top_file === '') {
    $top_file = $drawdown_dir . '/' . $in_base . '_drawdown_top.csv';
} elseif (!str_contains($top_file, '/')) {
    $top_file = $drawdown_dir . '/' . $top_file;
}

$html_file = (string) ($opts['html-out'] ?? '');
if ($html_file === '') {
    $html_file = $html_dir . '/' . $in_base . '_drawdown_top.html';
} elseif (!str_contains($html_file, '/')) {
    $html_file = $html_dir . '/' . $html_file;
}
$min_ratio = (float) ($opts['min'] ?? 0.01);   // 太小的回撤忽略
$top_ratio = (float) ($opts['top'] ?? 0.1);    // 取回撤幅度最大的前 10%
// 页面标题里的指数名：优先用 --title，没传时由输入文件名推导（去掉 _daily 后缀、下划线换空格），
// 如 sp500_daily.csv -> SP500
$index_label = (string) ($opts['title']
    ?? strtoupper(str_replace('_', ' ', preg_replace('/_daily$/i', '', basename($in_file, '.csv')))));

$index_data = load_index_data($in_file);
if (count($index_data) < 2) {
    fwrite(STDERR, "输入数据不足：{$in_file}\n");
    exit(1);
}

// 计算历史回撤记录
$dropdown_records = array();   // 历史回撤记录

// 初始化高点和低点
$date_value  = current($index_data);
$high_point  = array(
    'date'  => $date_value['date'],
    'level' => $date_value['level'],
    'index' => 0,
);
$low_point = $high_point;

$dropdown_high_point_index = -1;   // 历史回撤记录中的高点索引，用于合并回撤周期

// 单调递减栈，用于 O(log n) 查询「某个起点到当前交易日之间的最高点位」：
// 栈内元素按 index 递增、level 严格递减，[l, 当前] 的最高点位就是第一个 index >= l 的元素的 level。
// 合并回撤时用它挡掉「区间中途曾涨得比起点高点更高」的候选记录。
$max_stack = array();

/** 查询 [$from_index, 当前交易日] 区间内的最高点位 */
$window_max = function (int $from_index) use (&$max_stack): float {
    $lo = 0;
    $hi = count($max_stack) - 1;
    $ans = $max_stack[$hi]['level'];
    while ($lo <= $hi) {
        $mid = intdiv($lo + $hi, 2);
        if ($max_stack[$mid]['index'] >= $from_index) {
            $ans = $max_stack[$mid]['level'];
            $hi  = $mid - 1;
        } else {
            $lo = $mid + 1;
        }
    }

    return $ans;
};

foreach ($index_data as $order => $date_value) {
    // 维护单调递减栈：弹出所有不高于当前点位的元素，当前点位入栈
    while ($max_stack !== [] && $max_stack[count($max_stack) - 1]['level'] <= $date_value['level']) {
        array_pop($max_stack);
    }
    $max_stack[] = array('index' => $order, 'level' => $date_value['level']);

    if ($order == 0) {
        continue;
    }
    $level_diff = $date_value['level'] - $index_data[$order - 1]['level'];

    if ($level_diff > 0) {   // 上涨
        // 上涨，当前点位大于低点，一个完整的回撤周期
        if ($date_value['level'] > $low_point['level']) {
            // 存储之前的回撤
            if ($high_point['date'] != $low_point['date'] && $high_point['level'] != $low_point['level']) {
                $dropdown_info = array(
                    'date_start'  => $high_point['date'],
                    'high_level'  => $high_point['level'],
                    'date_end'    => $low_point['date'],
                    'low_level'   => $low_point['level'],
                    'start_index' => $high_point['index'],   // 合并时用来查区间最高点位
                );

                if ($dropdown_high_point_index == -1) {   // 第一个回撤周期
                    $dropdown_records[] = $dropdown_info;
                    $dropdown_high_point_index = 0;
                } else {                                  // 非第一个回撤周期
                    // 前一个回撤周期和当前回撤周期相同，替换掉即可
                    if ($dropdown_records[count($dropdown_records) - 1]['date_start'] == $dropdown_info['date_start']) {
                        $dropdown_records[count($dropdown_records) - 1] = $dropdown_info;
                    } else {                              // 新增回撤周期
                        $dropdown_records[] = $dropdown_info;
                    }
                }
                // 更换高点索引
                if ($dropdown_records[$dropdown_high_point_index]['high_level'] <= $dropdown_info['high_level']) {
                    $dropdown_high_point_index = count($dropdown_records) - 1;
                }
            }
            // 更新高点和低点
            $high_point = array(
                'date'  => $date_value['date'],
                'level' => $date_value['level'],
                'index' => $order,
            );
            $low_point = $high_point;
        }
    } else if ($level_diff < 0) {   // 下跌
        if ($date_value['level'] < $low_point['level']) {
            $low_point = array(
                'date'  => $date_value['date'],
                'level' => $date_value['level'],
                'index' => $order,
            );

            if ($dropdown_high_point_index >= 0) {
                // 分析之前的回撤，是否可以合并
                $max_high_point_index = -1;   // 最大高点索引
                $max_high_point_last  = 0;    // 最大高点的点位
                for ($i = $dropdown_high_point_index; $i < count($dropdown_records); $i++) {
                    // 如果之前回撤的高点和低点都高于当前的高点和低点，且是最大高点，则可以合并
                    if ($dropdown_records[$i]['high_level'] > $high_point['level'] && $dropdown_records[$i]['low_level'] > $low_point['level']) {
                        // 该记录的高点必须是 [它的起点, 当前交易日] 区间里的最高点位，否则合并出来的回撤
                        // 会把中途涨得更高的那段行情吞进去，导致 high_level 不是区间真正的高点
                        if ($dropdown_records[$i]['high_level'] < $window_max($dropdown_records[$i]['start_index']) - 1e-9) {
                            continue;
                        }
                        // 更新最大高点
                        if ($dropdown_records[$i]['high_level'] > $max_high_point_last) {
                            $max_high_point_last  = $dropdown_records[$i]['high_level'];
                            $max_high_point_index = $i;
                        }
                    }
                }
                // 能够合并回撤
                if ($max_high_point_index >= 0) {
                    // 合并回撤
                    $dropdown_records = array_slice($dropdown_records, 0, $max_high_point_index + 1);
                    $dropdown_records[$max_high_point_index]['date_end']  = $low_point['date'];
                    $dropdown_records[$max_high_point_index]['low_level'] = $low_point['level'];
                    // 更新高点
                    $high_point = array(
                        'date'  => $dropdown_records[$max_high_point_index]['date_start'],
                        'level' => $dropdown_records[$max_high_point_index]['high_level'],
                        'index' => $dropdown_records[$max_high_point_index]['start_index'],
                    );
                }
            }
        }
    } else {   // 点位持平
        // 持平，如果低点和当前一样，日期更新
        if ($low_point['level'] == $date_value['level']) {
            $low_point['date'] = $date_value['date'];
        }
    }
}

// 校验：不应存在「高点、低点都低于前一条回撤低点」的后续记录（说明合并逻辑漏了）
$dropdown_records_cnt = count($dropdown_records);
for ($i = 0; $i < $dropdown_records_cnt; $i++) {
    if (calculate_dropdown_ratio($dropdown_records[$i]) < $min_ratio) {
        continue;
    }
    for ($j = $i + 1; $j < $dropdown_records_cnt; $j++) {
        if ($dropdown_records[$i]['high_level'] <= $dropdown_records[$j]['high_level']) {
            break;
        }
        if ($dropdown_records[$i]['low_level'] > $dropdown_records[$j]['high_level']) {
            fwrite(STDERR, "回撤计算错误：{$dropdown_records[$i]['date_start']} 与 {$dropdown_records[$j]['date_start']}\n");
            exit(1);
        }
    }
}
echo "回撤计算校验通过\n";

// 整理出待输出的回撤记录（过滤掉太小的回撤）
$output_rows = array();
foreach ($dropdown_records as $record) {
    $ratio = calculate_dropdown_ratio($record);
    if ($ratio < $min_ratio) {   // 太小的回撤忽略
        continue;
    }

    $output_rows[] = array(
        'date_start'   => $record['date_start'],
        'high_level'   => round($record['high_level'], 6),
        'date_end'     => $record['date_end'],
        'low_level'    => round($record['low_level'], 6),
        'drawdown_pct' => $ratio,
        'days'         => (int) round((strtotime($record['date_end']) - strtotime($record['date_start'])) / 86400),
    );
}

// 写出 CSV
$fp_out = fopen($out_file, 'w');
if ($fp_out === false) {
    fwrite(STDERR, "无法写入输出文件：{$out_file}\n");
    exit(1);
}

fwrite($fp_out, "\xEF\xBB\xBF");   // BOM，方便 Excel 直接打开
fputcsv($fp_out, ['date_start', 'high_level', 'date_end', 'low_level', 'drawdown_pct', 'days'], ',', '"', '\\');
foreach ($output_rows as $row) {
    fputcsv($fp_out, array_values($row), ',', '"', '\\');
}
fclose($fp_out);

$written = count($output_rows);

printf("数据区间 %s ~ %s，共 %d 个交易日\n", $index_data[0]['date'], $index_data[count($index_data) - 1]['date'], count($index_data));
printf("回撤记录 %d 条（幅度 >= %s%%），已写入：%s\n", $written, $min_ratio, $out_file);
if ($output_rows === []) {
    echo "没有满足条件的回撤记录\n";
    exit(0);
}

// ------ 回撤幅度排名前 10% 的记录，单独按日期输出 ------

// 按回撤幅度从大到小排名（幅度相同则日期靠前的排在前面）
$ranked_rows = $output_rows;
usort($ranked_rows, function (array $a, array $b): int {
    return $b['drawdown_pct'] <=> $a['drawdown_pct'] ?: strcmp($a['date_start'], $b['date_start']);
});

$max_record = $ranked_rows[0];
printf("最大回撤  %s ~ %s  %.4f -> %.4f  -%.2f%%\n",
    $max_record['date_start'], $max_record['date_end'],
    $max_record['high_level'], $max_record['low_level'], $max_record['drawdown_pct']);

$total_cnt = count($ranked_rows);
$top_cnt   = (int) ceil($total_cnt * $top_ratio);   // 前 10%，向上取整，至少 1 条
$top_rows  = array_slice($ranked_rows, 0, $top_cnt);

// 打上名次：rank 是在前 10% 子集中的名次（也就是它在全部回撤中的名次，1 = 回撤最大）
// rank_decile 是该名次落在总名次中的第几个十分档 = ceil(rank / 总名次条数 * 10)，整数 1 ~ 10
foreach ($top_rows as $i => $row) {
    $top_rows[$i]['rank']        = $i + 1;
    $top_rows[$i]['rank_decile'] = (int) ceil(($i + 1) / $top_cnt * 10);
}

$cutoff_pct = $top_rows[$top_cnt - 1]['drawdown_pct'];   // 入选门槛

// 按日期排序后落盘
usort($top_rows, fn(array $a, array $b): int => strcmp($a['date_start'], $b['date_start']));

$fp_top = fopen($top_file, 'w');
if ($fp_top === false) {
    fwrite(STDERR, "无法写入输出文件：{$top_file}\n");
    exit(1);
}

fwrite($fp_top, "\xEF\xBB\xBF");
fputcsv($fp_top, ['date_start', 'high_level', 'date_end', 'low_level', 'drawdown_pct', 'days', 'rank', 'rank_decile'], ',', '"', '\\');
foreach ($top_rows as $row) {
    fputcsv($fp_top, array_values($row), ',', '"', '\\');
}
fclose($fp_top);

printf("回撤幅度前 %s%% 共 %d 条（入选门槛 %.2f%%），已按日期写入：%s\n",
    round($top_ratio * 100, 2), $top_cnt, $cutoff_pct, $top_file);

// ------ 同一批数据再生成一个 HTML，行背景色按 rank_decile 着色 ------

// HTML 里把名次两列排在最前面，并在 days 后面补上 days 的排名、回撤修复日期与点位、以及修复耗时
$columns = ['rank', 'rank_decile', 'date_start', 'high_level', 'date_end', 'low_level', 'drawdown_pct', 'rebound_pct', 'days', 'days_rank', 'dca_low_pct', 'recover_date', 'recover_level', 'recover_days', 'dca_positive_date', 'dca_days', 'next_high_pct'];

// days_rank：回撤持续天数的名次，天数越长名次越靠前，天数相同则名次相同
$days_desc = array_column($top_rows, 'days');
rsort($days_desc, SORT_NUMERIC);
$days_rank_map = array();
foreach ($days_desc as $i => $days) {
    if (!isset($days_rank_map[$days])) {
        $days_rank_map[$days] = $i + 1;
    }
}
foreach ($top_rows as $i => $row) {
    $top_rows[$i]['days_rank'] = $days_rank_map[$row['days']];
}

// recover_date / recover_level：从输入数据里找 date_end（低点）之后第一次收盘点位 >= 本段 high_level
// 的那一天（也就是这段回撤被修复的日期），找不到则留空
// 注意必须从低点之后开始找：合并出来的大回撤在区间内可能短暂涨回起点峰值之上，
// 若从 date_start 起找会取到低点之前的日期，导致 recover_days 为负
foreach ($top_rows as $i => $row) {
    $top_rows[$i]['recover_date']  = '';
    $top_rows[$i]['recover_level'] = '';
    $top_rows[$i]['recover_days']  = '';   // 低点到修复日的自然日天数
    foreach ($index_data as $point) {
        if ($point['date'] <= $row['date_end']) {
            continue;
        }
        if ($point['level'] >= $row['high_level']) {
            $top_rows[$i]['recover_date']  = $point['date'];
            $top_rows[$i]['recover_level'] = round($point['level'], 6);
            $top_rows[$i]['recover_days']  = (int) round((strtotime($point['date']) - strtotime($row['date_end'])) / 86400);
            break;
        }
    }
}

$html  = "<!DOCTYPE html>\n<html lang=\"zh-CN\">\n<head>\n<meta charset=\"utf-8\">\n";
$html .= '<title>' . htmlspecialchars($index_label . ' 历史收益与回撤分析', ENT_QUOTES) . "</title>\n";
$html .= "<style>\n";
$html .= "body{font-family:-apple-system,\"PingFang SC\",Helvetica,Arial,sans-serif;margin:24px;color:#222}\n";
$html .= "table{border-collapse:collapse;font-variant-numeric:tabular-nums}\n";
$html .= "caption{text-align:left;padding-bottom:8px;font-size:14px;color:#555}\n";
$html .= "th,td{border:1px solid #ccc;padding:6px 12px;text-align:right;white-space:nowrap}\n";
$html .= "th{background:#333;color:#fff;text-align:center}\n";
$html .= "tbody td{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,\"Courier New\",monospace}\n";
$html .= "td[title]{text-decoration:underline dotted #999}\n";
$html .= "table.kv td:first-child{text-align:left}\n";
// 年度涨跌幅表每格是「涨跌幅 + 年份」两行，居中排更整齐
$html .= "#annual-table tbody td{text-align:center}\n";
// 所有表格的表头吸顶，滚动查看下方数据时仍能看到列名；sticky 作用域限于各自的表格，
// border-collapse:collapse 下 th 的边框不会随 sticky 一起固定，用 box-shadow 补一条分隔线
$html .= "thead th{position:sticky;top:0;z-index:2;box-shadow:0 1px 0 #ccc}\n";
// 点击选中的行盖一层半透明蓝：用 background-image 画色层，与单元格内联的 background-color
// 是两个不同属性，不存在优先级冲突，所以无需 !important 就能叠在分组色带和灰底标记之上
$html .= "#drawdown tbody tr.selected td,#milestone-table tbody tr.selected td"
    . "{background-image:linear-gradient(rgba(40,80,220,0.22),rgba(40,80,220,0.22))}\n";
$html .= "h1{font-size:20px;margin:0 0 8px}\n";
$html .= "nav.toc{font-size:14px;margin:0 0 20px}\n";
$html .= "nav.toc a{margin-right:16px;white-space:nowrap}\n";
// 可排序的列：表头带 ⇅ 标记 + 手型光标，悬停时标记变亮，正在按该列排序时变成 ▲
$html .= "#drawdown thead th[data-sort]{cursor:pointer}\n";
$html .= "#drawdown thead th[data-sort]:hover .sort-flag{color:#fff}\n";
$html .= ".sort-flag{margin-left:5px;font-size:11px;font-weight:normal;color:#9a9a9a}\n";
$html .= ".sort-flag.on{color:#ffd54f}\n";
// 表头列名最多显示 5 个汉字宽，超出部分裁掉并显示省略号，完整名称在 th 的 title 里；
// 用内层 inline-block 限宽，这样即使列被下面的数据撑宽，表头文字也不会超过 5em
$html .= "#drawdown thead th .th-label{display:inline-block;max-width:5em;overflow:hidden;"
    . "text-overflow:ellipsis;white-space:nowrap;vertical-align:bottom}\n";
$html .= "</style>\n</head>\n<body>\n";

// ------ 页面标题与目录 ------

$html .= '<h1>' . htmlspecialchars($index_label . ' 历史收益与回撤分析', ENT_QUOTES) . "</h1>\n";
$html .= "<nav class=\"toc\">目录："
    . '<a href="#overall">整体收益</a>'
    . '<a href="#trailing">近若干年年化收益率与年化外推</a>'
    . '<a href="#annual">年度涨跌幅</a>'
    . "<a href=\"#drawdown-top\">回撤记录</a>"
    . "<a href=\"#milestones\">关键高低点涨幅与年化</a></nav>\n";

// ------ 整体收益板块 ------

$first_point  = $index_data[0];
$last_point   = $index_data[count($index_data) - 1];
$total_growth = $last_point['level'] / $first_point['level'];
// 区间年数按自然日折算，365.25 计入闰年
$span_years = (strtotime($last_point['date']) - strtotime($first_point['date'])) / 86400 / 365.25;
$cagr       = $span_years > 0 ? $total_growth ** (1 / $span_years) - 1 : 0.0;

$html .= "<h2 id=\"overall\">整体收益</h2>\n";
$html .= "<table class=\"kv\">\n<tbody>\n";
$html .= '<tr><td>数据区间</td><td>' . htmlspecialchars($first_point['date'] . ' ~ ' . $last_point['date'], ENT_QUOTES)
    . '（' . number_format($span_years, 2, '.', '') . " 年）</td></tr>\n";
$html .= '<tr><td>期初点位</td><td>' . number_format($first_point['level'], 2, '.', '') . "</td></tr>\n";
$html .= '<tr><td>期末点位</td><td>' . number_format($last_point['level'], 2, '.', '') . "</td></tr>\n";
$html .= '<tr><td>累计收益率</td><td>' . number_format(($total_growth - 1) * 100, 2, '.', '') . "%</td></tr>\n";
$html .= '<tr><td>年化收益率(CAGR)</td><td>' . number_format($cagr * 100, 2, '.', '') . "%</td></tr>\n";
$html .= "</tbody>\n</table>\n";

// ------ 近 N 年年化收益率 + 年化外推板块 ------
// 年化外推原来是【整体收益】下的独立表格（只按整体年化收益率外推），现在并入本表：
// 每一行都用该行自己的年化收益率往后推，「全部」那一行即原来那张表的结果
$future_spans = [5, 10, 20, 30];

$html .= "<h2 id=\"trailing\">近若干年年化收益率与年化外推</h2>\n";
$html .= "<table>\n<caption>" . htmlspecialchars(
    '起点取「期末日期往前推 N 年」当天或之前最近的一个交易日，年化按该起点到期末的实际天数折算；'
    . '「未来 N 年」是按本行年化收益率机械外推的累计收益率，仅为复利换算，不构成对未来行情的预测',
    ENT_QUOTES) . "</caption>\n";
$html .= "<thead>\n<tr><th>近 N 年</th><th>起点日期</th><th>起点点位</th><th>累计收益率(%)</th><th>年化收益率(%)</th>";
foreach ($future_spans as $future_years) {
    $html .= '<th>未来 ' . $future_years . ' 年(%)</th>';
}
$html .= "</tr>\n</thead>\n<tbody>\n";

// 先确定每一行的起点：近 N 年取往前推 N 年的最近交易日，最后再补一行整个区间
// 往前推超出数据区间的（如只有 26 年历史却要近 30 年）直接跳过，不出这一行
$trailing_rows = array();
foreach ([5, 10, 15, 20, 25, 30] as $trailing_years) {
    $target_date = date('Y-m-d', strtotime($last_point['date'] . " -{$trailing_years} years"));
    if ($target_date < $first_point['date']) {
        continue;
    }

    // 找出 target_date 当天或之前最近的一个交易日
    $base_point = null;
    foreach ($index_data as $point) {
        if ($point['date'] > $target_date) {
            break;
        }
        $base_point = $point;
    }

    if ($base_point === null) {
        continue;
    }

    $trailing_rows[] = array('label' => (string) $trailing_years, 'base' => $base_point);
}
$trailing_rows[] = array('label' => '全部', 'base' => $first_point);

foreach ($trailing_rows as $row_spec) {
    $base_point = $row_spec['base'];

    $growth        = $last_point['level'] / $base_point['level'];
    $years_exact   = (strtotime($last_point['date']) - strtotime($base_point['date'])) / 86400 / 365.25;
    $trailing_cagr = $years_exact > 0 ? $growth ** (1 / $years_exact) - 1 : 0.0;

    $html .= '<tr><td>' . htmlspecialchars($row_spec['label'], ENT_QUOTES) . '</td>'
        . '<td>' . htmlspecialchars($base_point['date'], ENT_QUOTES) . '</td>'
        . '<td>' . number_format($base_point['level'], 2, '.', '') . '</td>'
        . '<td>' . number_format(($growth - 1) * 100, 2, '.', '') . '</td>'
        . '<td>' . number_format($trailing_cagr * 100, 2, '.', '') . '</td>';
    foreach ($future_spans as $future_years) {
        $multiple = (1 + $trailing_cagr) ** $future_years;
        $html .= '<td>' . number_format(($multiple - 1) * 100, 2, '.', '') . '</td>';
    }
    $html .= "</tr>\n";
}

$html .= "</tbody>\n</table>\n";

// ------ 年度涨跌幅板块（放在页面最上方）：每年最后一个交易日相对上一年末的涨跌幅，每行 5 个年份 ------

$year_last = array();   // 每年最后一个交易日的点位
foreach ($index_data as $point) {
    $year_last[(int) substr($point['date'], 0, 4)] = $point['level'];
}

$annual_returns = array();
$prev_close = null;
foreach ($year_last as $year => $level) {
    // 首年没有上一年末，用数据起始点位作基准（因此首年是不完整年度）
    $base = $prev_close ?? $index_data[0]['level'];
    $annual_returns[$year] = round(($level / $base - 1) * 100, 2);
    $prev_close = $level;
}

$first_year    = (int) substr($index_data[0]['date'], 0, 4);
$last_year     = (int) substr($index_data[count($index_data) - 1]['date'], 0, 4);
// 每行的年份格数取总年份个数的平方根（取整），让表格接近正方形
$years_per_row = max(1, (int) sqrt(count($annual_returns)));

$html .= '<h2 id="annual">' . htmlspecialchars('年度涨跌幅（' . $first_year . ' ~ ' . $last_year . '）', ENT_QUOTES) . "</h2>\n";
$html .= "<table id=\"annual-table\">\n<caption>" . htmlspecialchars(
    '按每年最后一个交易日的收盘点位与上一年末对比；每个单元格上行为涨跌幅(%)、下行为年份，底色按涨跌幅取（涨绿跌红，幅度越大越深）；'
    . $first_year . ' 年自 ' . $index_data[0]['date']
    . ' 起、' . $last_year . ' 年截至 ' . $index_data[count($index_data) - 1]['date'] . '，均为不完整年度',
    ENT_QUOTES) . "</caption>\n";

// 每格自带涨跌幅与年份两行，列名靠 caption 说明，不再输出表头
$html .= "<tbody>\n";

// 年份按「行」的读取顺序排布：横着往右读是连续年份，每行 $years_per_row 个
$years      = array_keys($annual_returns);
$year_count = count($years);
$row_count  = (int) ceil($year_count / $years_per_row);
$max_abs    = 0.0;                       // 用绝对值最大的那一年做配色的满格基准
foreach ($annual_returns as $pct) {
    $max_abs = max($max_abs, abs($pct));
}

for ($r = 0; $r < $row_count; $r++) {
    $html .= '<tr>';
    for ($c = 0; $c < $years_per_row; $c++) {
        $k = $r * $years_per_row + $c;
        if ($k >= $year_count) {
            $html .= '<td></td>';            // 末行不足时补空单元格，保持表格对齐
            continue;
        }
        $year = $years[$k];
        $pct  = $annual_returns[$year];
        // 年份并入涨跌幅单元格，上行涨跌幅、下行年份，底色仍只按涨跌幅取
        $html .= '<td style="background-color:' . annual_return_color($pct, $max_abs) . '">'
            . number_format($pct, 2, '.', '') . '<br>' . $year . '</td>';
    }
    $html .= "</tr>\n";
}
$html .= "</tbody>\n</table>\n";

// ------ 回撤记录表 ------
$html .= '<h2 id="drawdown-top">' . htmlspecialchars("回撤幅度前 " . round($top_ratio * 100, 2) . "% 的回撤记录（共 {$top_cnt} 条，按日期升序）", ENT_QUOTES) . "</h2>\n";
$html .= "<table id=\"drawdown\">\n";

// 表头用中文，鼠标悬停显示原来的英文列名
$column_labels = [
    'rank'              => '名次',
    'rank_decile'       => '档位',// 回撤幅度的名次所在的十档
    'date_start'        => '起点日期',
    'high_level'        => '起点高点',
    'date_end'          => '低点日期',
    'low_level'         => '低点点位',
    'drawdown_pct'      => '回撤幅度(%)',
    'rebound_pct'       => '回本涨幅(%)',
    'days'              => '回撤天数',
    'days_rank'         => '天数名次',
    'dca_low_pct'       => '定投至低点收益率(%)',
    'recover_date'      => '修复日期',
    'recover_level'     => '修复点位',
    'recover_days'      => '修复天数',
    'dca_positive_date' => '定投转正日期',
    'dca_days'          => '定投转正天数',
    'next_high_pct'     => '距下个新高涨幅(%)',
];

// 这几列的算法说明，和英文列名一起放进表头的 title 里
$column_hints = [
    'days'          => '回撤天数 = 低点日期 - 起点日期（自然日，含非交易日）',
    'dca_low_pct'   => "定投至低点收益率(%) = (低点日期的持仓市值 / 累计投入 - 1) * 100\n"
        . '从起点日期到低点日期之间每个交易日等额投入 1 份（两端都算在内），按当日点位买入份额',
    'recover_days'  => "修复天数 = 修复日期 - 低点日期（自然日）\n"
        . '修复日期取低点之后首个收盘点位 >= 起点高点的交易日',
    'dca_days'      => "定投转正天数 = 定投转正日期 - 起点日期（自然日）\n"
        . '从起点日期起每个交易日等额投入 1 份，越过低点日期后首次出现「持仓市值 > 累计投入」的交易日即为转正日',
    'next_high_pct' => "距下个新高涨幅(%) = (之后首个创新高行的起点高点 / 本行低点点位 - 1) * 100\n"
        . '创新高指该行起点高点 >= 它之前所有行的起点高点',
];

// 表头可点击排序的列：点一次按该列数值升序，再点一次恢复按起点日期升序
$sortable_columns = ['rank', 'days_rank'];

// caption 里的列名统一取 $column_labels，避免和表头写法不一致
$sortable_labels = array_map(fn(string $col): string => '【' . ($column_labels[$col] ?? $col) . '】', $sortable_columns);
$html .= "<caption>" . htmlspecialchars(
    implode('', $sortable_labels) . '的表头带 ⇅ 标记，点击可按该列数值从小到大排序，再点一次恢复按【'
    . $column_labels['date_start'] . '】升序；'
    . '【' . $column_labels['rank_decile'] . '】【' . $column_labels['drawdown_pct']
    . '】两列背景色按档位取十档固定色，从 #ffbfbf（第 1 档，回撤最大）到 #bfffbf（第 10 档）；'
    . '【' . $column_labels['high_level'] . '】未超过前面所有行时用 #d9d9d9 标记；'
    . '点击某行可把该行背景切换为 #e5e5ff（已有背景色的单元格保持原色），再点一次恢复',
    ENT_QUOTES) . "</caption>\n";

$html .= "<thead>\n<tr>";
foreach ($columns as $col) {
    $label = $column_labels[$col] ?? $col;
    // title 里先放完整中文列名和英文列名（表头只显示 5 个汉字宽，超出部分靠悬停看），有算法说明的再换行补上
    $tip = $label . '（' . $col . '）' . (isset($column_hints[$col]) ? "\n" . $column_hints[$col] : '');
    // 可排序的列标出来给 JS 用，默认显示 ⇅ 提示这一列可以排序，排序后由 JS 换成 ▲
    $sortable = in_array($col, $sortable_columns, true);
    if ($sortable) {
        $tip .= "\n点击按【" . $label . '】从小到大排序，再点一次恢复按起点日期升序';
    }
    $html .= '<th title="' . htmlspecialchars($tip, ENT_QUOTES) . '"'
        . ($sortable ? ' data-sort="' . $col . '"' : '') . '>'
        . '<span class="th-label">' . htmlspecialchars($label, ENT_QUOTES) . '</span>'
        . ($sortable ? '<span class="sort-flag">&#8645;</span>' : '') . '</th>';
}
$html .= "</tr>\n</thead>\n<tbody>\n";

// dca_positive_date / dca_days：从 date_start 起每个交易日等额定投（每天投 1 份钱），一路投过
// date_end，之后第一次累计收益率转正（持仓市值 > 累计投入）的日期，以及从 date_start 算起的天数；
// 之所以只认 date_end 之后的日期，是因为下跌途中的反弹经常让定投短暂浮盈，那不是「熬过这轮回撤」
// dca_low_pct 顺带记下走到 date_end（低点当天）时这笔定投的收益率，用来对比一次性买入的回撤幅度
foreach ($top_rows as $i => $row) {
    $top_rows[$i]['dca_positive_date'] = '';
    $top_rows[$i]['dca_days']          = '';
    $top_rows[$i]['dca_low_pct']       = '';

    $shares = 0.0;   // 累计买到的份额
    $cost   = 0.0;   // 累计投入
    foreach ($index_data as $point) {
        if ($point['date'] < $row['date_start']) {
            continue;
        }
        $shares += 1 / $point['level'];
        $cost   += 1;

        if ($point['date'] === $row['date_end']) {
            $top_rows[$i]['dca_low_pct'] = round(($shares * $point['level'] / $cost - 1) * 100, 2);
        }

        if ($point['date'] > $row['date_end'] && $shares * $point['level'] > $cost) {
            $top_rows[$i]['dca_positive_date'] = $point['date'];
            $top_rows[$i]['dca_days']          = (int) round((strtotime($point['date']) - strtotime($row['date_start'])) / 86400);
            break;
        }
    }
}

// rebound_pct：从 low_level 涨回 high_level 所需的涨幅(%) = (high / low - 1) * 100
foreach ($top_rows as $i => $row) {
    $top_rows[$i]['rebound_pct'] = round(($row['high_level'] / $row['low_level'] - 1) * 100, 2);
}

// is_new_high：high_level 是否超过前面所有行（起点是否创了新高），用于灰底标记和下面的 next_high_pct
$high_max = null;
foreach ($top_rows as $i => $row) {
    $top_rows[$i]['is_new_high'] = $high_max === null || $row['high_level'] >= $high_max;
    $high_max = $high_max === null ? $row['high_level'] : max($high_max, $row['high_level']);
}

// next_high_pct：从本行 low_level 涨到之后第一个创新高的 high_level 所需的涨幅(%)，之后没有新高则留空
$top_row_count = count($top_rows);
foreach ($top_rows as $i => $row) {
    $top_rows[$i]['next_high_pct'] = '';
    for ($j = $i + 1; $j < $top_row_count; $j++) {
        if ($top_rows[$j]['is_new_high']) {
            $top_rows[$i]['next_high_pct'] = round(($top_rows[$j]['high_level'] / $row['low_level'] - 1) * 100, 2);
            break;
        }
    }
}

// 点位三列在 HTML 里只显示两位小数（CSV 仍保留 6 位）
foreach ($top_rows as $i => $row) {
    foreach (['high_level', 'low_level', 'recover_level'] as $col) {
        if (is_numeric((string) $row[$col])) {
            $top_rows[$i][$col] = number_format((float) $row[$col], 2, '.', '');
        }
    }
}

$colored_columns = ['rank_decile', 'drawdown_pct'];   // 需要按档位上色的列

foreach (align_column_decimals($top_rows, $columns) as $row) {
    $html .= '<tr>';
    foreach ($columns as $col) {
        if (in_array($col, $colored_columns, true)) {
            // rank_decile、drawdown_pct 按 rank_decile 档位上色
            $style = ' style="background-color:' . decile_color((int) $row['rank_decile']) . '"';
        } elseif ($col === 'high_level' && !$row['is_new_high']) {
            // high_level 没有超过前面所有行，说明这段回撤的起点不是新高，用灰底标记
            $style = ' style="background-color:#d9d9d9"';
        } else {
            $style = '';
        }
        // days、recover_days、dca_days 超过 1 个月时，鼠标悬停显示「Y年M月N天」
        $title = '';
        if (in_array($col, ['days', 'recover_days', 'dca_days'], true) && (int) $row[$col] > 30) {
            $title = ' title="' . htmlspecialchars(format_duration_text((int) $row[$col]), ENT_QUOTES) . '"';
        }


        $html .= '<td' . $style . $title . '>' . htmlspecialchars((string) $row[$col], ENT_QUOTES) . '</td>';
    }
    $html .= "</tr>\n";
}

$html .= "</tbody>\n</table>\n";

// ------ 关键高低点之间的涨幅与年化板块 ------
// 集合成员：档位为 1（回撤最大的一档）的全部记录，再并入第一条和最后一条回撤记录，
// 后两条用来把区间补齐到数据的两端。
$milestones = array();
foreach ($top_rows as $row) {
    if ((int) $row['rank_decile'] === 1) {
        $milestones[$row['date_start']] = $row;
    }
}
$milestones[$top_rows[0]['date_start']]                       = $top_rows[0];
$milestones[$top_rows[count($top_rows) - 1]['date_start']]    = $top_rows[count($top_rows) - 1];
ksort($milestones);                                           // $top_rows 已按起点日期升序，这里按 key 再排一次
$milestones = array_values($milestones);

// 【名次】是回撤幅度在本表格这些节点里的排名（1 = 幅度最大），幅度相同则起点日期靠前的排在前面
$milestone_order = range(0, count($milestones) - 1);
usort($milestone_order, fn(int $a, int $b): int =>
    $milestones[$b]['drawdown_pct'] <=> $milestones[$a]['drawdown_pct']
        ?: strcmp($milestones[$a]['date_start'], $milestones[$b]['date_start']));
$milestone_rank = array();
foreach ($milestone_order as $pos => $idx) {
    $milestone_rank[$idx] = $pos + 1;
}

/** 两个端点之间的涨幅(%)、年化(%)与自然日天数；终点不晚于起点时返回 null */
$leg = function (float $from_level, string $from_date, float $to_level, string $to_date): ?array {
    $days = (int) round((strtotime($to_date) - strtotime($from_date)) / 86400);
    if ($days <= 0) {
        return null;
    }
    $growth = $to_level / $from_level;

    return array(
        'days' => $days,
        'pct'  => ($growth - 1) * 100,
        'cagr' => ($growth ** (365.25 / $days) - 1) * 100,
    );
};

// 定投收益率：用「累计 1/点位」的前缀和，O(1) 算出任意两个交易日之间每日等额定投到期末的收益率
$date_index = array();
$prefix_inv = array();
$acc_inv    = 0.0;
foreach ($index_data as $k => $point) {
    $date_index[$point['date']] = $k;
    $acc_inv                   += 1 / $point['level'];
    $prefix_inv[$k]            = $acc_inv;
}

/** [$from_date, $to_date] 每个交易日等额投入 1 份（两端都算在内），到期末的累计收益率(%) */
$dca_return = function (string $from_date, string $to_date) use ($index_data, $date_index, $prefix_inv): ?float {
    if (!isset($date_index[$from_date], $date_index[$to_date])) {
        return null;
    }
    $l = $date_index[$from_date];
    $r = $date_index[$to_date];
    if ($r < $l) {
        return null;
    }
    $shares = $prefix_inv[$r] - ($l > 0 ? $prefix_inv[$l - 1] : 0.0);

    return ($shares * $index_data[$r]['level'] / ($r - $l + 1) - 1) * 100;
};

// 四种组合：上一节点的高/低点 → 本节点的高/低点。short 用作表头前缀，不带箭头和空格，保持表头紧凑
$legs = array(
    array('short' => '高高', 'label' => '上一节点的起点高点 → 本节点的起点高点'),
    array('short' => '高低', 'label' => '上一节点的起点高点 → 本节点的低点点位'),
    array('short' => '低高', 'label' => '上一节点的低点点位 → 本节点的起点高点'),
    array('short' => '低低', 'label' => '上一节点的低点点位 → 本节点的低点点位'),
);

$html .= '<h2 id="milestones">关键高低点之间的涨幅与年化（共 ' . count($milestones) . " 个节点）</h2>\n";
$html .= "<table id=\"milestone-table\">\n<caption>" . htmlspecialchars(
    '节点取【档位】为 1（回撤幅度最大的一档）的记录，再并入第一条和最后一条回撤记录，按【起点日期】升序排列；'
    . '后 8 列是「上一节点 → 本节点」的涨幅与年化收益率，列名的「高低」二字分别指上一节点、本节点取的是高点还是低点'
    . '（如【低高涨幅(%)】= 上一节点低点 → 本节点高点），悬停在表头上可看到完整说明；'
    . '年化按两个端点的实际自然日天数折算（1 年 = 365.25 天），悬停在涨幅、年化数值上可看到计算区间的起止日期与相隔天数（「Y年M月N天」）；'
    . '最后 4 列是同一区间内每个交易日等额定投到期末的累计收益率(%)；'
    . '【起点高点】未超过它之前所有回撤记录的起点高点（即起点不是历史新高）时用 #d9d9d9 标记；'
    . '【回撤幅度】按【名次】着色，名次越靠前越接近深红、越靠后越接近深绿、居中的接近白色；'
    . '【回撤天数】= 低点日期 - 起点日期（自然日），超过 1 个月时悬停可看「Y年M月N天」；'
    . '第 1 个节点没有上一节点，故留空；点击某行可给该行叠加一层半透明蓝色高亮，再点一次恢复',
    ENT_QUOTES) . "</caption>\n";
$html .= "<thead>\n<tr>"
    . '<th title="' . htmlspecialchars("名次\n本行【回撤幅度】在本表这些节点中的排名，1 = 幅度最大", ENT_QUOTES) . '">名次</th>'
    . "<th>起点日期</th><th>起点高点</th><th>低点日期</th><th>低点点位</th><th>回撤幅度(%)</th>"
    . '<th title="' . htmlspecialchars("回撤天数\n低点日期 - 起点日期（自然日，含非交易日）", ENT_QUOTES) . '">回撤天数</th>';
// 涨幅、年化、定投各自成组排列，同类列相邻便于横向比较
foreach ($legs as $leg_def) {
    $html .= '<th title="' . htmlspecialchars($leg_def['short'] . "涨幅(%)\n" . $leg_def['label'], ENT_QUOTES) . '">'
        . htmlspecialchars($leg_def['short'], ENT_QUOTES) . '涨幅(%)</th>';
}
foreach ($legs as $leg_def) {
    $html .= '<th title="' . htmlspecialchars($leg_def['short'] . "年化(%)\n" . $leg_def['label'], ENT_QUOTES) . '">'
        . htmlspecialchars($leg_def['short'], ENT_QUOTES) . '年化(%)</th>';
}
foreach ($legs as $leg_def) {
    $html .= '<th title="' . htmlspecialchars(
        $leg_def['short'] . "定投(%)\n" . $leg_def['label'] . "\n"
        . '这两个端点日期之间每个交易日等额投入 1 份（两端都算在内），到期末的累计收益率(%)',
        ENT_QUOTES) . '">' . htmlspecialchars($leg_def['short'], ENT_QUOTES) . '定投(%)</th>';
}
$html .= "</tr>\n</thead>\n<tbody>\n";

// 三组列各自的底色：涨幅淡黄、年化淡青、定投淡紫，让分组在视觉上连成色带
$pct_bg  = ' style="background-color:#ffffe5"';
$cagr_bg = ' style="background-color:#e5ffff"';
$dca_bg  = ' style="background-color:#e5e5ff"';

// 【回撤幅度】按【名次】上渐变底色：名次 1 最深红，名次末位最深绿，正中间的名次接近白色。
// 把名次换算成「离正中名次的带符号距离」后复用年度涨跌幅的配色函数（绿正、红负，饱和度 5%~100%）
$rank_middle   = (count($milestones) + 1) / 2;
$rank_max_dist = $rank_middle - 1;

foreach ($milestones as $i => $row) {
    // 起点不是历史新高时给【起点高点】铺灰底，与回撤表一致
    $high_style = $row['is_new_high'] ? '' : ' style="background-color:#d9d9d9"';
    $html .= '<tr>'
        . '<td>' . $milestone_rank[$i] . '</td>'
        . '<td>' . htmlspecialchars($row['date_start'], ENT_QUOTES) . '</td>'
        . '<td' . $high_style . '>' . number_format($row['high_level'], 2, '.', '') . '</td>'
        . '<td>' . htmlspecialchars($row['date_end'], ENT_QUOTES) . '</td>'
        . '<td>' . number_format($row['low_level'], 2, '.', '') . '</td>'
        . '<td style="background-color:'
        . annual_return_color($milestone_rank[$i] - $rank_middle, $rank_max_dist) . '">'
        . number_format($row['drawdown_pct'], 2, '.', '') . '</td>';
    // 回撤天数与回撤表同样处理：超过 1 个月时鼠标悬停显示「Y年M月N天」
    $days_title = (int) $row['days'] > 30
        ? ' title="' . htmlspecialchars(format_duration_text((int) $row['days']), ENT_QUOTES) . '"'
        : '';
    $html .= '<td' . $days_title . '>' . (int) $row['days'] . '</td>';

    if ($i === 0) {   // 第一个节点没有上一节点，空单元格也上底色，保持色带不断
        foreach ([$pct_bg, $cagr_bg, $dca_bg] as $group_bg) {
            $html .= str_repeat('<td' . $group_bg . '></td>', count($legs));
        }
        $html .= "</tr>\n";
        continue;
    }

    $prev  = $milestones[$i - 1];
    $pairs = array(
        array($prev['high_level'], $prev['date_start'], $row['high_level'], $row['date_start']),
        array($prev['high_level'], $prev['date_start'], $row['low_level'],  $row['date_end']),
        array($prev['low_level'],  $prev['date_end'],   $row['high_level'], $row['date_start']),
        array($prev['low_level'],  $prev['date_end'],   $row['low_level'],  $row['date_end']),
    );
    // 涨幅、年化、定投各自成组输出，所以先按类别分别攒起来
    $pct_cells  = '';
    $cagr_cells = '';
    $dca_cells  = '';
    foreach ($pairs as [$from_level, $from_date, $to_level, $to_date]) {
        $r = $leg($from_level, $from_date, $to_level, $to_date);
        if ($r === null) {   // 两段回撤区间重叠，终点不晚于起点，算不出有意义的年化
            $pct_cells  .= '<td' . $pct_bg . '>-</td>';
            $cagr_cells .= '<td' . $cagr_bg . '>-</td>';
        } else {
            // 涨幅与年化算的是同一段区间，悬停提示共用：起止日期 + 天数 + 「Y年M月N天」
            $tip = $from_date . ' → ' . $to_date . '，共 ' . $r['days'] . ' 天（' . format_duration_text($r['days']) . '）';
            $pct_cells  .= '<td' . $pct_bg . ' title="' . htmlspecialchars($tip, ENT_QUOTES) . '">'
                . number_format($r['pct'], 2, '.', '') . '</td>';
            $cagr_cells .= '<td' . $cagr_bg . ' title="' . htmlspecialchars($tip, ENT_QUOTES) . '">'
                . number_format($r['cagr'], 2, '.', '') . '</td>';
        }

        $dca = $dca_return($from_date, $to_date);
        if ($dca === null) {
            $dca_cells .= '<td' . $dca_bg . '>-</td>';
        } else {
            $dca_days   = (int) round((strtotime($to_date) - strtotime($from_date)) / 86400);
            $dca_tip    = '从 ' . $from_date . ' 定投到 ' . $to_date
                . '，共 ' . $dca_days . ' 天（' . format_duration_text($dca_days) . '）';
            $dca_cells .= '<td' . $dca_bg . ' title="' . htmlspecialchars($dca_tip, ENT_QUOTES) . '">'
                . number_format($dca, 2, '.', '') . '</td>';
        }
    }
    $html .= $pct_cells . $cagr_cells . $dca_cells . "</tr>\n";
}
$html .= "</tbody>\n</table>\n";
// 点击回撤表、节点表的某一行切换选中状态（整行背景变 #e5e5ff），再点一次恢复
$html .= "<script>\n";
$html .= "document.querySelectorAll('#drawdown tbody tr,#milestone-table tbody tr').forEach(function (tr) {\n";
$html .= "    tr.addEventListener('click', function () { tr.classList.toggle('selected'); });\n";
$html .= "});\n";
// 点击带 data-sort 的表头按该列数值升序排序，再点一次恢复原始顺序（即起点日期升序）；
// 列号直接用 th.cellIndex，换点另一列时改按新列排序并清掉上一列的方向标记
$html .= "(function () {\n";
$html .= "    var tbody = document.querySelector('#drawdown tbody');\n";
$html .= "    var ths = document.querySelectorAll('#drawdown thead th[data-sort]');\n";
$html .= "    if (!tbody || !ths.length) { return; }\n";
$html .= "    var origin = Array.prototype.slice.call(tbody.rows);   // 生成时已按起点日期升序\n";
$html .= "    var active = null;   // 当前排序列的表头，null 表示原始顺序\n";
$html .= "    ths.forEach(function (th) {\n";
$html .= "        th.addEventListener('click', function () {\n";
$html .= "            active = active === th ? null : th;\n";
$html .= "            var rows = origin.slice();\n";
$html .= "            if (active === th) {\n";
$html .= "                var index = th.cellIndex;\n";
$html .= "                rows.sort(function (a, b) {\n";
$html .= "                    return parseFloat(a.cells[index].textContent) - parseFloat(b.cells[index].textContent);\n";
$html .= "                });\n";
$html .= "            }\n";
$html .= "            var frag = document.createDocumentFragment();\n";
$html .= "            rows.forEach(function (tr) { frag.appendChild(tr); });\n";
$html .= "            tbody.appendChild(frag);\n";
$html .= "            ths.forEach(function (other) {\n";
$html .= "                var flag = other.querySelector('.sort-flag');\n";
$html .= "                if (!flag) { return; }\n";
$html .= "                flag.textContent = other === active ? '\\u25b2' : '\\u21c5';\n";
$html .= "                flag.classList.toggle('on', other === active);\n";
$html .= "            });\n";
$html .= "        });\n";
$html .= "    });\n";
$html .= "})();\n";
$html .= "</script>\n";

$html .= "</body>\n</html>\n";

if (file_put_contents($html_file, $html) === false) {
    fwrite(STDERR, "无法写入输出文件：{$html_file}\n");
    exit(1);
}

printf("HTML 已写入：%s\n", $html_file);

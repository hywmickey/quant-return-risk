<?php
/**
 * 生成滚动收益率（近 1 年 / 3 年 / 5 年）曲线图的 HTML
 *
 * 输入：data/daily/*.csv （列：date, level, change_pct，升序）
 * 输出：html/{数据源}_trailing_return.html
 *
 * 对数据区间内每个交易日 d，往前找「d 当天往前推 N 年」之前最近的一个交易日 b，
 * 收益率 = level(d) / level(b) - 1。三条曲线分别是 N = 1 / 3 / 5 年。
 * （1 年按 365.25 自然日折算，计入闰年；数据不足 N 年则该曲线从可算出的最早日期起画）
 *
 * 用法：
 *   php generate_trailing_return_html.php                      # 默认 fund_160213_daily.csv
 *   php generate_trailing_return_html.php --in=sp500_daily.csv --title=标普500
 *   php generate_trailing_return_html.php --help
 */

require_once __DIR__ . '/config_loader.php';

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
            'level' => (float) $cols[1],
        );
    }
    fclose($fp);

    return $index_data;
}

/**
 * 散点图的坐标轴刻度：把区间 [min, max] 按 1 / 2 / 5 × 10^n 的「好看」步长切分
 *
 * @return array{0: array<int, float>, 1: int}
 */
function nice_axis_ticks(float $min, float $max, int $target_count): array
{
    if ($max <= $min) {
        $max = $min + 1;
    }

    $raw_step = ($max - $min) / max(1, $target_count);
    $exponent = (int) floor(log10($raw_step));
    $base     = $raw_step / (10 ** $exponent);
    if ($base <= 1) {
        $multiplier = 1;
    } elseif ($base <= 2) {
        $multiplier = 2;
    } elseif ($base <= 5) {
        $multiplier = 5;
    } else {
        $multiplier = 10;
    }
    $step = $multiplier * (10 ** $exponent);

    $start = floor($min / $step) * $step;
    $end   = ceil($max / $step) * $step;
    $count = (int) round(($end - $start) / $step) + 1;

    $ticks = array();
    for ($i = 0; $i < $count; $i++) {
        $ticks[] = round($start + $i * $step, 6);
    }

    // 步长小于 1 时按步长的小数位显示标签（如 0.5 -> 1 位、0.05 -> 2 位），步长 >= 1 时取整数
    $decimals = max(0, (int) -floor(log10($step)));

    return array($ticks, $decimals);
}

// ------ 主逻辑 ------

$opts = getopt('', ['in::', 'title::', 'html-out::', 'help']);

if (isset($opts['help'])) {
    echo "用法：php generate_trailing_return_html.php [--in=xxx.csv] [--title=显示名]\n";
    echo "  生成滚动收益率（近 1/3/5 年）曲线图 HTML\n";
    echo "  输入未带路径时按 config.ini 的 daily 目录查找，默认 fund_160213_daily.csv\n";
    echo "  输出默认：html/{数据源}_trailing_return.html\n";
    exit(0);
}

$config    = quant_config();
$daily_dir = $config['daily_dir'];
$html_dir  = $config['html_dir'];

$in_file = (string) ($opts['in'] ?? 'fund_160213_daily.csv');
if (!str_contains($in_file, '/')) {
    $in_file = $daily_dir . '/' . $in_file;
}
if (!is_file($in_file)) {
    fwrite(STDERR, "输入文件不存在：{$in_file}\n");
    exit(1);
}

$in_base = preg_replace('/\.csv$/i', '', basename($in_file));

// 显示标题：优先 --title，再查 config.ini 的 titles_funds / titles_indexes，最后用文件名兜底
$index_label = (string) ($opts['title'] ?? '');
if ($index_label === '') {
    $titles_funds   = $config['titles_funds'];
    $titles_indexes = $config['titles_indexes'];
    $csv_name       = basename($in_file);
    if (str_starts_with($csv_name, 'fund_') && preg_match('/^fund_(\d+)_daily\.csv$/', $csv_name, $m)) {
        $index_label = $titles_funds[$m[1]] ?? "基金 {$m[1]}";
    } else {
        $index_label = $titles_indexes[$csv_name]
            ?? strtoupper(str_replace('_', ' ', preg_replace('/_daily$/i', '', $in_base)));
    }
}

$html_file = (string) ($opts['html-out'] ?? '');
if ($html_file === '') {
    $html_file = $html_dir . '/' . $in_base . '_trailing_return.html';
} elseif (!str_contains($html_file, '/')) {
    $html_file = $html_dir . '/' . $html_file;
}

$index_data = load_index_data($in_file);
if (count($index_data) < 2) {
    fwrite(STDERR, "输入数据不足：{$in_file}\n");
    exit(1);
}

$first_date = $index_data[0]['date'];
$last_date  = $index_data[count($index_data) - 1]['date'];

// ------ 计算每条曲线的收益率序列 ------
//
// 每条曲线是 N 年滚动收益率：对数据区间内每个交易日 d，
// 取「d - N 年」之前最近的一个交易日 b（b <= d - N 年自然日），
// 收益率 = level(d) / level(b) - 1。若往前推 N 年落在数据起点之前，
// 则该日没有足够历史，曲线从那一天之后才开始有值。

/**
 * 构造某一 N 年跨度的收益率序列
 *
 * @param array<int, array{date: string, level: float}> $data
 * @param float $years 跨度（年），自然日折算 = years * 365.25
 * @return array<int, array{date: string, pct: float|null}>
 */
function compute_trailing_returns(array $data, float $years): array
{
    $target_days = $years * 365.25;

    // 对每个交易日 d，b <= d - target_days 且是最近的一个
    // 因为 $data 是升序的，可以用双指针：base 指针只往右走
    $results = array();
    $base    = 0;
    $n       = count($data);

    for ($i = 0; $i < $n; $i++) {
        $d_sec  = strtotime($data[$i]['date']);
        $cutoff = $d_sec - (int) round($target_days * 86400);

        // 推进 base：如果 base+1 仍 <= cutoff，就可以更靠近当前目标
        while ($base + 1 < $n && strtotime($data[$base + 1]['date']) <= $cutoff) {
            $base++;
        }

        // base 就是 d - N 年之前最近的一个交易日
        if ($base < $i && strtotime($data[$base]['date']) <= $cutoff) {
            $pct = $data[$i]['level'] / $data[$base]['level'] - 1;
            $results[] = array(
                'date'  => $data[$i]['date'],
                'pct'   => $pct,
                'base'  => $data[$base]['date'],   // 用于悬停提示
                'level' => $data[$i]['level'],
            );
        }
        // 否则留空：数据起点不够长，还算不出
    }

    return $results;
}

$series = array(
    '1Y' => compute_trailing_returns($index_data, 1.0),
    '3Y' => compute_trailing_returns($index_data, 3.0),
    '5Y' => compute_trailing_returns($index_data, 5.0),
);

// 图例颜色（与项目里其他图保持一致的高对比配色）
$colors = array(
    '1Y' => '#1a73e8',
    '3Y' => '#34a853',
    '5Y' => '#fbbc04',
);

$labels = array(
    '1Y' => '近 1 年',
    '3Y' => '近 3 年',
    '5Y' => '近 5 年',
);

// ------ 找出三条曲线的公共区间端点 ------
// 每条曲线的起点都不同（跨度越大起点越晚），画折线时各自从自己的起点开始
// 但 X 轴范围取所有曲线覆盖的最早起点到共同终点
$plot_start = $last_date;   // 先取一个最晚的，之后被更小的值替换
foreach ($series as $series_data) {
    if ($series_data !== []) {
        $plot_start = min($plot_start, $series_data[0]['date']);
    }
}
// 至少要有 5Y 曲线才能画，否则说明数据不足 5 年
if ($series['5Y'] === []) {
    fwrite(STDERR, "数据区间不足 5 年，无法绘制近 5 年收益率曲线\n");
    exit(1);
}

// ------ Y 轴范围：所有曲线的收益率 min/max，并且 0% 必须在刻度范围内 ------
$y_min = PHP_FLOAT_MAX;
$y_max = -PHP_FLOAT_MAX;
foreach ($series as $series_data) {
    foreach ($series_data as $point) {
        $y_min = min($y_min, $point['pct']);
        $y_max = max($y_max, $point['pct']);
    }
}
// 把 0% 包含进去，并且上下各给一点 padding
$y_min = min($y_min, -0.01);   // 至少 -1%
$y_max = max($y_max,  0.01);   // 至少 +1%
$pad   = ($y_max - $y_min) * 0.05;
$y_min -= $pad;
$y_max += $pad;

list($y_ticks, $y_decimals_raw) = nice_axis_ticks($y_min * 100, $y_max * 100, 8);
// 手动确保 0% 在刻度里（nice_axis_ticks 步长可能错过 0）
$has_zero = false;
foreach ($y_ticks as $tick) {
    if (abs($tick) < 1e-9) { $has_zero = true; break; }
}
if (!$has_zero) {
    // 在最接近 0 的两个刻度之间插入 0
    $prev = null;
    $y_ticks_new = array();
    foreach ($y_ticks as $tick) {
        $y_ticks_new[] = $tick;
        if ($prev !== null && $prev < 0 && $tick > 0) {
            $y_ticks_new[] = 0.0;
        }
        $prev = $tick;
    }
    sort($y_ticks_new);
    $y_ticks = $y_ticks_new;
}

// X 轴：日期范围 + 刻度
// 目标 ~ 10 个刻度，用日期差值 / 10 得到大致间隔
$total_days = (int) round((strtotime($last_date) - strtotime($plot_start)) / 86400);
$target_ticks = 10;
$interval_days = max(30, (int) round($total_days / $target_ticks));   // 至少 30 天，避免标签过密

// 根据跨度选合适的「好看」间隔：按年数选 1/2/5 年或 3/6 月
if ($interval_days > 365 * 3) {
    $interval_days = 365 * 5;
} elseif ($interval_days > 365) {
    $interval_days = 365 * 2;
} elseif ($interval_days > 180) {
    $interval_days = 365;
} elseif ($interval_days > 90) {
    $interval_days = 180;
} elseif ($interval_days > 30) {
    $interval_days = 90;
}

/**
 * 构造 X 轴日期刻度（起点向上取整到 interval 的倍数，终点固定为 $last_date）
 *
 * @return array<int, string>
 */
function build_x_ticks(string $start, string $end, int $interval_days): array
{
    $start_sec = strtotime($start);
    $end_sec   = strtotime($end);
    $ticks = array();

    // 向上取整到 interval 的倍数，避免第一个刻度落在图的最左边缘
    $offset = $start_sec % ($interval_days * 86400);
    if ($offset > 0) {
        $current = $start_sec + ($interval_days * 86400 - $offset);
    } else {
        $current = $start_sec;
    }

    while ($current <= $end_sec) {
        $ticks[] = date('Y-m-d', $current);
        $current += $interval_days * 86400;
    }
    // 确保终点本身也是刻度
    if ($ticks === [] || end($ticks) !== date('Y-m-d', $end_sec)) {
        $ticks[] = date('Y-m-d', $end_sec);
    }

    return $ticks;
}

$x_ticks = build_x_ticks($plot_start, $last_date, $interval_days);

// ------ SVG 画布 ------
$svg_w  = 1600;
$svg_h  = (int) round($svg_w * 440 / 960);   // 保持原始宽高比 960:440，避免 height:auto 导致渲染高度缩小
$pad_l  = 88;
$pad_r  = 24;
$pad_t  = 20;
$pad_b  = 64;
$plot_w = $svg_w - $pad_l - $pad_r;
$plot_h = $svg_h - $pad_t - $pad_b;

$x_start_sec = strtotime($plot_start);
$x_end_sec   = strtotime($last_date);
$x_span_sec  = $x_end_sec - $x_start_sec;

$to_x = function (string $date) use ($x_start_sec, $x_span_sec, $pad_l, $plot_w): string {
    if ($x_span_sec <= 0) return (string) $pad_l;
    $sec = strtotime($date);
    $ratio = ($sec - $x_start_sec) / $x_span_sec;
    return number_format($pad_l + $plot_w * $ratio, 1, '.', '');
};

$to_y = function (float $pct) use ($y_ticks, $pad_t, $plot_h): string {
    $y_min = $y_ticks[0];
    $y_max = $y_ticks[count($y_ticks) - 1];
    $range = $y_max - $y_min;
    if ($range <= 1e-12) return (string) ($pad_t + $plot_h / 2);
    $ratio = ($pct - $y_min) / $range;
    return number_format($pad_t + $plot_h - $plot_h * $ratio, 1, '.', '');
};

// ------ 构造每条曲线的 SVG path ------
// 折线图用 <polyline>，每个点坐标用 to_x / to_y 换算
// 因为每条曲线有不同的起点，各自独立画 polyline

/**
 * 把一个 series 数组拼成 SVG polyline points 属性值
 *
 * @param array<int, array{date: string, pct: float, base: string, level: float}> $series_data
 * @param callable $to_x (string date) => string
 * @param callable $to_y (float pct) => string
 */
function build_polyline_points(array $series_data, callable $to_x, callable $to_y): string
{
    $parts = array();
    foreach ($series_data as $point) {
        $parts[] = $to_x($point['date']) . ',' . $to_y($point['pct'] * 100);
    }
    return implode(' ', $parts);
}

// ------ 组装 SVG ------
$svg  = '<svg class="chart" viewBox="0 0 ' . $svg_w . ' ' . $svg_h . '" role="img"'
    . ' aria-label="滚动收益率（近 1 / 3 / 5 年）折线图"' . "\n";
$svg .= '  xmlns="http://www.w3.org/2000/svg">' . "\n";

// 背景网格
foreach ($y_ticks as $tick) {
    $y = $to_y($tick);
    $svg .= '  <line class="y-grid" x1="' . $pad_l . '" y1="' . $y . '" x2="' . ($pad_l + $plot_w) . '" y2="' . $y . '"'
        . ' stroke="#e8e8e8"/>' . "\n";
}
foreach ($x_ticks as $tick_date) {
    $x = $to_x($tick_date);
    $svg .= '  <line class="x-grid" x1="' . $x . '" y1="' . $pad_t . '" x2="' . $x . '" y2="' . ($pad_t + $plot_h) . '"'
        . ' stroke="#e8e8e8"/>' . "\n";
}

// 0% 基准线（加粗、灰色）
$svg .= '  <line id="baseline" x1="' . $pad_l . '" y1="' . $to_y(0.0) . '"'
    . ' x2="' . ($pad_l + $plot_w) . '" y2="' . $to_y(0.0) . '"'
    . ' stroke="#666" stroke-width="1.2"/>' . "\n";

// X 轴
$svg .= '  <line x1="' . $pad_l . '" y1="' . ($pad_t + $plot_h) . '"'
    . ' x2="' . ($pad_l + $plot_w) . '" y2="' . ($pad_t + $plot_h) . '"'
    . ' stroke="#999"/>' . "\n";
// Y 轴
$svg .= '  <line x1="' . $pad_l . '" y1="' . $pad_t . '"'
    . ' x2="' . $pad_l . '" y2="' . ($pad_t + $plot_h) . '"'
    . ' stroke="#999"/>' . "\n";

// X 轴刻度标签（每个 X 刻度 + 起点 + 终点都要标）
$all_x_ticks = $x_ticks;
if (!in_array($plot_start, $all_x_ticks, true)) {
    array_unshift($all_x_ticks, $plot_start);
}
foreach ($all_x_ticks as $tick_date) {
    $x = $to_x($tick_date);
    $svg .= '  <text class="x-tick" x="' . $x . '" y="' . ($pad_t + $plot_h + 22) . '"'
        . ' text-anchor="middle" font-size="14" fill="#555">'
        . substr($tick_date, 0, 7) . "</text>\n";   // 只显示 YYYY-MM
}

// Y 轴刻度标签
foreach ($y_ticks as $tick) {
    $y = $to_y($tick);
    // 步长 <= 1 时显示 1 位小数，否则整数
    $decimals = abs($tick) >= 1 ? 0 : (abs($tick) >= 0.1 ? 1 : 2);
    $label = number_format($tick, $decimals, '.', '') . '%';
    $svg .= '  <text class="y-tick" x="' . ($pad_l - 12) . '" y="' . $y . '"'
        . ' text-anchor="end" dominant-baseline="middle"'
        . ' font-size="14" fill="#555">' . $label . "</text>\n";
}

// 轴标题
$svg .= '  <text x="' . ($pad_l + $plot_w / 2) . '" y="' . ($pad_t + $plot_h + 50) . '"'
    . ' text-anchor="middle" font-size="15" fill="#333">日期</text>' . "\n";
$svg .= '  <text x="16" y="' . ($pad_t + $plot_h / 2) . '" text-anchor="middle"'
    . ' font-size="15" fill="#333"'
    . ' transform="rotate(-90 16 ' . ($pad_t + $plot_h / 2) . ')">收益率(%)</text>' . "\n";

// 三条折线（5Y 在最下层，1Y 在最上层，避免高频曲线被挡住）
$draw_order = array('5Y', '3Y', '1Y');
foreach ($draw_order as $key) {
    $points = build_polyline_points($series[$key], $to_x, $to_y);
    $svg .= '  <polyline fill="none" stroke="' . $colors[$key] . '" stroke-width="1.8"'
        . ' stroke-linejoin="round" stroke-linecap="round"'
        . ' data-series="' . $key . '" points="' . $points . '"/>' . "\n";
}

// 最右端加图例（颜色方块 + 文字）
$legend_x_start = $pad_l + 12;
$legend_y       = $pad_t + 14;
$gap_between    = 275;
foreach ($draw_order as $i => $key) {
    $lx = $legend_x_start + $i * $gap_between;
    $svg .= '  <rect x="' . $lx . '" y="' . ($legend_y - 10) . '" width="14" height="14"'
        . ' fill="' . $colors[$key] . '"/>' . "\n";
    $svg .= '  <text x="' . ($lx + 20) . '" y="' . ($legend_y + 1) . '" font-size="14" fill="#333">'
        . $labels[$key] . '</text>' . "\n";
}

// 悬停竖线 + tooltip 容器（JS 控制显示与定位）
$svg .= '  <line id="hover-line" x1="0" y1="' . $pad_t . '" x2="0" y2="' . ($pad_t + $plot_h) . '"'
    . ' stroke="#333" stroke-dasharray="3,3" style="display:none"/>' . "\n";
$svg .= '  <g id="hover-dots" style="display:none">' . "\n";
// 三个悬停圆点，颜色分别对应三条曲线
foreach ($draw_order as $key) {
    $svg .= '    <circle id="hover-dot-' . $key . '" r="4.5" fill="#fff"'
        . ' stroke="' . $colors[$key] . '" stroke-width="2"'
        . ' style="display:none"/>' . "\n";
}
$svg .= '  </g>' . "\n";
$svg .= '</svg>' . "\n";

// ------ 页面其他信息 ------
$span_years  = (strtotime($last_date) - strtotime($first_date)) / 86400 / 365.25;
$first_point = $index_data[0];
$last_point  = $index_data[count($index_data) - 1];

// 三条曲线各自最新的收益率（用于页脚 summary）
$latest_rates = array();
foreach ($draw_order as $key) {
    $s = $series[$key];
    $latest_rates[$key] = $s !== [] ? $s[count($s) - 1]['pct'] : null;
}

// ------ 组装完整 HTML ------
$html  = "<!DOCTYPE html>\n<html lang=\"zh-CN\">\n<head>\n<meta charset=\"utf-8\">\n";
$html .= '<title>' . htmlspecialchars($index_label . ' 滚动收益率', ENT_QUOTES) . "</title>\n";
$html .= "<style>\n";
$html .= "body{font-family:-apple-system,\"PingFang SC\",Helvetica,Arial,sans-serif;margin:24px;color:#222}\n";
$html .= "h1{font-size:20px;margin:0 0 8px}\n";
$html .= "h2{font-size:15px;margin:24px 0 8px;color:#444}\n";
$html .= ".meta{color:#888;font-size:12px;margin-bottom:16px}\n";
$html .= ".summary{font-size:14px;margin:8px 0 16px}\n";
$html .= ".summary span{margin-right:18px}\n";
$html .= ".summary b{font-size:15px}\n";
$html .= ".pos{color:#1a7f37}\n";
$html .= ".neg{color:#cf222e}\n";
$html .= ".chart-wrap{position:relative;max-width:100%}\n";
$html .= "svg.chart{width:100%;height:auto;display:block}\n";
// 悬停 tooltip 浮层（绝对定位，JS 根据鼠标在 svg 中的坐标计算位置）
$html .= ".tooltip{position:absolute;background:#fff;border:1px solid #999;border-radius:4px;"
    . "padding:6px 10px;font-size:12px;line-height:1.5;pointer-events:none;"
    . "box-shadow:0 2px 8px rgba(0,0,0,0.15);white-space:nowrap;display:none;"
    . "font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,\"Courier New\",monospace}\n";
$html .= ".tooltip .date{font-weight:bold;margin-bottom:2px}\n";
$html .= ".tooltip .row{display:flex;justify-content:space-between;gap:16px}\n";
$html .= ".tooltip .dot{display:inline-block;width:10px;height:10px;border-radius:50%;"
    . "margin-right:6px;vertical-align:middle}\n";
// 时间区间滑块
$html .= ".range-bar{display:flex;flex-wrap:wrap;gap:12px 18px;align-items:center;margin:12px 0 6px;"
    . "padding:10px 14px;background:#f6f8fa;border:1px solid #e6e6e6;border-radius:6px}\n";
$html .= ".range-bar label{font-size:13px;color:#333;display:flex;align-items:center;gap:6px}\n";
$html .= ".range-bar input[type=date]{padding:2px 4px;font-size:13px;border:1px solid #ccc;border-radius:3px}\n";
$html .= ".range-bar .range-presets button{margin-right:6px;padding:3px 10px;font-size:12px;"
    . "border:1px solid #ccc;background:#fff;border-radius:3px;cursor:pointer}\n";
$html .= ".range-bar .range-presets button:hover{background:#eef4ff}\n";
$html .= ".range-bar .range-presets button.active{background:#1a73e8;color:#fff;border-color:#1a73e8}\n";
$html .= ".range-bar .range-info{font-size:12px;color:#666}\n";
$html .= "</style>\n</head>\n<body>\n";

$html .= '<h1>' . htmlspecialchars($index_label . ' 滚动收益率（近 1 / 3 / 5 年）', ENT_QUOTES) . "</h1>\n";
$html .= '<div class="meta">数据区间：' . htmlspecialchars($first_date . ' ~ ' . $last_date, ENT_QUOTES)
    . '（' . number_format($span_years, 2, '.', '') . ' 年，' . count($index_data) . " 个交易日）</div>\n";
$html .= '<div class="summary">';
$html .= '<span>当前净值/点位 <b>' . number_format($last_point['level'], 4, '.', '') . '</b></span>';
foreach ($draw_order as $key) {
    $val = $latest_rates[$key];
    if ($val === null) {
        $html .= '<span>近 ' . substr($key, 0, 1) . ' 年 <b>—</b></span>';
    } else {
        $css_class = $val >= 0 ? 'pos' : 'neg';
        $html .= '<span>近 ' . substr($key, 0, 1) . ' 年 <b class="' . $css_class . '">'
            . number_format($val * 100, 2, '.', '') . '%</b></span>';
    }
}
$html .= "</div>\n";

// ------ 时间区间滑块 ------
// 起始日期的最早可选值取所有曲线里最早的起点（5Y 最晚、1Y 最早），
// 这样用户可以看到 1Y 曲线的完整区间；结束日期固定为数据最后一天
$all_series_start = $series['1Y'][0]['date'] ?? $plot_start;   // 1Y 曲线起得最早
$html .= '<div class="range-bar">' . "\n";
$html .= '  <span class="range-presets">'
    . '<button data-range="all" class="active">全部</button>'
    . '<button data-range="50">近50年</button>'
    . '<button data-range="30">近30年</button>'
    . '<button data-range="20">近20年</button>'
    . '<button data-range="10">近10年</button>'
    . '<button data-range="5">近5年</button>'
    . "</span>\n";
$html .= '  <label>起始 <input type="date" id="range-start" min="' . htmlspecialchars($all_series_start, ENT_QUOTES)
    . '" max="' . htmlspecialchars($last_date, ENT_QUOTES) . '" value="' . htmlspecialchars($all_series_start, ENT_QUOTES) . '"></label>' . "\n";
$html .= '  <label>结束 <input type="date" id="range-end" min="' . htmlspecialchars($all_series_start, ENT_QUOTES)
    . '" max="' . htmlspecialchars($last_date, ENT_QUOTES) . '" value="' . htmlspecialchars($last_date, ENT_QUOTES) . '"></label>' . "\n";
$html .= '  <button id="range-apply" style="padding:3px 12px;font-size:12px;border:1px solid #1a73e8;'
    . 'background:#1a73e8;color:#fff;border-radius:3px;cursor:pointer">应用</button>' . "\n";
$html .= '  <span class="range-info" id="range-info"></span>' . "\n";
$html .= "</div>\n";

$html .= '<div class="chart-wrap" id="chart-wrap">' . "\n";
$html .= '  <div class="tooltip" id="tooltip"></div>' . "\n";
$html .= $svg . "\n";
$html .= "</div>\n";

$html .= "<p class=\"meta\">鼠标悬停在图上可查看该日三条曲线的收益率与各自的基准日期。"
    . "收益率 = 当日净值 / 往前推 N 年最近交易日净值 − 1。</p>\n";

// ------ 悬停交互 JS ------
$html .= "<script>\n";
// 把三条曲线的数据点作为 JSON 嵌入，避免 JS 里再去解析 polyline 字符串
$html .= "const seriesData = {\n";
foreach ($draw_order as $key) {
    $series_data = $series[$key];
    $html .= "  '{$key}': " . json_encode($series_data, JSON_UNESCAPED_UNICODE) . ",\n";
}
$html .= "};\n";
$html .= "const colors = " . json_encode($colors, JSON_UNESCAPED_UNICODE) . ";\n";
$html .= "const labels = " . json_encode($labels, JSON_UNESCAPED_UNICODE) . ";\n";

// PHP 注入静态常量：完整数据范围的起点/终点、Y 轴全范围
$html .= "const FULL_START = '{$all_series_start}';\n";
$html .= "const FULL_END   = '{$last_date}';\n";
$y_min_full = $y_ticks[0];
$y_max_full = $y_ticks[count($y_ticks) - 1];
$html .= "const Y_FULL_MIN = {$y_min_full};\n";
$html .= "const Y_FULL_MAX = {$y_max_full};\n";

$html .= <<<'JS'
const svg       = document.querySelector('.chart-wrap svg.chart');
const wrap      = document.getElementById('chart-wrap');
const tooltip   = document.getElementById('tooltip');
const hoverLine = document.getElementById('hover-line');
const plotArea  = svg.viewBox.baseVal;

function get_plot_bbox() {
    const pad_l = 88, pad_r = 24, pad_t = 20, pad_b = 64;
    return {
        left:   pad_l,
        right:  plotArea.width - pad_r,
        top:    pad_t,
        bottom: plotArea.height - pad_b,
    };
}

function svg_point_from_event(evt) {
    const pt = svg.createSVGPoint();
    pt.x = evt.clientX;
    pt.y = evt.clientY;
    const ctm = svg.getScreenCTM();
    if (!ctm) return null;
    const loc = pt.matrixTransform(ctm.inverse());
    return { x: loc.x, y: loc.y };
}

// ------ 视图状态 ------
let viewRange = { start: FULL_START, end: FULL_END };

// 当前视图的换算参数（rebuildChart 更新这些）
let gYMin, gYMax, gStartSec, gEndSec;

function compute_svg_x(dateStr) {
    const bbox = get_plot_bbox();
    const t = new Date(dateStr).getTime() / 1000;
    const ratio = (t - gStartSec) / (gEndSec - gStartSec);
    return bbox.left + (bbox.right - bbox.left) * ratio;
}

function compute_svg_y(pct) {
    const bbox = get_plot_bbox();
    const ratio = (pct - gYMin) / (gYMax - gYMin);
    return bbox.top + (bbox.bottom - bbox.top) - (bbox.bottom - bbox.top) * ratio;
}

function date_from_x(x) {
    const bbox = get_plot_bbox();
    const ratio = (x - bbox.left) / (bbox.right - bbox.left);
    const sec = gStartSec + ratio * (gEndSec - gStartSec);
    const d = new Date(sec * 1000);
    const yyyy = d.getUTCFullYear();
    const mm   = String(d.getUTCMonth() + 1).padStart(2, '0');
    const dd   = String(d.getUTCDate()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd}`;
}

function find_nearest_date(arr, targetDate) {
    if (!arr || arr.length === 0) return null;
    // 悬停日期不在该曲线的数据区间内（比如早于近 5 年曲线的起点）时视为没有数据，
    // 否则二分查找会退回曲线首点/末点，导致提示框里显示一条对不上的收益率
    if (targetDate < arr[0].date || targetDate > arr[arr.length - 1].date) return null;
    let lo = 0, hi = arr.length - 1;
    while (lo <= hi) {
        const mid = (lo + hi) >> 1;
        if (arr[mid].date < targetDate) lo = mid + 1;
        else hi = mid - 1;
    }
    let i = lo;
    if (i > 0 && (i >= arr.length || date_diff_days(arr[i - 1].date, targetDate) <= date_diff_days(arr[i].date, targetDate))) {
        i = i - 1;
    }
    if (i < 0 || i >= arr.length) return null;
    return arr[i];
}

function date_diff_days(a, b) {
    return Math.abs((new Date(a + 'T00:00:00Z') - new Date(b + 'T00:00:00Z')) / 86400000);
}

// ------ X 轴刻度生成（JS 版，和 PHP 算法一致）------
function build_x_ticks(startDate, endDate) {
    const startSec = new Date(startDate + 'T00:00:00Z').getTime() / 1000;
    const endSec   = new Date(endDate   + 'T00:00:00Z').getTime() / 1000;
    const totalDays = Math.round((endSec - startSec) / 86400);
    let intervalDays = Math.max(30, Math.round(totalDays / 10));

    if (intervalDays > 365 * 3) {
        intervalDays = 365 * 5;
    } else if (intervalDays > 365) {
        intervalDays = 365 * 2;
    } else if (intervalDays > 180) {
        intervalDays = 365;
    } else if (intervalDays > 90) {
        intervalDays = 180;
    } else if (intervalDays > 30) {
        intervalDays = 90;
    }

    const tickSec = intervalDays * 86400;
    let offset = startSec % tickSec;
    let current = offset > 0 ? startSec + tickSec - offset : startSec;
    const ticks = [];
    while (current <= endSec) {
        const d = new Date(current * 1000);
        const yyyy = d.getUTCFullYear();
        const mm   = String(d.getUTCMonth() + 1).padStart(2, '0');
        const dd   = String(d.getUTCDate()).padStart(2, '0');
        ticks.push(`${yyyy}-${mm}-${dd}`);
        current += tickSec;
    }
    const endStr = endDate;
    if (ticks.length === 0 || ticks[ticks.length - 1] !== endStr) {
        ticks.push(endStr);
    }
    return ticks;
}

// ------ Y 轴刻度生成（nice ticks，全部在百分比世界里）------
function nice_y_extent(data) {
    let min = Infinity, max = -Infinity;
    for (const pt of data) {
        min = Math.min(min, pt.pct);
        max = Math.max(max, pt.pct);
    }
    min = Math.min(min, 0);
    max = Math.max(max, 0);
    if (max <= min) { max = min + 1; }
    const rawStep = (max - min) / 8;
    const exponent = Math.floor(Math.log10(rawStep));
    const base = rawStep / Math.pow(10, exponent);
    let multiplier;
    if (base <= 1) multiplier = 1;
    else if (base <= 2) multiplier = 2;
    else if (base <= 5) multiplier = 5;
    else multiplier = 10;
    const step = multiplier * Math.pow(10, exponent);
    const start = Math.floor(min / step) * step;
    const end   = Math.ceil(max / step) * step;
    // 返回百分比值！pt.pct 是小数，这里 * 100
    return [start * 100, end * 100];
}

function build_y_ticks(yMin, yMax) {
    // yMin/yMax 已经是百分比（nice_y_extent 返回的）
    const range = yMax - yMin;
    if (range <= 1e-12) return [yMin];
    const rawStep = range / 8;
    const exponent = Math.floor(Math.log10(rawStep));
    const base = rawStep / Math.pow(10, exponent);
    let multiplier;
    if (base <= 1) multiplier = 1;
    else if (base <= 2) multiplier = 2;
    else if (base <= 5) multiplier = 5;
    else multiplier = 10;
    const step = multiplier * Math.pow(10, exponent);
    const start = Math.floor(yMin / step) * step;
    const end   = Math.ceil(yMax / step) * step;
    const ticks = [];
    for (let v = start; v <= end + 1e-9; v += step) {
        ticks.push(Math.round(v * 1e6) / 1e6);
    }
    // 确保 0 在里面
    if (!ticks.some(t => Math.abs(t) < 1e-9)) {
        for (let i = 0; i < ticks.length - 1; i++) {
            if (ticks[i] < 0 && ticks[i + 1] > 0) {
                ticks.splice(i + 1, 0, 0);
                break;
            }
        }
    }
    return ticks;
}

function format_y_tick(tick) {
    // tick 已经是百分比值，直接按小数位数显示
    const abs = Math.abs(tick);
    const decimals = abs === 0 ? 0 : (abs < 0.1 ? 2 : (abs < 1 ? 1 : 0));
    return tick.toFixed(decimals) + '%';
}

// ------ 重建图表 ------
function rebuildChart() {
    // 1. 过滤每条曲线到 viewRange
    const filtered = {};
    for (const key of Object.keys(seriesData)) {
        const arr = seriesData[key];
        const startSec = new Date(viewRange.start + 'T00:00:00Z').getTime() / 1000;
        const endSec   = new Date(viewRange.end   + 'T00:00:00Z').getTime() / 1000;
        filtered[key] = arr.filter(pt => {
            const t = new Date(pt.date + 'T00:00:00Z').getTime() / 1000;
            return t >= startSec && t <= endSec;
        });
    }

    // 2. 计算新的 Y min/max
    let allFiltered = [];
    for (const key of Object.keys(filtered)) {
        allFiltered = allFiltered.concat(filtered[key]);
    }
    const [nyMin, nyMax] = nice_y_extent(allFiltered);
    gYMin = nyMin;
    gYMax = nyMax;
    gStartSec = new Date(viewRange.start + 'T00:00:00Z').getTime() / 1000;
    gEndSec   = new Date(viewRange.end   + 'T00:00:00Z').getTime() / 1000;

    // 3. 更新 polylines 的 points
    document.querySelectorAll('polyline[data-series]').forEach(pl => {
        const key = pl.getAttribute('data-series');
        const pts = filtered[key];
        const parts = pts.map(pt => compute_svg_x(pt.date) + ',' + compute_svg_y(pt.pct * 100));
        pl.setAttribute('points', parts.join(' '));
    });

    // 4. 移除旧的 grid 和 tick 元素
    document.querySelectorAll('.x-grid, .y-grid, .x-tick, .y-tick').forEach(el => el.remove());

    // 5. 构造新的 Y 刻度和 grid
    const yTicks = build_y_ticks(gYMin, gYMax);
    const bbox = get_plot_bbox();
    const plotW = bbox.right - bbox.left;
    const plotH = bbox.bottom - bbox.top;

    yTicks.forEach(tick => {
        const y = compute_svg_y(tick);
        // Y grid line
        const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
        line.setAttribute('class', 'y-grid');
        line.setAttribute('x1', bbox.left);
        line.setAttribute('y1', y);
        line.setAttribute('x2', bbox.right);
        line.setAttribute('y2', y);
        line.setAttribute('stroke', '#e8e8e8');
        // 插入到 polyline 之前（保持背景在曲线下面）
        const firstPoly = svg.querySelector('polyline');
        if (firstPoly) svg.insertBefore(line, firstPoly); else svg.appendChild(line);

        // Y tick text
        const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
        text.setAttribute('class', 'y-tick');
        text.setAttribute('x', bbox.left - 12);
        text.setAttribute('y', y);
        text.setAttribute('text-anchor', 'end');
        text.setAttribute('dominant-baseline', 'middle');
        text.setAttribute('font-size', '14');
        text.setAttribute('fill', '#555');
        text.textContent = format_y_tick(tick);
        svg.appendChild(text);
    });

    // 6. 构造新的 X 刻度和 grid
    let xTicks = build_x_ticks(viewRange.start, viewRange.end);
    // 确保首尾在刻度里
    if (xTicks[0] !== viewRange.start) xTicks.unshift(viewRange.start);

    xTicks.forEach(tickDate => {
        const x = compute_svg_x(tickDate);
        // X grid line
        const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
        line.setAttribute('class', 'x-grid');
        line.setAttribute('x1', x);
        line.setAttribute('y1', bbox.top);
        line.setAttribute('x2', x);
        line.setAttribute('y2', bbox.bottom);
        line.setAttribute('stroke', '#e8e8e8');
        const firstPoly = svg.querySelector('polyline');
        if (firstPoly) svg.insertBefore(line, firstPoly); else svg.appendChild(line);

        // X tick text
        const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
        text.setAttribute('class', 'x-tick');
        text.setAttribute('x', x);
        text.setAttribute('y', bbox.bottom + 22);
        text.setAttribute('text-anchor', 'middle');
        text.setAttribute('font-size', '14');
        text.setAttribute('fill', '#555');
        text.textContent = tickDate.substring(0, 7);
        svg.appendChild(text);
    });

    // 7. 更新 baseline
    const baseline = document.getElementById('baseline');
    if (baseline) {
        const y0 = compute_svg_y(0);
        baseline.setAttribute('y1', y0);
        baseline.setAttribute('y2', y0);
    }

    // 8. 更新 range-info
    const info = document.getElementById('range-info');
    if (info) {
        const startSec = new Date(viewRange.start + 'T00:00:00Z').getTime();
        const endSec   = new Date(viewRange.end   + 'T00:00:00Z').getTime();
        const years = ((endSec - startSec) / 86400000 / 365.25).toFixed(2);
        info.textContent = `${viewRange.start} ~ ${viewRange.end}（${years} 年）`;
    }
}

// ------ 预设按钮 + 日期输入绑定 ------
function setActivePreset(rangeKey) {
    document.querySelectorAll('.range-presets button').forEach(btn => {
        btn.classList.toggle('active', btn.getAttribute('data-range') === rangeKey);
    });
}

function clampDate(dateStr) {
    const s = new Date(dateStr + 'T00:00:00Z').getTime();
    const lo = new Date(FULL_START + 'T00:00:00Z').getTime();
    const hi = new Date(FULL_END   + 'T00:00:00Z').getTime();
    return new Date(Math.max(lo, Math.min(hi, s)) * 1000).toISOString().slice(0, 10);
}

function updateInputs() {
    document.getElementById('range-start').value = viewRange.start;
    document.getElementById('range-end').value   = viewRange.end;
}

document.querySelectorAll('.range-presets button').forEach(btn => {
    btn.addEventListener('click', () => {
        const key = btn.getAttribute('data-range');
        if (key === 'all') {
            viewRange.start = FULL_START;
            viewRange.end   = FULL_END;
        } else {
            const years = parseInt(key, 10);
            const endSec   = new Date(FULL_END + 'T00:00:00Z').getTime() / 1000;
            const startSec = endSec - years * 365.25 * 86400;
            const d = new Date(startSec * 1000);
            const yyyy = d.getUTCFullYear();
            const mm   = String(d.getUTCMonth() + 1).padStart(2, '0');
            const dd   = String(d.getUTCDate()).padStart(2, '0');
            const candidate = `${yyyy}-${mm}-${dd}`;
            viewRange.start = candidate < FULL_START ? FULL_START : candidate;
            viewRange.end   = FULL_END;
        }
        setActivePreset(key);
        updateInputs();
        rebuildChart();
    });
});

document.getElementById('range-apply').addEventListener('click', () => {
    const s = document.getElementById('range-start').value;
    const e = document.getElementById('range-end').value;
    let start = clampDate(s);
    let end   = clampDate(e);
    if (new Date(start) > new Date(end)) {
        [start, end] = [end, start];
    }
    viewRange.start = start;
    viewRange.end   = end;
    setActivePreset(null);
    updateInputs();
    rebuildChart();
});

// ------ 悬停交互 ------
svg.addEventListener('mousemove', function (evt) {
    const p = svg_point_from_event(evt);
    if (!p) return;
    const bbox = get_plot_bbox();
    if (p.x < bbox.left || p.x > bbox.right || p.y < bbox.top || p.y > bbox.bottom) {
        hoverLine.style.display = 'none';
        tooltip.style.display = 'none';
        for (const k of Object.keys(seriesData)) {
            const dot = document.getElementById('hover-dot-' + k);
            if (dot) dot.style.display = 'none';
        }
        return;
    }

    hoverLine.style.display = '';
    hoverLine.setAttribute('x1', p.x);
    hoverLine.setAttribute('x2', p.x);

    const targetDate = date_from_x(p.x);

    let tipHtml = '<div class="date">' + targetDate + '</div>';
    for (const key of Object.keys(seriesData)) {
        const nearest = find_nearest_date(seriesData[key], targetDate);
        const dot = document.getElementById('hover-dot-' + key);
        if (!nearest) {
            // 该日期还没有这条曲线的数据（如近 5 年曲线尚未开始）：
            // 提示框里不显示这一行，同时隐藏上一次移动留下的圆点
            if (dot) dot.style.display = 'none';
            continue;
        }
        const pctStr = (nearest.pct >= 0 ? '+' : '') + (nearest.pct * 100).toFixed(2) + '%';
        const cls    = nearest.pct >= 0 ? 'pos' : 'neg';
        tipHtml += '<div class="row">'
            + '<span><span class="dot" style="background:' + colors[key] + '"></span>'
            + labels[key] + '（基准 ' + nearest.base + '）</span>'
            + '<span class="' + cls + '">' + pctStr + '</span></div>';

        // 悬停圆点定位：根据 nearest.date 和 nearest.pct 算 viewBox 坐标
        const pointX = compute_svg_x(nearest.date);
        const pointY = compute_svg_y(nearest.pct * 100);
        dot.setAttribute('cx', pointX);
        dot.setAttribute('cy', pointY);
        dot.style.display = '';
    }

    tooltip.innerHTML = tipHtml;
    tooltip.style.display = 'block';

    const wrapRect = wrap.getBoundingClientRect();
    const tooltipRect = tooltip.getBoundingClientRect();
    let left = evt.clientX - wrapRect.left + 14;
    let top  = evt.clientY - wrapRect.top - tooltipRect.height - 10;
    if (top < 0) top = evt.clientY - wrapRect.top + 14;
    if (left + tooltipRect.width > wrapRect.width) {
        left = evt.clientX - wrapRect.left - tooltipRect.width - 14;
    }
    tooltip.style.left = left + 'px';
    tooltip.style.top  = top + 'px';
});

svg.addEventListener('mouseleave', function () {
    hoverLine.style.display = 'none';
    tooltip.style.display = 'none';
    for (const k of Object.keys(seriesData)) {
        const dot = document.getElementById('hover-dot-' + k);
        if (dot) dot.style.display = 'none';
    }
});

// ------ 初始化 ------
rebuildChart();
JS;

$html .= "</script>\n";
$html .= "</body>\n</html>\n";

// ------ 写盘 ------
$written = file_put_contents($html_file, $html);
if ($written === false) {
    fwrite(STDERR, "写入失败：{$html_file}\n");
    exit(1);
}

printf("数据源       : %s\n", $in_file);
printf("标题         : %s\n", $index_label);
printf("数据区间     : %s ~ %s（%.2f 年，%d 天）\n", $first_date, $last_date, $span_years, count($index_data));
foreach ($draw_order as $key) {
    $s = $series[$key];
    $start = $s !== [] ? $s[0]['date'] : '—';
    $cnt   = count($s);
    $latest = $latest_rates[$key];
    $latestStr = $latest === null ? '—' : number_format($latest * 100, 2, '.', '') . '%';
    printf("  近 %s 年曲线: %d 点起于 %s，最新 %s\n", $key, $cnt, $start, $latestStr);
}
printf("输出文件     : %s（%d bytes）\n", $html_file, $written);

exit(0);

<?php
/**
 * 投资组合回测：组合净值生成器
 *
 * 把 config.ini 里定义的每个 portfolio_* 组合模拟成一条「虚拟基金」的日净值 CSV，
 * 格式与 data/daily/fund_*.csv 完全一致（date,level,change_pct，含 BOM），
 * 之后可直接用 return_drawdown_analyze.php 产出收益与回撤分析 HTML：
 *
 *   php portfolio_nav.php                                   # 跑 config.ini 里全部组合
 *   php portfolio_nav.php --portfolio=bond_nasdaq           # 只跑指定 key 的组合
 *   php return_drawdown_analyze.php --in=<项目根>/data/portfolio/portfolio_bond_nasdaq_daily.csv --title=债基+纳指组合
 *
 * 模拟规则：
 *   1) 基准日：各基金首个数据日中最晚的一天（成立时间最晚的基金决定组合起点），
 *      各基金净值在基准日归一为 1，组合净值从 1 开始；
 *   2) 交易日历：各基金日期的并集；某基金当日缺数据时用前一交易日净值填充
 *      （QDII 与内地基金节假日不同步，填充而非剔除可避免损失交易日）；
 *   3) 每日各腿市值按当日基金净值涨跌浮动：leg *= level_t / level_{t-1}，
 *      组合净值 = 各腿市值之和；
 *   4) 再平衡：触发方式由 config.ini 的 rebalance 决定，触发时把各腿市值
 *      重置回「组合净值 × 目标权重」（重置不改变组合净值，只改变各腿占比）：
 *        none        —— 买入后持有，权重自然漂移，不再平衡；
 *        period      —— 每周期（period = monthly/quarterly/semiannual/annual）
 *                       最后一个交易日再平衡；
 *        anniversary —— 按基准日的每年周年再平衡，取周年日当天或之后首个交易日；
 *        threshold   —— 任一基金实际权重与目标权重相差达到 threshold 个百分点即再平衡，
 *                       每日收盘后检查一次。
 *
 * 输出：data/portfolio/portfolio_{key}_daily.csv
 */

require_once __DIR__ . '/config_loader.php';

/**
 * 读取基金日线 CSV（列：date, level, change_pct），返回 date => level 的映射（按日期升序）
 *
 * @return array<string, float>
 */
function load_fund_levels(string $path): array
{
    $fp = fopen($path, 'r');
    if ($fp === false) {
        fwrite(STDERR, "无法打开基金数据文件：{$path}\n");
        exit(1);
    }

    $levels = array();
    while (($cols = fgetcsv($fp, 0, ',', '"', '\\')) !== false) {
        $date = ltrim((string) ($cols[0] ?? ''), "\xEF\xBB\xBF");   // 首行可能带 BOM
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            continue;                                              // 跳过表头与空行
        }
        if (!isset($cols[1]) || !is_numeric($cols[1])) {
            continue;
        }
        $levels[$date] = floatval($cols[1]);
    }
    fclose($fp);
    ksort($levels);

    return $levels;
}

/**
 * 日期所在的再平衡周期 key；周期 key 发生变化即说明前一天是上一周期的最后一个交易日
 */
function rebalance_period_key(string $date, string $mode): string
{
    $year  = substr($date, 0, 4);
    $month = (int) substr($date, 5, 2);

    switch ($mode) {
        case 'monthly':
            return substr($date, 0, 7);
        case 'quarterly':
            return $year . '-Q' . intdiv($month - 1, 3);
        case 'semiannual':
            return $year . '-H' . ($month <= 6 ? 1 : 2);
        default:   // annual
            return $year;
    }
}

/**
 * 把各腿市值重置回「组合净值 × 目标权重」
 *
 * @param array<string, float> $legs 基金代码 => 当前腿市值（按引用修改）
 */
function rebalance_legs(array &$legs, array $weights, float $portfolio_level): void
{
    foreach ($weights as $code => $weight) {
        $legs[$code] = $portfolio_level * $weight / 100;
    }
}

/**
 * 模拟一个组合的日净值序列
 *
 * @param array<string, float>                 $weights    基金代码 => 目标权重（百分数）
 * @param array<string, array<string, float>>  $fund_data  基金代码 => (date => level)
 * @param array{rebalance: string, period: string, threshold: float} $rule 再平衡规则
 * @return array{series: array<int, array{date: string, level: float, change_pct: float|null}>, rebalance_dates: array<int, string>}
 *         series 首行 change_pct 为 null（与 fund CSV 首行留空一致）；
 *         rebalance_dates 是实际发生再平衡的交易日（升序）
 */
function simulate_portfolio(array $weights, array $fund_data, array $rule): array
{
    $mode      = $rule['rebalance'];
    $period    = $rule['period'];
    $threshold = $rule['threshold'];

    // 基准日 = 各基金首个数据日中最晚的一天
    $base_date = '';
    foreach ($fund_data as $levels) {
        $base_date = max($base_date, (string) array_key_first($levels));
    }

    // 交易日历 = 各基金在基准日及之后的日期并集
    $calendar = array();
    foreach ($fund_data as $levels) {
        foreach ($levels as $date => $level) {
            if ($date >= $base_date) {
                $calendar[$date] = true;
            }
        }
    }
    $calendar = array_keys($calendar);
    sort($calendar);
    $days = count($calendar);

    // 各基金按日历对齐净值：缺数据日期用前一交易日净值填充。
    // 基准日不早于任何基金的首个数据日，所以指针走到基准日时必有已见净值，不会出现 null
    $aligned = array();   // 基金代码 => 与 $calendar 等长的净值数组
    foreach ($fund_data as $code => $levels) {
        $fund_dates  = array_keys($levels);
        $fund_count  = count($fund_dates);
        $ptr         = 0;
        $last_level  = null;
        $fund_series = array();
        foreach ($calendar as $date) {
            while ($ptr < $fund_count && $fund_dates[$ptr] <= $date) {
                $last_level = $levels[$fund_dates[$ptr]];
                $ptr++;
            }
            $fund_series[] = $last_level;
        }
        $aligned[$code] = $fund_series;
    }

    // anniversary 模式的周年日：基准日后第 1、2、3… 年的同一天（闰日 2-29 由 strtotime 顺延到下月 1 日）
    $anniversary_dates = array();
    if ($mode === 'anniversary') {
        for ($n = 1; $n <= 500; $n++) {
            $target = date('Y-m-d', strtotime($base_date . ' +' . $n . ' years'));
            if ($target > $calendar[$days - 1]) {
                break;
            }
            $anniversary_dates[] = $target;
        }
    }

    // 逐日模拟：各腿市值随基金净值涨跌浮动，再平衡日把各腿重置回目标权重
    $legs  = array();   // 基金代码 => 当前腿市值（基准日总和为 1）
    foreach ($weights as $code => $weight) {
        $legs[$code] = $weight / 100;
    }

    $series          = array(array('date' => $calendar[0], 'level' => 1.0, 'change_pct' => null));
    $rebalance_dates = array();
    $next_anniversary = 0;   // 下一个待触发的周年日在 $anniversary_dates 中的下标

    for ($i = 1; $i < $days; $i++) {
        $portfolio_level = 0.0;
        foreach ($legs as $code => $leg_value) {
            $prev = $aligned[$code][$i - 1];
            if ($prev > 0) {
                $leg_value *= $aligned[$code][$i] / $prev;
            }
            $legs[$code]      = $leg_value;
            $portfolio_level += $leg_value;
        }

        // 判断本交易日是否触发再平衡
        $should_rebalance = false;
        if ($mode === 'period') {
            // 每周期最后一个交易日：次日周期 key 不同即本日触发；最后一天无需再平衡
            $should_rebalance = $i < $days - 1
                && rebalance_period_key($calendar[$i], $period) !== rebalance_period_key($calendar[$i + 1], $period);
        } elseif ($mode === 'anniversary') {
            // 到（或越过）周年日即触发，一次跨过多个周年日时只再平衡一次
            if ($next_anniversary < count($anniversary_dates) && $calendar[$i] >= $anniversary_dates[$next_anniversary]) {
                $should_rebalance = true;
                while ($next_anniversary < count($anniversary_dates)
                    && $calendar[$i] >= $anniversary_dates[$next_anniversary]) {
                    $next_anniversary++;
                }
            }
        } elseif ($mode === 'threshold') {
            // 任一基金实际权重与目标权重相差达到阈值（百分点）即触发
            if ($portfolio_level > 0) {
                foreach ($weights as $code => $weight) {
                    if (abs($legs[$code] / $portfolio_level * 100 - $weight) >= $threshold) {
                        $should_rebalance = true;
                        break;
                    }
                }
            }
        }

        if ($should_rebalance) {
            rebalance_legs($legs, $weights, $portfolio_level);
            $rebalance_dates[] = $calendar[$i];
        }

        $prev_level = $series[$i - 1]['level'];
        $series[]   = array(
            'date'       => $calendar[$i],
            'level'      => $portfolio_level,
            'change_pct' => $prev_level > 0 ? round(($portfolio_level / $prev_level - 1) * 100, 2) : 0.0,
        );
    }

    return array('series' => $series, 'rebalance_dates' => $rebalance_dates);
}

// ------ 主逻辑 ------

$opts = getopt('', ['portfolio::', 'help']);

if (isset($opts['help'])) {
    echo "用法：php portfolio_nav.php [--portfolio=组合key]\n";
    echo "不传 --portfolio 时依次回测 config.ini 中定义的全部 portfolio_* 组合。\n";
    exit(0);
}

$config       = quant_config();
$daily_dir    = $config['daily_dir'];
$portfolioDir = $config['portfolio_dir'];
$portfolios   = quant_portfolios();

if ($portfolios === []) {
    fwrite(STDERR, "config.ini 中没有定义任何 portfolio_* 组合\n");
    exit(1);
}

$only = trim((string) ($opts['portfolio'] ?? ''));
if ($only !== '') {
    if (!isset($portfolios[$only])) {
        fwrite(STDERR, "未找到组合定义：[portfolio_{$only}]，可选：" . implode(', ', array_keys($portfolios)) . "\n");
        exit(1);
    }
    $portfolios = array($only => $portfolios[$only]);
}

$valid_rebalance = array('none', 'period', 'anniversary', 'threshold');
$valid_periods   = array('monthly', 'quarterly', 'semiannual', 'annual');

if (!is_dir($portfolioDir) && !mkdir($portfolioDir, 0755, true)) {
    fwrite(STDERR, "无法创建输出目录：{$portfolioDir}\n");
    exit(1);
}

foreach ($portfolios as $key => $spec) {
    $weights   = $spec['funds'];
    $rebalance = $spec['rebalance'];
    $period    = $spec['period'];
    $threshold = $spec['threshold'];

    // 组合定义校验：至少 2 只基金、权重都为正、总和等于 100、再平衡规则合法
    if (count($weights) < 2) {
        fwrite(STDERR, "[portfolio_{$key}] 组合至少需要 2 只基金\n");
        exit(1);
    }
    foreach ($weights as $code => $weight) {
        if ($weight <= 0) {
            fwrite(STDERR, "[portfolio_{$key}] 基金 {$code} 的权重必须为正数\n");
            exit(1);
        }
    }
    if (abs(array_sum($weights) - 100) > 0.01) {
        fwrite(STDERR, sprintf("[portfolio_{$key}] 权重之和须等于 100，当前为 %s\n", array_sum($weights)));
        exit(1);
    }
    if (!in_array($rebalance, $valid_rebalance, true)) {
        fwrite(STDERR, "[portfolio_{$key}] 非法的 rebalance={$rebalance}，可选：" . implode('/', $valid_rebalance) . "\n");
        exit(1);
    }
    if ($rebalance === 'period' && !in_array($period, $valid_periods, true)) {
        fwrite(STDERR, "[portfolio_{$key}] rebalance=period 时须指定 period=" . implode('/', $valid_periods) . "，当前为 {$period}\n");
        exit(1);
    }
    if ($rebalance === 'threshold' && ($threshold <= 0 || $threshold >= 100)) {
        fwrite(STDERR, "[portfolio_{$key}] rebalance=threshold 时须指定 threshold（0~100 之间的百分点），当前为 {$threshold}\n");
        exit(1);
    }

    // 读各基金日线
    $fund_data = array();
    foreach ($weights as $code => $weight) {
        $path = $daily_dir . '/fund_' . $code . '_daily.csv';
        if (!is_file($path)) {
            fwrite(STDERR, "[portfolio_{$key}] 找不到基金数据文件：{$path}\n");
            exit(1);
        }
        $fund_data[$code] = load_fund_levels($path);
        if (count($fund_data[$code]) < 2) {
            fwrite(STDERR, "[portfolio_{$key}] 基金 {$code} 数据不足：{$path}\n");
            exit(1);
        }
    }

    $result = simulate_portfolio($weights, $fund_data, [
        'rebalance' => $rebalance,
        'period'    => $period,
        'threshold' => $threshold,
    ]);
    $series = $result['series'];
    $days   = count($series);

    // 写出与 fund CSV 相同格式的组合净值：首行 change_pct 留空，level 保留 4 位小数
    $out_file = $portfolioDir . '/portfolio_' . $key . '_daily.csv';
    $fp       = fopen($out_file, 'w');
    if ($fp === false) {
        fwrite(STDERR, "无法写入输出文件：{$out_file}\n");
        exit(1);
    }

    fwrite($fp, "\xEF\xBB\xBF");   // BOM，方便 Excel 直接打开
    fputcsv($fp, ['date', 'level', 'change_pct'], ',', '"', '\\');
    foreach ($series as $row) {
        fputcsv($fp, [
            $row['date'],
            number_format($row['level'], 4, '.', ''),
            $row['change_pct'] === null ? '' : number_format($row['change_pct'], 2, '.', ''),
        ], ',', '"', '\\');
    }
    fclose($fp);

    $weight_text = implode(',', array_map(
        fn(string $code, float $weight): string => $code . ':' . rtrim(rtrim(number_format($weight, 2, '.', ''), '0'), '.'),
        array_keys($weights),
        $weights
    ));
    // 再平衡规则的显示文本：把触发方式和参数拼在一起，便于在控制台核对配置
    $rule_text = match ($rebalance) {
        'period'      => '每周期最后一个交易日(' . $period . ')',
        'anniversary' => '基准日周年(' . $series[0]['date'] . ')',
        'threshold'   => '偏离阈值(' . $threshold . '个百分点)',
        default       => '不再平衡',
    };
    printf("[portfolio_%s] %s | %s | 再平衡:%s | %s ~ %s 共 %d 个交易日 | 期末净值 %.4f | 再平衡 %d 次\n",
        $key, $spec['name'], $weight_text, $rule_text,
        $series[0]['date'], $series[$days - 1]['date'], $days, $series[$days - 1]['level'],
        count($result['rebalance_dates']));
    if ($result['rebalance_dates'] !== []) {
        printf("  首次 %s，末次 %s\n", $result['rebalance_dates'][0], $result['rebalance_dates'][count($result['rebalance_dates']) - 1]);
    }
    printf("  已写入：%s\n", $out_file);
    printf("  HTML 分析：php return_drawdown_analyze.php --in=%s --title=%s\n",
        $out_file, escapeshellarg($spec['name']));
}

<?php
/**
 * 一键更新所有数据脚本
 *
 * 整合 README.md 中描述的全部数据更新步骤：
 *   1. 美股三大指数 + NDXTMC + NDXTMCTR（FRED 数据源）
 *   2. NYSE FANG+ 指数（Yahoo Finance）
 *   3. MSCI USA 50 指数（MSCI 官方）
 *   4. 国内基金原始净值抓取 + 转换
 *   5. 对所有日线 CSV 生成回撤分析报告
 *
 * 本脚本只负责调度，实际抓取逻辑全部委托给 script/ 下已有的专用脚本。
 * 路径统一走 config.ini 中配置的目录，命令行参数透传给子脚本。
 *
 * 用法：
 *   php update_all.php                        # 更新全部（默认全部启用）
 *   php update_all.php --skip-funds           # 跳过基金数据更新
 *   php update_all.php --skip-indexes         # 跳过指数数据更新
 *   php update_all.php --skip-analyze         # 跳过回撤分析报告生成 + 跳过 index.html
 *   php update_all.php --skip-index-html      # 只跳过 index.html，回撤分析照常
 *   php update_all.php --funds=008114,050025  # 只更新指定基金（逗号分隔代码）
 *   php update_all.php --only-analyze         # 只跑回撤分析 + index.html（不抓新数据）
 *   php update_all.php --only-drawdown        # 只跑 return_drawdown_analyze.php，不生成 index.html
 *   php update_all.php --full                 # 所有抓取脚本走全量重抓
 *   php update_all.php --dry-run              # 打印要执行的命令，不真正运行
 *   php update_all.php --verbose              # 显示子脚本的完整输出
 */

require_once __DIR__ . '/config_loader.php';

// 数据源（基金代码 / 指数文件名）到中文标题的映射集中在项目根目录 config.ini 的
// [titles_funds] / [titles_indexes] section 下维护；新增数据源时只改 config.ini，无需动本脚本。

function show_help(): void
{
    echo <<<'HELP'
用法：php script/update_all.php [选项]

选项：
  --skip-funds      跳过基金数据抓取和转换
  --skip-indexes    跳过指数数据抓取
  --skip-analyze    跳过回撤分析报告生成和 index.html
  --skip-index-html 只跳过 index.html，回撤分析照常
  --only-analyze    只跑回撤分析 + index.html（不抓新数据）
  --only-drawdown   只跑 return_drawdown_analyze.php（不抓新数据、不生成 index.html）
  --funds=CODE1,CODE2  只更新指定基金（逗号分隔代码）
  --full            所有抓取脚本强制全量重抓
  --dry-run         打印命令但不真正执行，用于预览
  --verbose         显示子脚本的完整输出
  --help            显示本帮助

执行流程：
  步骤 1/5  指数抓取（FRED / Yahoo / MSCI）
  步骤 2/5  基金原始净值抓取（天天基金）
  步骤 3/5  基金数据转换为标准日线格式
  步骤 4/5  对所有日线 CSV 生成回撤分析报告（自动发现 data/daily/ 下的文件）
  步骤 5/5  重新生成 html/index.html 索引页

HELP;
}

// ---------------------------------------------------------------------------
// CLI 参数解析
// ---------------------------------------------------------------------------
$opts = getopt('', [
    'skip-funds',
    'skip-indexes',
    'skip-analyze',
    'skip-index-html',
    'only-analyze',
    'only-drawdown',
    'funds:',
    'full',
    'dry-run',
    'verbose',
    'help',
]);

if ($opts === false || isset($opts['help'])) {
    show_help();
    exit($opts === false ? 1 : 0);
}

$config          = quant_config();
$daily_dir       = $config['daily_dir'];
$fund_dir        = $config['fund_dir'];
$project         = $config['project_root'];
$script_dir      = __DIR__;
$titles_funds    = $config['titles_funds'];
$titles_indexes  = $config['titles_indexes'];

// --only-drawdown：只执行 return_drawdown_analyze.php，不抓数据、不重生成 index.html
$only_drawdown  = isset($opts['only-drawdown']);

$skip_funds      = isset($opts['skip-funds']) || isset($opts['only-analyze']) || $only_drawdown;
$skip_indexes    = isset($opts['skip-indexes']) || isset($opts['only-analyze']) || $only_drawdown;
$skip_analyze    = isset($opts['skip-analyze']);
$skip_index_html = $skip_analyze || isset($opts['skip-index-html']) || $only_drawdown;
$dry_run         = isset($opts['dry-run']);
$verbose         = isset($opts['verbose']);
$full            = isset($opts['full']);
$only_funds      = isset($opts['funds']) ? array_filter(explode(',', $opts['funds'])) : null;

echo "=== 数据更新脚本 ===\n";
echo "项目根目录: {$project}\n";
echo "日报目录:   {$daily_dir}\n";
echo "基金目录:   {$fund_dir}\n";
echo str_repeat('-', 50) . "\n";

$exit_code  = 0;
$start_time = microtime(true);

// ---------------------------------------------------------------------------
// 辅助：执行一个 PHP 子脚本，返回 exit code
// ---------------------------------------------------------------------------
$run = function (string $script, string $args, string $desc) use ($script_dir, $dry_run, $verbose): int {
    $cmd = sprintf(
        'php %s %s 2>&1',
        escapeshellarg($script_dir . '/' . $script),
        $args
    );

    echo "\n▶ {$desc}\n";
    echo "  {$cmd}\n";

    if ($dry_run) {
        echo "  [dry-run] 跳过执行\n";
        return 0;
    }

    $output = [];
    $code   = 0;
    exec($cmd, $output, $code);

    if ($verbose || $code !== 0) {
        foreach ($output as $line) {
            echo "  │ {$line}\n";
        }
    }

    if ($code !== 0) {
        echo "  ✗ 失败 (exit code: {$code})\n";
    } else {
        echo "  ✓ 完成\n";
    }

    return $code;
};

// ---------------------------------------------------------------------------
// Step 1: 更新指数数据（美股三大指数 + Yahoo 指数 + MSCI）
// ---------------------------------------------------------------------------
if (!$skip_indexes) {
    echo "\n" . str_repeat('=', 50) . "\n";
    echo "步骤 1/5: 更新指数数据\n";
    echo str_repeat('=', 50);

    // 1a. FRED 数据源：DJIA / SP500 / NASDAQ100 / NDXTMC / NDXTMCTR
    //     注意：fetch_us_indexes.php 不支持 --full，全量重抓用 --start=2000-01-01
    $fred_args = $full ? ' --start=2000-01-01' : '';
    $exit_code |= $run('fetch_us_indexes.php', $fred_args, '美股三大指数 + NDXTMC/NDXTMCTR（FRED）');

    // 1b. Yahoo Finance：NYSE FANG+ 指数（支持 --full）
    $yahoo_args = $full ? ' --full' : '';
    $exit_code |= $run('fetch_yahoo_index.php', $yahoo_args, 'NYSE FANG+ 指数（Yahoo）');

    // 1c. MSCI USA 50 指数（支持 --full）
    $msci_args = $full ? ' --full' : '';
    $exit_code |= $run('fetch_msci_usa50.php', $msci_args, 'MSCI USA 50 指数');
}

// ---------------------------------------------------------------------------
// Step 2 & 3: 更新基金原始数据 + 转换
// ---------------------------------------------------------------------------
if (!$skip_funds) {
    echo "\n" . str_repeat('=', 50) . "\n";
    echo "步骤 2/5: 更新基金原始净值数据\n";
    echo str_repeat('=', 50);

    $fund_codes = $only_funds ?? array_keys($titles_funds);
    $arg_full   = $full ? ' --full' : '';

    foreach ($fund_codes as $code) {
        $title = $titles_funds[$code] ?? '';
        $desc  = "基金 {$code}" . ($title ? " ({$title})" : '');
        $args  = "--code={$code}{$arg_full}";
        $exit_code |= $run('get_fund_data.php', $args, $desc);
    }

    echo "\n" . str_repeat('=', 50) . "\n";
    echo "步骤 3/5: 转换基金数据为日线格式\n";
    echo str_repeat('=', 50);

    foreach ($fund_codes as $code) {
        $raw_file = "fund_{$code}_full_data.csv";
        $desc     = "转换基金 {$code}";
        $exit_code |= $run('convert_fund.php', "--in={$raw_file}", $desc);
    }
}

// ---------------------------------------------------------------------------
// Step 4: 对所有日线 CSV 生成回撤分析报告（自动发现 data/daily/ 下的文件）
// ---------------------------------------------------------------------------
if (!$skip_analyze) {
    echo "\n" . str_repeat('=', 50) . "\n";
    echo "步骤 4/5: 生成回撤分析报告\n";
    echo str_repeat('=', 50);

    $csv_files = glob($daily_dir . '/*_daily.csv');
    if ($csv_files === false) {
        $csv_files = [];
    }

    if (empty($csv_files)) {
        echo "\n  ! 未找到 {$daily_dir}/*_daily.csv，跳过回撤分析\n";
    } else {
        foreach ($csv_files as $csv_path) {
            $filename = basename($csv_path);

            // 优先匹配显式映射
            $title = $titles_indexes[$filename] ?? '';

            // 未命中则尝试从文件名推导基金标题
            if ($title === '' && preg_match('/^fund_(\d+)_daily\.csv$/', $filename, $m)) {
                $code  = $m[1];
                $title = $titles_funds[$code] ?? "基金 {$code}";
            }

            // 兜底：直接用文件名
            if ($title === '') {
                $title = $filename;
            }

            $title_arg = escapeshellarg($title);
            $exit_code |= $run(
                'return_drawdown_analyze.php',
                "--in={$filename} --title={$title_arg}",
                "回撤分析: {$title}"
            );
        }
    }
}

// ---------------------------------------------------------------------------
// Step 5: 重新生成 html/index.html 索引页
// ---------------------------------------------------------------------------
if (!$skip_index_html) {
    $arg_dry = $dry_run ? ' --dry-run' : '';
    $exit_code |= $run('generate_index_html.php', $arg_dry, '重生成 html/index.html 索引页');
}

// ---------------------------------------------------------------------------
// 汇总
// ---------------------------------------------------------------------------
$elapsed = round(microtime(true) - $start_time, 1);
echo "\n" . str_repeat('=', 50) . "\n";
echo "全部完成 (退出码: {$exit_code}, 耗时: {$elapsed}s)\n";
echo str_repeat('=', 50) . "\n";

exit($exit_code === 0 ? 0 : 1);

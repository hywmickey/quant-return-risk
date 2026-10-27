<?php
/**
 * 生成 html/index.html 索引页
 *
 * 自动扫描 html/*_drawdown_top.html，按数据源类型（指数 / 基金）分组，
 * 用 config.php 中 titles.funds / titles.indexes 推中文标题，
 * 输出一个纯静态的 HTML 索引页。
 *
 * 用法：
 *   php generate_index_html.php                  # 正式生成
 *   php generate_index_html.php --dry-run        # 预览，不真写文件
 *   php generate_index_html.php --help
 *
 * 也可以由 update_all.php 作为 Step 5 统一调度。
 */

require_once __DIR__ . '/config_loader.php';

$opts = getopt('', ['dry-run', 'help']);

if (isset($opts['help'])) {
    echo "用法：php generate_index_html.php [--dry-run]\n";
    echo "  自动扫描 html/*_drawdown_top.html，重生成 html/index.html\n";
    echo "  --dry-run  只打印操作预览，不真写文件\n";
    exit(0);
}

$dry_run = isset($opts['dry-run']);
$config  = quant_config();
$html_dir = $config['html_dir'];
$titles_funds   = $config['titles_funds'];
$titles_indexes = $config['titles_indexes'];

$html_files = glob($html_dir . '/*_drawdown_top.html');
if ($html_files === false) {
    $html_files = [];
}

if (empty($html_files)) {
    fwrite(STDERR, "未找到 {$html_dir}/*_drawdown_top.html，跳过 index.html\n");
    exit(0);
}

// 按数据源类型分组：指数 vs 基金
$groups = ['指数' => [], '基金' => [], '其他' => []];

foreach ($html_files as $html_path) {
    $html_name = basename($html_path);   // e.g. "sp500_daily_drawdown_top.html"
    // 推回原始 CSV 名：去掉 _drawdown_top.html
    $csv_name = preg_replace('/_drawdown_top\.html$/', '.csv', $html_name);

    // 用 update_all.php Step 4 相同的匹配逻辑推标题
    $title = $titles_indexes[$csv_name] ?? '';
    if ($title === '' && preg_match('/^fund_(\d+)_daily\.csv$/', $csv_name, $m)) {
        $code  = $m[1];
        $title = $titles_funds[$code] ?? "基金 {$code}";
    }
    if ($title === '') {
        $title = $csv_name;
    }

    // 按类型分桶
    if (str_starts_with($csv_name, 'fund_')) {
        $groups['基金'][] = ['html' => $html_name, 'title' => $title, 'key' => $csv_name];
    } else {
        $groups['指数'][] = ['html' => $html_name, 'title' => $title, 'key' => $csv_name];
    }
}

// 每组内按文件名排序，保证列表稳定
foreach ($groups as &$items) {
    usort($items, fn($a, $b) => strcmp($a['key'], $b['key']));
}
unset($items);

// 组装 HTML
$today = date('Y-m-d');
$lines = [
    '<!DOCTYPE html>',
    '<html lang="zh-CN">',
    '<head>',
    '    <meta charset="utf-8">',
    '    <title>回撤分析报告索引</title>',
    '    <style>',
    '        body { font-family: -apple-system, "PingFang SC", "Microsoft YaHei", sans-serif; margin: 24px; color: #333; }',
    '        h1   { font-size: 20px; margin-bottom: 4px; }',
    '        .meta { color: #888; font-size: 12px; margin-bottom: 20px; }',
    '        h2   { font-size: 15px; margin-top: 24px; margin-bottom: 8px; color: #555; border-bottom: 1px solid #eee; padding-bottom: 4px; }',
    '        ul   { list-style: none; padding-left: 0; }',
    '        li   { margin: 6px 0; }',
    '        a    { color: #1a73e8; text-decoration: none; }',
    '        a:hover { text-decoration: underline; }',
    '        .count { color: #888; font-size: 12px; font-weight: normal; margin-left: 8px; }',
    '    </style>',
    '</head>',
    '<body>',
    '    <h1>回撤分析报告索引</h1>',
    "    <div class=\"meta\">共 " . count($html_files) . " 份报告 · 更新日期 {$today}</div>",
];

foreach ($groups as $group_name => $items) {
    if (empty($items)) {
        continue;
    }
    $lines[] = "    <h2>{$group_name}<span class=\"count\">(" . count($items) . ")</span></h2>";
    $lines[] = '    <ul>';
    foreach ($items as $item) {
        $lines[] = "        <li><a href=\"./{$item['html']}\">{$item['title']}</a></li>";
    }
    $lines[] = '    </ul>';
}

$lines[] = '</body>';
$lines[] = '</html>';

$index_path = $html_dir . '/index.html';
echo "生成 index.html (" . count($html_files) . " 份报告)\n";
echo "输出: {$index_path}\n";

if ($dry_run) {
    echo "[dry-run] 跳过写入\n";
    exit(0);
}

$written = file_put_contents($index_path, implode("\n", $lines) . "\n");
if ($written === false) {
    fwrite(STDERR, "写入失败\n");
    exit(1);
}

echo "已写入 ({$written} bytes)\n";
exit(0);

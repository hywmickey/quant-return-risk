<?php
/**
 * 统一配置加载器（目录 + 数据源标题映射）
 *
 * 读取项目根目录 config.ini 中的所有配置并解析，供 script/ 下各脚本共用。
 * 内置默认值只在本文件定义一次，各脚本不再各自硬编码 data/ 与 html/ 路径。
 *
 * 用法：
 *   require_once __DIR__ . '/config_loader.php';
 *   $config          = quant_config();
 *   $daily_dir       = $config['daily_dir'];       // 其余键：drawdown_dir / fund_dir / html_dir
 *   $project         = $config['project_root'];    // 项目根目录（本文件的上一级）
 *   $titles_funds    = $config['titles_funds'];    // array: 基金代码 => 中文标题
 *   $titles_indexes  = $config['titles_indexes'];  // array: 指数 CSV 文件名 => 中文标题
 *
 * 解析规则：
 *   - config.ini 中 [dirs] 下以 '/' 开头或 Windows 盘符开头的路径视为绝对路径，原样使用；
 *   - 其余相对路径按项目根目录拼接；
 *   - config.ini 缺失或某项缺省时，目录使用内置默认值，标题映射返回空数组。
 *
 * 结果在单次运行内缓存，重复调用 quant_config() 不会重复读盘。
 */

if (!function_exists('quant_config')) {
    function quant_config(): array
    {
        static $resolved = null;

        if ($resolved !== null) {
            return $resolved;
        }

        // 本文件位于 script/，上一级即项目根目录（config.ini 所在位置）
        $project_root = dirname(__DIR__);
        $config_file  = $project_root . '/config.ini';

        // INI_SCANNER_RAW：section 名和 key 都当作原始字符串处理，
        // 不会把 "." 当作数组层级分隔符（否则 "us30_daily.csv" 会被拆成嵌套）。
        $sections = is_file($config_file)
            ? parse_ini_file($config_file, true, INI_SCANNER_RAW)
            : false;
        if (!is_array($sections)) {
            $sections = [];
        }

        $dirs    = is_array($sections['dirs']    ?? null) ? $sections['dirs']    : [];
        $funds   = is_array($sections['titles_funds']   ?? null) ? $sections['titles_funds']   : [];
        $indexes = is_array($sections['titles_indexes'] ?? null) ? $sections['titles_indexes'] : [];

        // 内置默认目录的唯一定义处；config.ini 中的配置可覆盖
        $defaults = [
            'daily'    => 'data/daily',
            'drawdown' => 'data/drawdown',
            'fund'     => 'data/fund',
            'html'     => 'html',
        ];

        $resolve = static function (string $path) use ($project_root): string {
            if ($path === '') {
                return $project_root;
            }
            // Unix 绝对路径（/...）或 Windows 盘符路径（C:\... / C:/...）原样使用
            if ($path[0] === '/' || preg_match('/^[A-Za-z]:[\/\\\\]/', $path)) {
                return rtrim($path, '/\\');
            }
            return $project_root . '/' . trim($path, '/\\');
        };

        $resolved = ['project_root' => $project_root];
        foreach ($defaults as $key => $default) {
            $resolved[$key . '_dir'] = $resolve((string) ($dirs[$key] ?? $default));
        }

        // 数据源标题映射：config.ini 中缺省时返回空数组，调用方自行兜底
        $resolved['titles_funds']   = $funds;
        $resolved['titles_indexes'] = $indexes;

        return $resolved;
    }
}

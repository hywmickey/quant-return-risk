<?php
/**
 * 统一目录配置加载器
 *
 * 读取项目根目录 config.php 中的目录配置并解析为绝对路径，供 script/ 下各脚本共用。
 * 内置默认值只在本文件定义一次，各脚本不再各自硬编码 data/ 与 html/ 路径。
 *
 * 用法：
 *   require_once __DIR__ . '/config_loader.php';
 *   $config     = quant_config();
 *   $daily_dir  = $config['daily_dir'];     // 其余键：drawdown_dir / fund_dir / html_dir
 *   $project    = $config['project_root'];  // 项目根目录（本文件的上一级）
 *
 * 解析规则：
 *   - config.php 中以 '/' 开头或 Windows 盘符开头的路径视为绝对路径，原样使用；
 *   - 其余相对路径按项目根目录拼接；
 *   - config.php 缺失或某项缺省时，使用 $defaults 中的内置默认值。
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

        // 本文件位于 script/，上一级即项目根目录（config.php 所在位置）
        $project_root = dirname(__DIR__);
        $config_file  = $project_root . '/config.php';

        $raw = is_file($config_file) ? (require $config_file) : [];
        if (!is_array($raw)) {
            $raw = [];
        }
        $dirs = is_array($raw['dirs'] ?? null) ? $raw['dirs'] : [];

        // 内置默认目录的唯一定义处；config.php 中的配置可覆盖
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

        return $resolved;
    }
}

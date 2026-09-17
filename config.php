<?php
/**
 * 量化分析脚本统一目录配置
 *
 * script/ 下的所有脚本通过 script/config_loader.php 读取本文件获取默认数据目录：
 *   daily    指数 / 基金标准日线 CSV（date,level,change_pct）的读取与输出目录
 *   drawdown return_drawdown_analyze.php 产出的回撤分析 CSV 目录
 *   fund     get_fund_data.php 抓取的基金原始净值 CSV 目录（convert_fund.php 也从这里读）
 *   html     return_drawdown_analyze.php 产出的 HTML 报告目录
 *
 * 路径规则：
 *   - 相对路径一律按本文件所在的项目根目录解析（如 'data/daily'）；
 *   - 也可以写绝对路径（如 '/Volumes/data/quant/daily'），原样使用。
 *
 * 修改本文件无需改动任何脚本；命令行显式传入的 --in / --out / --top-out / --html-out
 * 等参数优先级高于本配置。本文件缺失或某项未配置时，加载器会退回内置默认值。
 */

return [
    'dirs' => [
        'daily'    => 'data/daily',
        'drawdown' => 'data/drawdown',
        'fund'     => 'data/fund',
        'html'     => 'html',
    ],
];

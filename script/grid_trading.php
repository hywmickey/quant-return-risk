<?php
/**
 * 网格交易补仓策略计算器
 *
 * 对应 Excel 中两个工作表的计算逻辑：
 *   - 工作表1：线性下跌模型（净值每次固定减去网格比例）
 *   - 工作表2：复利下跌模型（净值每次乘以 (1 - 网格比例)）
 *
 * 列含义：
 *   A 网格(grid)        : 每格下跌比例，默认 0.05 (5%)
 *   B 下跌次数(step)     : 0, 1, 2, ..., maxStep
 *   C 亏损比例(lossRatio): 累计亏损比例
 *   D 净值(nav)          : 相对于初始净值 1 的当前净值
 *   E 投入(invest)       : 本次下跌后需要加仓的金额
 *   F 前一天价值(prevVal): 下跌后、加仓前的持仓市值
 *   G 当前价值(curVal)   : 加仓后的持仓市值
 *   H 总投入(totalInvest): 累计投入金额
 *   I 总亏损价值(lossVal): 总投入 - 当前价值
 *   J 总亏损比率(lossRate): 总亏损价值 / 总投入
 */

class GridTradingCalculator
{
    /** @var float 每格下跌比例，如 0.05 表示 5% */
    private float $grid;

    /** @var int 最大下跌次数（不含第 0 次） */
    private int $maxStep;

    /** @var float 初始投入金额（第 0 行的 E 值） */
    private float $initialInvest;

    public function __construct(float $grid = 0.05, int $maxStep = 20, float $initialInvest = 1.0)
    {
        $this->grid = $grid;
        $this->maxStep = $maxStep;
        $this->initialInvest = $initialInvest;
    }

    /**
     * 工作表1：线性下跌模型
     *
     * 净值 D = 1 - step * grid（每次固定减去 grid）
     * 亏损比例 C = step * grid（与净值互补，线性累计）
     */
    public function calculateLinear(): array
    {
        return $this->calculate('linear');
    }

    /**
     * 工作表2：复利下跌模型
     *
     * 净值 D = (1 - grid) ^ step（每次乘以 (1-grid)，复利衰减）
     * 亏损比例 C = 1 - D（实际累计亏损比例）
     */
    public function calculateCompound(): array
    {
        return $this->calculate('compound');
    }

    /**
     * 通用计算引擎
     *
     * 补仓公式（E 列）推导：
     *   设 prevTotal = 之前累计总投入，prevVal = 下跌后持仓市值 = 上一行G * (1-grid)
     *   目标：加仓后总持仓的平均亏损比例 = 当前累计亏损比例 / 2
     *   即：(prevTotal + invest - (prevVal + invest)) / (prevTotal + invest) = lossRatio / 2
     *   化简：(prevTotal - prevVal) / (prevTotal + invest) = lossRatio / 2
     *   解得：invest = (prevTotal - prevVal) / (lossRatio / 2) - prevTotal
     */
    private function calculate(string $mode): array
    {
        $rows = [];

        // 第 0 行（初始状态）
        $step = 0;
        $nav = 1.0;
        $lossRatio = 0.0;
        $invest = $this->initialInvest;
        $prevVal = 0.0;                // F2 = 0
        $curVal = $invest;              // G2 = 1
        $totalInvest = $invest;         // H2 = SUM(E2:E2)
        $lossVal = 0.0;                 // I2 = 0
        $lossRate = 0.0;                // J2 = 0

        $rows[] = $this->makeRow($step, $lossRatio, $nav, $invest, $prevVal, $curVal, $totalInvest, $lossVal, $lossRate);

        // 逐行递推
        for ($step = 1; $step <= $this->maxStep; $step++) {
            $prevRow = $rows[$step - 1];

            // 1) 净值与亏损比例（两种模型的唯一区别）
            if ($mode === 'linear') {
                // 工作表1：D = 上一行D - grid；C = step * grid
                $nav = $prevRow['nav'] - $this->grid;
                $lossRatio = $step * $this->grid;
            } else {
                // 工作表2：D = 上一行D * (1 - grid)；C = 1 - D
                $nav = $prevRow['nav'] * (1 - $this->grid);
                $lossRatio = 1 - $nav;
            }

            // 2) 前一天价值（下跌后、加仓前的持仓市值）
            //    F = 上一行G * (1 - grid)
            $prevVal = $prevRow['curVal'] * (1 - $this->grid);

            // 3) 本次投入（补仓公式）
            //    E = (prevTotal - prevVal) / (lossRatio / 2) - prevTotal
            $prevTotal = $prevRow['totalInvest'];
            if ($lossRatio > 0) {
                $invest = ($prevTotal - $prevVal) / ($lossRatio / 2) - $prevTotal;
            } else {
                $invest = 0.0;
            }

            // 4) 当前价值（加仓后持仓市值）
            //    G = prevVal + invest
            $curVal = $prevVal + $invest;

            // 5) 总投入
            //    H = prevTotal + invest
            $totalInvest = $prevTotal + $invest;

            // 6) 总亏损价值
            //    I = totalInvest - curVal
            $lossVal = $totalInvest - $curVal;

            // 7) 总亏损比率
            //    J = lossVal / totalInvest
            $lossRate = $totalInvest > 0 ? $lossVal / $totalInvest : 0.0;

            $rows[] = $this->makeRow($step, $lossRatio, $nav, $invest, $prevVal, $curVal, $totalInvest, $lossVal, $lossRate);
        }

        return $rows;
    }

    private function makeRow(int $step, float $lossRatio, float $nav, float $invest, float $prevVal, float $curVal, float $totalInvest, float $lossVal, float $lossRate): array
    {
        return [
            'step'        => $step,        // B 下跌次数
            'lossRatio'   => $lossRatio,   // C 亏损比例
            'nav'         => $nav,         // D 净值
            'invest'      => $invest,      // E 投入
            'prevVal'     => $prevVal,     // F 前一天价值
            'curVal'      => $curVal,      // G 当前价值
            'totalInvest' => $totalInvest, // H 总投入
            'lossVal'     => $lossVal,     // I 总亏损价值
            'lossRate'    => $lossRate,    // J 总亏损比率
        ];
    }

    /**
     * 格式化为表格输出（便于对照 Excel）
     */
    public function printTable(array $rows, string $title): void
    {
        echo "\n=== {$title} ===\n";
        printf("%-4s %-10s %-10s %-10s %-12s %-12s %-12s %-12s %-12s %-10s\n",
            '次数', '亏损比例', '净值', '投入', '前一天价值', '当前价值', '总投入', '总亏损价值', '总亏损比率', '');
        echo str_repeat('-', 110) . "\n";

        foreach ($rows as $r) {
            printf("%-4d %-10.4f %-10.6f %-10.6f %-12.6f %-12.6f %-12.6f %-12.6f %-10.4f%%\n",
                $r['step'],
                $r['lossRatio'],
                $r['nav'],
                $r['invest'],
                $r['prevVal'],
                $r['curVal'],
                $r['totalInvest'],
                $r['lossVal'],
                $r['lossRate'] * 100,
            );
        }
    }
}

// ========== 使用示例 ==========
if (php_sapi_name() === 'cli') {
    $calc = new GridTradingCalculator($grid = 0.05, $maxStep = 20, $initialInvest = 1.0);

    // 工作表1：线性下跌模型
    $linearRows = $calc->calculateLinear();
    $calc->printTable($linearRows, '工作表1 - 线性下跌模型');

    // 工作表2：复利下跌模型
    $compoundRows = $calc->calculateCompound();
    $calc->printTable($compoundRows, '工作表2 - 复利下跌模型');

    // 关键指标对比
    echo "\n=== 第 20 次下跌时对比 ===\n";
    $l = end($linearRows);
    $c = end($compoundRows);
    printf("%-12s %-18s %-18s\n", '指标', '线性模型', '复利模型');
    printf("%-12s %-18.6f %-18.6f\n", '净值', $l['nav'], $c['nav']);
    printf("%-12s %-18.6f %-18.6f\n", '累计亏损比例', $l['lossRatio'], $c['lossRatio']);
    printf("%-12s %-18.2f %-18.2f\n", '总投入', $l['totalInvest'], $c['totalInvest']);
    printf("%-12s %-18.2f %-18.2f\n", '当前价值', $l['curVal'], $c['curVal']);
    printf("%-12s %-18.4f%% %-18.4f%%\n", '总亏损比率', $l['lossRate'] * 100, $c['lossRate'] * 100);
}

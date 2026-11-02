<?php
/**
 * Yahoo Finance 指数日线增量更新脚本
 *
 * 数据源：Yahoo Finance（通过 Python yfinance 库抓取）
 *
 * 为什么用 yfinance 而非 PHP curl 直连：
 *   Yahoo 的 chart / crumb 接口会按 TLS 指纹识别非浏览器请求并返回 429，
 *   PHP curl 的 TLS 指纹会被判定为机器人；yfinance 内部用 curl_cffi 模拟
 *   Chrome 的 TLS 指纹才能通过。因此本脚本由 PHP 提供统一 CLI 入口、目录
 *   配置与增量合并逻辑，实际 HTTP 抓取委托给 yfinance。
 *
 * Python 环境：
 *   解释器优先级：项目根目录 venv/bin/python > 系统 python3（PATH 中第一个）。
 *   若当前解释器无法 import yfinance，脚本会自动执行下面三步并在完成后继续抓取：
 *     python3 -m venv <项目根>/venv
 *     venv/bin/python -m pip install yfinance
 *     （随后用 venv 的解释器抓取）
 *   自动安装失败时会打印完整的手动修复命令。
 *
 * 指数配置（key => Yahoo 代码 / 中文名 / 输出文件 / 全量起点）：
 *   nyfang -> ^NYFANG -> data/daily/nyfang_daily.csv （NYSE FANG+ 指数，2014-09-22 发布）
 *
 * 输出 CSV 与 fetch_us_indexes.php / fetch_msci_usa50.php 一致：UTF-8 BOM + date,level,change_pct
 *
 * 增量更新：
 *   文件已存在时默认走增量——读出本地最后一个日期，只抓「最后日期 - overlap 天」到今天，
 *   再按日期合并写回（重叠区间以新数据为准，用于吸收 Yahoo 对近几日点位的修订）。
 *   适合挂 crontab 每日跑一次。
 *
 * 用法：
 *   php fetch_yahoo_index.php                       # 增量更新全部已配置指数
 *   php fetch_yahoo_index.php --symbols=nyfang      # 只更新指定指数，逗号分隔可多个
 *   php fetch_yahoo_index.php --full                # 忽略已有文件，全量重抓
 *   php fetch_yahoo_index.php --start=2020-01-01 --end=2026-09-18
 *   php fetch_yahoo_index.php --overlap=10          # 增量时向前回溯 10 天
 *
 * 参数：
 *   --symbols   只更新指定指数 key（默认全部），逗号分隔
 *   --start     起始日期 YYYY-MM-DD，显式指定时不走增量推算
 *   --end       结束日期 YYYY-MM-DD，默认今天
 *   --overlap   增量时向前回溯的天数，默认 7
 *   --full      强制全量重抓，覆盖已有文件
 *   --help      帮助
 */

class YahooIndexFetcher
{
    /**
     * 指数配置：key => [symbol, name, file, default_start]
     * 新增 Yahoo 独有指数时只需在此追加一行
     */
    public const SYMBOLS = [
        'nyfang' => ['symbol' => '^NYFANG', 'name' => 'NYSE FANG+ 指数', 'file' => 'nyfang_daily.csv', 'start' => '2014-09-22'],
    ];

    /** 实际用于抓取的 Python 解释器（checkEnv 后确定，优先项目内 venv） */
    private string $python = 'python3';

    /** 项目内虚拟环境目录 */
    private string $venvDir;

    public function __construct(?string $venvDir = null)
    {
        $this->venvDir = $venvDir ?? dirname(__DIR__) . '/venv';
    }

    /**
     * 检查 Python 环境；缺失时自动创建 venv 并安装 yfinance
     *
     * 解释器优先级：项目根 venv/bin/python > 系统 python3。
     * 当前解释器无法 import yfinance 时，自动执行 venv 创建 + pip install，
     * 成功后改用 venv 的解释器；失败则抛异常并给出手动修复命令。
     */
    public function checkEnv(): void
    {
        $base = $this->findBasePython();
        if ($base === '') {
            throw new RuntimeException('未找到 python3，请先安装 Python 3');
        }

        // venv 已存在时优先用它，避免依赖系统环境是否装过 yfinance
        $venvPy    = $this->venvPythonPath();
        $candidate = is_file($venvPy) ? $venvPy : $base;

        [$ok, $error] = $this->probeImport($candidate);
        if ($ok) {
            $this->python = $candidate;
            return;
        }

        $this->bootstrapEnv($base, $candidate, $error);
    }

    /**
     * 自动创建 venv 并安装 yfinance，成功后把解释器切到 venv
     */
    private function bootstrapEnv(string $base, string $candidate, string $firstError): void
    {
        echo "[env] {$candidate} 无法 import yfinance（{$firstError}），开始自动安装 ...\n";

        $venvPy = $this->venvPythonPath();
        if (!is_file($venvPy)) {
            echo "[env] 创建虚拟环境：{$this->venvDir}\n";
            $this->runCmd([$base, '-m', 'venv', $this->venvDir], 'python3 -m venv');
            $venvPy = $this->venvPythonPath();
        }

        if (!is_file($venvPy)) {
            throw new RuntimeException(
                "虚拟环境创建失败，未找到 {$venvPy}\n" . $this->manualInstructions()
            );
        }

        echo "[env] 安装 yfinance（首次安装需要几分钟）...\n";
        $this->runCmd([$venvPy, '-m', 'pip', 'install', '--disable-pip-version-check', 'yfinance'], 'pip install yfinance');

        [$ok, $error] = $this->probeImport($venvPy);
        if (!$ok) {
            throw new RuntimeException(
                "自动安装后仍无法 import yfinance（{$error}）\n" . $this->manualInstructions()
            );
        }

        $this->python = $venvPy;
        echo "[env] 依赖就绪：{$venvPy}\n";
    }

    /**
     * 在指定解释器里执行 `import yfinance`，返回 [是否成功, 错误信息]
     */
    private function probeImport(string $python): array
    {
        $desc = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $proc = @proc_open([$python, '-c', 'import yfinance'], $desc, $pipes);
        if (!is_resource($proc)) {
            return [false, "无法启动 {$python}"];
        }

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return [$code === 0, $stderr !== '' ? $stderr : trim($stdout)];
    }

    /**
     * 找系统里第一个可用的 python3 / python
     */
    private function findBasePython(): string
    {
        foreach (['python3', 'python'] as $name) {
            $path = trim((string) shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
            if ($path !== '') {
                return $path;
            }
        }

        return '';
    }

    /**
     * venv 内的解释器路径（Windows 与 Unix 两种布局都兼容）
     */
    private function venvPythonPath(): string
    {
        $win = $this->venvDir . '/Scripts/python.exe';
        if (is_file($win)) {
            return $win;
        }

        return $this->venvDir . '/bin/python';
    }

    /**
     * 执行安装类命令并把输出直接透传到终端
     */
    private function runCmd(array $cmd, string $label): void
    {
        $line = implode(' ', array_map('escapeshellarg', $cmd));
        echo "  {$label}: {$line}\n";

        $code = 0;
        passthru($line, $code);

        if ($code !== 0) {
            throw new RuntimeException("{$label} 失败（exit {$code}）\n" . $this->manualInstructions());
        }
    }

    /**
     * 手动修复命令（自动安装失败时给出）
     */
    private function manualInstructions(): string
    {
        return "手动修复步骤：\n"
            . '  cd ' . dirname($this->venvDir) . "\n"
            . "  python3 -m venv ./venv\n"
            . "  source venv/bin/activate\n"
            . "  python3 -m pip install yfinance\n"
            . "  php script/fetch_yahoo_index.php";
    }

    /**
     * 抓取指定指数在 [$startDate, $endDate] 区间的收盘点位
     *
     * 通过环境变量传参（避免 shell 转义），proc_open 数组形式不经过 shell
     *
     * @return array<int, array{date: string, level: float}> 按日期升序
     */
    public function fetch(string $symbol, string $startDate, string $endDate): array
    {
        // yfinance 下载脚本：参数从环境变量读取，结果以 JSON 输出到 stdout
        $py = <<<'PY'
import os, json
import yfinance as yf
sym = os.environ['YF_SYMBOL']
start = os.environ['YF_START']
end = os.environ['YF_END']
df = yf.download(sym, start=start, end=end, interval='1d', auto_adjust=False, progress=False)
rows = []
if df is not None and not df.empty:
    close = df['Close']
    if hasattr(close, 'columns'):
        close = close.iloc[:, 0]
    for ts, v in close.items():
        if v is None or v != v:
            continue
        rows.append({'date': ts.strftime('%Y-%m-%d'), 'level': round(float(v), 6)})
print(json.dumps(rows))
PY;

        $desc = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $proc = proc_open([$this->python, '-c', $py], $desc, $pipes, null, [
            'YF_SYMBOL' => $symbol,
            'YF_START'  => $startDate,
            'YF_END'    => $endDate,
        ]);
        if (!is_resource($proc)) {
            throw new RuntimeException("无法启动 {$this->python} 抓取 {$symbol}");
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($proc);

        if ($code !== 0) {
            throw new RuntimeException("yfinance 抓取 {$symbol} 失败（exit {$code}）：" . trim((string) $stderr));
        }

        $rows = json_decode((string) $stdout, true);
        if (!is_array($rows)) {
            throw new RuntimeException("yfinance 返回非 JSON：" . substr((string) $stdout, 0, 200));
        }

        usort($rows, fn(array $a, array $b): int => strcmp($a['date'], $b['date']));

        return $rows;
    }

    /**
     * 读取已有的日线 CSV，文件不存在时返回空数组
     *
     * @return array<int, array{date: string, level: float}> 按日期升序
     */
    public function readCsv(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $fh = fopen($path, 'r');
        if ($fh === false) {
            throw new RuntimeException("无法读取文件：{$path}");
        }

        $rows = [];
        while (($cols = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            $date = ltrim((string) ($cols[0] ?? ''), "\xEF\xBB\xBF");   // 首行可能带 BOM
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;                                               // 跳过表头与空行
            }
            $rows[] = ['date' => $date, 'level' => (float) $cols[1]];
        }
        fclose($fh);

        usort($rows, fn(array $a, array $b): int => strcmp($a['date'], $b['date']));

        return $rows;
    }

    /**
     * 写出 CSV：日期、点位、相对上一交易日涨跌幅(%)
     */
    public function writeCsv(array $rows, string $path): void
    {
        $fh = fopen($path, 'w');
        if ($fh === false) {
            throw new RuntimeException("无法写入文件：{$path}");
        }

        fwrite($fh, "\xEF\xBB\xBF");   // BOM，方便 Excel 直接打开
        fputcsv($fh, ['date', 'level', 'change_pct'], ',', '"', '\\');

        $prev = null;
        foreach ($rows as $r) {
            $change = $prev === null ? '' : round(($r['level'] / $prev - 1) * 100, 4);
            fputcsv($fh, [$r['date'], round($r['level'], 6), $change], ',', '"', '\\');
            $prev = $r['level'];
        }

        fclose($fh);
    }

    /**
     * 按日期合并新旧数据，新数据覆盖旧数据
     *
     * @param array<int, array{date: string, level: float}> $old
     * @param array<int, array{date: string, level: float}> $new
     * @return array{rows: array<int, array{date: string, level: float}>, added: int, updated: int}
     */
    public static function merge(array $old, array $new): array
    {
        $map = [];
        foreach ($old as $r) {
            $map[$r['date']] = $r['level'];
        }

        $added   = 0;
        $updated = 0;
        foreach ($new as $r) {
            if (!array_key_exists($r['date'], $map)) {
                $added++;
            } elseif (abs($map[$r['date']] - $r['level']) > 1e-6) {
                $updated++;
            }
            $map[$r['date']] = $r['level'];
        }

        ksort($map, SORT_STRING);

        $rows = [];
        foreach ($map as $date => $level) {
            $rows[] = ['date' => (string) $date, 'level' => $level];
        }

        return ['rows' => $rows, 'added' => $added, 'updated' => $updated];
    }
}

// ========== CLI 入口 ==========
if (PHP_SAPI === 'cli') {
    $opts = getopt('', ['symbols::', 'start::', 'end::', 'overlap::', 'full', 'help']);

    if (isset($opts['help'])) {
        echo "用法：php fetch_yahoo_index.php [--symbols=nyfang] [--start=2020-01-01] [--end=2026-09-18] [--overlap=7] [--full]\n";
        echo "默认增量更新 config.php 的 daily 目录下全部已配置指数文件。\n";
        echo "Python 环境缺失时自动创建 <项目根>/venv 并安装 yfinance（已存在 venv 则优先使用）。\n";
        exit(0);
    }

    $end      = $opts['end'] ?? date('Y-m-d');
    $overlap  = max(0, (int) ($opts['overlap'] ?? 7));
    $full     = isset($opts['full']);
    // 日线 CSV 输出目录统一来自项目根目录 config.php 的 daily 配置
    require_once __DIR__ . '/config_loader.php';
    $dataDir = quant_config()['daily_dir'];

    // 本次要更新的指数 key：--symbols 指定，默认全部
    $keys = array_keys(YahooIndexFetcher::SYMBOLS);
    if (isset($opts['symbols'])) {
        $keys = array_filter(array_map('trim', explode(',', (string) $opts['symbols'])), fn($s) => $s !== '');
        $unknown = array_diff($keys, array_keys(YahooIndexFetcher::SYMBOLS));
        if ($unknown !== []) {
            fwrite(STDERR, "未知 symbols：" . implode(', ', $unknown) . "\n");
            fwrite(STDERR, "支持：" . implode(', ', array_keys(YahooIndexFetcher::SYMBOLS)) . "\n");
            exit(1);
        }
    }

    try {
        $fetcher = new YahooIndexFetcher();
        $fetcher->checkEnv();
    } catch (Throwable $e) {
        fwrite(STDERR, '环境检查失败：' . $e->getMessage() . "\n");
        exit(1);
    }

    $failCount = 0;
    foreach ($keys as $key) {
        $cfg    = YahooIndexFetcher::SYMBOLS[$key];
        $symbol = $cfg['symbol'];
        $name   = $cfg['name'];
        $file   = $dataDir . '/' . $cfg['file'];

        try {
            $existing = $full ? [] : $fetcher->readCsv($file);

            if ($existing !== [] && !isset($opts['start'])) {
                // 增量：从最后一天往前回溯 overlap 天重抓，覆盖 Yahoo 对近几日点位的修订
                $lastDate = $existing[count($existing) - 1]['date'];
                $start    = date('Y-m-d', strtotime("{$lastDate} -{$overlap} day"));
                echo "[{$key}] {$name} 已有 " . count($existing) . " 条（截至 {$lastDate}），增量更新 ...\n";
            } else {
                $start = $opts['start'] ?? $cfg['start'];
                echo "[{$key}] {$name} 本地无数据或指定了 --start，全量抓取 {$start} 起 ...\n";
            }

            if ($start > $end) {
                echo "[{$key}] 本地数据已是最新（{$end} 之前无需抓取）\n";
                continue;
            }

            $fresh = $fetcher->fetch($symbol, $start, $end);
            if ($fresh === [] && $existing === []) {
                fwrite(STDERR, "[{$key}] 未取到数据，请检查 Yahoo 代码 {$symbol} 与日期区间\n");
                $failCount++;
                continue;
            }

            $merged = YahooIndexFetcher::merge($existing, $fresh);
            $fetcher->writeCsv($merged['rows'], $file);

            $last = $merged['rows'][count($merged['rows']) - 1];
            printf("[%s] 新增 %d 条，修订 %d 条，合计 %d 条，末条 %s %.2f，已写入：%s\n",
                $key, $merged['added'], $merged['updated'], count($merged['rows']),
                $last['date'], $last['level'], $file);
        } catch (Throwable $e) {
            fwrite(STDERR, "[{$key}] 出错：" . $e->getMessage() . "\n");
            $failCount++;
        }
    }

    exit($failCount > 0 ? 1 : 0);
}

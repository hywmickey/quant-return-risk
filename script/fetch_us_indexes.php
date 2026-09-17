<?php
/**
 * 美股三大指数日线增量更新脚本（FRED 数据源）
 *
 * 数据源：美联储圣路易斯分行 FRED 接口（公开，需 api_key）
 *   https://api.stlouisfed.org/fred/series/observations
 *
 * series_id 与本地文件对应关系：
 *   DJIA            -> data/daily/us30_daily.csv       （道琼斯工业平均指数）
 *   SP500           -> data/daily/sp500_daily.csv      （标普 500 指数）
 *   NASDAQ100       -> data/daily/nasdaq100_daily.csv  （纳斯达克 100 指数）
 *   NASDAQNDXTMC    -> data/daily/ndxtmc_daily.csv     （纳斯达克 100 科技行业市值加权指数 NDXTMC，价格指数）
 *   NASDAQNDXTMCTR  -> data/daily/ndxtmc_tr_daily.csv  （NDXTMC 总收益指数，分红再投资）
 *
 * 输出 CSV 与 convert_fund.php / fetch_msci_usa50.php 一致：UTF-8 BOM + date,level,change_pct
 *
 * 增量更新：
 *   文件已存在时默认走增量——读出本地最后一个日期，只抓「最后日期 - overlap 天」到今天，
 *   再按日期合并写回（重叠区间以新数据为准，用于吸收数据源对近几日点位的修订）。
 *   适合挂 crontab 每日跑一次。
 *
 * 用法：
 *   php fetch_us_indexes.php                                  # 增量更新全部指数
 *   php fetch_us_indexes.php --series=SP500                   # 只更新指定指数，逗号分隔可多个
 *   php fetch_us_indexes.php --series=NASDAQNDXTMC            # 只更新 NDXTMC 价格指数
 *   php fetch_us_indexes.php --series=NASDAQNDXTMCTR          # 只更新 NDXTMC 总收益指数
 *   php fetch_us_indexes.php --overlap=10                     # 增量时向前回溯 10 天
 *   php fetch_us_indexes.php --start=2026-01-01 --end=2026-09-09
 *   php fetch_us_indexes.php --api-key=xxx                    # 也可用环境变量 FRED_API_KEY
 *
 * 参数：
 *   --series    只更新指定 series_id（DJIA / SP500 / NASDAQ100 / NASDAQNDXTMC / NASDAQNDXTMCTR），默认全部
 *   --start     起始日期 YYYY-MM-DD，显式指定时不走增量推算
 *   --end       结束日期 YYYY-MM-DD，默认今天
 *   --overlap   增量时向前回溯的天数，默认 5
 *   --api-key   FRED api_key，默认用脚本内置 key 或环境变量 FRED_API_KEY
 *   --help      帮助
 */

class FredIndexFetcher
{
    /** FRED 序列观测值接口 */
    private const API_URL = 'https://api.stlouisfed.org/fred/series/observations';

    /** series_id => 本地 CSV 文件名（相对 data/daily 目录） */
    public const SERIES_FILES = [
        'DJIA'           => 'us30_daily.csv',
        'SP500'          => 'sp500_daily.csv',
        'NASDAQ100'      => 'nasdaq100_daily.csv',
        'NASDAQNDXTMC'   => 'ndxtmc_daily.csv',
        'NASDAQNDXTMCTR' => 'ndxtmc_tr_daily.csv',
    ];

    /** series_id => 全量抓取的默认起始日期（各指数发布/有数据的最早日期） */
    public const SERIES_STARTS = [
        'DJIA'           => '2000-01-01',
        'SP500'          => '2000-01-01',
        'NASDAQ100'      => '2000-01-01',
        'NASDAQNDXTMC'   => '2022-03-21',   // NDXTMC 指数发布日（base value 1000）
        'NASDAQNDXTMCTR' => '2022-03-21',
    ];

    /** @var string FRED api_key */
    private string $apiKey;

    /** @var int 请求超时秒数 */
    private int $timeout;

    /** @var int 失败重试次数 */
    private int $retries;

    public function __construct(string $apiKey, int $timeout = 60, int $retries = 3)
    {
        $this->apiKey  = $apiKey;
        $this->timeout = $timeout;
        $this->retries = $retries;
    }

    /**
     * 抓取指定序列在 [$startDate, $endDate] 区间的观测值
     *
     * FRED 的 observations 里 value 为 "." 表示该日无数据（节假日等），需跳过
     *
     * @return array<int, array{date: string, level: float}> 按日期升序
     */
    public function fetch(string $seriesId, string $startDate, string $endDate): array
    {
        $query = http_build_query([
            'series_id'        => $seriesId,
            'frequency'        => 'd',          // Daily
            'api_key'          => $this->apiKey,
            'file_type'        => 'json',
            'observation_start' => $startDate,
            'observation_end'  => $endDate,
        ]);

        $raw     = $this->httpGet(self::API_URL . '?' . $query);
        $payload = json_decode($raw, true);

        // 参数非法、key 失效时接口返回 error_code / error_message
        if (is_array($payload) && isset($payload['error_message'])) {
            throw new RuntimeException('FRED 接口报错：' . trim((string) $payload['error_message']));
        }
        if (!is_array($payload) || !isset($payload['observations']) || !is_array($payload['observations'])) {
            throw new RuntimeException("FRED 返回格式异常：" . substr($raw, 0, 200));
        }

        $rows = [];
        foreach ($payload['observations'] as $item) {
            $date  = (string) ($item['date'] ?? '');
            $value = (string) ($item['value'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !is_numeric($value)) {
                continue;   // 跳过缺失值（"."）与异常记录
            }
            $rows[] = ['date' => $date, 'level' => (float) $value];
        }

        usort($rows, fn(array $a, array $b): int => strcmp($a['date'], $b['date']));

        return $rows;
    }

    /**
     * 带重试的 HTTP GET
     */
    private function httpGet(string $url): string
    {
        $lastError = '';

        for ($attempt = 1; $attempt <= $this->retries; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_ENCODING       => 'gzip, deflate',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; fred-index-fetcher/1.0)',
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            ]);

            $body   = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errMsg = curl_error($ch);
            unset($ch);

            if ($body !== false && $status === 200) {
                return $body;
            }

            $lastError = $errMsg !== '' ? $errMsg : "HTTP {$status}";
            fwrite(STDERR, "请求失败（第 {$attempt}/{$this->retries} 次）：{$lastError}\n");

            if ($attempt < $this->retries) {
                sleep(2 * $attempt);   // 线性退避
            }
        }

        throw new RuntimeException("请求 FRED 接口失败：{$lastError}");
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
     * （FRED 可能对最近几个交易日的点位做修订，所以重叠区间以新抓到的为准）
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
                // 容差取 1e-6：CSV 落盘时点位保留 6 位小数，不能按原始浮点精度比
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
    $opts = getopt('', ['series::', 'start::', 'end::', 'overlap::', 'api-key::', 'help']);

    if (isset($opts['help'])) {
        echo "用法：php fetch_us_indexes.php [--series=DJIA,SP500,NASDAQ100,NASDAQNDXTMC,NASDAQNDXTMCTR] [--start=2026-01-01] [--end=2026-09-09] [--overlap=5] [--api-key=xxx]\n";
        echo "默认增量更新 config.php 的 daily 目录下全部指数文件。\n";
        exit(0);
    }

    // api_key 优先级：命令行 > 环境变量 FRED_API_KEY > 内置 key
    $apiKey = (string) ($opts['api-key'] ?? getenv('FRED_API_KEY') ?: 'ab5aa8c65baafe88f591861de6df5716');
    if ($apiKey === '') {
        fwrite(STDERR, "缺少 FRED api_key，请用 --api-key 或环境变量 FRED_API_KEY 提供\n");
        exit(1);
    }

    $end     = $opts['end']     ?? date('Y-m-d');
    $overlap = max(0, (int) ($opts['overlap'] ?? 5));
    // 日线 CSV 输出目录统一来自项目根目录 config.php 的 daily 配置
    require_once __DIR__ . '/config_loader.php';
    $dataDir = quant_config()['daily_dir'];

    // 本次要更新的序列：--series 指定，默认全部
    $seriesIds = array_keys(FredIndexFetcher::SERIES_FILES);
    if (isset($opts['series'])) {
        $seriesIds = array_filter(array_map('trim', explode(',', (string) $opts['series'])), fn($s) => $s !== '');
        $unknown   = array_diff($seriesIds, array_keys(FredIndexFetcher::SERIES_FILES));
        if ($unknown !== []) {
            fwrite(STDERR, "未知 series_id：" . implode(', ', $unknown) . "\n");
            fwrite(STDERR, "支持：" . implode(', ', array_keys(FredIndexFetcher::SERIES_FILES)) . "\n");
            exit(1);
        }
    }

    $fetcher   = new FredIndexFetcher($apiKey);
    $failCount = 0;

    foreach ($seriesIds as $seriesId) {
        $file = $dataDir . '/' . FredIndexFetcher::SERIES_FILES[$seriesId];

        try {
            $existing = $fetcher->readCsv($file);

            if ($existing !== [] && !isset($opts['start'])) {
                // 增量：从最后一天往前回溯 overlap 天重抓，覆盖数据源对近几日点位的修订
                $lastDate = $existing[count($existing) - 1]['date'];
                $start    = date('Y-m-d', strtotime("{$lastDate} -{$overlap} day"));
                echo "[{$seriesId}] 已有 " . count($existing) . " 条（截至 {$lastDate}），增量更新 ...\n";
            } else {
                // 本地没有文件时从该指数的最早可用日期抓（显式 --start 时按指定日期）
                $start = $opts['start'] ?? (FredIndexFetcher::SERIES_STARTS[$seriesId] ?? '2000-01-01');
                echo "[{$seriesId}] 本地无数据或指定了 --start，全量抓取 {$start} 起 ...\n";
            }

            if ($start > $end) {
                echo "[{$seriesId}] 本地数据已是最新（{$end} 之前无需抓取）\n";
                continue;
            }

            $fresh = $fetcher->fetch($seriesId, $start, $end);
            if ($fresh === [] && $existing === []) {
                fwrite(STDERR, "[{$seriesId}] 未取到数据，请检查日期区间与 api_key\n");
                $failCount++;
                continue;
            }

            $merged = FredIndexFetcher::merge($existing, $fresh);
            $fetcher->writeCsv($merged['rows'], $file);

            $last = $merged['rows'][count($merged['rows']) - 1];
            printf("[%s] 新增 %d 条，修订 %d 条，合计 %d 条，末条 %s %.2f，已写入：%s\n",
                $seriesId, $merged['added'], $merged['updated'], count($merged['rows']),
                $last['date'], $last['level'], $file);
        } catch (Throwable $e) {
            fwrite(STDERR, "[{$seriesId}] 出错：" . $e->getMessage() . "\n");
            $failCount++;
        }
    }

    exit($failCount > 0 ? 1 : 0);
}

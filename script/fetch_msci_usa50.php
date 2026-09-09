<?php
/**
 * MSCI USA 50 Index 历史数据抓取脚本
 *
 * 数据源：MSCI 官方 End-of-Day 指数接口（公开、无需鉴权）
 *   https://app2.msci.com/products/service/index/indexmaster/getLevelDataForGraph
 *
 * 指数信息：
 *   名称     MSCI USA 50 Index（MSCI 美国 50 指数）
 *   指数代码  750108
 *   起始日期  2000-06-01（接口可回溯的最早日期）
 *
 * 指数变体（index_variant）：
 *   STRD  价格指数（Price / Standard，不含分红）
 *   NETR  净总收益指数（Net Total Return，扣税后再投资）
 *   GRTR  总收益指数（Gross Total Return，不扣税再投资）
 *
 * 增量更新：
 *   输出文件已存在时默认走增量——读出本地最后一个日期，只抓「最后日期 - overlap 天」到今天，
 *   再按日期合并写回（重叠区间以新数据为准，用于吸收 MSCI 对近几日点位的修订）。
 *   适合挂 crontab 每日跑一次；想推翻重来时加 --full。
 *
 * 用法：
 *   php fetch_msci_usa50.php                                  # 首次全量，之后每次运行都是增量
 *   php fetch_msci_usa50.php --full                           # 忽略本地文件，全量重抓
 *   php fetch_msci_usa50.php --start=2020-01-01 --end=2024-12-31
 *   php fetch_msci_usa50.php --variant=NETR --freq=END_OF_MONTH
 *   php fetch_msci_usa50.php --currency=USD --out=data.csv --format=json
 *
 * 参数：
 *   --start     起始日期 YYYY-MM-DD，默认 2000-06-01；显式指定时不走增量推算
 *   --end       结束日期 YYYY-MM-DD，默认今天
 *   --variant   STRD | NETR | GRTR，默认 GRTR
 *   --currency  货币，如 USD / EUR / LOCAL，默认 USD
 *   --freq      DAILY | END_OF_MONTH | ANNUAL，默认 DAILY（接口仅认这三个值）
 *   --out       输出文件路径，默认 msci_usa_50_{variant}_{freq}.csv
 *   --format    csv | json，默认 csv
 *   --full      强制全量重抓，覆盖已有文件
 *   --overlap   增量时向前回溯的天数，默认 5
 */

class MsciIndexFetcher
{
    /** MSCI EOD 数据接口 */
    private const API_URL = 'https://app2.msci.com/products/service/index/indexmaster/getLevelDataForGraph';

    /** @var string MSCI 指数代码 */
    private string $indexCode;

    /** @var int 请求超时秒数 */
    private int $timeout;

    /** @var int 失败重试次数 */
    private int $retries;

    public function __construct(string $indexCode = '750108', int $timeout = 60, int $retries = 3)
    {
        $this->indexCode = $indexCode;
        $this->timeout   = $timeout;
        $this->retries   = $retries;
    }

    /**
     * 抓取指定区间的指数点位
     *
     * @return array<int, array{date: string, level: float}> 按日期升序
     */
    public function fetch(string $startDate, string $endDate, string $variant = 'GRTR', string $currency = 'USD', string $frequency = 'DAILY'): array
    {
        $query = http_build_query([
            'currency_symbol' => $currency,
            'index_variant'   => $variant,
            'start_date'      => str_replace('-', '', $startDate),  // 接口要求 YYYYMMDD
            'end_date'        => str_replace('-', '', $endDate),
            'data_frequency'  => $frequency,
            'baseValue'       => 'false',
            'index_codes'     => $this->indexCode,
        ]);

        $raw     = $this->httpGet(self::API_URL . '?' . $query);
        $payload = json_decode($raw, true);

        // 参数非法时接口返回 error_message 而非数据
        if (is_array($payload) && isset($payload['error_message'])) {
            throw new RuntimeException('接口报错：' . trim($payload['error_message']));
        }
        if (!is_array($payload) || !isset($payload['indexes']['INDEX_LEVELS'])) {
            throw new RuntimeException('接口返回格式异常：' . substr($raw, 0, 200));
        }

        $rows = [];
        foreach ($payload['indexes']['INDEX_LEVELS'] as $item) {
            // calc_date 形如 20240102（int），level_eod 为收盘点位
            $d = (string) $item['calc_date'];
            $rows[] = [
                'date'  => substr($d, 0, 4) . '-' . substr($d, 4, 2) . '-' . substr($d, 6, 2),
                'level' => (float) $item['level_eod'],
            ];
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
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; msci-eod-fetcher/1.0)',
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

        throw new RuntimeException("请求 MSCI 接口失败：{$lastError}");
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

    public function writeJson(array $rows, string $path): void
    {
        $json = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (file_put_contents($path, $json) === false) {
            throw new RuntimeException("无法写入文件：{$path}");
        }
    }

    /**
     * 读取已抓取的历史文件（csv / json），文件不存在时返回空数组
     *
     * @return array<int, array{date: string, level: float}> 按日期升序
     */
    public function readExisting(string $path, string $format): array
    {
        if (!is_file($path)) {
            return [];
        }

        $rows = $format === 'json' ? $this->readJson($path) : $this->readCsv($path);
        usort($rows, fn(array $a, array $b): int => strcmp($a['date'], $b['date']));

        return $rows;
    }

    private function readCsv(string $path): array
    {
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

        return $rows;
    }

    private function readJson(string $path): array
    {
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            throw new RuntimeException("已有 JSON 文件格式异常：{$path}");
        }

        $rows = [];
        foreach ($data as $item) {
            if (isset($item['date'], $item['level'])) {
                $rows[] = ['date' => (string) $item['date'], 'level' => (float) $item['level']];
            }
        }

        return $rows;
    }

    /**
     * 按日期合并新旧数据，新数据覆盖旧数据
     * （MSCI 会对最近几个交易日的点位做修订，所以重叠区间以新抓到的为准）
     *
     * @return array{rows: array<int, array{date: string, level: float}>, added: int, updated: int}
     */
    public static function merge(array $old, array $new): array
    {
        $map = [];
        foreach ($old as $r) {
            $map[$r['date']] = $r['level'];
        }

        $added = 0;
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
    $opts = getopt('', ['start::', 'end::', 'variant::', 'currency::', 'freq::', 'out::', 'format::', 'full', 'overlap::', 'help']);

    if (isset($opts['help'])) {
        echo "用法：php fetch_msci_usa50.php [--start=2000-06-01] [--end=2026-08-28] [--variant=STRD|NETR|GRTR] [--currency=USD] [--freq=DAILY|END_OF_MONTH|ANNUAL] [--out=file] [--format=csv|json] [--full] [--overlap=5]\n";
        echo "默认增量更新：输出文件已存在时，只从「已有数据最后一天 - overlap 天」开始抓，再按日期合并写回。\n";
        exit(0);
    }

    $start    = $opts['start']    ?? '2000-06-01';
    $end      = $opts['end']      ?? date('Y-m-d');
    $variant  = strtoupper($opts['variant']  ?? 'GRTR');
    $currency = strtoupper($opts['currency'] ?? 'USD');
    $freq     = strtoupper($opts['freq']     ?? 'DAILY');
    $format   = strtolower($opts['format']   ?? 'csv');
    $out      = $opts['out'] ?? __DIR__ . "/msci_usa_50_{$variant}_{$freq}." . $format;
    $full     = isset($opts['full']);                        // 强制全量重抓
    $overlap  = max(0, (int) ($opts['overlap'] ?? 5));       // 增量时向前回溯的天数

    if (!in_array($variant, ['STRD', 'NETR', 'GRTR'], true)) {
        fwrite(STDERR, "variant 只支持 STRD / NETR / GRTR\n");
        exit(1);
    }
    if (!in_array($freq, ['DAILY', 'END_OF_MONTH', 'ANNUAL'], true)) {
        fwrite(STDERR, "freq 只支持 DAILY / END_OF_MONTH / ANNUAL\n");
        exit(1);
    }

    try {
        $fetcher = new MsciIndexFetcher();

        // 已有数据（--full 时忽略，等于全量重抓）
        $existing = $full ? [] : $fetcher->readExisting($out, $format);

        if ($existing !== [] && !isset($opts['start'])) {
            // 增量：从最后一天往前回溯 overlap 天重抓，用于覆盖 MSCI 对近几日点位的修订
            $lastDate = $existing[count($existing) - 1]['date'];
            $start    = date('Y-m-d', strtotime("{$lastDate} -{$overlap} day"));
            echo "已有 " . count($existing) . " 条数据（截至 {$lastDate}），增量更新 ...\n";
        }

        if ($start > $end) {
            echo "本地数据已是最新（{$end} 之前无需抓取）\n";
            exit(0);
        }

        echo "正在抓取 MSCI USA 50 Index（代码 750108，{$variant}/{$currency}/{$freq}）{$start} ~ {$end} ...\n";
        $fresh = $fetcher->fetch($start, $end, $variant, $currency, $freq);

        if ($fresh === [] && $existing === []) {
            fwrite(STDERR, "未取到数据，请检查日期区间与参数\n");
            exit(1);
        }

        $merged = MsciIndexFetcher::merge($existing, $fresh);
        $rows   = $merged['rows'];

        $format === 'json' ? $fetcher->writeJson($rows, $out) : $fetcher->writeCsv($rows, $out);

        $first = $rows[0];
        $last  = $rows[count($rows) - 1];
        $total = ($last['level'] / $first['level'] - 1) * 100;

        echo "新增 {$merged['added']} 条，修订 {$merged['updated']} 条，合计 " . count($rows) . " 条，已写入：{$out}\n";
        printf("首条  %s  %.4f\n", $first['date'], $first['level']);
        printf("末条  %s  %.4f\n", $last['date'], $last['level']);
        printf("区间累计涨幅  %.2f%%\n", $total);
    } catch (Throwable $e) {
        fwrite(STDERR, '出错：' . $e->getMessage() . "\n");
        exit(1);
    }
}

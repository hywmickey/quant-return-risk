<?php
/**
 * 天天基金历史净值抓取脚本（doc/get_fund_data.py 的 PHP 版本）
 *
 * 数据源：天天基金 F10 净值 JSON 接口（公开，但必须带 Referer，否则被拦）
 *   https://api.fund.eastmoney.com/f10/lsjz?fundCode={基金代码}&pageIndex=1&pageSize=20
 *   返回 {"Data":{"LSJZList":[{"FSRQ":净值日期,"DWJZ":单位净值,"LJJZ":累计净值,"JZZZL":日增长率}]},
 *        "ErrCode":0,"TotalCount":总条数,"PageSize":实际每页条数}
 *   注意：pageSize 服务端强制为 20，传更大值无效，所以总页数按 TotalCount/实际 PageSize 算。
 *
 * 写盘方式：每抓完一页立刻追加落盘并 flush，不在内存里攒全量数据，
 *   中途失败/被打断时已抓到的部分不会丢，下次运行接着增量补。
 *
 * CSV 顺序：按净值日期升序（最早在最上、最新在最后），这样每天的新数据天然追加到文件末尾。
 *   因为接口是按日期倒序分页的，全量首抓会从最后一页往第一页抓，保证「一页一写」即全局有序。
 *
 * 增量更新：文件已存在时读出本地最新日期，只抓比它更新的记录，追平即停，适合挂 crontab 每天跑一次。
 *
 * 用法：
 *   php get_fund_data.php --code=008114                # 首抓全量，之后每次运行都是增量
 *   php get_fund_data.php --code=008114 --out=out.csv  # 指定输出文件
 *   php get_fund_data.php --code=008114 --full         # 忽略已有文件，全量重抓覆盖
 */

/** 请求用的 UA，接口对 UA 有基本校验 */
const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';

/** lsjz 接口要求的 Referer，缺失会被拒 */
const API_REFERER = 'https://fundf10.eastmoney.com/';

/** lsjz 接口地址 */
const API_URL = 'https://api.fund.eastmoney.com/f10/lsjz';

/** 请求的每页条数（服务端实际强制 20，这里只是入参） */
const PAGE_SIZE = 20;

/** 翻页间隔，避免请求过快 */
const PAGE_INTERVAL_US = 200000;

/**
 * HTTP GET，失败返回 null
 *
 * @param array<int, string> $headers 额外请求头
 */
function httpGet(string $url, array $headers = []): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_ENCODING       => 'gzip, deflate',
        CURLOPT_USERAGENT      => USER_AGENT,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);

    return ($body !== false && $status === 200) ? $body : null;
}

/**
 * 获取基金名称（lsjz 接口不返回名称，从 pingzhongdata 的 fS_name 变量取）
 * 取不到时退回基金代码，避免打印空名称
 */
function getFundInfo(string $fundCode): string
{
    $content = httpGet("https://fund.eastmoney.com/pingzhongdata/{$fundCode}.js");

    // 形如 var fS_name = "天弘中证红利低波动100联接A";
    if ($content !== null && preg_match('/var\s+fS_name\s*=\s*"([^"]+)"/', $content, $m) === 1) {
        return trim($m[1]);
    }

    return $fundCode;
}

/**
 * 解析 lsjz 接口返回的净值列表
 *
 * 列名直接沿用接口字段名（FSRQ 净值日期 / DWJZ 单位净值 / LJJZ 累计净值 / JZZZL 日增长率 等），
 * 接口返回的所有字段原样输出，不做重命名与格式加工。
 *
 * @param array<int, array<string, mixed>> $list Data.LSJZList
 * @return array<int, array<string, string>> 与接口一致，按日期倒序
 */
function parseNavList(array $list): array
{
    $rows = [];
    foreach ($list as $item) {
        // 验证日期格式
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($item['FSRQ'] ?? '')) !== 1) {
            continue;
        }

        $row = [];
        foreach ($item as $key => $value) {
            // null（如 SDATE / DTYPE）落盘为空串
            $row[(string) $key] = $value === null ? '' : (string) $value;
        }

        $rows[] = $row;
    }

    return $rows;
}

/**
 * 抓取单页净值
 *
 * @return array{rows: array<int, array<string, string>>, totalCount: int, pageSize: int}|null 失败返回 null
 */
function fetchNavPage(string $fundCode, int $page): ?array
{
    $url = API_URL . '?' . http_build_query([
        'fundCode'  => $fundCode,
        'pageIndex' => $page,
        'pageSize'  => PAGE_SIZE,
        '_'         => (int) round(microtime(true) * 1000),
    ]);

    $content = httpGet($url, ['Referer: ' . API_REFERER, 'Accept: application/json']);
    if ($content === null) {
        echo "第 {$page} 页请求失败\n";
        return null;
    }

    $payload = json_decode($content, true);
    if (!is_array($payload) || (int) ($payload['ErrCode'] ?? -1) !== 0) {
        $errMsg = is_array($payload) ? (string) ($payload['ErrMsg'] ?? '') : substr($content, 0, 120);
        echo "第 {$page} 页接口报错：{$errMsg}\n";
        return null;
    }

    $list = $payload['Data']['LSJZList'] ?? null;

    return [
        'rows'       => is_array($list) ? parseNavList($list) : [],
        'totalCount' => (int) ($payload['TotalCount'] ?? 0),
        // 服务端可能把 pageSize 改小，总页数要按实际值算
        'pageSize'   => max(1, (int) ($payload['PageSize'] ?? PAGE_SIZE)),
    ];
}

/**
 * 读取已有 CSV 的表头、最新日期与记录数，文件不存在时返回空表头
 *
 * @return array{header: array<int, string>, latest: string, count: int}
 */
function readCsvMeta(string $path): array
{
    $meta = ['header' => [], 'latest' => '', 'count' => 0];
    if (!is_file($path)) {
        return $meta;
    }

    $fh = fopen($path, 'r');
    if ($fh === false) {
        return $meta;
    }

    $dateIdx = false;
    while (($cols = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        if ($cols === [null]) {
            continue;                                                   // 空行
        }

        if ($meta['header'] === []) {
            // 首行是表头，第一个字段可能带 BOM
            $meta['header'] = array_map(
                static fn($c): string => ltrim((string) $c, "\xEF\xBB\xBF"),
                $cols
            );
            $dateIdx = array_search('FSRQ', $meta['header'], true);
            continue;
        }

        $date = $dateIdx === false ? '' : (string) ($cols[$dateIdx] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            $meta['count']++;
            if ($date > $meta['latest']) {
                $meta['latest'] = $date;
            }
        }
    }

    fclose($fh);

    return $meta;
}

/**
 * 追加写入一页数据（按表头顺序对齐，页内转成日期升序），写完立即 flush
 *
 * @param resource $fh
 * @param array<int, array<string, string>> $rows 接口顺序（日期倒序）
 * @param array<int, string> $header
 */
function appendPage($fh, array $rows, array $header): int
{
    foreach (array_reverse($rows) as $row) {
        $line = [];
        foreach ($header as $field) {
            $line[] = $row[$field] ?? '';                               // 接口加字段时以本地表头为准
        }
        fputcsv($fh, $line, ',', '"', '\\');
    }

    fflush($fh);                                                        // 每页落盘，中断也不丢

    return count($rows);
}

/**
 * 抓取并写入数据：文件已存在走增量，--full 或首次运行走全量
 *
 * @return array{written: int, latest: string, total: int}
 */
function syncFundData(string $fundCode, string $outFile, bool $full): array
{
    $meta   = $full ? ['header' => [], 'latest' => '', 'count' => 0] : readCsvMeta($outFile);
    $append = $meta['header'] !== [];                                   // 有表头才能追加

    // 第 1 页既用于探测总页数，也是最新的一页数据
    $first = fetchNavPage($fundCode, 1);
    if ($first === null || $first['rows'] === []) {
        throw new RuntimeException('未取到数据，请检查基金代码与网络');
    }

    $totalPages = (int) ceil($first['totalCount'] / $first['pageSize']);
    $header     = $append ? $meta['header'] : array_keys($first['rows'][0]);

    if ($append) {
        echo "已有 {$meta['count']} 条数据（截至 {$meta['latest']}），增量更新 ...\n";
        $pages = collectNewPages($fundCode, $first, $meta['latest'], $totalPages);
    } else {
        echo "全量抓取，共 {$first['totalCount']} 条 / {$totalPages} 页 ...\n";
        $pages = null;                                                  // 全量走边抓边写，不缓存
    }

    if ($pages === []) {
        echo "本地数据已是最新（{$meta['latest']}）\n";
        return ['written' => 0, 'latest' => $meta['latest'], 'total' => $meta['count']];
    }

    $fh = fopen($outFile, $append ? 'a' : 'w');
    if ($fh === false) {
        throw new RuntimeException("无法写入文件：{$outFile}");
    }

    if (!$append) {
        fwrite($fh, "\xEF\xBB\xBF");                                    // BOM，方便 Excel 直接打开
        fputcsv($fh, $header, ',', '"', '\\');
    }

    $written = 0;
    $latest  = $meta['latest'];

    if ($pages === null) {
        // 全量：接口按日期倒序分页，所以从最后一页往第 1 页写，保证文件整体升序
        $broken = false;
        for ($page = $totalPages; $page >= 2; $page--) {
            usleep(PAGE_INTERVAL_US);
            $result = fetchNavPage($fundCode, $page);
            if ($result === null) {
                // 中间页失败就停在这里，不能再写最新的第 1 页，
                // 否则本地最新日期跳过缺口，下次增量会永久漏掉这段数据
                echo "第 {$page} 页抓取失败，已写入的数据保留，重跑会自动补齐\n";
                $broken = true;
                break;
            }

            $written += appendPage($fh, $result['rows'], $header);
            echo "已写入第 {$page}/{$totalPages} 页，累计 {$written} 条\n";
        }

        if (!$broken) {
            $written += appendPage($fh, $first['rows'], $header);        // 最新一页最后写
            echo "已写入第 1/{$totalPages} 页，累计 {$written} 条\n";
            $latest = $first['rows'][0]['FSRQ'];
        } else {
            $latest = readCsvMeta($outFile)['latest'];
        }
    } else {
        // 增量：$pages 是「新→旧」的页列表，倒过来写，追加后仍是升序
        foreach (array_reverse($pages) as $i => $rows) {
            $written += appendPage($fh, $rows, $header);
            echo '已写入第 ' . ($i + 1) . '/' . count($pages) . " 批，累计新增 {$written} 条\n";
        }
        $latest = $first['rows'][0]['FSRQ'];
    }

    fclose($fh);

    return ['written' => $written, 'latest' => $latest, 'total' => $meta['count'] + $written];
}

/**
 * 增量模式下逐页抓取比 $latestDate 更新的记录，追平即停
 *
 * 新数据一般只有一两页，缓存在内存里再倒序写盘，才能保证文件按日期升序。
 *
 * @param array{rows: array<int, array<string, string>>, totalCount: int, pageSize: int} $first 已抓好的第 1 页
 * @return array<int, array<int, array<string, string>>> 按「新→旧」排列的页数据，无新增时为空数组
 */
function collectNewPages(string $fundCode, array $first, string $latestDate, int $totalPages): array
{
    $pages  = [];
    $page   = 1;
    $result = $first;

    while (true) {
        $newRows = array_values(array_filter(
            $result['rows'],
            static fn(array $r): bool => $r['FSRQ'] > $latestDate
        ));

        if ($newRows !== []) {
            $pages[] = $newRows;
        }

        // 本页出现已有日期，说明已追平；或者已经翻到最后一页
        if (count($newRows) < count($result['rows']) || $page >= $totalPages) {
            break;
        }

        $page++;
        usleep(PAGE_INTERVAL_US);
        $result = fetchNavPage($fundCode, $page);
        if ($result === null || $result['rows'] === []) {
            break;
        }

        echo "已探测第 {$page} 页 ...\n";
    }

    return $pages;
}

/**
 * 按字符数（而非字节数）右侧补空格，保证中英文混排时列对齐
 */
function padRight(string $text, int $width): string
{
    $pad = $width - mb_strlen($text, 'UTF-8');

    return $pad > 0 ? $text . str_repeat(' ', $pad) : $text;
}

/**
 * 读 CSV 末尾 N 条数据行（文件按日期升序，末尾即最新）
 *
 * @return array<int, array<string, string>>
 */
function readTailRows(string $path, int $limit): array
{
    $fh = fopen($path, 'r');
    if ($fh === false) {
        return [];
    }

    $header = [];
    $buffer = [];
    while (($cols = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        if ($cols === [null]) {
            continue;
        }

        if ($header === []) {
            $header = array_map(
                static fn($c): string => ltrim((string) $c, "\xEF\xBB\xBF"),
                $cols
            );
            continue;
        }

        if (count($cols) !== count($header)) {
            continue;
        }

        $buffer[] = array_combine($header, $cols);
        if (count($buffer) > $limit) {
            array_shift($buffer);                                       // 只留最后 $limit 条
        }
    }

    fclose($fh);

    return $buffer;
}

/**
 * 打印数据预览表格（只挑关键字段，CSV 里才是全字段）
 */
function printPreview(array $rows): void
{
    echo padRight('日期', 12) . ' ' . padRight('单位净值', 12) . ' '
        . padRight('累计净值', 12) . ' ' . padRight('日涨跌幅', 10) . "\n";
    echo str_repeat('-', 50) . "\n";
    foreach ($rows as $row) {
        echo padRight($row['FSRQ'], 12) . ' ' . padRight($row['DWJZ'], 12) . ' '
            . padRight($row['LJJZ'], 12) . ' ' . padRight($row['JZZZL'], 10) . "\n";
    }
}

// ========== CLI 入口（被 require 时不执行，等价于 Python 的 __main__ 判断）==========
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $opts = getopt('', ['code:', 'out::', 'full', 'help']);

    if ($opts === false || isset($opts['help'])) {
        echo "用法：php get_fund_data.php --code=008114 [--out=file.csv] [--full]\n";
        echo "  --code  基金代码，6 位数字，必传\n";
        echo "  --out   输出 CSV 路径，默认 data/fund/fund_{code}_full_data.csv\n";
        echo "  --full  忽略已有文件，全量重抓覆盖\n";
        echo "默认增量：输出文件已存在时只抓比本地最新日期更新的记录。\n";
        exit($opts === false ? 1 : 0);
    }

    // --code 必传，缺失或格式不对直接退出，避免默默抓错基金
    $fundCode = (string) ($opts['code'] ?? '');
    if (preg_match('/^\d{6}$/', $fundCode) !== 1) {
        fwrite(STDERR, $fundCode === ''
            ? "缺少必传参数 --code，如 --code=008114\n"
            : "基金代码应为 6 位数字，收到：{$fundCode}\n");
        exit(1);
    }

    // 默认输出到 data/fund/fund_{code}_full_data.csv，省去每次手写 --out
    $outputFile = (string) ($opts['out'] ?? '');
    if ($outputFile === '') {
        $outputFile = dirname(__DIR__) . "/data/fund/fund_{$fundCode}_full_data.csv";
    } elseif (!str_contains($outputFile, '/')) {
        $outputFile = dirname(__DIR__) . '/data/fund/' . $outputFile;
    }
    $full       = isset($opts['full']);

    echo "基金 {$fundCode} " . getFundInfo($fundCode) . "\n";

    try {
        $stat = syncFundData($fundCode, $outputFile, $full);
    } catch (Throwable $e) {
        fwrite(STDERR, '出错：' . $e->getMessage() . "\n");
        exit(1);
    }

    echo "\n新增 {$stat['written']} 条，合计 {$stat['total']} 条（最新 {$stat['latest']}），文件：{$outputFile}\n";

    $tail = readTailRows($outputFile, 10);
    if ($tail !== []) {
        echo "\n数据预览（最新 " . count($tail) . " 条）:\n";
        printPreview($tail);
    }
}

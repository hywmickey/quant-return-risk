#!/usr/bin/env python3
"""用 yfinance 抓取美股指数历史收盘点位，输出 return_drawdown_analyze.php 可直接读取的 CSV。

PHP 直连 Yahoo 会被 429 限流（匿名请求没有 cookie+crumb），yfinance 内部维护
持久 cookie，所以这一步用 Python 完成，后续回撤分析仍然走 PHP 脚本。

输出格式与 fetch_msci_usa50.php 一致：UTF-8 BOM + date,level,change_pct

用法:
    python3 fetch_yahoo_indexes.py                  # 抓取全部预置指数（增量）
    python3 fetch_yahoo_indexes.py us30 sp500       # 只抓指定指数
    python3 fetch_yahoo_indexes.py --start=1990-01-01   # 指定起点，覆盖增量推算
    python3 fetch_yahoo_indexes.py --overlap=30     # 增量时多回溯的天数（默认 7）
    python3 fetch_yahoo_indexes.py --full           # 忽略已有文件，全量重写

增量策略: 已有 CSV 存在时，从「最后一条日期 - overlap 天」开始抓，回溯这几天是为了
让 Yahoo 事后修订过的点位能被覆盖掉；已有文件里更早的数据原样保留。
"""

import csv
import os
import sys
from datetime import date, timedelta

import yfinance as yf

# 指数配置：key => (Yahoo 代码, 中文名, 输出文件名)
INDEXES = {
# us30 只作为补丁源：长历史来自 doc/DJA.csv，由 convert_dja.php 用这份数据覆盖 1992 年以后的部分
    'us30':      ('^DJI',  '道琼斯工业平均指数 (US30)', 'us30_yahoo.csv'),
    'sp500':     ('^GSPC', '标普 500 指数',             'sp500_daily.csv'),
    'nasdaq100': ('^NDX',  '纳斯达克 100 指数',         'nasdaq100_daily.csv'),
}

BASE_DIR = os.path.dirname(os.path.abspath(__file__))


def read_existing(path):
    """读取已有 CSV，返回 {date: level}。文件不存在或格式异常时返回空字典。"""
    if not os.path.isfile(path):
        return {}
    rows = {}
    with open(path, 'r', encoding='utf-8-sig', newline='') as fh:
        for row in csv.DictReader(fh):
            if row.get('date') and row.get('level'):
                rows[row['date']] = float(row['level'])
    return rows


def fetch(symbol, start, end):
    """下载日线收盘价，返回 {date: level}。"""
    df = yf.download(symbol, start=start, end=end,
                     interval='1d', auto_adjust=False, progress=False)
    if df is None or df.empty:
        return {}
    close = df['Close']
    if hasattr(close, 'columns'):          # 多列（MultiIndex）时取第一列
        close = close.iloc[:, 0]
    out = {}
    for ts, level in close.items():
        if level is None or level != level:  # 跳过 NaN
            continue
        out[ts.strftime('%Y-%m-%d')] = round(float(level), 2)
    return out


def write_csv(path, merged):
    """按日期升序写出，change_pct 为相对前一交易日的涨跌幅（%，4 位小数）。"""
    with open(path, 'w', encoding='utf-8', newline='') as fh:
        fh.write('\ufeff')
        writer = csv.writer(fh)
        writer.writerow(['date', 'level', 'change_pct'])
        prev = None
        for d in sorted(merged):
            level = merged[d]
            pct = '' if prev in (None, 0) else round((level / prev - 1) * 100, 4)
            writer.writerow([d, level, pct])
            prev = level


def main():
    args = [a for a in sys.argv[1:] if not a.startswith('--')]
    flags = {a[2:] for a in sys.argv[1:] if a.startswith('--') and '=' not in a}
    opts = dict(a[2:].split('=', 1) for a in sys.argv[1:] if a.startswith('--') and '=' in a)

    start_opt = opts.get('start')
    end = opts.get('end', (date.today() + timedelta(days=1)).strftime('%Y-%m-%d'))
    overlap = int(opts.get('overlap', 7))
    keys = args or list(INDEXES)
    full = 'full' in flags

    failed = []
    for key in keys:
        if key not in INDEXES:
            print(f'未知指数: {key}，可选 {", ".join(INDEXES)}')
            failed.append(key)
            continue

        symbol, name, filename = INDEXES[key]
        path = os.path.join(BASE_DIR, filename)
        existing = {} if full else read_existing(path)
        # 增量：从已有数据的最后一天往前回溯 overlap 天开始抓；没有已有数据或指定了
        # --start / --full 时按全量起点抓
        if start_opt:
            start = start_opt
        elif existing:
            last = date.fromisoformat(max(existing))
            start = (last - timedelta(days=overlap)).strftime('%Y-%m-%d')
        else:
            start = '1900-01-01'
        mode = '全量' if not existing else f'增量（自 {start}）'
        print(f'抓取 {name} [{symbol}] {mode} ...', flush=True)

        try:
            fresh = fetch(symbol, start, end)
        except Exception as exc:                      # 网络/接口异常不影响其他指数
            print(f'  失败: {exc}')
            failed.append(key)
            continue

        if not fresh:
            print('  失败: 未返回任何数据')
            failed.append(key)
            continue

        added   = [d for d in fresh if d not in existing]
        changed = [d for d in fresh if d in existing and existing[d] != fresh[d]]
        merged = {**existing, **fresh}                # 新数据覆盖旧数据
        write_csv(path, merged)
        days = sorted(merged)
        print(f'  共 {len(days)} 条（新增 {len(added)} 条，修正 {len(changed)} 条）'
              f'  {days[0]} -> {days[-1]}  最新 {merged[days[-1]]}')
        print(f'  已写入 {path}')

    return 1 if failed else 0


if __name__ == '__main__':
    sys.exit(main())

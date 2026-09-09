### 概述

yahoo美股指数数据的获取脚本，以及道琼斯历史数据的纠正。

fetch_yahoo_indexes.py 里 us30 的输出文件改成 us30_yahoo.csv——它现在只当补丁源，不再直接产出 us30_daily.csv。这同时消除了我上次提醒的覆盖风险：us30_daily.csv 现在只由 convert_dja.php 产出。

convert_dja.php 增加 --patch（默认 us30_yahoo.csv）和 --no-patch。流程是先读 DJA 建底，再用补丁文件按日期覆盖/补入，最后统一排序、重算 change_pct。

```
-- DJA 有约 18 处疑似数字错位的笔误，Yahoo 的值更可信。
2002-09-25   DJA 7481.82  vs Yahoo 7841.82   （前后日 7683.13 → 7997.12，Yahoo 更连贯）
2008-10-24   DJA 8278.95  vs Yahoo 8378.95   （8691.25 跌 312.30 点 = 8378.95，公认数值）
1999-01-26   DJA 9234.58  vs Yahoo 9324.58

-- DJA 缺 5 个近期交易日：2026-06-01、06-05、06-11、06-22、07-27（都是工作日、非美股假日），Yahoo 有。

$ python3 fetch_yahoo_indexes.py us30 --full    # 刷新补丁源 us30_yahoo.csv
```







### 文件说明

| 文件名                 | 说明                                                         |
| ---------------------- | ------------------------------------------------------------ |
| DJA.csv                | measuringworth 的道琼斯日收盘数据，最早的 DJA 数据源，可能存在错误 |
| fetch_yahoo_indexes.py | 抓取美股指数数据，依赖Python的虚拟环境。                     |
| us30_yahoo.csv         | fetch_yahoo_indexes.py 抓取的道琼斯数据                      |
| convert_dja.php        | 参考 us30_yahoo.csv 中的数据，对 DJA.csv 数据进行修正，最后生成 us30_daily.csv 供数据分析。 |
| us30_daily.csv         | convert_dja.php生成的纠正过得的道琼斯数据。                  |
| sp500_daily.csv        | fetch_yahoo_indexes.py 抓取的标普500数据                     |
| nasdaq100_daily.csv    | fetch_yahoo_indexes.py 抓取的纳指100数据                     |

### 运行方式

```bash
# 安装环境 Python 虚拟环境
$ python3 -m venv ~/.venvs/yf             # 别放 /tmp，会被清
$ ~/.venvs/yf/bin/pip install -U pip
$ ~/.venvs/yf/bin/pip install yfinance

# 使用虚拟环境中的Python，获取美股指数数据
$ ~/.venvs/yf/bin/python fetch_yahoo_indexes.py us30


# 生成 us30_yahoo.csv
$ php convert_dja.php  # DJA + 补丁 -> us30_daily.csv
# 生成分析报告
$ php msci_drawdown_analyze.php --in=us30_daily.csv --title="道琼斯工业平均指数 (US30)"
```


# Quant Return Risk

**基金、股票、指数的收益、风险与回撤量化分析工具**

本项目专注于对基金、股票和市场指数进行专业的量化分析，主要功能包括：

- 收益率分析
- 风险指标计算（波动率、夏普比率等）
- 最大回撤及相关指标
- 支持基金、股票、指数数据
- 投资组合数据分析（规划中）

适用于量化研究者、投资者和开发者，帮助更清晰地评估金融产品的风险与收益表现。

## 配置文件

项目根目录的 `config.php` 统一维护所有运行时配置，经 `script/config_loader.php` 解析后暴露给各脚本使用。

### 数据目录（`dirs`）

| 配置键 | 默认目录 | 用途 |
|---|---|---|
| `daily` | `data/daily` | 指数/基金标准日线 CSV 的读取与输出 |
| `drawdown` | `data/drawdown` | 回撤分析 CSV 输出 |
| `fund` | `data/fund` | 基金原始净值 CSV |
| `html` | `html` | 回撤分析 HTML 报告输出 |

目录可写相对路径（相对项目根目录解析）或绝对路径；命令行显式传入的 `--in` / `--out` 等参数优先级高于该配置。

### 数据源标题映射（`titles`）

`update_all.php` 回撤分析时需要为每个数据源传入 `--title` 参数，对应关系集中维护在 `titles` 下：

| 配置键 | 内容 | 说明 |
|---|---|---|
| `titles.funds` | `基金代码 => 中文标题` | 数组顺序即批量抓取顺序 |
| `titles.indexes` | `CSV 文件名 => 中文标题` | 文件名需与 `data/daily/` 下的文件完全一致 |

新增数据源时在对应数组追加一行即可，无需改动任何脚本。

## 数据分析

### 一键更新全部数据（推荐）

`script/update_all.php` 整合了 README.md 中描述的所有数据更新步骤，一条命令完成从抓取到报告生成的全流程：

```bash
# 更新全部（指数抓取 → 基金抓取 → 基金转换 → 回撤分析 → 索引页）
$ php script/update_all.php

# 常用变体
$ php script/update_all.php --only-analyze          # 只跑回撤分析 + 索引页，不抓新数据
$ php script/update_all.php --skip-funds             # 跳过基金，只更新指数
$ php script/update_all.php --funds=008114,050025    # 只更新指定基金
$ php script/update_all.php --skip-index-html        # 只跳过 index.html，回撤分析照常
$ php script/update_all.php --full                   # 所有抓取脚本走全量重抓
$ php script/update_all.php --dry-run                # 预览将执行什么命令（不真跑）
$ php script/update_all.php --verbose                # 显示子脚本的完整输出
```

执行流程（5 步，按顺序串行）：

| 步骤 | 内容 | 涉及脚本 |
|---|---|---|
| 1/5 | 指数增量更新 | `fetch_us_indexes.php`（美股三大指数 + NDXTMC/NDXTMCTR）、`fetch_yahoo_index.php`（NYSE FANG+）、`fetch_msci_usa50.php`（MSCI USA 50） |
| 2/5 | 基金原始净值抓取 | `get_fund_data.php`（循环处理所有已配置基金） |
| 3/5 | 基金数据转换为日线格式 | `convert_fund.php` |
| 4/5 | 回撤分析报告生成 | `return_drawdown_analyze.php`（自动扫描 `data/daily/*_daily.csv`） |
| 5/5 | 重生成 `html/index.html` 索引页 | 内置逻辑（自动扫描 `html/*_drawdown_top.html`，按「指数/基金」分组） |

基金代码与指数文件名到中文标题的映射，集中维护在项目根目录 `config.php` 的 `titles.funds` / `titles.indexes` 下；新增数据源时在对应数组追加一行即可，无需改动任何脚本。

以下是各子脚本的手动使用说明（供需要单步调试或精细控制时参考）。

### 美股指数更新

#### 初始数据的获取

可以将`backup/yahoo_indexes/*daily.csv` 拷贝到 `data/daily/`目录下。

#### 数据的增量更新

```bash
$ php script/fetch_us_indexes.php # 增量更新美股三大指数

# 更新美股三大指数数据分析（--in 只写文件名即可，输出路径默认落到 data/drawdown/ 与 html/）

$ php script/return_drawdown_analyze.php --in=us30_daily.csv --title="道琼斯指数"

$ php script/return_drawdown_analyze.php --in=sp500_daily.csv --title="标准普尔500指数"

$ php script/return_drawdown_analyze.php --in=nasdaq100_daily.csv --title="纳斯达克100指数"

# NYSE FANG+ 指数（Yahoo 代码 ^NYFANG，2014-09-22 起有数据；需 pip install --user yfinance）
$ php script/fetch_yahoo_index.php                              # 增量更新，默认输出 data/daily/nyfang_daily.csv
$ php script/return_drawdown_analyze.php --in=nyfang_daily.csv --title="NYSE FANG+ 指数"
```



### 国内基金数据更新

```bash
# 更新数据抓取（默认输出 data/fund/fund_{code}_full_data.csv）
$ php script/get_fund_data.php --code=008114
# 更新每日收益变化数据（--in 只写文件名即按 data/fund/ 查找，默认输出 data/daily/fund_{code}_daily.csv）
$ php script/convert_fund.php --in=fund_008114_full_data.csv
# 更新回撤报告
$ php script/return_drawdown_analyze.php --in=fund_008114_daily.csv --title="天弘中证红利低波动100联接A(008114)"
```

#### MSCI US50 指数更新

```bash
# 更新数据抓取（默认输出 data/daily/msci_usa_50_GRTR_DAILY.csv）
$ php script/fetch_msci_usa50.php
# 更新回撤报告
$ php script/return_drawdown_analyze.php --in=msci_usa_50_GRTR_DAILY.csv --title="MSCI美国50指数"
```


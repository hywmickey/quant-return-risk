# Quant Return Risk

**基金、股票、指数的收益、风险与回撤量化分析工具**

本项目专注于对基金、股票和市场指数进行专业的量化分析，主要功能包括：

- 收益率分析
- 风险指标计算（波动率、夏普比率等）
- 最大回撤及相关指标
- 支持基金、股票、指数数据
- 投资组合回测（多基金组合、权重配置、多种再平衡策略）

适用于量化研究者、投资者和开发者，帮助更清晰地评估金融产品的风险与收益表现。

## 配置文件

项目根目录的 `config.ini` 统一维护所有运行时配置，经 `script/config_loader.php` 以 `INI_SCANNER_RAW` 模式解析后暴露给各脚本使用。

### 数据目录（`dirs`）

| 配置键 | 默认目录 | 用途 |
|---|---|---|
| `daily` | `data/daily` | 指数/基金标准日线 CSV 的读取与输出 |
| `drawdown` | `data/drawdown` | 回撤分析 CSV 输出 |
| `fund` | `data/fund` | 基金原始净值 CSV |
| `html` | `html` | 回撤分析 HTML 报告输出 |
| `portfolio` | `data/portfolio` | 投资组合回测净值 CSV 输出 |

目录可写相对路径（相对项目根目录解析）或绝对路径；命令行显式传入的 `--in` / `--out` 等参数优先级高于该配置。

### 数据源标题映射

`update_all.php` 回撤分析时需要为每个数据源传入 `--title` 参数，对应关系集中维护在：

| INI section | 内容 | 说明 |
|---|---|---|
| `[titles_funds]` | `基金代码 = 中文标题` | section 内从上到下的顺序即批量抓取顺序 |
| `[titles_indexes]` | `CSV 文件名 = 中文标题` | 文件名（含 `.csv` 后缀）需与 `data/daily/` 下的文件完全一致 |

新增数据源时在对应 section 追加一行即可，无需改动任何脚本。

### 投资组合定义（`portfolio_*`）

`config.ini` 中每个以 `portfolio_` 开头的 section 即一个投资组合回测任务，包含 `name` / `funds` / `rebalance` 等键，详见下文[投资组合回测](#投资组合回测)。

## 数据分析

### 一键更新全部数据（推荐）

`script/update_all.php` 整合了 README.md 中描述的所有数据更新步骤，一条命令完成从抓取到报告生成的全流程：

```bash
# 更新全部（指数抓取 → 基金抓取 → 基金转换 → 投资组合回测 → 回撤分析 → 索引页）
$ php script/update_all.php

# 常用变体
$ php script/update_all.php --only-analyze          # 只跑回撤分析 + 索引页，不抓新数据、不回测组合
$ php script/update_all.php --only-drawdown         # 只跑回撤分析，不抓新数据、不回测组合、不生成 index.html
$ php script/update_all.php --skip-funds             # 跳过基金，只更新指数
$ php script/update_all.php --skip-portfolio         # 跳过投资组合回测与组合报告
$ php script/update_all.php --funds=008114,050025    # 只更新指定基金
$ php script/update_all.php --skip-index-html        # 只跳过 index.html，回撤分析照常
$ php script/update_all.php --full                   # 所有抓取脚本走全量重抓
$ php script/update_all.php --dry-run                # 预览将执行什么命令（不真跑）
$ php script/update_all.php --verbose                # 显示子脚本的完整输出
```

执行流程（6 步，按顺序串行）：

| 步骤 | 内容 | 涉及脚本 |
|---|---|---|
| 1/6 | 指数增量更新 | `fetch_us_indexes.php`（美股三大指数 + NDXTMC/NDXTMCTR）、`fetch_yahoo_index.php`（NYSE FANG+）、`fetch_msci_usa50.php`（MSCI USA 50） |
| 2/6 | 基金原始净值抓取 | `get_fund_data.php`（循环处理所有已配置基金） |
| 3/6 | 基金数据转换为日线格式 | `convert_fund.php` |
| 4/6 | 投资组合回测 | `portfolio_nav.php`（回测 `config.ini` 中全部 `portfolio_*` 组合，输出到 `data/portfolio/`） |
| 5/6 | 回撤分析报告生成 | `return_drawdown_analyze.php`（自动扫描 `data/daily/*_daily.csv` 与 `data/portfolio/*_daily.csv`） |
| 6/6 | 重生成 `html/index.html` 索引页 | `generate_index_html.php`（自动扫描 `html/*_drawdown_top.html`，按「指数/基金」分组） |

基金代码与指数文件名到中文标题的映射，集中维护在项目根目录 `config.ini` 的 `[titles_funds]` / `[titles_indexes]` section 下；新增数据源时在对应 section 追加一行即可，无需改动任何脚本。

以下是各子脚本的手动使用说明（供需要单步调试或精细控制时参考）。

### 回撤分析 HTML 报告

`return_drawdown_analyze.php` 为每个数据源生成一份 HTML 报告（默认输出到 `html/`），主要板块：

| 板块 | 内容 |
|---|---|
| 整体收益 | 数据区间、期初/期末点位、累计收益率、年化收益率 |
| 近若干年年化收益率与年化外推 | 近 5~30 年的年化收益率（历史不足的年份跳过），及按复利机械外推的未来涨幅 |
| 年度涨跌幅 | 每年最后一个交易日相对上一年末的涨跌幅，涨绿跌红着色 |
| 回撤记录 | 回撤幅度前 10% 的记录，按档位着色，支持点击表头排序、点击行高亮 |
| 回撤幅度与回撤天数散点图 | 位于回撤记录表下方，横轴为回撤天数、纵轴为回撤幅度 |
| 关键高低点之间的涨幅与年化 | 回撤最大一档及其间关键节点之间的涨幅、年化与定投收益率 |

散点图中每个点是一条回撤记录，颜色沿用回撤表的【档位】十档配色；鼠标悬停在点上会显示该段回撤的开始日期、结束日期、幅度、天数、修复日期与档位。截至数据末日仍未修复（回撤表【修复日期】为空）的点，用更大的圆点加深红粗边突出显示。

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

### 投资组合回测

`script/portfolio_nav.php` 把 `config.ini` 中定义的多个基金组合模拟成一条「虚拟基金」的日净值序列，格式与 `data/daily/fund_*.csv` 完全一致（`date,level,change_pct`），可直接交给 `return_drawdown_analyze.php` 生成与单只基金版式相同的收益与回撤 HTML 报告。

#### 组合定义

在 `config.ini` 中新增以 `portfolio_` 开头的 section（后缀即组合 key），无需改动任何脚本：

```ini
[portfolio_bond_nasdaq]
name      = 债基+纳指组合（50/50 年度再平衡）
funds     = 003376:50,160213:50
rebalance = period
period    = annual
```

| 配置键 | 说明 |
|---|---|
| `name` | 组合显示名，用作 HTML 页面标题 |
| `funds` | `基金代码:权重`，逗号分隔；至少 2 只，权重之和须等于 100 |
| `rebalance` | 再平衡触发方式，见下表；缺省 `none` |
| `period` | 仅 `rebalance = period` 时生效 |
| `threshold` | 仅 `rebalance = threshold` 时生效，单位百分点（如 `10` 表示 10%） |

| `rebalance` | 触发时机 |
|---|---|
| `none` | 买入后持有，权重自然漂移，不再平衡 |
| `period` | 每周期最后一个交易日（`period` = `monthly` / `quarterly` / `semiannual` / `annual`） |
| `anniversary` | 基准日的每年周年，取周年日当天或之后首个交易日 |
| `threshold` | 任一基金实际权重与目标权重相差达到 `threshold` 个百分点，每日收盘后检查 |

#### 回测规则

1. **基准日**：各基金首个数据日中最晚的一天（成立时间最晚的基金决定组合起点），各基金净值在基准日归一为 1
2. **交易日历**：取各基金日期的并集；某基金当日缺数据时用前一交易日净值填充（QDII 与内地基金节假日不同步）
3. **净值计算**：每日各腿市值按所属基金净值涨跌浮动，组合净值 = 各腿市值之和
4. **再平衡**：触发时把各腿市值重置回「组合净值 × 目标权重」，只改变占比、不改变组合净值

#### 运行

```bash
# 回测 config.ini 中定义的全部组合
$ php script/portfolio_nav.php

# 只回测指定组合（传 section 名的 portfolio_ 后缀）
$ php script/portfolio_nav.php --portfolio=bond_nasdaq

# 生成 HTML 报告：把上面输出的 CSV 直接作为 --in
$ php script/return_drawdown_analyze.php \
    --in=data/portfolio/portfolio_bond_nasdaq_daily.csv \
    --title="债基+纳指组合（50/50 年度再平衡）"
```

产出文件：

| 路径 | 内容 |
|---|---|
| `data/portfolio/portfolio_{key}_daily.csv` | 组合日净值序列 |
| `data/drawdown/portfolio_{key}_daily_drawdown*.csv` | 回撤明细与前 10% 记录 |
| `html/portfolio_{key}_daily_drawdown_top.html` | 整体收益、年度涨跌幅、回撤记录、回撤幅度-回撤天数散点图等完整报告 |

注意：投资组合回测已包含在 `update_all.php` 的一键流程内（步骤 4/6 回测组合，步骤 5/6 生成组合报告），只需在需要跳过时加 `--skip-portfolio`；上面的两条命令仅用于手动单步执行。


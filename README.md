# Quant Return Risk

**基金、股票、指数的收益、风险与回撤量化分析工具**

本项目专注于对基金、股票和市场指数进行专业的量化分析，主要功能包括：

- 收益率分析
- 风险指标计算（波动率、夏普比率等）
- 最大回撤及相关指标
- 支持基金、股票、指数数据
- 投资组合数据分析（规划中）

适用于量化研究者、投资者和开发者，帮助更清晰地评估金融产品的风险与收益表现。

## 数据分析

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


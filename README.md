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

# 更新美股三大指数数据分析

$ php script/msci_drawdown_analyze.php --in=data/daily/us30_daily.csv --out=data/drawdown/us30_daily_drawdown.csv --top-out=data/drawdown/us30_daily_drawdown_top.csv --html-out=html/us30_daily_drawdown_top.html --title="道琼斯指数"

$ php script/msci_drawdown_analyze.php --in=data/daily/sp500_daily.csv --out=data/drawdown/sp500_daily_drawdown.csv --top-out=data/drawdown/sp500_daily_drawdown_top.csv --html-out=html/sp500_daily_drawdown_top.html --title="标准普尔500指数"

$ php script/msci_drawdown_analyze.php --in=data/daily/nasdaq100_daily.csv --out=data/drawdown/nasdaq100_daily_drawdown.csv --top-out=data/drawdown/nasdaq100_daily_drawdown_top.csv --html-out=html/nasdaq100_daily_drawdown_top.html --title="纳斯达克100指数"
```



### 国内基金数据更新

```bash
# 更新数据抓取
$ php script/get_fund_data.php --code=008114 --out=data/fund/fund_008114_full_data.csv
# 更新每日收益变化数据
$ php script/convert_fund.php --in=data/fund/fund_008114_full_data.csv --out=data/daily/fund_008114_daily.csv
# 更新回撤报告
$ php script/msci_drawdown_analyze.php --in=data/daily/fund_008114_daily.csv --out=data/drawdown/fund_008114_daily_drawdown.csv --top-out=data/drawdown/fund_008114_daily_drawdown_top.csv --html-out=html/fund_008114_daily_drawdown_top.html --title="天弘中证红利低波动100联接A(008114)"
```

#### MSCI US50 指数更新

```bash
# 更新数据抓取
$ php script/fetch_msci_usa50.php  --out=data/daily/msci_usa_50_GRTR_DAILY.csv
# 更新回撤报告
$ php script/msci_drawdown_analyze.php --in=data/daily/msci_usa_50_GRTR_DAILY.csv --out=data/drawdown/msci_usa_50_GRTR_DAILY_drawdown.csv --top-out=data/drawdown/msci_usa_50_GRTR_DAILY_drawdown_top.csv --html-out=html/msci_usa_50_GRTR_DAILY_drawdown_top.html --title="MSCI美国50指数"
```


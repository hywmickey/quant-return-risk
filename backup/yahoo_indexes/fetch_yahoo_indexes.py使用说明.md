

脚本本身只用标准库 + `yfinance`，所以迁移就是三件事：Python 环境、装 yfinance、能出网访问 Yahoo。

## 1. 装环境

```bash
# Debian/Ubuntu
sudo apt update && sudo apt install -y python3 python3-venv python3-pip
# RHEL/CentOS/Rocky
sudo dnf install -y python3 python3-pip

mkdir -p ~/quant && cd ~/quant          # 放脚本的目录
python3 -m venv ~/.venvs/yf             # 别放 /tmp，会被清
~/.venvs/yf/bin/pip install -U pip
~/.venvs/yf/bin/pip install yfinance
```

我这边实测可用的组合是 Python 3.13.5 + yfinance 1.7.0，它会连带装 pandas / numpy / requests / curl_cffi / lxml / peewee 等 23 个包。要完全复刻版本可以在本机 `pip freeze > requirements.txt` 带过去 `pip install -r`；不锁版本也基本没问题。

## 2. 传文件

```bash
scp fetch_yahoo_indexes.py user@server:~/quant/
scp sp500_daily.csv nasdaq100_daily.csv us30_yahoo.csv user@server:~/quant/   # 可选
```

CSV 是可选的：脚本用 `BASE_DIR = 脚本所在目录` 定位输出文件，**带过去就走增量**（只抓最近几天），**不带就是全量首抓**（1927 年起，几十秒到一两分钟）。要求该目录对运行用户可写。

## 3. 跑

```bash
cd ~/quant && ~/.venvs/yf/bin/python fetch_yahoo_indexes.py
```

每天自动更新（美股收盘后，UTC 22:30 之后比较稳）：

```bash
crontab -e
30 23 * * 1-6 cd ~/quant && ~/.venvs/yf/bin/python fetch_yahoo_indexes.py >> ~/quant/fetch.log 2>&1
```

cron 里必须用绝对路径的解释器，且要先 `cd`，否则输出文件会落到别处。

## 几个容易踩的点

- **出网**：服务器需要能直连 `query*.finance.yahoo.com` / `fc.yahoo.com`。走代理的话在环境里加 `export HTTPS_PROXY=http://ip:port`（requests / curl_cffi 都认）。国内机房建议先 `curl -I https://query2.finance.yahoo.com` 验一下，Yahoo 对机房 IP 的限流比家宽严。
- **Alpine / musl**：`curl_cffi` 和 `pandas` 没有 musl 预编译 wheel，要装 `gcc musl-dev libffi-dev` 现场编译，比较折腾。用 Debian/Ubuntu 系镜像省事。
- **时区**：默认 `end = 今天+1`，用的是服务器本地时间。服务器在 UTC 而你按北京时间理解日期时，注意可能少一个交易日；不影响正确性，下次跑会补上。
- **us30**：这个脚本产出的是 `us30_yahoo.csv`（只是补丁源，1992 年起）。要完整的 `us30_daily.csv`（1885 年起）还得把 `convert_dja.php`、`doc/DJA.csv` 和 PHP 一起搬过去，跑 `php convert_dja.php`。同理 [`msci_drawdown_analyze.php`](/Users/v_houyongwei/tmp/php/msci_drawdown_analyze.php) 出报告也需要服务器上有 PHP（`sudo apt install php-cli`）。

```memory_extract
{"files":[]}
```

```memory_extract
{"files":[]}
```

ConversationID: 280f192b-405f-4932-8c5e-3b7516f48983
# NeuroTrader Lab

NeuroTrader Lab is an MVP web platform where a trading strategy engine trains on historical market data, produces signals, checks outcomes, records mistakes, and generates a daily report.

The first MVP does not execute real trades. It only runs:

```text
Data -> Strategy -> Backtest -> Result -> Mistake Journal -> Daily Report
```

## Codex Project Memory

Codex agentlar yangi ish boshlaganda avval `PROJECT_CONTEXT.md` faylini o'qishi kerak. Bu fayl README yonida turadi va loyiha arxitekturasi, route'lar, modellar, Python endpointlar, flowlar, test holati va keyingi etaplar bo'yicha qisqa xotira vazifasini bajaradi.

Har muhim o'zgarishdan keyin `PROJECT_CONTEXT.md` ham yangilansin.

## MVP Scope

- Instrument: `XAU/USD`
- Timeframes: `M15`, `H1`
- First strategy components:
  - EMA 50 / EMA 200
  - RSI
  - Fibonacci pullback zone
  - ATR stop-loss
- No live trading
- No AI model in the first pass
- Python service performs strategy and backtest calculations
- Laravel will own dashboard, persistence, queues, and user workflows

## Structure

```text
neurotrader-lab/
+-- backend-laravel/        # Laravel app placeholder for dashboard/API
+-- ai-service-python/      # FastAPI strategy/backtest service
+-- database/               # MVP schema notes and SQL
+-- docs/                   # Product and service contracts
+-- datasets/               # Historical/sample market data
`-- PROJECT_CONTEXT.md      # Codex project memory
```

## Bajarilgan Ishlar

### 2-etap

- Python FastAPI service tayyorlandi.
- `XAUUSD_H1.csv` test dataset qo'shildi.
- EMA 50 / EMA 200 va RSI 14 asosidagi `EMA_RSI_V1` strategiya yozildi.
- Python endpoint qo'shildi:

```http
POST /api/backtest/run
```

- Backtest natijasi quyidagi metrikalar bilan qaytadigan qilindi:
  - total trades
  - wins / losses
  - winrate
  - profit factor
  - max drawdown
  - net profit percent
  - last trades
  - strategy conclusion
- Laravel API controller Python service endpointiga ulanadigan qilindi.
- Laravel web sahifa qo'shildi:

```text
GET  /backtests
POST /backtests/run
```

- `/backtests` formasi Laravel orqali Python service'ga request yuboradi.
- `/backtests/run` natijani `backtests.result` sahifasida ko'rsatadi.
- Dashboard va Backtest Results sahifalari 2-etap metrikalariga moslandi.

### 3-etap

- Mistake Journal uchun trade loss sabablarini klassifikatsiya qilish qo'shildi.
- Python backtester loss trade uchun quyidagi maydonlarni qaytaradigan qilindi:
  - `mistake_type`
  - `reason`
  - `suggestion`
- Python response ichiga `top_mistakes` qo'shildi.
- `make_conclusion` logikasi top mistake asosida kuchaytirildi.
- Laravel bazaga saqlash qo'shildi:
  - `backtest_runs`
  - `trades`
  - `mistakes`
  - `daily_reports`
- Laravel modellari yaratildi:
  - `BacktestRun`
  - `Trade`
  - `Mistake`
  - `DailyReport`
- Backtest natijasi DB transaction ichida saqlanadigan qilindi.
- Mistake Journal sahifasi bazadagi xatolarni ko'rsatadigan qilindi.
- AI Daily Report sahifasi bazadagi daily reportni ko'rsatadigan qilindi.
- Daily Reportlar uchun alohida controller va tarix sahifalari qo'shildi:
  - `GET /daily-reports`
  - `GET /daily-reports/{dailyReport}`
- Dashboardda oxirgi AI Training Report ko'rinadigan qilindi.
- Daily report generator command qo'shildi:

```bash
php artisan trading:daily-report
```

- Scheduler qo'shildi:

```text
trading:daily-report -> dailyAt('23:50')
```

- Eski lokal DB schema bilan to'qnashmasligi uchun reconcile migration qo'shildi:

```text
2026_06_04_000000_add_stage_three_training_fields.php
```

### Tekshiruvlar

- Python service compile tekshiruvi o'tdi:

```bash
python -m compileall app
```

- Laravel migration ishga tushirildi:

```bash
php artisan migrate --force
```

- Laravel testlar o'tdi:

```text
14 passed, 66 assertions
```

### 4-etap

- Strategy Lab uchun 4 ta agent/strategiya qo'shildi:
  - `ema_rsi_v1` -> `EMA_RSI_V1`
  - `macd_trend_v1` -> `MACD_TREND_V1`
  - `fibonacci_v1` -> `FIBONACCI_V1`
  - `breakout_v1` -> `BREAKOUT_V1`
- Python strategy registry qo'shildi:

```text
ai-service-python/app/strategies/registry.py
```

- Yangi strategy fayllari qo'shildi:
  - `macd_trend.py`
  - `fibonacci.py`
  - `breakout.py`
- FastAPI strategy list endpoint qo'shildi:

```http
GET /api/strategies
```

- Barcha agentlarni bir xil datasetda test qiladigan endpoint qo'shildi:

```http
POST /api/backtest/run-all
```

- Backtester endi `payload.strategy` bo'yicha registry orqali kerakli agentni ishga tushiradi.
- Run-all endpoint score hisoblaydi va leaderboardni score bo'yicha qaytaradi.
- Score formulasi winrate, profit, trade count va loss penalty asosida ishlaydi.
- Laravel backtest form va Strategy Lab sahifasida 4 ta strategy tanlash mumkin.
- Laravel `strategy_scores` jadvali va `StrategyScore` modeli qo'shildi.
- `StrategyLabController` qo'shildi:
  - `GET /strategy-lab`
  - `POST /strategy-lab/run-all`
- Strategy Lab sahifasiga `Run All Agents` tugmasi va DB'dan o'qiladigan Agent Leaderboard jadvali qo'shildi.
- Smoke testda 4 ta strategiyaning barchasi `/api/backtest/run` orqali `200` qaytardi.
- Smoke testda `/api/backtest/run-all` endpointi ham `200` qaytardi.

### 5-etap

- Run All Agents endi oddiy leaderboard emas, to'liq Training Session yaratadi.
- Training session uchun DB ustunlari qo'shildi:
  - best agent
  - worst agent
  - agents count
  - total trades
  - average winrate
  - average profit
  - AI conclusion
  - next training plan
  - raw leaderboard
- Model version history qo'shildi:
  - `ModelVersion`
  - `/model-versions`
- Model version statuslari avtomatik yangilanadi:
  - `score >= 75` -> `active`
  - `score < 30` -> `rejected`
  - qolgan holatlar -> `testing`
- Strategy score yozuvlari training sessionga bog'landi:

```text
strategy_scores.training_session_id
```

- `StrategyLabController::runAll` endi:
  - Python `/api/backtest/run-all` endpointini chaqiradi
  - leaderboard bo'sh emasligini tekshiradi
  - `TrainingSession` yaratadi
  - har bir agent natijasini `StrategyScore` sifatida saqlaydi
  - `ModelVersion` yozuvlarini yangilaydi
  - foydalanuvchini `/training-sessions` sahifasiga qaytaradi
- Training session sahifalari qo'shildi:

```text
GET /training-sessions
GET /training-sessions/{trainingSession}
```

- Version history sahifasi qo'shildi:

```text
GET /model-versions
```

- Laravel testlar yangilandi:

```text
14 passed, 66 assertions
```

## Run Python Backtest Service

```bash
cd ai-service-python
python -m venv .venv
.venv\Scripts\activate
pip install -r requirements.txt
uvicorn app.main:app --reload --host 127.0.0.1 --port 9000
```

Open:

```text
http://127.0.0.1:9000/docs
```

If port `8001` is busy, use another port:

```bash
uvicorn app.main:app --reload --host 127.0.0.1 --port 9001
```

## Run Laravel Dashboard

```bash
cd backend-laravel
php artisan serve --host=127.0.0.1 --port=8003
```

Open:

```text
http://127.0.0.1:8003
```

The MVP includes six pages:

- Dashboard
- Market Data
- Strategy Lab
- Backtest Results
- Mistake Journal
- AI Daily Report

## Laravel Backtest Endpoint

```http
POST /api/backtest/run
```

Request:

```json
{
  "symbol": "XAU_USD",
  "timeframe": "H1",
  "strategy": "ema_rsi_v1",
  "from": "2023-01-01",
  "to": "2025-12-31"
}
```

Response:

```json
{
  "strategy": "EMA_RSI_V1",
  "instrument": "XAU/USD",
  "timeframe": "H1",
  "period": "2023-01-01 - 2025-12-31",
  "trades": 248,
  "winrate": 56.4,
  "profit_factor": 1.42,
  "max_drawdown": 8.7,
  "net_profit": 18.5,
  "conclusion": "Trend paytida yaxshi, flat bozorda ko'p xato qiladi."
}
```

## Laravel Backtest Pages

Open the server-rendered backtest form:

```text
http://127.0.0.1:8003/backtests
```

Routes:

```text
GET  /backtests
POST /backtests/run
```

The form sends `symbol`, `timeframe`, `strategy`, `initial_balance`, and `risk_per_trade` to the Python service endpoint configured by `AI_SERVICE_URL`.

## Stage 3: Mistake Journal and Daily Report

Backtest web runs are stored in:

```text
backtest_runs
trades
mistakes
daily_reports
```

Run migrations:

```bash
cd backend-laravel
php artisan migrate
```

Generate the daily AI training report:

```bash
php artisan trading:daily-report
```

Generate for a specific date:

```bash
php artisan trading:daily-report 2026-06-02
```

The scheduler runs it daily at `23:50`.

Server cron example:

```cron
* * * * * cd /var/www/neurotrader-lab/backend-laravel && php artisan schedule:run >> /dev/null 2>&1
```

Daily report pages:

```text
GET /daily-reports
GET /daily-reports/{dailyReport}
```

3-etap yakuniy tekshiruv:

```bash
cd backend-laravel
php artisan migrate
php artisan trading:daily-report
```

Then open:

```text
http://127.0.0.1:8003/backtests
http://127.0.0.1:8003/daily-reports
```

## Database

Laravel is configured for MySQL:

```text
DB_CONNECTION=mysql
DB_DATABASE=neurotrader_lab
DB_USERNAME=root
```

Create the database, then run:

```bash
cd backend-laravel
php artisan migrate
```

## Sample Backtest Request

Use `POST /api/backtest/run` for the simple EMA/RSI H1 MVP:

```json
{
  "symbol": "XAUUSD",
  "timeframe": "H1",
  "strategy": "ema_rsi_v1",
  "initial_balance": 10000,
  "risk_per_trade": 1,
  "dataset_path": "../datasets/XAUUSD_H1.csv"
}
```

The response includes dashboard-ready metrics plus the last 20 trades. The detailed ATR/Fibonacci MVP endpoint remains available at `POST /backtests/run`.

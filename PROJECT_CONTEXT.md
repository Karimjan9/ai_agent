# NeuroTrader Lab Project Context

Bu fayl Codex agent uchun loyiha xotirasi sifatida ishlatiladi.
Maqsad: har safar loyihani boshidan titkilamasdan, asosiy arxitektura,
hozirgi holat, muhim fayllar va kelishuvlarni shu joydan tez tushunish.

Har yangi etap yoki muhim o'zgarishdan keyin shu fayl ham yangilanishi kerak.

## Hozirgi Holat

Loyiha hozir 5-etap holatida.

Qilingan etaplar:

- 2-etap: Python FastAPI backtest service, XAU/USD H1 CSV, EMA/RSI strategy, Laravel-Python API ulanishi.
- 3-etap: Mistake Journal, Daily Report, DB saqlash, `trading:daily-report`.
- 4-etap: Strategy Lab, 4 ta agent, run-all endpoint, leaderboard, `strategy_scores`.
- 5-etap: Training Session, Model Version, best/worst agent, session conclusion, next training plan, active/testing/rejected status.

Oxirgi test holati:

```text
php artisan test -> 14 passed, 66 assertions
```

## Loyiha Tuzilishi

```text
ai_agent/
+-- backend-laravel/       # Laravel dashboard, DB, web/API controllers
+-- ai-service-python/     # FastAPI strategy/backtest service
+-- datasets/              # CSV candle data
+-- docs/                  # Contract/spec hujjatlar
+-- database/              # SQL/schema notes
+-- README.md              # Umumiy README
`-- PROJECT_CONTEXT.md     # Codex project memory
```

## Yuqori Darajadagi Arxitektura

```text
User
 |
Laravel UI
 |
Controllers
 | HTTP
Python FastAPI AI Service
 |
CSV Dataset + Strategy Registry
 |
Backtester
 |
Metrics / Trades / Mistakes / Scores
 | HTTP response
Laravel DB save
 |
Dashboard / Reports / Leaderboard / Sessions / Versions
```

## Laravel Qismi

Laravel papka:

```text
backend-laravel/
```

Asosiy vazifalari:

- Web dashboard
- Backtest form/result
- Strategy Lab leaderboard
- Training sessions
- Model version history
- Mistake journal
- Daily reports
- DB persistence
- Scheduler/console commands

### Laravel Controllers

```text
app/Http/Controllers/BacktestController.php
app/Http/Controllers/Api/BacktestController.php
app/Http/Controllers/StrategyLabController.php
app/Http/Controllers/TrainingSessionController.php
app/Http/Controllers/ModelVersionController.php
app/Http/Controllers/DailyReportController.php
app/Http/Controllers/DashboardController.php
```

Controller rollari:

- `BacktestController`: bitta strategy backtest run qiladi, Python `/api/backtest/run` chaqiradi, natijani DB'ga yozadi.
- `Api\BacktestController`: Laravel API endpoint, Python service bilan bridge.
- `StrategyLabController`: `Run All Agents`, Python `/api/backtest/run-all`, training session, strategy scores, model versions.
- `TrainingSessionController`: training session list/detail.
- `ModelVersionController`: model version history.
- `DailyReportController`: daily report list/detail.
- `DashboardController`: umumiy dashboard va eski sahifalar.

### Laravel Models

```text
app/Models/BacktestRun.php
app/Models/Trade.php
app/Models/Mistake.php
app/Models/DailyReport.php
app/Models/StrategyScore.php
app/Models/TrainingSession.php
app/Models/ModelVersion.php
```

Model rollari:

- `BacktestRun`: bitta backtest session.
- `Trade`: backtest ichidagi trade.
- `Mistake`: loss trade sababi va suggestion.
- `DailyReport`: kunlik AI training report.
- `StrategyScore`: agent/strategy leaderboard natijasi.
- `TrainingSession`: Run All Agents natijasida yaratilgan AI training session.
- `ModelVersion`: strategy version status/history.

### Laravel Routes

Muhim web routes:

```text
GET  /
GET  /backtests
POST /backtests/run

GET  /strategy-lab
POST /strategy-lab/run-all

GET  /training-sessions
GET  /training-sessions/{trainingSession}

GET  /model-versions

GET  /daily-reports
GET  /daily-reports/{dailyReport}

GET  /mistake-journal
GET  /ai-daily-report
GET  /backtest-results
GET  /market-data
```

Muhim API route:

```text
POST /api/backtest/run
```

### Laravel Commands

```text
php artisan trading:daily-report
```

Scheduler:

```text
routes/console.php
Schedule::command('trading:daily-report')->dailyAt('23:50');
```

### Laravel DB Jadvallar

Muhim jadvallar:

```text
backtest_runs
trades
mistakes
daily_reports
strategy_scores
training_sessions
model_versions
symbols
candles
strategies
```

`strategy_scores.training_session_id` training session bilan bog'laydi.

`model_versions.status` qiymatlari:

```text
testing
active
rejected
archived
```

Status qoidasi:

```text
score >= 75 -> active
score < 30  -> rejected
otherwise   -> testing
```

## Python AI Service

Python papka:

```text
ai-service-python/
```

Ishga tushirish:

```bash
cd ai-service-python
uvicorn app.main:app --reload --host 127.0.0.1 --port 9000
```

### Python Endpoints

```text
GET  /
GET  /health
GET  /api/strategies
POST /api/backtest/run
POST /api/backtest/run-all
POST /backtests/run
```

Endpoint rollari:

- `/api/strategies`: mavjud agent strategiyalar ro'yxati.
- `/api/backtest/run`: bitta strategy backtest.
- `/api/backtest/run-all`: barcha agentlarni bir xil data bilan test qiladi, leaderboard qaytaradi.
- `/backtests/run`: eski detailed ATR/Fibonacci contract endpoint.

### Python Strategiyalar

Strategy registry:

```text
ai-service-python/app/strategies/registry.py
```

Agentlar:

```text
ema_rsi_v1      -> EMA_RSI_V1      -> EMA RSI Agent
macd_trend_v1   -> MACD_TREND_V1   -> MACD Trend Agent
fibonacci_v1    -> FIBONACCI_V1    -> Fibonacci Pullback Agent
breakout_v1     -> BREAKOUT_V1     -> Breakout Agent
```

Strategy fayllari:

```text
app/strategies/ema_rsi.py
app/strategies/macd_trend.py
app/strategies/fibonacci.py
app/strategies/breakout.py
app/strategies/registry.py
```

### Python Backtester

Asosiy fayl:

```text
ai-service-python/app/services/backtester.py
```

Vazifalari:

- CSV data o'qish
- Strategy registry orqali signal chiqarish
- Trade ochish/yopish
- Win/loss hisoblash
- Winrate, profit factor, drawdown, net profit hisoblash
- Loss trade uchun mistake classification
- Top mistakes chiqarish
- Run-all leaderboard score hisoblash

Score formula hozircha:

```text
winrate contribution
profit contribution
trade count bonus/penalty
loss rate penalty
```

Keyingi versiyada kutilgan formula:

```text
Score = Winrate + Profit Factor - Drawdown Penalty + Stability Bonus
```

## Dataset

Asosiy dataset:

```text
datasets/XAUUSD_H1.csv
```

Format:

```text
time,open,high,low,close,volume
```

## Asosiy Flowlar

### Bitta Backtest Flow

```text
/backtests
 |
BacktestController
 |
POST Python /api/backtest/run
 |
Python backtester
 |
Laravel DB:
  - backtest_runs
  - trades
  - mistakes
 |
backtests.result
```

### Run All Agents Flow

```text
/strategy-lab
 |
Start New Training Session
 |
StrategyLabController::runAll
 |
POST Python /api/backtest/run-all
 |
Python barcha agentlarni test qiladi
 |
Leaderboard qaytadi
 |
Laravel DB:
  - training_sessions
  - strategy_scores
  - model_versions
 |
/training-sessions
```

### Daily Report Flow

```text
php artisan trading:daily-report
 |
BacktestRun + Mistake yozuvlarini o'qiydi
 |
daily_reports yaratadi/yangilaydi
 |
/daily-reports
```

## Muhim Sahifalar

```text
/                  -> Dashboard
/backtests         -> Single backtest form
/strategy-lab      -> Agent leaderboard + Start New Training Session
/training-sessions -> Training session history
/model-versions    -> Version history/status
/daily-reports     -> Daily AI reports
/mistake-journal   -> Mistake journal
```

## Test Buyruqlari

Laravel:

```bash
cd backend-laravel
php artisan test
```

Python:

```bash
cd ai-service-python
python -m compileall app
```

Migration:

```bash
cd backend-laravel
php artisan migrate --force
```

Route check:

```bash
php artisan route:list --path=strategy-lab
php artisan route:list --path=training-sessions
php artisan route:list --path=model-versions
```

## Local Service URLlar

Python:

```text
http://127.0.0.1:9000
```

Laravel:

```text
http://127.0.0.1:8003
```

Agar `8003` band bo'lsa, oldingi ishlarda `8004` ishlatilgan.

## Muhim Config

Laravel:

```text
backend-laravel/config/services.php
AI_SERVICE_URL=http://127.0.0.1:9000
AI_SERVICE_DEFAULT_DATASET=../datasets/XAUUSD_H1.csv
```

Python dependencies:

```text
fastapi
uvicorn
pandas
numpy
ta
pydantic
```

## Codex Uchun Ishlash Qoidasi

Har yangi vazifa boshlanishida avval shu faylni o'qi:

```text
PROJECT_CONTEXT.md
```

Keyin faqat kerakli fayllarni tekshir. Butun loyihani boshidan skan qilish shart emas.

Har muhim o'zgarishdan keyin shu faylni yangila:

- yangi route qo'shilsa
- yangi model yoki migration qo'shilsa
- yangi Python endpoint qo'shilsa
- yangi strategy/agent qo'shilsa
- test natijasi o'zgarsa
- arxitektura flow o'zgarsa
- README'dagi etap holati o'zgarsa

## Keyingi Ehtimoliy Etaplar

Kelajakdagi rivojlanish:

- Professional score formula
- Profit factor + drawdown penalty + stability bonus
- Strategy parameter optimizer
- Agent mutation / v2 generation
- Market regime classifier
- Real historical data import
- Multi-symbol support
- Queue jobs for long training
- Chart UI
- Export reports

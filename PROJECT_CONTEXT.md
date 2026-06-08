# NeuroTrader Lab Project Context

Bu fayl Codex agent uchun loyiha xotirasi sifatida ishlatiladi.
Maqsad: har safar loyihani boshidan titkilamasdan, asosiy arxitektura,
hozirgi holat, muhim fayllar va kelishuvlarni shu joydan tez tushunish.

Har yangi etap yoki muhim o'zgarishdan keyin shu fayl ham yangilanishi kerak.

## Hozirgi Holat

Loyiha hozir 12-etap holatida.

Qilingan etaplar:

- 2-etap: Python FastAPI backtest service, XAU/USD H1 CSV, EMA/RSI strategy, Laravel-Python API ulanishi.
- 3-etap: Mistake Journal, Daily Report, DB saqlash, `trading:daily-report`.
- 4-etap: Strategy Lab, 4 ta agent, run-all endpoint, leaderboard, `strategy_scores`.
- 5-etap: Training Session, Model Version, best/worst agent, session conclusion, next training plan, active/testing/rejected status.
- 6-etap: Agent Evolution Engine, evolution proposals, approve/apply/reject flow, v1 -> v2 model version yaratish.
- 7-etap: Dynamic Strategy Engine, model version parametrlarini Python'ga yuborish, v2/v3 strategiyalarni base strategy + parameters orqali backtest qilish.
- 8-etap: Risk Metrics Engine, professional score, equity curve, stability score, risk-aware leaderboard.
- 9-etap: Equity Curve + Charts Dashboard, Chart.js orqali session, strategy lab va model version grafiklari.
- 10-etap: Market Regime Detection, trade va agent natijalarini trend/range/volatility sharoitlari bo'yicha tahlil qilish.
- 11-etap: Auto Training Scheduler, daily workflow command, training logs va avtomatik AI training jarayoni.
- 12-etap: Data Update Engine, CSV market data provider, candles DB pipeline va Python'ga DB candle payload yuborish.

Oxirgi test holati:

```text
php artisan test -> 27 passed, 154 assertions
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

11-etap Auto Training Scheduler deployment/manual run hujjati:

```text
docs/AUTO_TRAINING_SCHEDULER.md
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
app/Http/Controllers/TrainingLogController.php
app/Http/Controllers/ModelVersionController.php
app/Http/Controllers/EvolutionProposalController.php
app/Http/Controllers/DailyReportController.php
app/Http/Controllers/DashboardController.php
app/Http/Controllers/MarketDataController.php
```

Controller rollari:

- `BacktestController`: bitta strategy backtest run qiladi, Python `/api/backtest/run` chaqiradi, natijani DB'ga yozadi.
- `Api\BacktestController`: Laravel API endpoint, Python service bilan bridge.
- `StrategyLabController`: `Run All Agents`, model version parametrlarini Python `/api/backtest/run-all`ga yuboradi, training session, strategy scores, model versions.
- `TrainingSessionController`: training session list/detail.
- `TrainingLogController`: auto training/daily workflow loglari list/detail.
- `ModelVersionController`: model version history.
- `EvolutionProposalController`: proposal list/detail, approve/apply/reject, yangi model version yaratish.
- `DailyReportController`: daily report list/detail.
- `DashboardController`: umumiy dashboard va eski sahifalar.
- `MarketDataController`: active symbol candle status sahifasi va `market-data:update` web action.

### Frontend Charts

Chart kutubxonasi:

```text
Chart.js CDN
```

Layout:

```text
resources/views/layouts/app.blade.php
```

Chart ishlatiladigan sahifalar:

```text
resources/views/training-sessions/show.blade.php
resources/views/strategy-lab/index.blade.php
resources/views/model-versions/index.blade.php
```

### Laravel Services

```text
app/Services/AgentEvolutionService.php
app/Services/MarketData/MarketDataProviderInterface.php
app/Services/MarketData/CsvMarketDataProvider.php
app/Services/MarketData/MarketDataService.php
app/Services/MarketData/CandlePayloadService.php
```

Service rollari:

- training session ichidan eng zaif agentni oladi
- `worst_score < 50` bo'lsa evolution proposal yaratadi
- strategyga qarab yangi parametrlar taklif qiladi
- `breakout_v1`, `fibonacci_v1`, `ema_rsi_v1`, `macd_trend_v1` uchun alohida evolution plan bor
- 10-etapdan keyin eng yomon agentning `regime_performance` ma'lumotidan eng zararli market regime topiladi va proposal ichiga `avoid_regime` parametri qo'shiladi

Market data service rollari:

- `CsvMarketDataProvider`: `storage/app/market-data/{SYMBOL}_{TIMEFRAME}.csv` faylidan candle o'qiydi
- `MarketDataService`: provider candlelarini `candles` jadvaliga `updateOrCreate` bilan saqlaydi
- `CandlePayloadService`: DB candlelarini Python `/api/backtest/run*` payloadiga mos arrayga aylantiradi

### Laravel Models

```text
app/Models/BacktestRun.php
app/Models/Trade.php
app/Models/Mistake.php
app/Models/DailyReport.php
app/Models/StrategyScore.php
app/Models/TrainingSession.php
app/Models/TrainingLog.php
app/Models/ModelVersion.php
app/Models/EvolutionProposal.php
app/Models/MarketSymbol.php
app/Models/Symbol.php
app/Models/Candle.php
```

Model rollari:

- `BacktestRun`: bitta backtest session.
- `Trade`: backtest ichidagi trade.
- `Mistake`: loss trade sababi va suggestion.
- `DailyReport`: kunlik AI training report.
- `StrategyScore`: agent/strategy leaderboard natijasi.
- `TrainingSession`: Run All Agents natijasida yaratilgan AI training session.
- `TrainingLog`: auto training va daily workflow commandlari tarixi.
- `ModelVersion`: strategy version status/history.
- `EvolutionProposal`: zaif agent uchun yangi version va parametr taklifi.
- `MarketSymbol`: data provider symbol mapping va active symbol ro'yxati.
- `Symbol`: ichki symbol jadvali, candles bilan bog'lanadi.
- `Candle`: DB ichidagi OHLCV candle data.

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

GET  /training-logs
GET  /training-logs/{trainingLog}

GET  /model-versions

GET  /evolution-proposals
GET  /evolution-proposals/{evolutionProposal}
POST /evolution-proposals/{evolutionProposal}/approve
POST /evolution-proposals/{evolutionProposal}/apply
POST /evolution-proposals/{evolutionProposal}/reject

GET  /daily-reports
GET  /daily-reports/{dailyReport}

GET  /mistake-journal
GET  /ai-daily-report
GET  /backtest-results
GET  /market-data
POST /market-data/update
```

Muhim API route:

```text
POST /api/backtest/run
```

### Laravel Commands

```text
php artisan trading:daily-report
php artisan trading:auto-train
php artisan trading:daily-workflow
php artisan market-data:update
```

Scheduler:

```text
routes/console.php
Schedule::command('trading:daily-report')->dailyAt('23:50');
Schedule::command('trading:daily-workflow')->dailyAt('01:00')->withoutOverlapping()->runInBackground();
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
evolution_proposals
training_logs
market_symbols
symbols
candles
strategies
```

`strategy_scores.training_session_id` training session bilan bog'laydi.

`strategy_scores.parameters` har bir leaderboard natijasi qaysi model parametrlar bilan chiqqanini saqlaydi.

`strategy_scores` 8-etapdan keyin quyidagi risk metriclarni ham saqlaydi:

```text
average_win_percent
average_loss_percent
risk_reward_ratio
max_consecutive_losses
stability_score
equity_curve
regime_performance
volatility_performance
```

`trades` 10-etapdan keyin har bir trade qaysi regime'da ochilganini saqlaydi:

```text
market_regime
volatility_regime
```

`candles` 12-etapdan keyin market data provider nomini ham saqlaydi:

```text
provider
```

`market_symbols` provider mapping jadvali:

```text
symbol
provider_symbol
name
market_type
is_active
```

`training_sessions` 8-etapdan keyin session-level risk average metriclarni saqlaydi:

```text
average_drawdown
average_profit_factor
average_stability_score
```

`evolution_proposals.training_session_id` proposal qaysi sessiondan chiqqanini ko'rsatadi.

`evolution_proposals.model_version_id` proposal qaysi model versiondan o'sganini ko'rsatadi.

`model_versions.status` qiymatlari:

```text
testing
active
rejected
archived
```

Status qoidasi:

```text
score >= 75 and profit_factor >= 1.3 and drawdown <= 15 -> active
score < 30 or profit_factor < 0.8 -> rejected
otherwise -> testing
```

`evolution_proposals.status` qiymatlari:

```text
pending
approved
rejected
applied
```

`training_logs.status` qiymatlari:

```text
pending
running
success
failed
```

`training_logs.type` asosiy qiymatlari:

```text
auto_training
daily_workflow
daily_report
evolution
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
- `/api/backtest/run-all`: Laravel yuborgan dynamic strategy versiyalarini yoki default v1 agentlarni bir xil data bilan test qiladi, leaderboard qaytaradi.
- 12-etapdan keyin `/api/backtest/run` va `/api/backtest/run-all` payload ichidagi `candles` arraydan ishlay oladi; candles bo'lmasa CSV fallback ishlaydi.
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

7-etapdan keyin Python registry quyidagicha ishlaydi:

```text
breakout_v2 + base_strategy=breakout_v1 + parameters -> breakout strategy function
ema_rsi_v2 + base_strategy=ema_rsi_v1 + parameters -> EMA/RSI strategy function
```

Ya'ni v2/v3 uchun alohida Python fayl shart emas; model version parametrlariga qarab signal o'zgaradi.

Strategy fayllari:

```text
app/strategies/ema_rsi.py
app/strategies/macd_trend.py
app/strategies/fibonacci.py
app/strategies/breakout.py
app/strategies/registry.py
```

Market regime detector:

```text
app/services/market_regime.py
```

Vazifalari:

- EMA 50 / EMA 200 va ADX orqali trend/range holatini aniqlash
- ATR percent orqali high/low/normal volatility holatini aniqlash
- `ta` paketi yo'q lokal muhitda pandas fallback hisob-kitoblari bilan ishlash

### Python Backtester

Asosiy fayl:

```text
ai-service-python/app/services/backtester.py
```

Vazifalari:

- CSV data o'qish
- Laravel payload orqali kelgan `candles` arraydan DataFrame yaratish
- Strategy registry orqali signal chiqarish
- Dynamic `parameters` orqali strategy signalini sozlash
- Trade ochish/yopish
- Win/loss hisoblash
- Winrate, profit factor, drawdown, net profit hisoblash
- Average win/loss, risk/reward ratio, max consecutive losses hisoblash
- Equity curve va stability score chiqarish
- Market regime va volatility regime analytics chiqarish
- Loss trade uchun mistake classification
- Top mistakes chiqarish
- Run-all professional leaderboard score hisoblash

Professional score formula:

```text
score = profit quality
      + winrate
      + profit factor
      - drawdown penalty
      - loss streak penalty
      + stability score
      + trade count reliability
      + regime adaptability bonus
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
  - evolution_proposals
 |
/training-sessions
```

### Agent Evolution Flow

```text
Training session completed
 |
Worst agent aniqlanadi
 |
worst_score < 50 bo'lsa
 |
AgentEvolutionService evolution plan tuzadi
 |
Laravel DB:
  - evolution_proposals
 |
/evolution-proposals
 |
Approve / Reject / Apply
 |
Apply qilinganda:
  - model_versions ichida yangi v2 yaratiladi
```

7-etap Dynamic Strategy Flow:

```text
model_versions
 |
StrategyLabController active/testing versiyalarni oladi
 |
active/testing version topilmasa error qaytaradi
 |
POST /api/backtest/run-all:
  strategies: [
    strategy,
    base_strategy,
    version,
    parameters
  ]
 |
Python registry base_strategy bo'yicha function topadi
 |
Strategy function parameters bilan signal chiqaradi
 |
Backtester leaderboard qaytaradi
 |
StrategyScore:
  - raw_result
  - parameters
  - risk metrics
  - equity_curve
  - regime_performance
  - volatility_performance
```

### Risk Metrics Flow

```text
Python backtester
 |
trades -> equity_curve
 |
calculate:
  - max_drawdown_percent
  - profit_factor
  - average_win_percent
  - average_loss_percent
  - risk_reward_ratio
  - max_consecutive_losses
  - stability_score
 |
professional score formula
 |
Laravel StrategyScore + TrainingSession risk averages
 |
ModelVersion status risk-adjusted qoida bilan yangilanadi:
  - active: score >= 75, PF >= 1.3, drawdown <= 15
  - rejected: score < 30 yoki PF < 0.8
```

### Charts Dashboard Flow

```text
TrainingSession detail
 |
StrategyScore risk metrics + equity_curve
 |
Chart.js:
  - Equity Curve
  - Score Comparison
  - Profit vs Drawdown
  - Win/Loss Distribution
  - Stability Score

Strategy Lab
 |
Top Strategy Scores + Profit vs Drawdown charts

Model Versions
 |
Model Status Distribution chart
```

### Market Regime Detection Flow

```text
Python backtester
 |
CSV candles -> apply_market_regime()
 |
market_regime:
  - trend_up
  - trend_down
  - range
  - unknown
 |
volatility_regime:
  - high_volatility
  - low_volatility
  - normal_volatility
 |
Trade:
  - market_regime
  - volatility_regime
 |
Result:
  - regime_performance
  - volatility_performance
 |
Score formula:
  - profitable regimes bonus
  - no profitable regime penalty
 |
Laravel DB:
  - strategy_scores regime analytics
  - trades regime columns
 |
Training Session UI:
  - Best Agent Regime Profit chart
  - Market Regime Performance table
 |
Evolution proposal:
  - worst regime note
  - avoid_regime parameter
```

6-etap manual test flow:

```text
php artisan migrate
php artisan db:seed --class=ModelVersionSeeder
php artisan test

Python service -> http://127.0.0.1:9000
Laravel UI:
  /strategy-lab
  Start New Training Session
  /evolution-proposals
  Apply & Create Version
  /model-versions
```

11-etap Auto Training uchun Python service doim ishlashi kerak:

```text
uvicorn app.main:app --host 127.0.0.1 --port 9000
```

12-etap market data CSV joyi:

```text
backend-laravel/storage/app/market-data/XAUUSD_H1.csv
```

Productionda Laravel scheduler uchun cron kerak:

```cron
* * * * * cd /var/www/neurotrader-lab/backend-laravel && php artisan schedule:run >> /dev/null 2>&1
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

### Auto Training Workflow

```text
Scheduler 01:00
 |
php artisan trading:daily-workflow
 |
1) php artisan market-data:update --symbol=XAUUSD --timeframe=H1 --limit=1000
   |
   storage/app/market-data/{SYMBOL}_{TIMEFRAME}.csv
   |
   candles table updateOrCreate
 |
2) php artisan trading:auto-train
   |
   ModelVersion testing/active
   |
   CandlePayloadService DB candles -> candles payload
   |
   POST Python /api/backtest/run-all
   |
   Laravel DB:
     - training_sessions
     - strategy_scores
     - model_versions
     - evolution_proposals
     - training_logs
 |
3) php artisan trading:daily-report
 |
TrainingLog:
  - daily_workflow
  - auto_training
```

### Data Update Engine Flow

```text
market_symbols
 |
php artisan market-data:update
 |
MarketDataService
 |
CsvMarketDataProvider
 |
storage/app/market-data/{SYMBOL}_{TIMEFRAME}.csv
 |
symbols + candles
 |
CandlePayloadService
 |
Laravel -> Python payload:
  candles: [
    time, open, high, low, close, volume
  ]
 |
Python SimpleBacktestRequest.candles
 |
Pandas DataFrame
```

## Muhim Sahifalar

```text
/                  -> Dashboard
/backtests         -> Single backtest form
/strategy-lab      -> Agent leaderboard + Start New Training Session
/training-sessions -> Training session history + session charts
/training-logs     -> Auto training and daily workflow logs
/model-versions    -> Version history/status + status chart
/evolution-proposals -> Agent evolution proposal queue
/daily-reports     -> Daily AI reports
/mistake-journal   -> Mistake journal
/market-data      -> Candle status + manual market data update
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

Dynamic strategy smoke:

```bash
cd ai-service-python
python -c "from app.schemas import SimpleBacktestRequest, StrategyRuntimeConfig; from app.main import run_all_backtests; payload=SimpleBacktestRequest(symbol='XAUUSD', timeframe='H1', strategies=[StrategyRuntimeConfig(strategy='breakout_v2', base_strategy='breakout_v1', version='v2', parameters={'lookback':30,'atr_period':14,'atr_multiplier':0.4,'confirmation_candles':2})]); item=run_all_backtests(payload)['leaderboard'][0]; print(item['strategy'], item['parameters']['lookback'], item['result']['parameters']['atr_multiplier'])"
```

Risk metrics smoke:

```bash
cd ai-service-python
python -c "from app.schemas import SimpleBacktestRequest, StrategyRuntimeConfig; from app.main import run_all_backtests; payload=SimpleBacktestRequest(symbol='XAUUSD', timeframe='H1', strategies=[StrategyRuntimeConfig(strategy='ema_rsi_v1', base_strategy='ema_rsi_v1', version='v1', parameters={'ema_fast':50,'ema_slow':200,'rsi_period':14,'rsi_buy_min':50,'rsi_buy_max':70,'rsi_sell_min':30,'rsi_sell_max':50})]); item=run_all_backtests(payload)['leaderboard'][0]; result=item['result']; print(item['strategy'], item['score'], result['total_trades'], result['profit_factor'], result['max_consecutive_losses'], result['stability_score'], len(result['equity_curve']))"
```

Market regime smoke:

```bash
cd ai-service-python
python -c "from app.schemas import SimpleBacktestRequest, StrategyRuntimeConfig; from app.main import run_all_backtests; payload=SimpleBacktestRequest(symbol='XAUUSD', timeframe='H1', strategies=[StrategyRuntimeConfig(strategy='ema_rsi_v1', base_strategy='ema_rsi_v1', version='v1', parameters={'ema_fast':50,'ema_slow':200,'rsi_period':14,'rsi_buy_min':50,'rsi_buy_max':70,'rsi_sell_min':30,'rsi_sell_max':50})]); item=run_all_backtests(payload)['leaderboard'][0]; result=item['result']; print(item['strategy'], item['score'], sorted(result['regime_performance'].keys()), sorted(result['volatility_performance'].keys()))"
```

Migration:

```bash
cd backend-laravel
php artisan migrate --force
```

Market data update:

```bash
cd backend-laravel
php artisan db:seed --class=MarketSymbolSeeder
php artisan market-data:update --symbol=XAUUSD --timeframe=H1 --limit=1000
```

Route check:

```bash
php artisan route:list --path=strategy-lab
php artisan route:list --path=training-sessions
php artisan route:list --path=training-logs
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
MARKET_DATA_PROVIDER=csv
OANDA_API_TOKEN=
OANDA_ACCOUNT_ID=
OANDA_BASE_URL=https://api-fxpractice.oanda.com
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
- yangi service qo'shilsa
- yangi Python endpoint qo'shilsa
- yangi strategy/agent qo'shilsa
- test natijasi o'zgarsa
- arxitektura flow o'zgarsa
- README'dagi etap holati o'zgarsa

## Keyingi Ehtimoliy Etaplar

Kelajakdagi rivojlanish:

- Strategy parameter optimizer
- Agent mutation / v3 generation
- OANDA/TwelveData live provider integration
- Multi-symbol support
- Queue jobs for long training
- Export reports

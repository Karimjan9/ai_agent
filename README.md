# NeuroTrader Lab

NeuroTrader Lab is an MVP web platform where a trading strategy engine trains on historical market data, produces signals, checks outcomes, records mistakes, and generates a daily report.

The first MVP does not execute real trades. It only runs:

```text
Data -> Strategy -> Backtest -> Result -> Mistake Journal -> Daily Report
```

## Codex Project Memory

Codex agentlar yangi ish boshlaganda avval `docs/project-memory/README.md` va `docs/project-memory/project-index.json`ni o'qishi kerak. Vazifaga mos modul yozuvi keyin ochiladi; shu usul butun repository'ni qayta skan qilish zaruratini kamaytiradi. `PROJECT_CONTEXT.md` batafsil tarixiy kontekst va qarorlar jurnalidir.

Har muhim o'zgarishdan keyin tegishli `docs/project-memory/` yozuvi va indeks yangilansin; zarur strategic qarorlar `PROJECT_CONTEXT.md`ga ham qo'shilsin.

## MVP Scope

- Instrument: `XAU/USD`
- Timeframes: `M15`, `H1`
- First strategy components:
  - EMA 50 / EMA 200
  - RSI
  - Fibonacci pullback zone
  - ATR stop-loss
- No live trading
- Historical promotion evidence has one explicit canonical provider (`MARKET_DATA_CANONICAL_PROVIDER=twelve` by default); secondary feeds are audit-only.
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
27 passed, 154 assertions
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
27 passed, 154 assertions
```

### 6-etap

- Agent Evolution Engine qo'shildi: past score olgan agent uchun yangi version proposal yaratiladi.
- Yangi jadval va model qo'shildi:
  - `evolution_proposals`
  - `EvolutionProposal`
- `AgentEvolutionService` yaratildi:
  - training session ichidagi `worst_strategy` ni topadi
  - score `< 50` bo'lsa proposal yaratadi
  - strategy turiga qarab `main_problem`, `reason`, `proposal`, `new_parameters` tayyorlaydi
- `StrategyLabController::runAll` endi training session tugagach avtomatik evolution proposal yaratadi.
- `ModelVersion.parameters` endi strategy parametrlarini saqlash uchun ishlatiladi; backtest result esa `metadata` ichida qoladi.
- Default model version parametrlarini yaratish uchun `ModelVersionSeeder` qo'shildi.
- Evolution proposal sahifalari va actionlari qo'shildi:

```text
GET  /evolution-proposals
GET  /evolution-proposals/{evolutionProposal}
POST /evolution-proposals/{evolutionProposal}/approve
POST /evolution-proposals/{evolutionProposal}/apply
POST /evolution-proposals/{evolutionProposal}/reject
```

- `apply` qilinganda yangi `ModelVersion` yaratiladi:

```text
breakout_v1 -> breakout_v2
v1 -> v2
generation 1 -> 2
status -> testing
```

- Laravel testlar yangilandi:

```text
27 passed, 154 assertions
```

#### 6-etap yakuniy test tartibi

```bash
cd backend-laravel
php artisan migrate
php artisan db:seed --class=ModelVersionSeeder
php artisan test
```

Python service ishlab turganda:

```bash
cd ai-service-python
uvicorn app.main:app --reload --host 127.0.0.1 --port 9000
```

Laravel UI flow:

```text
/strategy-lab
Start New Training Session
/evolution-proposals
Apply & Create Version
/model-versions
```

Kutilgan natija:

```text
breakout_v1 -> v1 -> testing/rejected
breakout_v2 -> v2 -> testing
```

### 7-etap

- Dynamic Strategy Engine qo'shildi.
- Laravel `StrategyLabController::runAll` endi `model_versions` jadvalidagi `testing` va `active` versiyalarni Python'ga yuboradi.
- `testing/active` model version topilmasa, run-all endi aniq error qaytaradi.
- Python `/api/backtest/run-all` endi ixtiyoriy `strategies` payloadni qabul qiladi:

```json
{
  "strategy": "breakout_v2",
  "base_strategy": "breakout_v1",
  "version": "v2",
  "parameters": {
    "lookback": 30,
    "atr_multiplier": 0.4,
    "confirmation_candles": 2
  }
}
```

- Python strategy registry `breakout_v2` kabi nomlarni alohida faylsiz `breakout_v1` base strategy orqali ishlata oladi.
- Strategy fayllari dynamic parametrlarni qabul qiladigan qilindi:
  - `ema_rsi.py`
  - `macd_trend.py`
  - `fibonacci.py`
  - `breakout.py`
- 6-etapdagi cheklov olib tashlandi: `breakout_v2` endi Python backtesterda real ishlaydi, lekin u hali alohida yangi algoritm emas, base strategy + yangi parametrlar sifatida ishlaydi.
- Python response endi `parameters`ni ham qaytaradi.
- `strategy_scores.parameters` ustuni qo'shildi va leaderboard natijasi qaysi parametr bilan chiqqani DB'da saqlanadi.
- Training Session detail sahifasida har bir strategy score uchun `Parameters` details bloki qo'shildi.
- Laravel testlar yangilandi:

```text
27 passed, 154 assertions
```

Python compile/smoke:

```text
python -m compileall app -> OK
breakout_v2 dynamic smoke -> OK, parameters leaderboard/result ichida qaytdi
```

### 8-etap

- Risk Metrics Engine qo'shildi.
- Python backtester endi har bir strategy uchun professional risk metrikalarni qaytaradi:
  - `max_drawdown_percent`
  - `profit_factor`
  - `average_win_percent`
  - `average_loss_percent`
  - `risk_reward_ratio`
  - `max_consecutive_losses`
  - `stability_score`
  - `equity_curve`
- Python score formulasi 100 ballik professional formulaga o'tdi:

```text
profit quality
+ winrate
+ profit factor
- drawdown penalty
- loss streak penalty
+ stability score
+ trade count reliability
```

- Laravel `strategy_scores` jadvali risk metric ustunlari bilan kengaytirildi.
- Laravel `training_sessions` jadvali umumiy risk average metriclari bilan kengaytirildi:
  - `average_drawdown`
  - `average_profit_factor`
  - `average_stability_score`
- `StrategyLabController` risk metriclarni DB'ga saqlaydi va training session xulosasida ishlatadi.
- `ModelVersion` active/rejected qarori endi faqat score emas, risk-adjusted qoida bilan ishlaydi:
  - active: `score >= 75`, `profit_factor >= 1.3`, `drawdown <= 15`
  - rejected: `score < 30` yoki `profit_factor < 0.8`
- Strategy Lab leaderboardda PF, loss streak va stability ko'rinadi.
- Training Session detail sahifasida average risk kartalari va har bir agentning risk metriclari ko'rinadi.

Tekshiruv:

```text
python -m compileall app -> OK
ema_rsi_v1 risk smoke -> OK
php artisan migrate -> DONE
php artisan test -> 27 passed, 154 assertions
```

### 9-etap

- Equity Curve + Charts Dashboard qo'shildi.
- Layoutga Chart.js CDN qo'shildi va Blade script stack yoqildi:

```text
resources/views/layouts/app.blade.php
```

- Training Session detail sahifasiga quyidagi chartlar qo'shildi:
  - Equity Curve
  - Agent Score Comparison
  - Profit vs Drawdown
  - Win / Loss Distribution
  - Stability Score
- Strategy Lab sahifasiga umumiy leaderboard chartlari qo'shildi:
  - Top Strategy Scores
  - Profit vs Drawdown
- Model Versions sahifasiga status distribution doughnut chart qo'shildi.
- Chart scriptlar null-safe qilindi: eski sessionlarda `equity_curve` bo'lmasa xato bermaydi.
- Feature testlar chart canvas va sarlavhalarini tekshiradigan qilindi.

Tekshiruv:

```text
php artisan test -> 27 passed, 154 assertions
```

### 10-etap

- Market Regime Detection qo'shildi.
- Python service har bir candle uchun bozor holatini aniqlaydi:
  - `trend_up`
  - `trend_down`
  - `range`
  - `unknown`
- Volatility regime ham aniqlanadi:
  - `high_volatility`
  - `low_volatility`
  - `normal_volatility`
- `ai-service-python/app/services/market_regime.py` yaratildi.
- `ta` paketi bo'lmagan lokal muhitda ham ishlashi uchun EMA, ATR va ADX fallback hisob-kitoblari qo'shildi.
- Python backtester har bir trade ichida `market_regime` va `volatility_regime` qaytaradi.
- Python response ichiga `regime_performance` va `volatility_performance` qo'shildi.
- Score formulasiga regime adaptability bonus/penalty qo'shildi.
- Laravel `strategy_scores` jadvali `regime_performance` va `volatility_performance` JSON ustunlari bilan kengaytirildi.
- Laravel `trades` jadvali `market_regime` va `volatility_regime` ustunlari bilan kengaytirildi.
- Training Session detail sahifasiga `Best Agent Regime Profit` chart va `Market Regime Performance` jadvali qo'shildi.
- `AgentEvolutionService` endi eng yomon agentning eng zararli market regime'ini proposal ichiga yozadi va `avoid_regime` parametrini taklif qiladi.

Tekshiruv:

```text
python -m compileall app -> OK
run-all regime smoke -> OK
php artisan migrate -> DONE
php artisan test -> 27 passed, 154 assertions
```

### 11-etap

- Auto Training Scheduler qo'shildi.
- `training_logs` jadvali va `TrainingLog` modeli yaratildi.
- Yangi commandlar qo'shildi:
  - `php artisan trading:auto-train`
  - `php artisan trading:daily-workflow`
- `trading:auto-train` active/testing model versionlarni olib, Python `/api/backtest/run-all` endpointiga yuboradi.
- Auto training natijasi DB'ga saqlanadi:
  - `training_sessions`
  - `strategy_scores`
  - `model_versions`
  - `evolution_proposals`
  - `training_logs`
- `trading:daily-workflow` auto trainingdan keyin `trading:daily-report` commandini ishga tushiradi.
- Schedulerga quyidagi workflow qo'shildi:

```text
trading:daily-workflow -> dailyAt('01:00') -> withoutOverlapping() -> runInBackground()
```

- Auto Training Logs sahifalari qo'shildi:
  - `GET /training-logs`
  - `GET /training-logs/{trainingLog}`
- Sidebar ichiga `Training Logs` linki qo'shildi.
- Productionda Laravel scheduler uchun cron kerak:

```cron
* * * * * cd /var/www/neurotrader-lab/backend-laravel && php artisan schedule:run >> /dev/null 2>&1
```

- Auto training ishlashi uchun Python FastAPI service doim ishlab turishi kerak; serverda buni `systemd` yoki process manager orqali `uvicorn app.main:app --host 127.0.0.1 --port 9000` sifatida ushlab turish kerak.
- Batafsil manual run/deployment yo'riqnomasi: `docs/AUTO_TRAINING_SCHEDULER.md`.
- Feature testlar auto training command, daily workflow va training logs sahifalarini tekshiradi.

Tekshiruv:

```text
php artisan migrate -> DONE
php artisan test -> 27 passed, 154 assertions
```

### 12-etap

- Data Update Engine qo'shildi.
- `market_symbols` jadvali va `MarketSymbol` modeli yaratildi.
- Mavjud `candles` jadvaliga `provider` ustuni qo'shildi.
- `Candle` va `Symbol` modellari qo'shildi.
- `MarketSymbolSeeder` yaratildi va `DatabaseSeeder`ga ulandi.
- Market data config qo'shildi:
  - `MARKET_DATA_PROVIDER=csv`
  - `TWELVE_DATA_API_KEY`
  - `DUKASCOPY_NODE_BINARY`
- CSV, Dukascopy va Twelve Data provider servicelari qo'shildi:
  - `MarketDataProviderInterface`
  - `CsvMarketDataProvider`
  - `MarketDataService`
  - `CandlePayloadService`
- Yangi command:

```bash
php artisan market-data:update --symbol=XAUUSD --timeframe=H1 --limit=1000
```

- CSV fayl joyi:

```text
storage/app/market-data/XAUUSD_H1.csv
```

- `trading:daily-workflow` endi 3 qadamli: market data update, auto training, daily report.
- `trading:auto-train`, manual Strategy Lab run-all va single backtest endi DB `candles`dan olingan candle payloadni Python'ga yuboradi.
- Python `SimpleBacktestRequest` endi `candles` array qabul qiladi; candles bo'lmasa CSV fallback saqlangan.
- Market Data web sahifasi real status sahifaga almashtirildi:
  - `GET /market-data`
  - `POST /market-data/update`
- Sahifada active symbol, provider symbol, candle count va oxirgi candle vaqti ko'rinadi.

Tekshiruv:

```text
php artisan migrate -> DONE
python -m compileall app -> OK
Python candles-array smoke -> OK
php artisan test -> 27 passed, 154 assertions
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

The MVP includes these main pages:

- Dashboard
- Market Data
- Strategy Lab
- DNA Laboratory
- Training Sessions
- Model Versions
- Evolution Proposals
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

## Current Advanced Stages: 13-15

Bu bo'lim keyingi ishda loyihani tez tushunish uchun qisqa Obsidian-style yozuv. Batafsil kontekst `PROJECT_CONTEXT.md` ichida.

### Stage 13: Walk Forward Validation Engine

Purpose:

```text
Agent tarixiy datasetda emas, forward segmentda ham ishlaydimi?
```

Python:

```text
ai-service-python/app/services/walk_forward.py
```

Run-all flow:

```text
Dataset -> Train 70% -> Validation 15% -> Forward 15%
 -> train_score / validation_score / forward_score
 -> robustness_score
 -> is_overfit
 -> final leaderboard score
```

Laravel persistence:

```text
strategy_scores.train_score
strategy_scores.validation_score
strategy_scores.forward_score
strategy_scores.robustness_score
strategy_scores.is_overfit
```

Model promotion now requires:

```text
score >= 75
profit_factor >= 1.3
drawdown <= 15
robustness_score >= 70
is_overfit = false
```

### Stage 14: Monte Carlo Risk Simulation Engine

Purpose:

```text
Trade tartibi yomon bo'lsa ham account tirik qoladimi?
```

Python:

```text
ai-service-python/app/services/monte_carlo.py
```

Response:

```text
result.monte_carlo.worst_profit_percent
result.monte_carlo.avg_profit_percent
result.monte_carlo.best_profit_percent
result.monte_carlo.worst_drawdown_percent
result.monte_carlo.avg_drawdown_percent
result.monte_carlo.risk_of_ruin_percent
result.monte_carlo.worst_equity_curve
result.monte_carlo.best_equity_curve
```

Laravel persistence:

```text
strategy_scores.mc_worst_profit_percent
strategy_scores.mc_avg_profit_percent
strategy_scores.mc_best_profit_percent
strategy_scores.mc_worst_drawdown_percent
strategy_scores.mc_avg_drawdown_percent
strategy_scores.mc_risk_of_ruin_percent
strategy_scores.mc_worst_equity_curve
strategy_scores.mc_best_equity_curve
```

Additional model rules:

```text
active requires:
mc_risk_of_ruin_percent <= 10
mc_worst_drawdown_percent <= 25

rejected if:
mc_risk_of_ruin_percent > 30
or mc_worst_drawdown_percent > 40
```

### Stage 15: Strategy DNA & Personality Engine

Purpose:

```text
Strategiyaning xarakteri: agressivmi, trendga qarammi, adaptivmi, survival kuchlimi?
```

Python:

```text
ai-service-python/app/services/strategy_dna.py
```

Response:

```text
result.strategy_dna.aggression_score
result.strategy_dna.trend_dependency
result.strategy_dna.range_dependency
result.strategy_dna.volatility_sensitivity
result.strategy_dna.adaptability_score
result.strategy_dna.recovery_score
result.strategy_dna.survival_score
result.strategy_dna.dna_summary
```

Laravel:

```text
strategy_dna_profiles
StrategyDnaProfile model
StrategyScore -> hasOne StrategyDnaProfile
```

UI:

```text
/strategy-lab/dna-laboratory
Training Session detail -> Strategy DNA Radar chart
```

Evolution now also reads DNA:

```text
trend_dependency > 90   -> excessive_trend_dependency
adaptability_score < 35 -> low_adaptability
survival_score < 50     -> weak_survival_dna
```

### Latest Test Snapshot

```bash
cd ai-service-python
python -m unittest discover -s tests
python -m compileall app

cd ../backend-laravel
php artisan test
```

Last known result:

```text
Python unittest: 8 passed
Python compileall: OK
Laravel: 34 passed, 177 assertions
```

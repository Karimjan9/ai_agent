# Auto Training Scheduler

Bu hujjat 11-etapdagi avtomatik training workflow uchun amaliy yo'riqnoma.

## Maqsad

Platforma har kuni o'zi training session ishga tushiradi:

```text
Scheduler
 |
trading:daily-workflow
 |
market-data:update
 |
trading:auto-train
 |
Python /api/backtest/run-all
 |
training_sessions + strategy_scores + model_versions + evolution_proposals + training_logs
 |
trading:daily-report
```

## Laravel Commandlar

Market data yangilash:

```bash
cd backend-laravel
php artisan market-data:update --symbol=XAUUSD --timeframe=H1 --limit=1000
```

Auto training:

```bash
cd backend-laravel
php artisan trading:auto-train
```

Parametrlar bilan:

```bash
php artisan trading:auto-train --symbol=XAUUSD --timeframe=H1 --balance=10000 --risk=1
```

To'liq daily workflow:

```bash
php artisan trading:daily-workflow
```

Daily workflow ichida qadamlar:

```text
1) market-data:update
2) trading:auto-train
3) trading:daily-report
```

Daily report alohida:

```bash
php artisan trading:daily-report
```

## Scheduler

Laravel scheduler `routes/console.php` ichida:

```php
Schedule::command('trading:daily-workflow')
    ->dailyAt('01:00')
    ->withoutOverlapping()
    ->runInBackground();
```

Eski daily report command ham qolgan:

```php
Schedule::command('trading:daily-report')->dailyAt('23:50');
```

## Web Sahifalar

Loglar:

```text
/training-logs
/training-logs/{trainingLog}
```

Market data status va manual update:

```text
/market-data
POST /market-data/update
```

Natija sahifalari:

```text
/training-sessions
/evolution-proposals
/model-versions
/daily-reports
```

## Python Service

Auto training Laravel'dan Python API'ga request yuboradi. Shuning uchun FastAPI service doim ishlab turishi kerak.

Lokal ishga tushirish:

```bash
cd ai-service-python
uvicorn app.main:app --reload --host 127.0.0.1 --port 9000
```

## CSV Market Data

12-etap MVP `csv` provider bilan ishlaydi.

CSV fayl joyi:

```text
backend-laravel/storage/app/market-data/XAUUSD_H1.csv
```

Format:

```csv
time,open,high,low,close,volume
2024-01-01 00:00:00,2062.12,2065.40,2059.10,2063.50,0
```

Symbol seeder:

```bash
php artisan db:seed --class=MarketSymbolSeeder
```

Health check:

```bash
curl http://127.0.0.1:9000/
```

## Server Cron

Production serverda Laravel scheduler har minut ishlashi kerak.

Crontab:

```cron
* * * * * cd /var/www/neurotrader-lab/backend-laravel && php artisan schedule:run >> /dev/null 2>&1
```

Project path boshqa bo'lsa, `cd` yo'lini o'zgartiring.

## Systemd Service

Linux serverda Python service uchun namunaviy systemd unit:

```ini
[Unit]
Description=NeuroTrader AI FastAPI Service
After=network.target

[Service]
User=www-data
Group=www-data
WorkingDirectory=/var/www/neurotrader-lab/ai-service-python
Environment="PATH=/var/www/neurotrader-lab/ai-service-python/.venv/bin"
ExecStart=/var/www/neurotrader-lab/ai-service-python/.venv/bin/uvicorn app.main:app --host 127.0.0.1 --port 9000
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Ishga tushirish:

```bash
sudo systemctl daemon-reload
sudo systemctl enable neurotrader-ai
sudo systemctl start neurotrader-ai
sudo systemctl status neurotrader-ai
```

## Troubleshooting

`Testing yoki active model version topilmadi.`

```bash
php artisan db:seed --class=ModelVersionSeeder
```

Python service ulanmasa:

```text
config/services.php -> AI_SERVICE_URL
.env -> AI_SERVICE_URL=http://127.0.0.1:9000
```

Scheduler ishlamasa:

```bash
php artisan schedule:list
php artisan schedule:run
```

Oxirgi loglarni webda ko'ring:

```text
/training-logs
```

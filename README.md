# NeuroTrader Lab

NeuroTrader Lab — Laravel boshqaruv qatlami va FastAPI replay qatlami orqali
AI trading research, evidence lifecycle, MTF pilot va paper-trading
kuzatuvini yuritadigan tizim.

## Hozirgi arxitektura

- `backend-laravel/` — operator UI, API, evidence, market data, queue va scheduler.
- `ai-service-python/` — deterministic backtest, replay, MTF va holdout hisoblari.
- `docs/project-memory/` — AI Laboratory, adaptive evolution va modul holatining amaldagi xotirasi.
- `docs/operations/` — production/recovery runbooklar.

Kodning canonical navigatsiyasi va hujjatlarning ustuvorlik tartibi uchun
[Canonical index](docs/CANONICAL_INDEX.md)dan boshlang. Eski etaplar tarixi
`PROJECT_CONTEXT.md`da saqlanadi, ammo operatsion qarorlar uchun index ko‘rsatgan
hujjatlar ustun hisoblanadi.

## Ishga tushirish

```powershell
cd backend-laravel
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate

cd ..\ai-service-python
python -m venv .venv
.\.venv\Scripts\pip install -r requirements.txt
```

Sozlamalar uchun [environment reference](docs/ENVIRONMENT.md)ni, Redis uzilishi
uchun esa [recovery runbook](docs/operations/redis-recovery.md)ni o‘qing.

## Tekshiruv

```powershell
cd backend-laravel
php artisan test
php artisan system:runtime-monitor --persist
php artisan system:redis-recovery --strict

cd ..\ai-service-python
python -m compileall app
```

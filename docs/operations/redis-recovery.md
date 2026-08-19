# Redis availability va recovery runbook

## Maqsad

Redis primary transport: queue, cache locks va default session uchun ishlatiladi.
Redis yo‘qolganda tizim promotion qarorini o‘zgartirmaydi va ishchi processni
yashirin boshqa backendga almashtirmaydi. Recovery faqat operator tasdiqlagan,
audit qilinadigan profile switch orqali bajariladi.

## Oldindan tayyorgarlik

- MySQL `sessions`, `cache`, `jobs`, `job_batches` va `failed_jobs` migrationlari bajarilgan bo‘lishi kerak.
- Redis persistent storage, monitoring va alohida host/managed service production uchun tavsiya etiladi.
- `ALERT_CACHE_STORE=database` qolsin: Redis o‘chsa ham critical alert dedupe ishlaydi.
- `php artisan system:runtime-monitor --persist` schedulerda har daqiqa ishlaydi.

## Health check

```powershell
php artisan system:redis-recovery --strict
php artisan system:runtime-monitor --json --persist
```

`primary_healthy` — primary profilni saqlang. `controlled_failover_required`
— quyidagi ketma-ketlikni bajaring.

## Controlled failover (Redis mavjud emas)

1. Yangi `trading:dispatch-*` ishlarini to‘xtating va joriy workerlarni gracefull to‘xtating. Uzoq full replay tugaganini yoki recovery contract bilan qayd etilganini tekshiring.
2. `.env`da quyidagini o‘rnating, keyin config cache va workerlarni qayta ishga tushiring:

```dotenv
CACHE_STORE=redis_failover
QUEUE_CONNECTION=redis_failover
SESSION_DRIVER=database
SESSION_CONNECTION=mysql
SESSION_TABLE=sessions
```

3. `php artisan config:clear` bajaring va queue workerini yangi connection bilan ishga tushiring. Session almashgani sabab operatorlar qayta login qilishi mumkin.
4. `php artisan system:redis-recovery --strict` va `php artisan system:health-check --strict` orqali database fallback ham ishlashini tekshiring.
5. Evidence/gate holatini o‘zgartirmang; faqat `trading:recover-*` komandalarini queue bo‘sh va diagnostika tasdiqlangandan keyin operator qo‘lda ishlatadi.

`redis_failover` Redis -> database (`cache`/`jobs`) zanjiridan foydalanadi. Cache lock semantikasi Redis bilan bir xil throughput bermaydi; bu vaqtinchalik degraded mode.

## Primaryga qaytish

1. Redis ping, cache round-trip va queue connection `ok` ekanini tekshiring.
2. Fallback workerlarni drain/stop qiling; database jobs va failed jobs holatini audit qiling.
3. `.env`ni primary qiymatlarga qaytaring: `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=redis`, `SESSION_CONNECTION=session`.
4. Config va workerlarni restart qiling, so‘ng `system:runtime-monitor --persist` va `system:redis-recovery --strict`ni qayta ishlating.

## Taqiqlangan amallar

- Ishlayotgan queue workerini joyida backendga almashtirmang.
- Redis outage paytida promotion, champion replacement yoki evidence statusini “tuzatish” uchun qayta yozmang.
- Stale lockning o‘zi replay o‘lganini isbotlamaydi; faqat mavjud recovery command va AI liveness dalili bilan ishlang.

# Environment reference

`.env.example` canonical kalitlar ro‘yxati; bu fayl ularni operator maqsadi
bo‘yicha guruhlaydi. Secret qiymatlar hech qachon hujjatga yozilmaydi.

| Guruh | Asosiy parametrlar | Maqsad |
| --- | --- | --- |
| Application | `APP_*`, `BCRYPT_ROUNDS`, `LOG_*` | Laravel host, locale va log siyosati |
| Database | `DB_*`, `MYSQL_*`, `DATABASE_BACKUP_*` | MySQL ulanishi, G: backup katalogi, retention va backup clientlari |
| Transport | `SESSION_*`, `QUEUE_*`, `CACHE_*`, `REDIS_*` | Session, queue, cache va Redis continuity |
| AI replay | `AI_SERVICE_*`, `AI_REPLAY_*`, `LAB_REPLAY_*` | Python endpoint, timeout, cache va parallelism |
| Laboratory | `LAB_*` | Population, queue fairness, parent/adaptive evolution va evidence gate’lar |
| Market data | `MARKET_DATA_*`, `TWELVE_*`, `DUKASCOPY_*`, `MT5_*` | Provider, continuity, import va feed freshness |
| MTF / paper | `MTF_*`, `PAPER_*`, `PROMOTION_*` | H1/M15 pilot, paper observation va promotion guards |
| Risk / execution | `RISK_*`, `EXECUTION_*`, `LIVE_*` | Cost model, position limits va hard live-trading stop |
| External intelligence | `ECONOMIC_*`, `FMP_*`, `ALPHA_VANTAGE_*`, `CURRENTS_*`, `COT_*` | Optional calendar/news/COT data |
| Notifications | `TELEGRAM_*`, `MAIL_*`, `BROADCAST_*` | Alert va local delivery |

## Transport profiles

Normal profil: `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`,
`SESSION_DRIVER=redis`. Redis outage uchun vaqtinchalik profil
`CACHE_STORE=redis_failover`, `QUEUE_CONNECTION=redis_failover`,
`SESSION_DRIVER=database`; to‘liq tartib [Redis recovery runbook](operations/redis-recovery.md)da.

## O‘zgartirish qoidalari

- `LIVE_TRADING_ENABLED`, `LIVE_TRADING_HARD_STOP`, `LIVE_KILL_SWITCH_ENGAGED` faqat tasdiqlangan production change orqali o‘zgaradi.
- `LAB_*` gate/budgetlarini feature kodi va evidence contractidan alohida o‘zgartirmang.
- Provider API keylari bo‘sh qolsa, tegishli provider “not configured” deb qayd qilinishi kerak; success deb qabul qilinmaydi.
- Yangi env kaliti qo‘shilsa, `.env.example`ga guruhli comment va shu reference jadvaliga kiriting.
- `DATABASE_BACKUP_VERIFY_HASH_ON_HEALTH=false` normal holatda manifest va byte-size’ni tekshiradi; katta backupni har bir health poll’da qayta hash qilish uchun vaqtincha `true` qiling.
- `DATABASE_BACKUP_PATH` faqat `G:/` yoki `G:\` bilan boshlanishi kerak. Tizim G: mavjud bo‘lmasa C:ga fallback qilmaydi.
- `DATABASE_BACKUP_RETENTION=3` kunlik backup sonini belgilaydi; yangi backup yozilgach undan eski SQL va manifest juftliklari o‘chiriladi.
- `DATABASE_BACKUP_SCHEDULE_TIME=02:30` har kungi Laravel scheduler vaqtidir.

# Smart Discipline va Process Integrity Engine

Status: `[IMPLEMENTED / PAPER EXECUTION]`  
Protocol: `smart_discipline_engine_v2`  
Owner: Laravel paper-execution qatlami

## Nega bu qatlam kerak

Loyihada avvaldan Risk Sentinel, portfolio risk gate, execution state machine,
MTF passport, Opportunity Funnel va risk hysteresis mavjud edi. Yetishmagan
qism — ularni haqiqiy paper order ochilishida account tarixini ham ko‘radigan,
P&L’dan mustaqil baholanadigan yagona jarayon vakolatiga bog‘lash edi.

Smart Discipline yangi alpha yoki signal modeli emas. U faqat uchta amalni
bajaradi: `APPROVE`, `SHRINK`, `VETO`. Risk multiplier hech qachon `1.0` dan
oshmaydi va bu ledger hech qachon promotion evidence bo‘lmaydi.

```text
immutable paper signal
  -> execution contract
  -> Risk Sentinel
  -> portfolio/account risk gate
  -> one Smart Discipline decision receipt
     (external risk vetoes have priority and receive canonical NO_TRADE codes)
  -> APPROVE | SHRINK | VETO
  -> risk-authorized execution contract
     (the final reduced position multiplier is the multiplier used by paper P&L)
  -> simulated order
  -> immutable post-trade process review
  -> GOOD_WIN | GOOD_LOSS | BAD_WIN | BAD_LOSS
  -> faqat process-compliant outcome learningga kiradi
```

Vakolat tartibi: `SAFETY > RISK > DISCIPLINE > STRATEGY > AI_CONFIDENCE`.

## Tadqiqotdan olingan va chegaralangan xulosalar

- [CME trade-plan risk guidance](https://www.cmegroup.com/education/courses/building-a-trade-plan/risk-management-and-your-trade-plan)
  leverage, maksimal trade/day loss, bir vaqtdagi pozitsiyalar va maksimal
  exposure oldindan son bilan belgilanishini tavsiya qiladi. Shu sabab limitlar
  runtime’dan oldin config contract sifatida muhrlanadi.
- [SEC/Library of Congress investor-behavior summary](https://www.sec.gov/investor/tools/behaviorialpatterns.htm)
  active trading, disposition effect, mania/panic va noise tradingni zararli
  xulqiy patternlar sifatida jamlaydi. Shu sabab savdo chastotasi, late chase va
  loser-streak nazoratlari outcome’dan alohida audit qilinadi.
- Barber va Odeanning [individual investorlar bo‘yicha tadqiqoti](https://onlinelibrary.wiley.com/doi/10.1111/0022-1082.00226)
  ko‘p savdo qilgan household guruhida katta performance penalty topgan. Bu
  natija stock-investor householdlariga tegishli; u “intraday trade cap alpha
  yaratadi” degan causal isbot emas. Projectda cap faqat overtrading zararini
  cheklovchi containment policy hisoblanadi.
- Gollwitzer va Sheeranning [implementation-intention meta-analysis’i](https://www.socmot.uni-konstanz.de/publications/implementation-intentions-and-goal-achievement-meta-analysis-effects-and-processes)
  oldindan belgilangan `if situation -> then action` qoidalarini umumiy
  self-regulation usuli sifatida o‘rganadi. Bu trading-specific edge isboti
  emas; loyiha undan deterministic gate shakli uchun dizayn motivatsiyasi
  sifatida foydalanadi.
- [FINRA algorithmic-trading supervision guidance](https://www.finra.org/rules-guidance/key-topics/algorithmic-trading)
  productiondan oldingi test/validation, productiondagi activity review va
  cross-functional risk nazoratini ajratadi. Shu sabab research playbook hech
  qachon o‘z paper risk vakolatiga ega emas.
- [EU RTS 6 / Delegated Regulation 2017/589](https://eur-lex.europa.eu/eli/reg_del/2017/589/oj/eng)
  pre-trade control, real-time alert, post-trade reconciliation, mustaqil risk
  monitoring va kill functionality’ni alohida talablar sifatida belgilaydi.
  Project hozir paper-only bo‘lsa ham, authority va audit chegaralari shu
  production-safe topologiyaga mos saqlanadi.
- [NIST AI RMF Core](https://airc.nist.gov/airmf-resources/airmf/5-sec-core/)
  AI scope, oversight, measurement, documentation va lifecycle monitoringini
  aniq belgilashni tavsiya qiladi. Shu sabab AI setup taklif qiladi, ammo
  deterministic risk/discipline gate’ni o‘zgartira olmaydi.

Hech bir tashqi manba default thresholdlarni “optimal” deb isbotlamaydi. Shu
sabab barcha qiymatlar konservativ boshlang‘ich guardrail va env orqali
sozlanadigan parametrdir; ularni o‘zgartirish faqat paper evidence bilan
kalibrlanadi.

Qo'shimcha tekshiruvlar ham dizaynni tasdiqladi: [CME Trade and Risk
Management](https://www.cmegroup.com/education/courses/trade-and-risk-management)
trade ochilishidan oldin exit/stop va risk qilinadigan equity ma'lum bo'lishini
talab qiladi. [CFA Institute Backtesting &
Simulation](https://www.cfainstitute.org/insights/professional-learning/refresher-readings/2026/backtesting-and-simulation)
rolling/walk-forward test, scenario/sensitivity analysis hamda look-ahead va
survivorship bias nazoratini ajratadi. Shu sabab management variantlari live
paytda o'zgartirilmaydi; ular research replay va yangi version orqali kiradi.

## Pre-trade hard gates

| Gate | Canonical veto reason |
| --- | --- |
| Independent Risk Sentinel authority | `NO_TRADE_RISK_SENTINEL_*` |
| Account/data/news/exposure risk authority | owner bergan canonical `NO_TRADE_*` |
| Candidate, model version va direction identity lock | `NO_TRADE_STRATEGY_VERSION_OR_IDENTITY_DRIFT` |
| BUY/SELL stop-entry-target geometriyasi | `NO_TRADE_INVALID_EXIT_GEOMETRY` |
| Minimal reward/risk | `NO_TRADE_REWARD_RISK_TOO_LOW` |
| Signal narxidan adverse kechikish, stop units’da | `NO_TRADE_LATE_ENTRY_CHASE` |
| Account-level daily net-loss limit | `NO_TRADE_DAILY_LOSS_LOCK` |
| Account-level weekly net-loss limit | `NO_TRADE_WEEKLY_LOSS_LOCK` |
| UTC sessiya trade limiti | `NO_TRADE_SESSION_TRADE_LIMIT` |
| UTC kunlik trade limiti | `NO_TRADE_DAILY_TRADE_LIMIT` |
| Ketma-ket loss’dan keyingi vaqtli lock | `NO_TRADE_LOSS_STREAK_COOLDOWN` |

`setup_quality_score` soft diagnostika: u yaxshi setupni o‘lchaydi, lekin hard
gate’ni bypass qilmaydi. `gate_pass_score` candidate hard shartlarining qancha
qismi o‘tganini ko‘rsatadi. `process_adherence_score` esa qoidalarga rioya
qilishni P&L’dan mustaqil o‘lchaydi; engine’ning to‘g‘ri `VETO` qilishi process
xatosi emas va pre-trade adherence’ni pasaytirmaydi. Real closed-order history
`RiskHysteresisControllerService`ga beriladi: `NORMAL`, `CAUTION`, `DEFENSE`,
`RECOVERY` state’lari mos ravishda `GREEN`, `YELLOW`, `RED` jarayon holatiga
aylanadi; hard veto `LOCKED` holatidir.

Loss streak keyingi signal albatta yutqazishini bashorat qilmaydi. To‘rtta loss
default holatda qisqa cooldown beradi; cooldown tugagach trade DEFENSE size’da
qayta ochilishi mumkin. Shunday qilib tizim permanent deadlock yaratmaydi.
`CAUTION/DEFENSE -> RECOVERY -> NORMAL` o‘tishi esa har bir bosqichda yangi
yopilgan trade evidence fingerprintini talab qiladi; bir xil eski window bilan
takroriy signal state’ni bo‘shata olmaydi.

## Risk-authorized execution contract

`APPROVE/SHRINK`dan keyin strategiya so‘ragan multiplier, Sentinel cap va
Discipline multiplier bitta final qiymatga yig‘iladi:

```text
final_size = min(strategy_requested, sentinel_cap) * discipline_multiplier
```

Natija `risk_authorized_execution_contract_v1` bilan muhrlanadi. Uning
`authorization_hash`, policy hash, uchta input multiplier va “risk oshmaydi”
invariantlari order contextida saqlanadi. Python `advance-contract` aynan shu
final `position_size_multiple` bilan P&L hisoblaydi; auditdagi shrink bilan real
paper accounting endi ajralib ketmaydi.

Har pre-trade receipt qo‘llangan rule/thresholdlarning canonical
`policy_hash`ini ham saqlaydi. Report bir nechta hash ko‘rsa, operator turli
policy versiyalarini bitta sample sifatida aralashtirmasligi kerak.

## Frozen trade-management contract va replay parity

Python `paper_trade_management_v1` kontrakti entry paytida partial take-profit
fraction, partial target ATR multiplier, trailing-stop ATR multiplier va
time-stop candle countni alohida hash bilan muhrlaydi.

Paper reconciliation shu hashni joriy strategy request bilan qayta tekshiradi.
Hash yoki parameter drift bo'lsa request fail-closed bo'ladi. Eski ochiq orderda
management contract bo'lmasa u operational continuity uchun yopilishi mumkin,
ammo `management_attested=false` bo'ladi va learning evidence sifatida qabul
qilinmaydi.

`/api/paper/advance-contract` endi backtester bilan bir xil ketma-ketlikni
ishlatadi: favorable trailing-stop -> time stop/intrabar stop-target -> partial
TP -> weighted final P&L. Oldingi paper yo'lda partial TP ijro qilinmayotgan
parity bo'shlig'i shu bilan yopildi. Har reconciliation
`paper_management_audit_v1` qaytaradi: contract attestations, initial/final
stop, stop-widening, partial holati, holding bars, realized R, MFE R va MAE R.

Laravel audit hashini order ichidagi frozen hash bilan mustaqil solishtiradi.
Stop faqat riskni kamaytiruvchi tomonga yurishi mumkin; adverse widening
`STOP_WIDENING_VIOLATION`, hash/identity attestation yo'qligi esa
`UNATTESTED_TRADE_MANAGEMENT` hisoblanadi.

## Post-trade review va learning quarantine

Review pre-trade authorization, frozen strategy version, stop/target contract,
position-size ceiling, execution state transitions va system-owned broker’ni
tekshiradi. Natija va jarayon ikki mustaqil o‘q sifatida yoziladi:

- `GOOD_WIN`: qoida bajarilgan, P&L musbat;
- `GOOD_LOSS`: qoida bajarilgan, P&L manfiy;
- `BAD_WIN`: qoida buzilgan, P&L musbat;
- `BAD_LOSS`: qoida buzilgan, P&L manfiy;
- nol P&L uchun `GOOD_FLAT` / `BAD_FLAT`.

`BAD_*` outcome immutable saqlanadi, ammo uning paper order’i evidence sifatida
quarantine qilinadi va calibration/settlement learning oqimlariga yuborilmaydi.
Bu foydali tasodif orqali intizomsiz xulqni mukofotlashni to‘xtatadi.

Post-trade hard gates management hash/strategy/execution attestationsini va
stop hech qachon adverse tomonga kengaymaganini ham tekshiradi. Operator report
management attestation rate, stop-widening rate, average realized R, MFE R,
MAE R va top process violationsni alohida ko'rsatadi.

## Persistence va operator contracti

- Migration: `2026_08_27_060000_create_smart_discipline_decisions_table.php`
- Immutable model: `SmartDisciplineDecision`
- Engine: `SmartDisciplineEngineService`
- Paper integration: `PaperTradingExecutionService`
- Report:

```powershell
php artisan migrate --force
php artisan trading:discipline-report --days=30
php artisan trading:discipline-report --symbol=XAUUSD --timeframe=H1 --days=90 --json
```

Report entry count, approve/shrink/veto, veto rate, setup/process averages,
hard-gate pass score, policy versiyalari, invalid-trade rate, outcome
quadrants, risk states va top veto reasonlarni ko‘rsatadi.

## Config defaults

| Env | Default | Ma’nosi |
| --- | ---: | --- |
| `SMART_DISCIPLINE_ENABLED` | `true` | Process authority switch |
| `SMART_DISCIPLINE_MIN_REWARD_RISK` | `1.0` | Minimal after-contract R:R |
| `SMART_DISCIPLINE_LATE_ENTRY_MAX_STOP_UNITS` | `0.5` | Adverse chase maksimumi |
| `SMART_DISCIPLINE_WEEKLY_LOSS_LIMIT_PERCENT` | `5` | Account weekly net-loss lock |
| `SMART_DISCIPLINE_MAX_TRADES_PER_SESSION` | `4` | UTC session frequency cap |
| `SMART_DISCIPLINE_MAX_TRADES_PER_DAY` | `8` | UTC daily frequency cap |
| `SMART_DISCIPLINE_MAX_CONSECUTIVE_LOSSES` | `4` | Cooldown trigger |
| `SMART_DISCIPLINE_LOSS_COOLDOWN_MINUTES` | `20` | Hard lock davomiyligi |

Daily loss limit mavjud `RISK_DAILY_LOSS_LIMIT_PERCENT`dan olinadi; bir risk
haqiqati ikki config’da takrorlanmaydi.

Deploy vaqtida eski ochiq orderda pre-trade discipline decision bo‘lmasa, uni
orqaga qarab “approved” deb yasash mumkin emas. U yopilganda outcome auditda
qoladi, ammo fail-closed `BAD_*`/invalid evidence bo‘ladi. Eski paper window’ni
saqlash kerak bo‘lsa migrationdan oldin open orderlar drain qilinadi.

## Ataylab qilinmagan ishlar

- “Faqat uchta universal setup” kabi yangi magic strategy katalogi qo‘shilmadi;
  mavjud evidence-gated instrument/playbook tizimi saqlandi.
- News, spread, correlation va max-open-position gate’lari takrorlanmadi;
  ularning canonical owner’lari Economic Calendar, TradingRiskService va Risk
  Sentinel bo‘lib qoladi.
- Loss streak, trade cap yoki setup score alpha/promotion evidence sifatida
  ishlatilmaydi.
- Live trading yoqilmadi va kill switch o‘zgarmadi.

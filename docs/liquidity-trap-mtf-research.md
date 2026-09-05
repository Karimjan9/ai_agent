# Liquidity Trap MTF — research contract

## Maqsad

`Liquidity Trap MTF` alohida indikator emas. Bu H4 yo'nalish, H1 joylashuv,
M15 dagi likvidlik-trap va M5 dagi tasdiqlangan kirishni bir trade g'oyasiga
bog'laydigan, **faqat tadqiqot uchun** playbook.

Kanonik oqim:

```text
H4 closed direction
  -> H1 closed structure + POI
  -> M15 closed sweep / false-break / inducement
  -> M5 closed MSS/CHOCH + displacement
  -> next M5 open, only after FVG/OB retest when that variant is selected
```

Uning manbasi foydalanuvchi bergan SMC/ICT uslubidagi materialdir. Ushbu
material hipoteza beradi; o'zi foyda yoki haqiqiy order-flow mavjudligini
isbotlamaydi.

## Qatlamlar va ularning majburiyatlari

| Qatlam | Faqat yopilgan ma'lumot | Vazifa | Rad etish sababi |
| --- | --- | --- | --- |
| H4 | H4 sham yopilgach | yo'nalish, major swing, premium/discount proksisi | yo'nalish noaniq yoki H1 bilan zid |
| H1 | H1 sham yopilgach | external structure, POI va target proksisi | POI yo'q yoki narx range markazida |
| M15 | M15 sham yopilgach | prior high/low sweep, false breakout yoki inducement proksisi | sweep reclaim qilinmadi / trap davom etdi |
| M5 | M5 sham yopilgach | MSS/CHOCH, displacement va FVG/OB retest proksisi | displacement yoki tasdiq yo'q |
| Risk | M15 + execution xarajati | invalidation, pozitsiya hajmi, cost gate | spread/slippage stop masofasini yomonlashtiradi |
| Target | yopilgan H1/H4 | H1 internal, H1 external, H4 runner targetlari | minimal reward/risk bajarilmaydi |

`liquidity`, `order block`, `FVG`, `inducement` va `POI` bu loyihada OHLCV
dan olinadigan proksilar bo'lib qoladi. Order-book yoki trade/tick oqimi
bo'lmaganda ular yashirin institutsional likvidlik haqidagi fakt deb
nomlanmaydi.

## Signal kontrakti

### Long — balanced variant

1. Oxirgi yopilgan H4 `bullish` yo'nalish beradi.
2. Oxirgi mavjud H1 bullish external structure va valid demand/discount POI
   beradi.
3. Narx o'sha H1 POI ichida bo'lganda M15 oldingi tasdiqlangan low ostiga
   chiqib, shu level ustida yopiladi (`sell-side sweep proxy`).
4. Keyingi M5 oqimida bullish MSS/CHOCH va belgilangan minimal displacement
   bor.
5. Tanlangan M5 FVG/OB-retestidan keyingi yopilgan shamda signal beriladi;
   ijro faqat keyingi M5 ochilishida bo'ladi.
6. `SL = M15 trap extreme + execution buffer`. M5 micro-low mustaqil stop
   bo'la olmaydi.
7. TP1 H1 internal liquidity proxy, TP2 H1 prior high, runner esa H4
   external target proxy bo'ladi.

Short oqim aynan teskarisi. `Aggressive`, `balanced` va `conservative`
variantlari alohida, bir-biriga aralashtirilmagan hipotezalar bo'lishi kerak.

## Scoring — signal emas, audit ko'rsatkichi

Boshlang'ich diagnostik ball:

| Dalil | Ball |
| --- | ---: |
| H4 direction aniq | 2 |
| H1 structure align | 2 |
| H1 POI | 2 |
| M15 liquidity-trap proxy | 3 |
| M5 MSS/CHOCH | 2 |
| M5 displacement | 1 |
| M5 FVG/OB retest | 1 |
| London yoki NY session | 1 |

Bu ballar faqat `decision_trace` va ablation hisobotiga yoziladi. Dastlabki
chegaralar (A+, A, B) trade ruxsatini bermaydi; ular train-only statistik
kalibrlashdan keyin ham alohida parametrlar sifatida sinovdan o'tadi.

## Kerakli data-plane

Replay data-plane endi `closed_h4_h1_m15_m5_snapshot_v1` protokoli bilan
mavjud. `MultiTimeframeSnapshotService` bir xil symbol va UTC uchun M5/M15/H1
oqimlarini umumiy `closed_cutoff`da muzlatadi, H4ni faqat to'liq UTC-aligned
to'rtta H1 shamdan agregatsiya qiladi va barcha stream hashlarini immutable
manifestga yozadi. Bundle o'zgarsa Python feature-cache ham o'zgacha key bilan
qayta quriladi.

Python `closed_h4_h1_m15_m5_stack_v1` har qatlamga
`available_at = candle_open + timeframe_duration` belgilaydi va M5ga faqat
M5 shamining yopilishidagi `decision_at`ga `merge_asof(...,
direction="backward")` orqali biriktiradi. Demak hali yopilmagan H4/H1/M15
sham signalga kira olmaydi. M15 sweep holati M5ga
state-machine sifatida uzatiladi; trap bo'lmagan M5 signal, POIsiz M15 trap,
yetishmayotgan yoki stale H1/H4 kontekstlari majburan `WAIT` bo'ladi.

`str_041_liquidity_trap_mtf` hamon `SHADOW`: yangi oqim manual/shadow
backtest uchun tayyor, ammo paper/live promotion huquqini bermaydi. U production
H1 -> M15 oqimini o'zgartirmaydi.

Quyidagilar backtest natijasida audit qilinadi:

1. Decision trace kamida H4/H1/M15 context hashlar, M15 trap extreme,
   M5 trigger/retest, entry/SL/TP va barcha veto sabablarini qaytaradi.
2. `execution_contract` spread, slippage va next-open qoidalarini hozirgi
   replay/paper qoidalaridan meros oladi. Risk Sentinel faqat kamaytiradi
   yoki `WAIT` qiladi.

## Falsifikatsiya va tajriba tartibi

Har bir o'zgarish bitta savolga javob berishi kerak:

| Tajriba | Frozen control | O'lchanadigan inkor |
| --- | --- | --- |
| H4 filter | H1->M15 baseline | H4 qo'shilganda costdan keyingi edge oshmaydi |
| H1 POI filter | H4->M15 trap | POI trade sifati yoki DDni yaxshilamaydi |
| M15 trap | H4->H1->M5 | sweep proxy false-entrylarni kamaytirmaydi |
| M5 confirmation | trap-reclaim entry | kechikish opportunity costdan katta |
| Session filter | no-session control | London/NY taqsimoti boshqa omillardan mustaqil emas |
| Management | frozen exit | M15 invalidation stopi mikro-stopdan yaxshi emas |

Natija faqat bir datasetdagi win rate bilan baholanmaydi: bir xil immutable
manifest, xarajatlar, paired control, holdout/forward oynalari, confidence
kalibrlash va paper shadow kuzatuvi talab qilinadi. Har variant faqat
research-only bo'lib qoladi, to mavjud promotion protokoli barcha darvozalardan
to'g'ri o'tmaguncha.

## Loyiha bilan bog'lanishi

- `ai-service-python/app/strategies/structure.py`: swing, sweep, MSS/CHOCH,
  displacement, FVG va OB proksilarining causal hisoblash nuqtasi.
- `ai-service-python/app/services/multitimeframe_stack.py`: H4/H1/M15ni
  `available_at` bilan M5ga look-aheadsiz biriktiradigan replay stack.
- `backend-laravel/app/Services/MultiTimeframeSnapshotService.php`: umumiy
  cutoff, H1->H4 agregatsiyasi va immutable manifest bundle'i.
- `backend-laravel/app/Services/StrategyLibraryCompilerService.php`:
  `str_041_liquidity_trap_mtf` ning shadow kontrakti.
- `FeatureValueCatalogService` va `StrategyFeatureBundleService`: data-plane
  tayyor bo'lgach H4/H1/M15/M5 provenance qiymatlari faqat shu yerga
  qo'shiladi.

Bu usul hozirgi trend pullback, session sweep va SMC continuation
strategiyalarining ustiga qo'yiladigan tasdiq qatlamidir; ularni yashirin
ravishda almashtirmaydi yoki bitta baholanmaydigan mega-strategiyaga
birlashtirmaydi.

## Kengaytirilgan Strategy Matrix

Yangi research katalogi 12 ta mustaqil test hipotezasini saqlaydi. Ularning
barchasi `shadow_only`: katalogga kirish execution, paper yoki promotion
ruxsati emas.

| Prioritet | Playbook | Asosiy farqlovchi dalil |
| ---: | --- | --- |
| 1 | Liquidity Trap MTF | H4/H1 joylashuv ichidagi M15 trap va M5 tasdiq |
| 2 | Raid -> MSS -> FVG | raid, displacement va FVG retracement ketma-ketligi |
| 3 | PO3 / AMD | session accumulation, manipulation va distribution |
| 4 | London Judas Swing | Asia range sweep va London reversali |
| 5 | Turtle Soup MTF | katta leveldagi false break reclaimi |
| 6 | Time-window liquidity | oldindan e'lon qilingan vaqt oynasi |
| 7 | SMT + Sweep + MSS | related-market divergenceni faqat tasdiq sifatida ishlatish |
| 8 | Wyckoff Spring / UTAD | range phase, spring/UTAD va test |
| 9 | Elder Triple Screen + liquidity | H4 trend, H1 correction, M5 resumption |
| 10 | ORB + HTF bias | opening-range breakout/retest faqat HTF bilan |
| 11 | ORB + VWAP reclaim | false break, VWAP reclaim va opposite-range break |
| 12 | Adaptive confirmation | H4/H1 zid bo'lganda katta confirmation talab qilish |

Quyidagilar esa mustaqil robot emas, testda bitta playbookka oshkora
qo'shiladigan modullardir: Unicorn (breaker + FVG overlap), CRT reference
range, session window, SMT divergence, VWAP/anchored VWAP, OTE,
IFVG, Wyckoff phase va nested execution. Har trialda bitta playbook hamda
oldindan e'lon qilingan modullar ishlatiladi; aks holda qaysi qism edge
berganini aniqlashning imkoni bo'lmaydi.

Bu matrixning code-resursi
`backend-laravel/app/Services/StrategyResearchCatalogueService.php` ichida
saqlanadi. U kelajakda data-plane tayyor bo'lganda strategiya generatori va
research planner uchun yagona, audit qilinadigan manba bo'ladi.

## 12 playbook uchun frozen-control replay

Katalog endi faqat ma'lumotnoma emas: har bir model uchun
`mtf_playbook_frozen_control_v1` runner mavjud. Bir qatorda doim ikki replay
bo'ladi: o'zgarmas M5-only control va tanlangan candidate. Ikkalasiga ham aynan
bir xil M5 entry CSV, H4/H1/M15 (zarur bo'lsa D1) context bundle, manifest hash,
spread/slippage va next-M5-open execution contract beriladi. Natija
`mtf_playbook_frozen_control_runs` jadvalida control/candidate metrikalari,
delta va veto/reason-code bilan saqlanadi.

`liquidity_trap_mtf` o'zining maxsus `liquidity_trap_mtf_v1` candidate'i bilan
sinovdan o'tadi; qolgan 11 model `mtf_research_playbook_v1`ning qat'iy,
model-idga bog'langan tarmoqlaridir. Natija hech qachon promotion dalili emas:
har recordda `promotion_evidence=false` qoladi.

Ishga tushirish:

```powershell
php artisan migrate
php artisan trading:mtf-playbook-frozen-control XAUUSD --related-symbol=RELATED_SYMBOL --json
```

Ikkinchi buyruq 12 modelning barchasini replay qiladi. `RELATED_SYMBOL` faqat
SMT modeli uchun required: u primary market o'rniga taxmin qilinmaydi. Ushbu
argument bo'lmasa SMT qatorda `RELATED_MARKET_REQUIRED` bilan `blocked` bo'ladi,
qolgan mustaqil modellar esa o'z immutable replayini davom ettiradi. Bitta
modelni qayta tekshirish uchun `--model=ict_2022_raid_mss_fvg` ishlatiladi.

## Agent toolbox va creative window

12 playbook agent uchun signal-router yoki yopiq strategiyalar ro'yxati emas.
`AgentResearchPlaybookToolboxService` har bir cognitive plan ichiga to'liq
kutubxonani beradi, lekin attribution yo'qolmasligi uchun agentning mastery
bosqichiga qarab faqat 1, 2 yoki 3 ta nomlangan playbookni bitta o'quv
navbatiga qo'yadi. Qolganlari rotation queue'da turadi. Demak kutubxonadan
maksimal foydalaniladi, ammo 12 tasi yashirin mega-strategiyaga qo'shilmaydi.

Global frozen-control natija agentga prior sifatida ko'rinadi, lekin uning
shaxsiy malakasi deb yozilmaydi. Agent bir modelni o'z tajribasiga kiritishi
uchun `paired_frozen_replay`, mustaqil oynalar va paper-shadow outcome talab
qilinadi. Faqat shundan keyin natija keyingi study-set tanlovini yaxshilashi
mumkin; risk, sizing va promotion huquqi baribir berilmaydi.

Katalog yakuniy chegara emas. Validated specialist uchun mavjud curriculum
orqali `bounded_shadow_window` ochiladi: bitta yangi playbook yoki bitta yangi
causal relation, bitta o'zgargan axis, oshkora baseline va measurable behavior
delta. Future data, risk override, cheksiz playbook mixing va direct promotion
taqiqlangan. Har yangi g'oya oldin frozen control, keyin mustaqil tasdiq va
paper-shadowdan o'tadi.

## Model conflict qoidasi

Professional playbooklar council ovozi emas. Regime router model ishga
tushishidan oldin **bitta** modelni tanlaydi; faqat o'sha model candidate setup
berishi mumkin. `ResearchPlaybookConflictResolutionService` diagnostic
natijalarda quyidagi fail-closed qoidani qo'llaydi:

| Holat | Natija |
| --- | --- |
| Bir model BUY, boshqasi SELL | `WAIT_OPPOSITE_PLAYBOOK_DIRECTIONS` |
| Ikki yoki undan ortiq model bir tomonga signal berdi | `WAIT_UNDECLARED_MODEL_BLEND` |
| Data hash yoki execution hashlar boshqa | `WAIT_NON_COMPARABLE_MODEL_OUTPUTS` |
| Router tanlagan bitta model actionable | faqat `candidate_setup`; risk/execution gate hali alohida |

Shuning uchun `BUY`lar soni yoki o'rtacha confidence trade ruxsati emas. Agar
ikki modelning birgalikdagi qoidasi haqiqatan foydali degan hipoteza bo'lsa, u
alohida yangi candidate sifatida yoziladi va o'z frozen-control replayiga ega
bo'ladi. Mavjud generic portfolio/council mexanizmi bu 12 professional
playbookning implicit ensemble'i sifatida ishlatilmaydi.

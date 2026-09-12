# Causal Compounding Kernel v2 — implementation auditi

Sana: 2026-09-11
Scope: yagona XAUUSD H1-storage / H4-H1-M15-M5 organism

## Hukm

Quyidagi engineering zanjir kodda ulangan va regression bilan tekshirilgan:

```text
yangi hypothesis
-> exact same-generation frozen control
-> runtime-consumption attestation
-> paired economic settlement
-> local instrument posterior
-> exact contextual bundle posterior
-> memory-first keyingi mutation
-> confirmed skill / mentor incubator
-> independently improving descendants
-> eligible parent
-> control-relative performance credit
```

Zanjir profitable edge ishlab chiqarishga majburlamaydi. Uning vazifasi mavjud
signalni sababiy, context-local va qayta tekshiriladigan tarzda topish; signal
bo‘lmasa yolg‘on progress yoki parent yaratmaslikdir.

## Amalga oshirilgan kuchaytirishlar

### 1. Current-cohort MTF truth

- Candidate eski M15 population’dan emas, yagona XAUUSD H1 storage lineage’dan
  olinadi.
- Cohort hash count/first/last bilan emas, to‘liq tartiblangan H1 va M15
  historical OHLCV payload bilan quriladi.
- Eski completed ablation tarix sifatida ko‘rinadi, lekin current cohort deb
  ko‘rsatilmaydi.
- Autonomy yoqilganda scheduler har 30 daqiqada ko‘pi bilan bitta bounded
  qaror qiladi: avval exact control, keyin maksimal to‘rtta yuqori iqtisodiy
  information hypothesis.
- H1/M15 live freshness operational admission sifatida tekshiriladi, lekin
  pre-2026 historical evidence hash’ini o‘zgartirmaydi.

Compute’ni barcha variantlarga teng sarflamasdan promising variantlarga
bosqichma-bosqich berish Hyperband’ning adaptive resource allocation
tamoyiliga mos keladi: [Hyperband, JMLR](https://www.jmlr.org/papers/volume18/16-558/16-558.pdf).

### 2. Aqlli instrument va kombinatsiya learning

- One-gene candidate uchun exact same-generation control oldindan persisted
  bo‘lmasa u Python replay’ga kirmaydi.
- Catalogue assignmentning o‘zi invocation emas. Python assignment hash,
  parameter hash va real runtime bindingni attestation qilgandan keyingina
  invocation ledger ochiladi.
- Settlementda faqat changed causal instrument individual credit oladi.
  ATR-risk yoki cost-exit kabi support instrumentlar `support_consumed`
  bo‘ladi, lekin sababiy g‘olib sifatida yozilmaydi.
- Exact instrument bundle uchun alohida context-local posterior yoziladi.
  Bu posterior joint value’ni o‘lchaydi; interaction/synergy claim bermaydi.
- Keyingi mutationda positive reuse faqat isolated instrument va exact bundle
  bir xil local contextda kelishganda mumkin. Ulardan biridagi zarar veto
  beradi.
- Context regime, session, volatility, spread/liquidity va transition bo‘yicha
  ajratiladi. London yutug‘i Asia yoki global priorga aylanmaydi.

Haqiqiy interactionni aniqlash uchun control/A/B/AB factorial dizayn zarur;
ikki komponentli factorial dizayn main effect va interactionni alohida
baholaydi: [NIST factorial-design guide](https://www.itl.nist.gov/div898/handbook/pri/section3/pri3325.htm).

Context bo‘yicha bitta global champion o‘rniga niche/cell ichidagi sifatli
variantlarni saqlash MAP-Elites tamoyiliga mos:
[MAP-Elites](https://arxiv.org/abs/1504.04909). Bu loyihada cell authority
bermaydi; u faqat qayerda qayta sinash yoki abstain qilishni boshqaradi.

### 2.1 Contextual Instrument Council v2 execution wiring

2026-09-11 integratsiyasi instrument zanjirini execution darajasida
qat'iylashtirdi:

- Laravel assignment protokoli va Python attestation protokoli bir xil
  `lab_instrument_research_assignment_v2` contractga keltirildi; cross-language
  regression ularning yana ajralishiga yo'l qo'ymaydi.
- Python immutable entry-time trade ledgerdan
  `regime|volatility|session|direction` slice chiqaradi. Drawdown va execution
  cost ham aynan shu slice ichida hisoblanadi.
- `MutationResponseMap` endi robustness va instrument trace'ni compactionda
  yo'qotmaydi. Settlement faqat candidate hamda exact frozen controlning bir
  xil context key'ida ikkala arm ham powered bo'lsa local posterior yozadi.
- Posterior identity contextga qo'shimcha ravishda strategy family va direction
  bilan seal qilinadi. Family prior Research Inbox'da experiment taklif qilishi
  mumkin, lekin mutation/inheritance authority bermaydi.
- Router exact bundle va bundle ichidagi barcha component posteriorlarini
  baholaydi. Forbidden component veto beradi; missing ablation uncertainty
  sifatida ko'rinadi va hech qachon soxta component creditga aylanmaydi.
- Hierarchical backoff
  `regime+volatility+session+direction -> regime+volatility+direction ->
  regime+direction -> regime` tartibida shrinkage bilan research prior beradi.
  Faqat exact, canonical-confirmed bundle positive conservative lower bound
  bilan paper selection ochishi mumkin; aks holda explicit `ABSTAIN`.
- `InstrumentPosteriorAuthorityService` status labelni dalildan qayta hosil
  qiladi: valid local context, family seal, evidence identity coverage,
  independent windows, kamida ikki positive observation va non-target safety
  birga bo'lmasa eski `confirmed/forbidden` row `status_only_quarantined` bo'ladi.
- XAUUSD H4/H1/M15/M5 chaqiruvlari alohida instrument tizimlar emas. H1 storage
  coordinate ham yagona M15 instrument-decision layerga canonical route qilinadi.

### 3. Learning → parent → evolution bridge

- Canonical memory mutationdan keyin moslashtirilmaydi. Avval compatible
  cartridge olinadi, keyin aynan uning gene/value intervention’i qurilib intent
  agent persist bo‘lishidan oldin seal qilinadi.
- Family prior faqat experiment taklif qiladi; inheritance bermaydi.
- Parentless root yolg‘on genetic parent olmaydi. Verified causal control
  alohida baseline sifatida incubatorga beriladi.
- Descendant settlement production path’dan `recordDescendantTrial()`ni
  chaqiradi.
- Authority refresh avvalgi passportni saqlaydi.
- Kamida ikki independently improving descendantdan keyingina model
  `eligible_parent` bo‘lishi mumkin.
- Parent credit autonomous, mentored va ablated counterfactuallar hamda exact
  control-relative outcome talab qiladi.

Bu exploit/explore ajratilishi Population Based Training g‘oyasiga mos, lekin
loyihada exploit faqat evidence-authorized parent uchun ochiladi:
[Population Based Training](https://arxiv.org/abs/1711.09846). Noaniqlikda
baseline’ga qaytish safety kontrakti SPIBB’dagi baseline bootstrapping
tamoyiliga mos: [SPIBB](https://proceedings.mlr.press/v97/laroche19a.html).

### 4. False-green monitorni yopish

Truth projection har bosqichni real dalildan alohida hisoblaydi:

```text
hypothesis
controlled_experiment
positive_economic_signal
confirmed_instrument
confirmed_contextual_bundle
strong_parent
rewarded_evolution
```

Keyingi artifact oldingi yetishmayotgan bosqichni yashira olmaydi.
`lesson.status=confirmed`, eski beneficial label, invocation soni yoki parent
row’ning o‘zi chain’ni yashil qilmaydi.

### 5. Bitta durable Research Loop owner

- XAUUSD yangi ishini endi faqat `ResearchLoopArbiterService` tanlaydi.
- Lifecycle, causal Director, canonical learning pair, MTF research, portfolio,
  drift va targeted handoff alohida schedule writer emas; arbiter ulardan har
  tickda faqat bittasiga bounded execution authority beradi.
- Har qaror immutable evidence snapshot, decision hash va bir daqiqalik
  idempotency identity bilan `research_loop_decisions`ga yoziladi.
- Redis lock mavjud bo‘lmasa state-changing tick fail-closed; dry-run esa
  runtime o‘chiq paytda ham diagnostika bera oladi.
- STOP yangi ishni rad etadi. Faqat allaqachon admitted active generation yoki
  yarim qurilgan `technical_quarantine` constructor drain qilinadi; 20/20
  terminal quarantine active deb noto‘g‘ri talqin qilinmaydi.
- Production schedule renderida XAUUSD uchun aynan bitta har-daqiqalik selector
  mavjud. Qolgan `lab-generation` schedule’lari faqat EURUSD/GBPUSD shadow
  laboratoriyalariga tegishli.

### 6. Immutable 20-seat generation contract

- Barcha plannerlar tugab, actual agent soni final plan soniga teng bo‘lgach
  plan bir marta seal qilinadi.
- Contract to‘liq canonical plan hash, har seatning nested spec hash’i,
  data/execution identity, population va causal/MTF talablarini saqlaydi.
- Yarim constructor explicit unsealed holatda qoladi va dispatchdan o‘tmaydi.
- Dispatch oldidan plan, seat identity, requirements va contractning o‘zi
  qayta hash qilinadi. Nested instrument/control o‘zgarishi ham, contract-only
  rewrite ham fail-closed.
- Contract maydoni umuman yo‘q historical generationlar compatibility uchun
  o‘qiladi; yangi constructor yozgan explicit `null` historical deb
  grandfather qilinmaydi.

### 7. Terminal receipt va executable next-work closure

- Har terminal experiment uchun qat’iy XOR ishlaydi: aynan bitta owned durable
  next work yoki bitta explicit terminal reason.
- Work item owner, executor, executable flag, retry condition, retry budget,
  dependency va same-evidence replay taqiqini olib yuradi.
- Arbiter workni SQL darajasida owner bo‘yicha claim qiladi; boshqa ownerning
  yuqori-priority rowlari uning itemini scan limit ortida yashira olmaydi.
- Lease token va fence eski workerning yangi attemptni settle qilishini
  to‘sadi. Consumer executiondan oldin autonomy, owner, dependency va joriy
  fence’ni qayta tekshiradi.
- Haqiqiy yangi independent window/compiler mavjud bo‘lmagan continuationlar
  fake replication sifatida bajarilmaydi; aniq retry sharti bilan `blocked`
  qoladi.
- Closure truth missing/ambiguous receipt, owner/retry gap, expired lease,
  duplicate open work va version-eligible projection debtni ko‘rsatadi.
  Historical completed outboxlar bajarib bo‘lmaydigan soxta reconciliation
  qarziga aylantirilmaydi.

### 8. Contextual Causal Trait Capsule v2

Evolution endi yalang'och model yoki gene'ni emas, quyidagi atomik paketni
ko'chiradi:

```text
executable trait + exact instrument bundle + activation predicate
```

- Capsule canonical context, baseline model, data/execution hash, instrument
  bundle, intervention value, replication receipts va non-target effectlarni
  bitta durable hash ostida seal qiladi.
- JSON bazasidagi `1.0 -> 1` round-trip sealni buzmasligi uchun Laravel va
  Python bir xil `numeric_canonical_json_v1` hash protocolidan foydalanadi.
  Eski yoki noto'g'ri hashli v2 assignment qayta seal qilinadi; tayyor row deb
  qabul qilinmaydi.
- Cartridge identity receipt/source ID bilan parchalanmaydi. Bir xil treatment,
  bundle va activation cell bo'yicha mustaqil replaylar bitta cartridge'ga
  yig'iladi; provenance esa barcha alohida source'larni saqlaydi.
- `component_confirmed` uchun uchta positive label yetmaydi: kamida uchta
  mustaqil window, uchta consumed instrument attestation va bir xil powered
  context slice talab qilinadi. Yetishmasa skill
  `skill_confirmed_capsule_pending` bo'lib qoladi.
- Incubator va descendant 20-seat generation ichida capsule hash, activation
  context va bundle hashni har armga ko'chiradi. Child faqat global replayda
  emas, aynan activation cell ichida ham control va matched trait-ablationdan
  ustun bo'lsa reproductive credit oladi.
- Confirmed mentor marker endi `eligible_parent`ni doimiy veto qilmaydi. Ammo
  selector capsule, exact/broad routing compatibility va ayni contextdagi ikki
  positive descendant trust bo'lmasa uni rad etadi.
- Broad regime/volatility cell parentni tanlashi mumkin, lekin session,
  transition, liquidity va direction predicate'lari child contractida saqlanib,
  `activate_only_when_full_predicate_matches_else_abstain` policy bilan qoladi.
  Explicit qarama-qarshi session/regime esa darhol abstain.
- Authority cohortlar oddiy candidate pair reservationiga noto'g'ri urilmaydi;
  exact inherited instrument bundle replay assignmentiga uzatiladi. Bir nechta
  capsule'ni isbotsiz aralashtirish factorial crossover proofgacha bloklanadi.
- Composition P&L faqat explicit paired component ablationga fanout qilinadi;
  aggregate composition natijasi strategy/tactic/risk/managementning barchasiga
  to'liq kredit sifatida yozilmaydi. Instrument value esa powered decision-time
  context slice bo'yicha saqlanadi, global mixed row prior-only qoladi.

Real authority acceptance zanjiri:

```text
3 independent context-attested replications
-> sealed contextual capsule
-> 5-arm incubator
-> 2 child vs matched trait-ablation challenges
-> 2 positive exact-context trust outcomes
-> eligible_parent
-> context-compatible child inheritance
```

Bu wiring profitable edge'ni uydirmaydi. U edge bo'lsa aynan qayerda va qaysi
instrument bundle bilan ishlaganini ajratadi; dalil bo'lmasa `pending` yoki
`abstain` qiladi.

## Verification

- Laravel full suite: **853 passed, 5,488 assertions**.
- Python full suite: **194 passed**.
- Hash-protocol durability hardeningdan keyingi focused regression: Laravel
  **28 passed, 225 assertions**; Python instrument contract **5 passed**.
- `git diff --check`: xato yo‘q; faqat mavjud Windows LF/CRLF ogohlantirishlari.
- Exact-pair rejection, runtime attestation, idempotent individual/bundle
  settlement, context isolation, current-cohort identity, bounded MTF dispatch,
  descendant passport, single-writer ownership, closure XOR/fencing,
  owner-starvation, partial-constructor drain, immutable contract tamper va
  end-to-end truth chain uchun maxsus regression testlar mavjud.
- `problems.txt`: tekshirilgan uchta P1 band yechilgach olib tashlandi.

## Hozirgi production evidence — soxta optimizmsiz

- Autonomy: `STOPPED`; yangi generation yoki MTF research dispatch qilinmadi.
- PM2 project processlari va Redis hozir ishlamayapti; shu sabab scheduler,
  constructor va queue truth `UNKNOWN` ko‘rinadi.
- Latest generation: G215, 20/20, `technical_quarantine`.
- Research closure: 17 receipt; missing/ambiguous closure, projection debt,
  ready/leased orphan va ownerless work = 0.
- Arbiter dry-run: `DEFER_AUTONOMY_STOPPED`; command/queue = `null`.
- Current MTF cohort: candidate #437, canonical H1-storage organism, yangi full
  payload hash mavjud, aynan shu cohort uchun research run = 0.
- Historical MTF reportda 19 recent run bor; ular current cohort evidence emas.
- Learning truth’da hypothesis, controlled experiment va positive economic
  signal dalili bor. Birinchi yetishmayotgan bosqich: `confirmed_instrument`.
- Confirmed instrument = 0 bo‘lgani uchun confirmed bundle → eligible parent →
  rewarded evolution hali real iqtisodiy dalil sifatida ochilmagan.

2026-09-11 read-only production truth qo'shimchasi:

- Instrument runtime funnel: 168 attested invocation, 56 distinct agent va 28
  settled causal invocation. 8 helped, 4 harmed, 16 neutral; 69 paired control
  kutmoqda, 71 support-only consumption.
- Historical instrument value: 28 evidence va 10 declared posterior. Canonical
  qayta baholashda 9 provisional, eski bitta `forbidden` esa yetarli authority
  seal bo'lmagani uchun `status_only_quarantined`; canonical confirmed/forbidden
  instrument va confirmed exact bundle = 0.
- Yangi decision-time context-slice evidence hali 0, chunki autonomy STOPPED va
  yangi v2 replay ishga tushirilmagan. Router decision ham 0; bu kod wiring
  testlanganini, ammo production economic outcome hali yaratilmaganini aniq
  ajratadi.

Demak engineering wiring tayyor, lekin real compounding hali ishga tushdi deb
aytish mumkin emas. Runtime operator tomonidan ko‘tarilib, `ai:start` berilgach
state machine aynan birinchi missing evidence’dan davom etadi; safety gate’lar
yumshatilmaydi.

## Operator contract

Runtime bir marta hidden supervisor bilan ko‘tariladi; u Redis, AI service,
scheduler va barcha queue lane’larni oynasiz saqlaydi:

```powershell
wscript.exe .\backend-laravel\scripts\run-laravel-workers-hidden.vbs
```

Shundan keyin kuchsiz monitor uchun faqat uchta boshqaruv kerak:

```powershell
cd .\backend-laravel
php artisan ai:start --controller=lightweight --json
php artisan ai:status --controller=lightweight --json
php artisan ai:stop --json
```

`ai:stop` yangi ishni bloklaydi, admitted ishni drain qiladi va monitoringni
saqlaydi. U Redis/scheduler processlarini o‘chirmaydi. Strong profil xuddi shu
governed engine’ni boshqaradi, faqat chuqur read-only audit qo‘shadi.

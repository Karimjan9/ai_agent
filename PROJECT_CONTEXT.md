---
aliases:
  - NeuroTrader Lab Context
  - Project Memory
tags:
  - neurotrader
  - project-context
  - architecture
  - roadmap
status: active
updated: 2026-06-25
---

# NeuroTrader Lab Project Context

> [!IMPORTANT]
> **2026-07-12 priority override:** keyingi ishlab chiqishning asosiy fokusi AI Learning va pair-owned AI Laboratory hisoblanadi. Agentlar XAUUSD, EURUSD va GBPUSD uchun alohida population, rolling walk-forward, Monte Carlo, paper evidence, champion–challenger lifecycle hamda mutation memory orqali real avloddan-avlodga yaxshilanishini isbotlashi kerak. Knowledge Graph, AI Civilization, Theory Generation va boshqa katta modullar saqlanadi, lekin ikkinchi darajali cadence’da ishlaydi. Tezkor, amaldagi navigatsiya va gate qoidalari `docs/project-memory/ai-learning-laboratory.md` hamda `docs/project-memory/project-index.json`da.

> **Canonical navigation:** current module and operations documentation is indexed in `docs/CANONICAL_INDEX.md`. This file retains historical implementation context; its recorded test totals are not a live test result.

> [!IMPORTANT]
> Bu fayl loyihaning **yagona asosiy xotirasi va Source of Truth** hisoblanadi.
> Har bir Codex/AI agent ish boshlashdan oldin shu faylni to'liq o'qishi, ish tugagach esa real o'zgarishlar, qarorlar, testlar va keyingi qadamlarni shu faylga kiritishi shart.

> [!IMPORTANT]
> **2026-07-27 execution policy:** yangi populationlar faqat gate-aware generation budget bilan yaratiladi: 8 gate-targeted, 4 risk/exit, 3 architecture, 3 robust crossover, 2 random explorer. Har agent uchun `trade_deficit`, `pf_deficit`, `rolling_deficit`, `drawdown_excess`, `ruin_excess` yoziladi va mutation memory parentga nisbatan qaysi gate yaxshilangan/yomonlashganini saqlaydi. Uch completed generation davomida gate progress bo'lmagan family yoki architecture vaqtincha rejalashtirilmaydi. Promotion evidence uchun `MARKET_DATA_CANONICAL_PROVIDER=twelve`; Dukascopy secondary archive/audit evidence. Live trading o'chirilgan, kill switch engaged va human approval yo'q — buni o'zgartirish ushbu roadmap scope'iga kirmaydi.

## Status Belgilari

- `[IMPLEMENTED]` — kod, migration, UI va testlarda real mavjud.
- `[DECIDED]` — arxitektura qarori qabul qilingan, lekin hali to'liq kodlanmagan.
- `[PLANNED]` — roadmap rejasi; implementatsiya boshlanmagan.
- `[EXPERIMENTAL]` — tekshirilayotgan, yakuniy qaror emas.
- `[DEPRECATED]` — tarix uchun saqlanadi, yangi flow'da ishlatilmaydi.

Agent hech qachon `[DECIDED]` yoki `[PLANNED]` bandni implementatsiya qilingan deb yozmasligi kerak.

Bu fayl Codex agent uchun loyiha xotirasi sifatida ishlatiladi.
Maqsad: har safar loyihani boshidan titkilamasdan, asosiy arxitektura,
hozirgi holat, muhim fayllar va kelishuvlarni shu joydan tez tushunish.

Har yangi etap yoki muhim o'zgarishdan keyin shu fayl ham yangilanishi kerak.

## Hozirgi Holat

Kod bazasi hozir **canonical 28-etap - Reality Verification Engine / Reality Center**gacha implementatsiya qilingan, lekin CTO qarori bo'yicha yangi konseptual modullar vaqtincha **muzlatildi**. 20-etap Future Simulation, 21-etap Meta Intelligence, 22-etap AI Civilization, 23-etap Quant Laws, 24-etap Causal Intelligence va 25-etap Theory Lab buzilmagan; Reality Verification knowledge/law/theory/unified model'larni model realitydan ajratib, reality score, certification, cemetery va skeptic report ledgerlariga yozadi. Oldingi legacy 15-etap Strategy DNA & Personality Engine saqlangan va AI Scientist, Agent Mind hamda Evolution Genome uchun qo'shimcha evidence/personality foundation sifatida ishlaydi.

Strategik roadmap qayta belgilandi: endi asosiy prioritet **PHASE 2 - Paper Trading Deployment**. Live market data, signal generation, paper trading, position lifecycle, signal outcome tracking va daily paper scientist feedback bo'lmaguncha Multi-Agent Competition Arena yoki boshqa yangi "wow" modullar kodlanmaydi. Mavjud Strategy DNA funksiyasi o'chirilmaydi; u Agent Mind va 17-etap Evolution Genome uchun tayyor foundation sifatida qayta ishlatiladi.

```text
IMPLEMENTED: 2-14 + legacy Strategy DNA + canonical 15 AI Trading Scientist + canonical 16 Agent Mind + canonical 17 Evolution Genome + canonical 18 Market Reality + canonical 19 Knowledge Graph + canonical 20 Future Simulation + canonical 21 Meta Intelligence + canonical 22 AI Civilization + canonical 23 Quant Laws + canonical 24 Causal Intelligence + canonical 25 Autonomous Theory Generation + canonical 28 Reality Verification
DECIDED:     Roadmap freeze; next production work is Phase 2 Paper Trading Deployment, not more conceptual modules
FOUNDATION:  Phase 2 Event Store + Agent Health Center + MT5 Market Health + Telegram alert hook + Auto Recovery hook + System Logs + Signal Market Snapshot + Agent Memory matching + Market Profiles implemented
NOT DONE:    Live Market Infrastructure, Signal Generation Engine, Paper Trading Engine, Position Lifecycle, Signal Outcome Tracker, Daily Paper Scientist Report, real outcome calibration
SERVER:      Dashboard/backtest lab sifatida ishlaydi; 24/7 paper trading research server sifatida hali tayyor emas
```

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
- 13-etap: Walk Forward Validation Engine, train/validation/forward split, robustness score, overfit detection, active status uchun robustness sharti.
- 14-etap: Monte Carlo Risk Simulation Engine, trade sequence risk, worst/avg/best scenario, risk of ruin, MC drawdown va survival UI.
- legacy 15-etap: Strategy DNA & Personality Engine, aggression/trend/range/volatility/adaptability/recovery/survival profili, DNA Laboratory va DNA-aware evolution.
- canonical 15-etap: AI Trading Scientist Engine, trade/signal hypothesis, belief update, scientist journal, counterfactual analysis va knowledge extraction.
- canonical 16-etap: Agent Consciousness / Metacognition Engine, psychology snapshot, self reflection, memory, internal debate, reputation va evolution triggers.
- canonical 17-etap: Evolution Genome Engine, immutable strategy genome, genes, lineage DAG, mutation diff, crossover candidate, fitness evaluation, selection/extinction va genome discoveries.
- canonical 18-etap: Market Reality / Intelligence Engine, OHLCV'dan market state snapshots, market genome, species library, memory, similarity scanner, discoveries va Strategy x Species performance.
- canonical 19-etap: Universal Trading Knowledge Graph, strategy/agent/market/genome/hypothesis/discovery/failure evidence'ni node-edge-claim graphga bog'lash, mining, research assistant va failure intelligence.
- canonical 20-etap: Future Simulation & Planning Engine, Market Genome + Knowledge Graph priorlardan scenario tree, timeline forecast, strategy survival, future stress test, planning bias va future discoveries yaratish.
- canonical 21-etap: Meta Intelligence Engine, knowledge audit, belief decay, contradiction detector, unknown zone detector, blind spot finder, knowledge health score va AI self critic audit trail yaratish.
- canonical 22-etap: Artificial Quant Civilization / AI Civilization Engine, role agents, non-transferable research credits, council votes, civilization goals, collective memory va institutional knowledge yaratish.
- canonical 23-etap: Universal Quant Laws Discovery Engine, law candidates, multi-evidence validation, promoted quant laws, law graph, law conflicts, law evolution events va universal driver ranking yaratish.
- canonical 24-etap: Causal Intelligence Engine, causal graph, causal effect estimates, counterfactual laboratory, interventions, experiments, root causes va discovery quality score yaratish.
- canonical 25-etap: Autonomous Theory Generation Engine, laws + causes + root causes'dan higher-order theories, competing theory battles, theory predictions, evolution events va unified quant models yaratish.
- canonical 28-etap: Reality Verification Engine, knowledge/law/theory/unified model uchun reality score, paper/live validation experiments, certified knowledge, knowledge cemetery va skeptic reports yaratish.

Phase 2 Paper Trading Deployment uchun keyingi production roadmap:

- Foundation: Event Store (`system_events`), Agent Health Center, MT5 Market Health (`market_provider_health`), Telegram alert hook, Auto Recovery hook, `system_logs`, Signal-time Market Snapshot va Agent Memory matching qo'shildi.
- Foundation: Instrument Intelligence / Market Profiles qo'shildi; boshlang'ich Reality Phase konfiguratsiyasi XAUUSD + EURUSD, M15 + H1, Trend + Breakout + Mean Reversion agentlari.
- Phase 2 / 21-etap: Live Market Infrastructure, provider interface, Binance/Bybit/MT5 adapterlari, live candle storage va candle update scheduler.
- Phase 2 / 22-etap: Signal Generation Engine, new candle -> strategy run -> `live_signals` DB journal.
- Phase 2 / 23-etap: Paper Trading Engine, paper accounts, paper orders, paper positions va paper trades.
- Phase 2 / 24-etap: Position Lifecycle Engine, entry, TP, SL, close, duration, MAE va MFE tracking.
- Phase 2 / 25-etap: Signal Outcome Tracker, signal hypothesis confirmed/failed/inconclusive lifecycle.
- Phase 2 / 26-etap: Daily Paper Scientist Report, daily signal/position/hypothesis report.
- Phase 2 / 27-etap: Reality Feedback Loop, backtest vs paper delta, overfit detection va reality score calibration.
- Deferred / 28-etap: Multi-Agent Competition Arena faqat 60-90 kun paper data yig'ilgandan keyin. Minimum target: 1000+ signals, 200+ paper trades, 100+ hypothesis outcomes.

Oxirgi test holati:

```text
Python: python -m unittest discover -s tests -> 8 passed
Python: python -m compileall app -> OK
Laravel: php artisan test --filter=MarketHealthEngineTest -> 3 passed, 13 assertions
Laravel: php artisan test -> 75 passed, 454 assertions
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
app/Http/Controllers/MarketProfilesController.php
app/Http/Controllers/AiScientistController.php
app/Http/Controllers/AgentMindController.php
app/Http/Controllers/EvolutionLabController.php
app/Http/Controllers/MarketIntelligenceController.php
app/Http/Controllers/KnowledgeCenterController.php
app/Http/Controllers/FutureIntelligenceController.php
app/Http/Controllers/MetaIntelligenceController.php
app/Http/Controllers/AiCivilizationController.php
app/Http/Controllers/QuantLawsController.php
app/Http/Controllers/CausalIntelligenceController.php
app/Http/Controllers/TheoryLabController.php
app/Http/Controllers/RealityCenterController.php
app/Http/Controllers/AgentHealthController.php
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
- `MarketProfilesController`: Instrument Intelligence dashboard; XAUUSD/EURUSD brain, session, strategy, current regime va profile comparison.
- `AiScientistController`: AI Scientist dashboard; hypotheses, beliefs, journals, knowledge facts va counterfactual runs.
- `AgentMindController`: Agent Mind dashboard; psychology, stress, trust, memory, reputation, internal debate va evolution triggers.
- `EvolutionLabController`: Evolution Lab dashboard; genome tree, mutations, crossovers, extinct agents, discoveries va evolution efficiency.
- `MarketIntelligenceController`: Market Intelligence dashboard; current market genome, species library, memories, similarity scanner, discoveries va Strategy x Species performance.
- `KnowledgeCenterController`: Knowledge Center dashboard; graph nodes/edges, claims, research assistant query, failure analysis, pattern explorer va knowledge timeline.
- `FutureIntelligenceController`: Future Intelligence dashboard; future map, probability tree, scenario lab, timeline forecast, survival forecast, stress tests va market futures discoveries.
- `MetaIntelligenceController`: Meta Intelligence dashboard; knowledge audits, belief decay, contradictions, unknown zones, blind spots, health timeline va self critiques.
- `AiCivilizationController`: AI Civilization dashboard; agent society, council decisions/votes, internal economy, civilization goals, collective memory va institutional knowledge.
- `QuantLawsController`: Quant Laws dashboard; law candidates, universal laws library, law graph, conflicts, evidence va universal driver ranking.
- `CausalIntelligenceController`: Causal Intelligence dashboard; causal graph, root causes, counterfactual laboratory, interventions, experiments va discovery quality.
- `TheoryLabController`: Theory Lab dashboard; autonomous theory generation, emerging/dominant theories, theory battles, theory predictions, theory evolution va unified models.
- `RealityCenterController`: Reality Center dashboard; reality scores, certified knowledge, failed knowledge, knowledge cemetery, reality validation experiments va skeptic reports.
- `AgentHealthController`: Phase 2 foundation dashboard; service health, event store, signal market snapshots va agent memory matches.

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
app/Services/TradingScientistService.php
app/Services/AgentMindService.php
app/Services/EvolutionGenomeService.php
app/Services/MarketRealityService.php
app/Services/UniversalKnowledgeGraphService.php
app/Services/FutureSimulationService.php
app/Services/MetaIntelligenceService.php
app/Services/QuantCivilizationService.php
app/Services/UniversalQuantLawsService.php
app/Services/CausalIntelligenceService.php
app/Services/AutonomousTheoryGenerationService.php
app/Services/RealityVerificationService.php
app/Services/PhaseTwoFoundationService.php
app/Services/InstrumentIntelligenceService.php
app/Services/MarketHealthService.php
app/Services/TelegramAlertService.php
app/Services/SystemLogService.php
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
- `TradingScientistService`: training session tugaganda StrategyScore va raw trade natijalaridan agent hypotheses, belief updates, scientist journal, counterfactual runs va knowledge facts yaratadi.
- `AgentMindService`: AI Scientist evidence, StrategyScore, DNA va risk metriclardan agent psychology snapshot, self reflection, memory, reputation, internal debate va evolution triggerlar yaratadi.
- `EvolutionGenomeService`: StrategyScore/ModelVersion/Proposal data'dan immutable genome, gene rows, fitness evaluation, lineage, mutation diff, crossover candidate, selection/extinction va genome discoveries yaratadi.
- `MarketRealityService`: OHLCV candles'dan trend, panic, compression, expansion, momentum va liquidity_proxy featurelarini chiqarib market state snapshots, Market Genome, Market Species, memories, similarity matches, discoveries va Strategy x Species performance yaratadi.
- `UniversalKnowledgeGraphService`: training session, StrategyScore, Market Species, Strategy Genome, hypotheses, beliefs, discoveries va failure evidence'ni node/edge/claim graphga bog'laydi; `knowledge:mine` va research assistant query javoblarini yaratadi.
- `FutureSimulationService`: latest Market Genome va Knowledge Graph priorsdan future scenarios, probability tree, timeline forecast, strategy survival forecast, stress tests, planning bias va future discoveries yaratadi.
- `MetaIntelligenceService`: Knowledge Graph claimlari va AgentBelief'larni non-destructive audit qiladi; audited confidence, belief decay, contradictions, unknown zones, blind spots, knowledge health score va self critique yaratadi.
- `QuantCivilizationService`: role agents va strategy members yaratadi, research credits taqsimlaydi, institutional knowledge'ni preserve qiladi, civilization goals hisoblaydi va council decision/vote ledger yozadi.
- `UniversalQuantLawsService`: Strategy DNA, StrategyScore, KnowledgeClaim va institutional evidence'dan law candidates yaratadi, universality/confidence hisoblaydi, quant laws promote qiladi, law graph/conflicts/evolution events va top driver ranking yozadi.
- `CausalIntelligenceService`: Quant Laws relationlarini causal candidate sifatida baholaydi; causal nodes/edges, effect estimates, counterfactuals, interventions, experiments, root causes va discovery quality score yaratadi.
- `AutonomousTheoryGenerationService`: Quant Laws, CausalEdge va CausalRootCause evidence'larini birlashtirib higher-order quant theories, theory components, theory battles, predictions, evolution events va unified quant models yaratadi.
- `RealityVerificationService`: KnowledgeClaim, QuantLaw, QuantTheory va UnifiedQuantModel source'larini operational/paper evidence bilan tekshiradi; reality score, validation event, experiment, certification, cemetery va skeptic report yaratadi.
- `PhaseTwoFoundationService`: Event Store, Agent Health checks, signal-time market snapshot capture, experience memory creation va agent memory similarity matchingni boshqaradi.
- `InstrumentIntelligenceService`: MarketSymbol, StrategyScore, SignalMarketSnapshot va MarketStateSnapshot data'dan symbol/timeframe profile, best/worst session, best/worst strategy, news sensitivity, volatility profile va trend cleanliness hisoblaydi.
- `MarketHealthService`: MT5 market feed freshness'ni XAUUSD/EURUSD M15/H1 bo'yicha tekshiradi, `market_provider_health` va `service_health_checks`ni yangilaydi, provider lost/recovered eventlari, Telegram alert va optional recovery hookni boshqaradi.
- `TelegramAlertService`: Telegram Bot API orqali production alert yuboradi; default o'chiq, `.env` orqali yoqiladi.
- `SystemLogService`: `system_logs` jadvaliga MT5/provider/recovery/Telegram kabi operatsion loglarni yozadi.

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
app/Models/AgentHypothesis.php
app/Models/AgentBelief.php
app/Models/ScientistJournal.php
app/Models/KnowledgeFact.php
app/Models/CounterfactualRun.php
app/Models/AgentPsychologySnapshot.php
app/Models/AgentSelfReflection.php
app/Models/AgentMemory.php
app/Models/InternalDebate.php
app/Models/DebateArgument.php
app/Models/AgentReputation.php
app/Models/EvolutionTrigger.php
app/Models/StrategyGenome.php
app/Models/GenomeGene.php
app/Models/GenomeLineage.php
app/Models/GenomeMutation.php
app/Models/GenomeCrossover.php
app/Models/EvolutionGeneration.php
app/Models/FitnessEvaluation.php
app/Models/SelectionEvent.php
app/Models/ExtinctionEvent.php
app/Models/GenomeDiscovery.php
app/Models/MarketSpecies.php
app/Models/MarketSpeciesVersion.php
app/Models/MarketStateSnapshot.php
app/Models/MarketStateProbability.php
app/Models/MarketGenome.php
app/Models/MarketMemory.php
app/Models/MarketSimilarityMatch.php
app/Models/MarketDiscovery.php
app/Models/StrategySpeciesPerformance.php
app/Models/KnowledgeGraphNode.php
app/Models/KnowledgeGraphEdge.php
app/Models/KnowledgeClaim.php
app/Models/KnowledgeEvidence.php
app/Models/KnowledgeQuery.php
app/Models/KnowledgeMiningRun.php
app/Models/FutureSimulationRun.php
app/Models/FutureScenario.php
app/Models/FutureProbabilityNode.php
app/Models/FutureTimelineForecast.php
app/Models/StrategySurvivalForecast.php
app/Models/FutureStressTest.php
app/Models/FutureDiscovery.php
app/Models/MetaAuditRun.php
app/Models/KnowledgeAudit.php
app/Models/BeliefDecayEvent.php
app/Models/KnowledgeContradiction.php
app/Models/UnknownZone.php
app/Models/BlindSpot.php
app/Models/KnowledgeHealthScore.php
app/Models/SelfCritique.php
app/Models/CivilizationAgent.php
app/Models/CivilizationCreditEvent.php
app/Models/CouncilDecision.php
app/Models/CouncilVote.php
app/Models/CivilizationMemory.php
app/Models/InstitutionalKnowledge.php
app/Models/CivilizationGoal.php
app/Models/QuantLawDiscoveryRun.php
app/Models/QuantLawCandidate.php
app/Models/QuantLaw.php
app/Models/QuantLawEvidence.php
app/Models/QuantLawGraphEdge.php
app/Models/QuantLawConflict.php
app/Models/QuantLawEvolutionEvent.php
app/Models/UniversalDriverRanking.php
app/Models/CausalDiscoveryRun.php
app/Models/CausalNode.php
app/Models/CausalEdge.php
app/Models/CausalEffectEstimate.php
app/Models/CausalCounterfactual.php
app/Models/CausalIntervention.php
app/Models/CausalExperiment.php
app/Models/CausalRootCause.php
app/Models/DiscoveryQualityScore.php
app/Models/TheoryGenerationRun.php
app/Models/QuantTheory.php
app/Models/TheoryComponent.php
app/Models/TheoryBattle.php
app/Models/TheoryPrediction.php
app/Models/TheoryEvolutionEvent.php
app/Models/UnifiedQuantModel.php
app/Models/RealityVerificationRun.php
app/Models/RealityScore.php
app/Models/RealityValidationEvent.php
app/Models/RealityExperiment.php
app/Models/KnowledgeCemeteryEntry.php
app/Models/SkepticReport.php
app/Models/CertifiedKnowledgeItem.php
app/Models/SystemEvent.php
app/Models/ServiceHealthCheck.php
app/Models/SignalMarketSnapshot.php
app/Models/AgentMemoryMatch.php
app/Models/SymbolProfile.php
app/Models/MarketProviderHealth.php
app/Models/SystemLog.php
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
- `AgentHypothesis`: trade/signal paytidagi decision, confidence, market regime, measurable target va evaluation status.
- `AgentBelief`: har bir strategy uchun trend following, RSI confirmation, breakout follow-through, survival/adaptability kabi belief score va sample history.
- `ScientistJournal`: training session yakunidagi ilmiy jurnal, observations, failed hypotheses va conclusion.
- `KnowledgeFact`: repeated evidence'dan chiqqan provisional/validated trading knowledge facts.
- `CounterfactualRun`: failed hypothesis uchun alternative reality analysis, intervention, estimated delta va verdict.
- `AgentPsychologySnapshot`: strategy ichki holati; confidence, stress, trust, adaptation pressure, stability, learning rate va state.
- `AgentSelfReflection`: session yakunidagi agentning o'z performance holati bo'yicha reflection va suggested action.
- `AgentMemory`: agentning context-aware xotirasi; regime/volatility/species/outcome bo'yicha eslab qolingan failure, success yoki mismatch lesson.
- `InternalDebate`: session-level BUY/NO/WAIT consensus va debate context.
- `DebateArgument`: har strategy/agentning internal debate ichidagi argumenti.
- `AgentReputation`: ko'p sessionlik reputation, trust, calibration, stability va survival score.
- `EvolutionTrigger`: stress yoki adaptation pressure threshold oshganda evolution review uchun signal.
- `StrategyGenome`: immutable strategy genome snapshot, family/version/generation, phenotype, fitness va evolution efficiency.
- `GenomeGene`: genome ichidagi har bir parameter/gene va observed fitness.
- `GenomeLineage`: parent-child lineage DAG relation.
- `GenomeMutation`: old/new gene diff va proposal sababli mutation tarixi.
- `GenomeCrossover`: ikki parent genome'dan proposed child strategy candidate.
- `EvolutionGeneration`: family/generation bo'yicha aggregate fitness.
- `FitnessEvaluation`: genome fitness score va component breakdown.
- `SelectionEvent`: survival-of-the-fittest selection snapshot.
- `ExtinctionEvent`: archived/extinct genome sababi va evidence.
- `GenomeDiscovery`: gene range va high-fitness pattern discovery.
- `MarketSpecies`: OHLCV-derived market state taxonomy; Slow Bull Expansion, Volatile Fake Breakout, Liquidity Vacuum kabi specieslarni saqlaydi.
- `MarketSpeciesVersion`: species signature va sample size versiyalari.
- `MarketStateSnapshot`: har candle/timeframe uchun market_state, liquidity_state, momentum_state, structure_state va feature evidence.
- `MarketStateProbability`: state alternatives/probability distribution.
- `MarketGenome`: market vectori; trend, panic, compression, momentum, liquidity_proxy va genome_hash.
- `MarketMemory`: panic/trap/compression kabi muhim market holatlari bo'yicha lesson.
- `MarketSimilarityMatch`: current market genome bilan oldingi genome similarity matchlari.
- `MarketDiscovery`: takroriy market state patternlaridan chiqqan discovery.
- `StrategySpeciesPerformance`: strategy natijasini market species kontekstida bog'laydi.
- `KnowledgeGraphNode`: strategy, market species, genome, parameter, metric, hypothesis, belief, discovery va failure cause node'lari.
- `KnowledgeGraphEdge`: node'lar orasidagi typed relation; confidence, evidence_count, polarity va metadata saqlaydi.
- `KnowledgeClaim`: graphdan chiqarilgan institutional knowledge claim; confidence, evidence, status va scope bilan.
- `KnowledgeEvidence`: claim/node/edge uchun provenance evidence.
- `KnowledgeQuery`: Research Assistant savol-javoblari va matched graph ids.
- `KnowledgeMiningRun`: `knowledge:mine` run tarixi va nechta node/edge/claim yaratilgani.
- `FutureSimulationRun`: latest market genome asosidagi probabilistic future simulation run.
- `FutureScenario`: Bull Continuation, Range Reversion, Panic Event, Fake Breakout, Trend Reversal kabi aggregated scenario branch.
- `FutureProbabilityNode`: probability tree root/branch node'lari.
- `FutureTimelineForecast`: 10/25/50 candle horizon bo'yicha bull/range/panic/reversal ehtimollari.
- `StrategySurvivalForecast`: har strategy ehtimoliy kelajaklarda survival_probability, future_confidence va recommended_action.
- `FutureStressTest`: volatility x2, liquidity -50%, trend reversal kabi future stress test natijalari.
- `FutureDiscovery`: simulationdan chiqqan future risk/pattern discovery.
- `MetaAuditRun`: Meta Intelligence audit run statusi, health score, summary va aggregate metrics.
- `KnowledgeAudit`: KnowledgeClaim uchun original vs audited confidence, decay, verdict va recommended action.
- `BeliefDecayEvent`: AgentBelief uchun non-destructive decayed score, reason code va freshness/failure evidence.
- `KnowledgeContradiction`: bir scope ichida qarama-qarshi knowledge claimlar va severity score.
- `UnknownZone`: historical similarity yoki Knowledge Graph evidence yetarli bo'lmagan market territory.
- `BlindSpot`: yetarlicha o'rganilmagan market condition kombinatsiyalari va suggested research.
- `KnowledgeHealthScore`: Knowledge Base umumiy sog'ligi; fresh, aging, contradiction, unknown va blind spot komponentlari.
- `SelfCritique`: Meta Engine haftalik self-critic xulosasi va recommended action.
- `CivilizationAgent`: role agent yoki strategy member; credits, reputation, contribution, trust, vote weight va objectives saqlaydi.
- `CivilizationCreditEvent`: non-transferable research credit allocation ledger.
- `CouncilDecision`: AI Council proposal, risk/knowledge gap/expected value, final decision, quorum va rationale.
- `CouncilVote`: har civilization agentning weighted YES/NO/VETO ovozi va evidence'i.
- `CivilizationMemory`: collective memory; meta audit, council decision va institutional lessonlarni saqlaydi.
- `InstitutionalKnowledge`: agent/version o'lsa ham saqlanadigan preserved knowledge library.
- `CivilizationGoal`: profitdan tashqari adaptability, unknown zone reduction, prediction reliability, knowledge coverage va capital protection goals.
- `QuantLawDiscoveryRun`: Universal Laws discovery run statusi, candidate/law/conflict counts va summary.
- `QuantLawCandidate`: pattern/discovery'dan chiqqan provisional law candidate; confidence, universality, evidence, strategy/species/session/trade counts.
- `QuantLaw`: promoted universal/provisional quant law; statement, status, confidence, universality va scope.
- `QuantLawEvidence`: law/candidate uchun Strategy DNA, StrategyScore yoki KnowledgeClaim provenance evidence.
- `QuantLawGraphEdge`: law graph relation; driver -> target, polarity, confidence va evidence count.
- `QuantLawConflict`: bir driver/target bo'yicha qarama-qarshi lawlar conflict'i.
- `QuantLawEvolutionEvent`: law confidence/status vaqt o'tishi bilan qanday o'zgargani.
- `UniversalDriverRanking`: top driver analysis; impact, confidence, rank va law ids.
- `CausalDiscoveryRun`: Causal Intelligence discovery run statusi, edge/effect/intervention/experiment counts va summary.
- `CausalNode`: causal graph variable node; driver yoki outcome.
- `CausalEdge`: source -> target causal candidate; causality score, correlation score, identification status va assumptions.
- `CausalEffectEstimate`: causal edge uchun ATE-style effect estimate, confidence interval va adjustment set.
- `CausalCounterfactual`: "agar X o'zgarganda Y nima bo'lardi?" savoli va estimated delta.
- `CausalIntervention`: cause-based evolution/intervention proposal, expected impact, cost va risk.
- `CausalExperiment`: control/experimental group bilan planned quant experiment.
- `CausalRootCause`: top root-cause ranking; impact/confidence/status.
- `DiscoveryQualityScore`: discovery/law uchun correlation vs causality quality score.
- `TheoryGenerationRun`: Autonomous Theory Generation run statusi, theory/battle/prediction/unified model counts va summary.
- `QuantTheory`: higher-order quant theory; thesis, status, confidence, explanatory power, predictive power va scope.
- `TheoryComponent`: theory'ni qo'llab-quvvatlaydigan law, causal edge, root cause yoki driver evidence component.
- `TheoryBattle`: competing theories orasidagi evidence-based battle, winner/confidence gap va evidence.
- `TheoryPrediction`: theory asosidagi forecast; intervention, predicted delta, confidence va validation status.
- `TheoryEvolutionEvent`: theory lifecycle; generated/revalidated status va confidence o'zgarishi.
- `UnifiedQuantModel`: bir nechta kuchli theory'ni birlashtirgan grand/unified quant model.
- `RealityVerificationRun`: Reality Verification run statusi, scored/certified/failed/cemetery/skeptic counts va summary.
- `RealityScore`: har knowledge/law/theory/unified model uchun original confidence vs market reality score, drift, false discovery risk va validation status.
- `RealityValidationEvent`: reality score qayta tekshirilganda status/score evolution trail.
- `RealityExperiment`: paper/live validation experiment; planned/observed samples, success rate va criteria.
- `CertifiedKnowledgeItem`: validated yoki institutional-grade reality-certified knowledge certificate.
- `KnowledgeCemeteryEntry`: reality failed bo'lgan knowledge/law/theory arxivi va failure reason.
- `SkepticReport`: false discovery risk yuqori yoki paper/live evidence yetarli bo'lmagan source uchun auditor objection va suggested tests.
- `SystemEvent`: signal_generated, position_opened, hypothesis_failed kabi barcha Phase 2 lifecycle eventlari uchun append-only event store.
- `ServiceHealthCheck`: market feed, scheduler, event store, signal foundation, scientist memory va reality loop health statusi.
- `SignalMarketSnapshot`: signal paytidagi trend, volatility, liquidity, momentum, market_species, hypothesis va memory match score snapshot.
- `AgentMemoryMatch`: yangi signal contexti bilan oldingi agent memory similarity matchlari va lesson provenance.
- `SymbolProfile`: instrument/timeframe brain; best session, worst session, best strategy, current regime, news sensitivity, volatility profile, trend cleanliness va confidence.
- `MarketProviderHealth`: MT5 provider/symbol/timeframe bo'yicha last candle, stale/lost status, alert holati va auto-recovery attemptini saqlaydi.
- `SystemLog`: provider lost/recovered, MT5 restart, Telegram alert, queue/python restart kabi production operatsion loglarni saqlaydi.

### Laravel Routes

Muhim web routes:

```text
GET  /
GET  /ai-scientist
GET  /agent-mind
GET  /evolution-lab
GET  /market-intelligence
GET  /knowledge-center
GET  /future-intelligence
POST /future-intelligence/simulate
GET  /meta-intelligence
POST /meta-intelligence/audit
GET  /ai-civilization
POST /ai-civilization/sync
GET  /quant-laws
POST /quant-laws/discover
GET  /causal-intelligence
POST /causal-intelligence/discover
GET  /theory-lab
POST /theory-lab/generate
GET  /reality-center
POST /reality-center/verify
GET  /agent-health
POST /agent-health/check
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
GET  /market-profiles
POST /market-profiles/refresh
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
php artisan knowledge:mine
php artisan future:simulate
php artisan meta:audit
php artisan civilization:sync
php artisan laws:discover
php artisan causal:discover
php artisan theory:generate
php artisan reality:verify
php artisan system:health-check
php artisan market:health
php artisan profiles:refresh
```

Scheduler:

```text
routes/console.php
Schedule::command('trading:daily-report')->dailyAt('23:50');
Schedule::command('trading:daily-workflow')->dailyAt('01:00')->withoutOverlapping()->runInBackground();
Schedule::command('system:health-check')->everyFiveMinutes()->withoutOverlapping()->runInBackground();
Schedule::command('market:health')->everyMinute()->withoutOverlapping()->runInBackground();
Schedule::command('profiles:refresh')->hourly()->withoutOverlapping()->runInBackground();
Schedule::command('knowledge:mine')->dailyAt('02:00')->withoutOverlapping()->runInBackground();
Schedule::command('future:simulate')->dailyAt('02:30')->withoutOverlapping()->runInBackground();
Schedule::command('meta:audit')->weeklyOn(1, '03:00')->withoutOverlapping()->runInBackground();
Schedule::command('civilization:sync')->weeklyOn(1, '03:30')->withoutOverlapping()->runInBackground();
Schedule::command('laws:discover')->weeklyOn(1, '04:00')->withoutOverlapping()->runInBackground();
Schedule::command('causal:discover')->weeklyOn(1, '04:30')->withoutOverlapping()->runInBackground();
Schedule::command('theory:generate')->weeklyOn(1, '05:00')->withoutOverlapping()->runInBackground();
Schedule::command('reality:verify')->weeklyOn(1, '05:30')->withoutOverlapping()->runInBackground();
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
symbol_profiles
symbols
candles
strategies
agent_hypotheses
agent_beliefs
scientist_journals
knowledge_facts
counterfactual_runs
agent_psychology_snapshots
agent_self_reflections
agent_memories
internal_debates
debate_arguments
agent_reputations
evolution_triggers
strategy_genomes
genome_genes
genome_lineages
genome_mutations
genome_crossovers
evolution_generations
fitness_evaluations
selection_events
extinction_events
genome_discoveries
market_species
market_species_versions
market_state_snapshots
market_state_probabilities
market_genomes
market_memories
market_similarity_matches
market_discoveries
strategy_species_performance
knowledge_graph_nodes
knowledge_graph_edges
knowledge_claims
knowledge_evidence
knowledge_queries
knowledge_mining_runs
future_simulation_runs
future_scenarios
future_probability_nodes
future_timeline_forecasts
strategy_survival_forecasts
future_stress_tests
future_discoveries
meta_audit_runs
knowledge_audits
belief_decay_events
knowledge_contradictions
unknown_zones
blind_spots
knowledge_health_scores
self_critiques
civilization_agents
civilization_credit_events
council_decisions
council_votes
civilization_memories
institutional_knowledge
civilization_goals
quant_law_discovery_runs
quant_law_candidates
quant_laws
quant_law_evidences
quant_law_graph_edges
quant_law_conflicts
quant_law_evolution_events
universal_driver_rankings
causal_discovery_runs
causal_nodes
causal_edges
causal_effect_estimates
causal_counterfactuals
causal_interventions
causal_experiments
causal_root_causes
discovery_quality_scores
theory_generation_runs
quant_theories
theory_components
theory_battles
theory_predictions
theory_evolution_events
unified_quant_models
reality_verification_runs
reality_scores
reality_validation_events
reality_experiments
knowledge_cemetery_entries
skeptic_reports
certified_knowledge_items
system_events
service_health_checks
signal_market_snapshots
agent_memory_matches
market_provider_health
system_logs
```

`strategy_scores.training_session_id` training session bilan bog'laydi.

`strategy_scores.parameters` har bir leaderboard natijasi qaysi model parametrlar bilan chiqqanini saqlaydi.

`agent_hypotheses` 15-etapdan keyin har trade/signalni kichik ilmiy prediction sifatida saqlaydi:

```text
training_session_id
strategy_score_id
strategy
decision
confidence
market_regime
volatility_regime
hypothesis
measurable_target
actual_outcome
status: pending / confirmed / failed / inconclusive
evidence_snapshot
```

`agent_beliefs` har agentning o'zgarib boradigan ishonchlarini saqlaydi:

```text
strategy
belief_key
belief_label
score
sample_size
confirmed_count
failed_count
confidence_interval_low/high
regime
last_evidence_at
```

`scientist_journals` training session yakunidagi ilmiy jurnal:

```text
training_session_id
summary
observations
most_failed_hypothesis
conclusion
metrics
```

`knowledge_facts` takroriy evidence'dan chiqqan bilim bazasi:

```text
title
fact
scope
confidence_score
evidence_count
status: provisional / validated / challenged
source_type
source_id
```

`counterfactual_runs` failed hypothesis uchun alternative reality analysis:

```text
agent_hypothesis_id
strategy_score_id
training_session_id
scenario_name
intervention
baseline_result
alternative_result
delta_percent
verdict
```

`agent_psychology_snapshots` 16-etapdan keyin agent ichki holatini saqlaydi:

```text
training_session_id
strategy_score_id
strategy
confidence
stress
trust
adaptation_pressure
stability
learning_rate
state: stable / watch / stressed / adaptation_required
metrics
```

`agent_self_reflections` session yakunidagi metacognitive reflection:

```text
strategy
reflection
observations
suggested_action
stress
adaptation_pressure
```

`agent_memories` context-aware memory:

```text
strategy
memory_type
market_regime
volatility_regime
summary
lesson
strength
source_type
source_id
```

`internal_debates` va `debate_arguments` agentlararo BUY/NO/WAIT ichki bahsni saqlaydi:

```text
internal_debates:
training_session_id
symbol
timeframe
final_decision
consensus_score
context

debate_arguments:
internal_debate_id
strategy
stance
confidence
argument
evidence
```

`agent_reputations` ko'p sessionlik agent obro'si:

```text
strategy
reputation_score
stability_score
trust_score
calibration_score
survival_score
sessions_count
last_training_session_id
```

`evolution_triggers` self-observationdan chiqqan evolution review signal:

```text
strategy
trigger_type: stress / adaptation_pressure
trigger_value
threshold
status
reason
payload
```

`strategy_genomes` 17-etapdan keyin har model versiyani immutable genome sifatida saqlaydi:

```text
model_version_id
strategy_score_id
training_session_id
strategy
family
version
generation
genome_hash
genes
phenotype
fitness_score
evolution_efficiency
status: alive / archived
death_reason
```

`genome_genes` har genome parametrini alohida gene sifatida indekslaydi:

```text
strategy_genome_id
gene_key
gene_value
value_type
observed_fitness
```

`genome_lineages` parent-child DAG relation:

```text
parent_genome_id
child_genome_id
lineage_type: mutation / crossover
metadata
```

`genome_mutations` proposal apply qilinganda old/new diff:

```text
parent_genome_id
child_genome_id
evolution_proposal_id
mutation_type
mutation_diff
reason
```

`genome_crossovers` cross-breeding candidate:

```text
parent_a_genome_id
parent_b_genome_id
child_genome_id
child_strategy
combined_genes
rationale
status
```

`fitness_evaluations` genome fitness evidence:

```text
strategy_genome_id
strategy_score_id
training_session_id
fitness_score
components
evaluation_summary
```

`selection_events` va `extinction_events` survival-of-the-fittest tarixini saqlaydi:

```text
selection_events:
survivor_genome_ids
archived_genome_ids
criteria

extinction_events:
strategy_genome_id
reason_code
reason
evidence
extinct_at
```

`genome_discoveries` genome heatmap/pattern discovery:

```text
title
discovery
gene_key
scope
confidence_score
evidence_count
status
```

`market_state_snapshots` 18-etapdan keyin har candle uchun inferred market reality state saqlaydi:

```text
symbol_id
candle_id
market_species_id
symbol
timeframe
time
market_state
liquidity_state
momentum_state
structure_state
confidence_score
trend_score
panic_score
compression_score
expansion_score
momentum_score
liquidity_proxy_score
features
explanation
```

`market_genomes` market holatini numeric genome/vector sifatida saqlaydi:

```text
market_state_snapshot_id
market_species_id
symbol
timeframe
time
genome_hash
vector
trend
panic
compression
momentum
liquidity_proxy
```

`market_species` va `market_species_versions` OHLCV-derived market taxonomy'ni saqlaydi:

```text
code
name
dominant_state
description
danger_score
opportunity_score
signature
version
sample_size
```

`market_memories`, `market_similarity_matches`, `market_discoveries` va `strategy_species_performance` Market Intelligence xotira/tadqiqot qatlamlari:

```text
market_memories:
market_species_id
market_state_snapshot_id
symbol
timeframe
memory_type
market_state
summary
lesson
strength
evidence

market_similarity_matches:
current_market_genome_id
matched_market_genome_id
similarity_score
lesson

market_discoveries:
title
discovery
market_species_id
market_state
confidence_score
evidence_count
status
metadata

strategy_species_performance:
market_species_id
strategy_score_id
training_session_id
strategy
species_code
species_name
trades
winrate
profit_percent
confidence_score
evidence
```

`knowledge_graph_nodes` va `knowledge_graph_edges` 19-etapdan keyin barcha trading evidence'ni graphga bog'laydi:

```text
knowledge_graph_nodes:
node_type
node_key
label
summary
source_type
source_id
confidence_score
evidence_count
metadata
last_seen_at

knowledge_graph_edges:
source_node_id
target_node_id
relation_type
weight
confidence_score
evidence_count
polarity
status
metadata
last_seen_at
```

`knowledge_claims`, `knowledge_evidence`, `knowledge_queries`, `knowledge_mining_runs` institutional knowledge va query layer:

```text
knowledge_claims:
primary_node_id
title
claim
claim_type
confidence_score
evidence_count
status
scope
metadata
last_seen_at

knowledge_evidence:
knowledge_claim_id
knowledge_graph_node_id
knowledge_graph_edge_id
source_type
source_id
evidence_type
summary
weight
observed_at
metadata

knowledge_queries:
question
answer
matched_node_ids
matched_edge_ids
confidence_score
reasoning
metadata

knowledge_mining_runs:
status
started_at
finished_at
nodes_created
edges_created
claims_created
summary
metrics
```

`future_simulation_runs` va `future_scenarios` 20-etapdan keyin probabilistic future scenario engine natijalarini saqlaydi:

```text
future_simulation_runs:
market_state_snapshot_id
market_genome_id
market_species_id
symbol
timeframe
scenario_count
max_horizon_candles
random_seed
status
current_confidence
future_confidence
planning_bias
current_market_vector
knowledge_prior_summary
summary

future_scenarios:
future_simulation_run_id
scenario_key
scenario_label
simulated_count
probability
expected_return
risk_score
confidence_score
state_path
drivers
```

`future_probability_nodes`, `future_timeline_forecasts`, `strategy_survival_forecasts`, `future_stress_tests`, `future_discoveries` planning qatlamlari:

```text
future_probability_nodes:
future_simulation_run_id
parent_id
node_key
label
probability
horizon_candles
node_type
metadata

future_timeline_forecasts:
future_simulation_run_id
horizon_candles
bull_probability
range_probability
panic_probability
reversal_probability
confidence_score
drivers

strategy_survival_forecasts:
future_simulation_run_id
strategy_score_id
strategy
current_confidence
future_confidence
survival_probability
future_robustness
recommended_action
scenario_breakdown
planning_adjustments

future_stress_tests:
future_simulation_run_id
stress_key
stress_label
impact_score
survival_rate
confidence_score
risk_level
planning_note
parameters

future_discoveries:
future_simulation_run_id
title
discovery
discovery_type
confidence_score
evidence_count
status
scope
metadata
```

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

### AI Trading Scientist Flow

```text
StrategyScore + raw_result.trades
 |
TradingScientistService
 |
Decision Brain:
  - decision
  - confidence
  - market_regime
  - volatility_regime
 |
Hypothesis Engine:
  - 20 candle horizon
  - 1.5 ATR expected move
  - confirmed / failed / inconclusive
 |
Counterfactual Engine:
  - delayed_entry_2_candles
  - wider_atr_stop
  - without_rsi_filter yoki stricter_breakout_confirmation
 |
Belief System:
  - trend_following
  - regime_adaptability
  - survival_under_drawdown
  - rsi_confirmation / breakout_follow_through
 |
Scientist Journal
 |
Knowledge Extraction
 |
/ai-scientist dashboard
```

Manual `StrategyLabController::runAll` va `trading:auto-train` ikkalasi ham session saqlangandan keyin `TradingScientistService::recordTrainingSession()` chaqiradi.

### Agent Mind / Metacognition Flow

```text
AI Scientist artifacts
  - agent_hypotheses
  - agent_beliefs
  - scientist_journals
 |
StrategyScore + DNA + Walk Forward + Monte Carlo
 |
AgentMindService
 |
Psychology Snapshot:
  - confidence
  - stress
  - trust
  - adaptation_pressure
  - stability
  - learning_rate
  - state
 |
Self Reflection
 |
Contextual Memory
 |
Agent Reputation
 |
Internal Debate:
  BUY / NO / WAIT arguments
 |
Evolution Triggers:
  stress > 80
  adaptation_pressure > 85
 |
/agent-mind dashboard
```

Manual `StrategyLabController::runAll` va `trading:auto-train` ikkalasi ham `TradingScientistService`dan keyin `AgentMindService::recordTrainingSession()` chaqiradi. Bu triggerlar mavjud `EvolutionProposal` flow'ni buzmaydi; ular metacognitive evolution review signali sifatida saqlanadi.

### Evolution Genome Flow

```text
StrategyScore + ModelVersion parameters
 |
EvolutionGenomeService
 |
Strategy Genome:
  - family
  - version
  - generation
  - immutable genes
  - phenotype
  - fitness_score
 |
Genome Genes
 |
Fitness Evaluation
 |
Selection:
  top 10 alive genomes survive
  lower fitness genomes archived with extinction reason
 |
Genome Discoveries:
  high-fitness numeric gene ranges
 |
Evolution Lab dashboard
```

Proposal apply flow:

```text
EvolutionProposal old_parameters/new_parameters
 |
ModelVersion child created
 |
EvolutionGenomeService::recordAppliedProposal()
 |
Child StrategyGenome
 |
GenomeLineage parent -> child
 |
GenomeMutation old/new diff
```

Cross-breeding hozir sandbox candidate sifatida yoziladi: eng kuchli turli family genome'lar `genome_crossovers`ga `proposed` status bilan tushadi; production strategy avtomatik yaratilmaydi.

### Market Reality / Intelligence Flow

```text
MarketDataService stores candles
 |
MarketRealityService::analyzeSymbol(symbol, timeframe)
 |
Rolling 20-candle OHLCV feature extraction:
  trend_score
  panic_score
  compression_score
  expansion_score
  momentum_score
  liquidity_proxy_score
 |
Market State Snapshot:
  market_state
  liquidity_state
  momentum_state
  structure_state
 |
Market Species + Species Version
 |
Market Genome vector:
  trend, panic, compression, momentum, liquidity_proxy
 |
Market Memory + Similarity Scanner + Market Discoveries
 |
/market-intelligence dashboard
```

Training session tugaganda `MarketRealityService::recordStrategyPerformance()` latest species'ni `strategy_species_performance` orqali StrategyScore bilan bog'laydi. Bu 18-etapdagi contextual fitness foundation: strategy faqat umumiy score bilan emas, qaysi Market Species ichida ishlagani bilan ham baholanadi.

Muhim cheklov: hozircha faqat OHLCV borligi sababli haqiqiy liquidity emas, `liquidity_proxy` hisoblanadi. Order book yoki tick/trade data qo'shilsa, bu qatlam real liquidity featurelari bilan kengaytiriladi.

### Universal Knowledge Graph Flow

```text
Training session completed
 |
AI Scientist + Agent Mind + Evolution Genome + Market Reality artifacts
 |
UniversalKnowledgeGraphService::recordTrainingSession()
 |
Knowledge Nodes:
  strategy, session, symbol, timeframe, metric, parameter,
  market_species, strategy_genome, hypothesis, belief,
  discovery, failure_cause
 |
Knowledge Edges:
  OBSERVED_STRATEGY
  ACHIEVED_METRIC
  USES_PARAMETER
  HAS_GENOME
  PERFORMS_IN_MARKET_SPECIES
  HAS_FAILURE_CAUSE
  PRODUCED_HYPOTHESIS
  HAS_BELIEF
 |
Knowledge Claims:
  strategy_species_performance
  failure_cause
  genome_pattern
  market_pattern
  agent_belief
 |
Knowledge Center dashboard + Research Assistant query
```

Nightly mining:

```text
php artisan knowledge:mine
 |
Strategy x Species patterns
Failure Intelligence
Genome discoveries
Market discoveries
Agent belief claims
 |
knowledge_claims + knowledge_mining_runs
```

Research Assistant chatbot emas; u graph node/edge/claim ichidan evidence topadi va `knowledge_queries`ga matched node/edge ids bilan saqlaydi.

### Future Simulation / Planning Flow

```text
Latest MarketStateSnapshot + MarketGenome
 |
Knowledge Graph priors:
  species claims
  failure pressure
  opportunity pressure
 |
FutureSimulationService::simulate(symbol, timeframe)
 |
Scenario probabilities:
  bull_continuation
  range_reversion
  panic_event
  fake_breakout
  trend_reversal
 |
Future Probability Tree
 |
Timeline Forecast:
  next 10 / 25 / 50 candles
 |
Strategy Survival Forecast:
  survival_probability
  future_confidence
  recommended_action
  position_size_multiplier
 |
Future Stress Tests:
  volatility x2
  liquidity_proxy -50%
  trend reversal
 |
Future Discoveries + Future Intelligence dashboard
```

Manual `/strategy-lab/run-all` va `php artisan trading:auto-train` tugaganda Future Simulation no-op guardrail bilan chaqiriladi: latest Market Genome bo'lmasa hech narsani buzmaydi. Nightly scheduler `future:simulate`ni 02:30 da yuritadi.

Muhim cheklov: bu causal proof yoki narxni aniq bashorat qilish emas. 20-etap hozir deterministic probabilistic scenario planning qiladi; forecast outcome/calibration keyingi chuqurlashtirishga qoladi.

### Meta Intelligence / Self-Correcting Audit Flow

```text
KnowledgeClaim + AgentBelief + MarketStateSnapshot
 |
MetaIntelligenceService::runAudit()
 |
Knowledge Audit:
  original_confidence
  audited_confidence
  decay_amount
  verdict
 |
Belief Decay:
  original_score
  decayed_score
  reason_code
 |
Contradiction Detector:
  same scope + opposite claim direction
 |
Unknown Zone Detector:
  low market similarity
  weak Knowledge Graph evidence
 |
Blind Spot Finder:
  under-sampled market condition combinations
 |
Knowledge Health Score + Self Critique
 |
Meta Intelligence dashboard
```

Meta Intelligence non-destructive ishlaydi: `knowledge_claims` yoki `agent_beliefs`ni darrov pasaytirib yubormaydi, balki alohida audit trail (`knowledge_audits`, `belief_decay_events`) yozadi. Weekly scheduler `meta:audit`ni dushanba 03:00 da yuritadi; manual run `/meta-intelligence` sahifasidan qilinadi.

### AI Civilization / Artificial Quant Organization Flow

```text
Knowledge Graph + Meta Intelligence + Agent Reputation
 |
QuantCivilizationService::synchronize()
 |
Civilization Agents:
  Research Agent
  Risk Agent
  Market Agent
  Evolution Agent
  Knowledge Agent
  Prediction Agent
  Meta Agent
  Strategy Members
 |
Internal Economy:
  non-transferable research credits
  credit event ledger
 |
Institutional Knowledge:
  preserved claims
  future/market/genome discoveries
 |
Civilization Goals:
  increase adaptability
  reduce unknown zones
  improve prediction reliability
  expand knowledge coverage
  protect capital
 |
AI Council:
  weighted votes
  YES / NO / VETO
  quorum + consensus
 |
Collective Memory + AI Civilization dashboard
```

AI Civilization agentlarni antropomorfik o'yinchoq qilmaydi; role separation, accountability, evidence-based credits, decision ledger va institutional memory yaratadi. `civilization:sync` weekly dushanba 03:30 da ishlaydi; manual run `/ai-civilization` sahifasidan qilinadi.

### Universal Quant Laws / Quant Physics Flow

```text
Strategy DNA + StrategyScore + KnowledgeClaim + Institutional Knowledge
 |
UniversalQuantLawsService::discover()
 |
Law Candidate Engine:
  trend dependency -> adaptability
  low volatility -> breakout failure
  confirmation density -> adaptability tradeoff
  claim-derived provisional laws
 |
Multi-Evidence Validation:
  strategy_count
  species_count
  session_count
  trade_count
  universality_score
 |
Promoted Quant Laws:
  active / emerging / weak
  confidence_score
  effect_size
 |
Law Graph:
  driver -> target
  increases / reduces
 |
Law Conflicts:
  opposite direction in same driver-target scope
 |
Universal Driver Ranking:
  impact_score
  confidence_score
 |
Quant Laws dashboard
```

Quant Laws causal proof emas; ular repeated invariant va provisional scientific capital. `laws:discover` weekly dushanba 04:00 da ishlaydi; manual run `/quant-laws` sahifasidan qilinadi. Keyingi Causal Intelligence bosqichi qaysi law relationlari uchun causal effect identifikatsiya qilinishini alohida tekshiradi.

### Causal Intelligence / Cause Discovery Flow

```text
Quant Laws
 |
CausalIntelligenceService::discover()
 |
Causal Graph:
  causal_nodes
  causal_edges
  identification_status
  causality_score
 |
Causal Effect Estimates:
  effect_estimate
  confidence interval
  adjustment_set
 |
Counterfactual Laboratory:
  baseline_value
  intervention_value
  estimated_delta
 |
Intervention Engine:
  recommendation
  expected_impact
  cost
  risk
 |
Quant Experiments:
  control_group
  experimental_group
  success_criteria
 |
Root Cause Library + Discovery Quality Score
```

Causal Intelligence Quant Laws'dagi correlation/law relationlarni sabab deb avtomatik e'lon qilmaydi. Har edge `associational`, `partially_identified` yoki `provisionally_identified` status oladi. `causal:discover` weekly dushanba 04:30 da ishlaydi; manual run `/causal-intelligence` sahifasidan qilinadi.

### Autonomous Theory Generation / Theory Lab Flow

```text
Quant Laws + Causal Intelligence + Root Causes
 |
AutonomousTheoryGenerationService::generate()
 |
Theory Layer:
  quant_theories
  confidence_score
  explanatory_power_score
  predictive_power_score
 |
Theory Components:
  law evidence
  causal edge evidence
  root cause evidence
 |
Competing Theories:
  theory_battles
  winner / contested status
 |
Theory Predictions:
  target_metric
  predicted_delta
  validation status
 |
Unified Quant Models + Theory Evolution
```

Theory Generation pattern -> law -> cause -> theory pog'onasini yaratadi. U mavjud Quant Laws yoki Causal Intelligence natijalarini pasaytirmaydi va overwrite qilmaydi; alohida `quant_theories`, `theory_components`, `theory_battles`, `theory_predictions`, `theory_evolution_events` va `unified_quant_models` ledgerlariga yozadi. `theory:generate` weekly dushanba 05:00 da ishlaydi; manual run `/theory-lab` sahifasidan qilinadi.

### Reality Verification / Reality Center Flow

```text
Knowledge Claims + Quant Laws + Quant Theories + Unified Models
 |
RealityVerificationService::verify()
 |
Reality Score:
  original_confidence
  reality_score
  drift_score
  false_discovery_risk
 |
Reality Validation:
  paper/live samples
  backtest samples
  validation events
 |
Lifecycle:
  draft
  backtest_only
  needs_paper_validation
  validated
  institutional_grade
  reality_failed
 |
Certified Knowledge / Knowledge Cemetery / Skeptic Reports
```

Reality Verification model reality bilan market reality orasidagi farqni alohida layer sifatida tekshiradi. U mavjud knowledge, law yoki theory'ni overwrite qilmaydi; `reality_scores`, `reality_validation_events`, `reality_experiments`, `certified_knowledge_items`, `knowledge_cemetery_entries` va `skeptic_reports` ledgerlariga yozadi. Hozirgi A-rank v1 mavjud `StrategyScore` operational evidence'ini proxy sifatida ishlatadi; `raw_result.execution_mode = paper_trading|paper|live` bo'lsa paper/live sample sifatida sanaydi. `reality:verify` weekly dushanba 05:30 da ishlaydi; manual run `/reality-center` sahifasidan qilinadi.

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
MarketRealityService::analyzeSymbol()
 |
market_state_snapshots + market_genomes + market_species
 |
market_memories + market_similarity_matches + market_discoveries
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
/ai-scientist      -> AI Trading Scientist dashboard: hypotheses, beliefs, journals, knowledge, counterfactuals
/agent-mind        -> Agent Mind dashboard: psychology, stress, trust, memory, reputation, debate, triggers
/evolution-lab     -> Evolution Lab dashboard: genome tree, mutations, crossovers, extinct agents, discoveries, efficiency
/market-intelligence -> Market Intelligence dashboard: current genome, species, memories, discoveries, similarity scanner
/knowledge-center -> Universal Knowledge Graph dashboard: graph, discoveries, research assistant, failure analysis, pattern explorer, timeline
/future-intelligence -> Future Intelligence dashboard: future map, probability tree, scenario lab, survival forecast, stress tests
/backtests         -> Single backtest form
/strategy-lab      -> Agent leaderboard + Start New Training Session
/strategy-lab/dna-laboratory -> Strategy DNA leaders + DNA history
/training-sessions -> Training session history + session charts
/training-logs     -> Auto training and daily workflow logs
/model-versions    -> Version history/status + status chart
/evolution-proposals -> Agent evolution proposal queue
/daily-reports     -> Daily AI reports
/mistake-journal   -> Mistake journal
/market-data      -> Candle status + manual market data update
```

## 13-15 Etaplar: Institutional Validation + Strategy Genetics

Bu bo'lim keyingi sessiyada loyiha kontekstini tez olish uchun yozildi. 13, 14, 15-etaplar Strategy Lab oqimini oddiy backtest leaderboarddan institutsional strategiya validatsiya laboratoriyasiga o'tkazdi.

### 13-etap: Walk Forward Validation Engine

Maqsad:

```text
Strategiya tarixda emas, kelajak segmentida ham ishlaydimi?
```

Python:

```text
ai-service-python/app/services/walk_forward.py
```

Asosiy flow:

```text
Dataset
 -> 70% train
 -> 15% validation
 -> 15% forward
 -> train_score / validation_score / forward_score
 -> robustness_score
 -> is_overfit
```

`/api/backtest/run-all` endi har strategy uchun bularni qaytaradi:

```text
train_score
validation_score
forward_score
robustness_score
is_overfit
score
result.walk_forward
```

Laravel:

```text
backend-laravel/database/migrations/2026_06_17_130000_add_walk_forward_columns_to_strategy_scores_table.php
backend-laravel/app/Services/OverfitDetectorService.php
```

`strategy_scores` ustunlari:

```text
train_score
validation_score
forward_score
robustness_score
is_overfit
```

Model status:

```text
active bo'lishi uchun:
score >= 75
profit_factor >= 1.3
drawdown <= 15
robustness_score >= 70
is_overfit = false

overfit:
abs(train_score - forward_score) > 25
```

UI:

```text
Strategy Lab leaderboard:
Train / Valid / Forward / Robust / Status

Training Session detail:
Walk Forward Validation chart
```

### 14-etap: Monte Carlo Risk Simulation Engine

Maqsad:

```text
Trade tartibi yomonlashsa ham account tirik qoladimi?
```

Python:

```text
ai-service-python/app/services/monte_carlo.py
```

`SimpleBacktestResponse` va `run-all result` ichida:

```text
result.monte_carlo.simulations
result.monte_carlo.worst_profit_percent
result.monte_carlo.avg_profit_percent
result.monte_carlo.best_profit_percent
result.monte_carlo.worst_drawdown_percent
result.monte_carlo.avg_drawdown_percent
result.monte_carlo.risk_of_ruin_percent
result.monte_carlo.worst_equity_curve
result.monte_carlo.best_equity_curve
```

Eslatma:

```text
Trade profit_percent qiymatlari faqat shuffle qilinsa final compounded profit ko'pincha bir xil qoladi.
Monte Carlo bu yerda asosan drawdown path, sequence risk va risk_of_ruin uchun ishlatiladi.
```

Laravel:

```text
backend-laravel/database/migrations/2026_06_17_140000_add_monte_carlo_columns_to_strategy_scores_table.php
```

`strategy_scores` MC ustunlari:

```text
mc_worst_profit_percent
mc_avg_profit_percent
mc_best_profit_percent
mc_worst_drawdown_percent
mc_avg_drawdown_percent
mc_risk_of_ruin_percent
mc_worst_equity_curve
mc_best_equity_curve
```

Model status:

```text
active qo'shimcha sharti:
mc_risk_of_ruin_percent <= 10
mc_worst_drawdown_percent <= 25

rejected qo'shimcha sharti:
mc_risk_of_ruin_percent > 30
yoki mc_worst_drawdown_percent > 40
```

UI:

```text
Strategy Lab leaderboard:
MC Worst / MC Avg / MC Best / Ruin Risk / Risk Grade

Training Session detail:
Monte Carlo Survival Test
Worst Path / Best Path chart
Monte Carlo survival cards
```

Evolution:

```text
High Monte Carlo risk bo'lsa proposal:
main_problem = high_risk_of_ruin
risk_multiplier = 0.5
confirmation_candles + 1
avoid_high_volatility = true
```

### 15-etap: Strategy DNA & Personality Engine

Maqsad:

```text
Strategiya o'zi kim? Trend-followingmi, agressivmi, adaptivmi, survival kuchlimi?
```

Python:

```text
ai-service-python/app/services/strategy_dna.py
```

`SimpleBacktestResponse` va `run-all result` ichida:

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
backend-laravel/database/migrations/2026_06_17_150000_create_strategy_dna_profiles_table.php
backend-laravel/app/Models/StrategyDnaProfile.php
```

Relation:

```text
StrategyScore -> hasOne StrategyDnaProfile
StrategyDnaProfile -> belongsTo StrategyScore
```

`strategy_dna_profiles` ustunlari:

```text
strategy_score_id
aggression_score
trend_dependency
range_dependency
volatility_sensitivity
adaptability_score
recovery_score
survival_score
dna_summary
```

Yangi UI:

```text
/strategy-lab/dna-laboratory

Kartalar:
Most Aggressive Agent
Most Adaptive Agent
Highest Survival Agent
Best Recovery Agent

Table:
DNA History
```

Training Session detail:

```text
Strategy DNA Radar chart
Best Agent DNA cards
DNA summary text
```

Evolution DNA rules:

```text
trend_dependency > 90      -> excessive_trend_dependency
adaptability_score < 35    -> low_adaptability
survival_score < 50        -> weak_survival_dna
```

Proposal examples:

```text
excessive_trend_dependency:
range_capability = true
volatility_filter = true

low_adaptability:
market_regime_filter = true
adaptive_thresholds = true

weak_survival_dna:
risk_multiplier = 0.5
recovery_guard = true
```

### 13-15 Etaplarda Muhim O'zgargan Fayllar

Python:

```text
ai-service-python/app/main.py
ai-service-python/app/schemas.py
ai-service-python/app/services/backtester.py
ai-service-python/app/services/walk_forward.py
ai-service-python/app/services/monte_carlo.py
ai-service-python/app/services/strategy_dna.py
ai-service-python/tests/test_walk_forward.py
ai-service-python/tests/test_monte_carlo.py
ai-service-python/tests/test_strategy_dna.py
```

Laravel:

```text
backend-laravel/app/Http/Controllers/StrategyLabController.php
backend-laravel/app/Http/Controllers/TrainingSessionController.php
backend-laravel/app/Console/Commands/RunAutoTrainingSession.php
backend-laravel/app/Services/AgentEvolutionService.php
backend-laravel/app/Services/OverfitDetectorService.php
backend-laravel/app/Models/StrategyScore.php
backend-laravel/app/Models/StrategyDnaProfile.php
backend-laravel/resources/views/strategy-lab/index.blade.php
backend-laravel/resources/views/strategy-lab/dna-laboratory.blade.php
backend-laravel/resources/views/training-sessions/show.blade.php
backend-laravel/resources/views/layouts/app.blade.php
backend-laravel/routes/web.php
```

Docs:

```text
docs/ai-service-contract.md
README.md
PROJECT_CONTEXT.md
```

### 13-15 Etap Yakuniy Test Natijalari

Oxirgi tekshiruv:

```text
Python: python -m unittest discover -s tests -> 8 passed
Python: python -m compileall app -> OK
Laravel: php artisan test -> 34 passed, 177 assertions
```

Smoke:

```bash
cd ai-service-python
python -c "from app.schemas import SimpleBacktestRequest, StrategyRuntimeConfig; from app.main import run_all_backtests; payload=SimpleBacktestRequest(symbol='XAUUSD', timeframe='H1', dataset_path='../datasets/XAUUSD_H1.csv', strategies=[StrategyRuntimeConfig(strategy='ema_rsi_v1', base_strategy='ema_rsi_v1', version='v1', parameters={})]); item=run_all_backtests(payload)['leaderboard'][0]; print(item['strategy'], item['score'], item['train_score'], item['forward_score'], item['result']['monte_carlo']['simulations'], item['result']['strategy_dna']['survival_score'])"
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
TELEGRAM_ALERTS_ENABLED=false
TELEGRAM_BOT_TOKEN=
TELEGRAM_CHAT_ID=
MT5_PROVIDER=mt5
MT5_SYMBOLS=XAUUSD,EURUSD
MT5_TIMEFRAMES=M15,H1
MT5_FEED_STALE_AFTER_SECONDS=900
MT5_FEED_LOST_AFTER_SECONDS=1200
MT5_AUTO_RECOVERY_ENABLED=false
MT5_RESTART_SCRIPT=/opt/neurotrader/scripts/restart_mt5.sh
MT5_RESTART_TIMEOUT_SECONDS=60
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

### Majburiy Context Protocol

Har yangi vazifa boshlanishida, kodni ko'rish yoki o'zgartirishdan **oldin**, avval shu faylni to'liq o'qi:

```text
PROJECT_CONTEXT.md
```

Keyin vazifaga tegishli bo'lim va fayllarnigina tekshir. Butun loyihani sababsiz boshidan skan qilish shart emas. Bu token sarfini kamaytiradi va oldingi arxitektura qarorlarini yo'qotmaslikka yordam beradi.

Har ish yakunida, foydalanuvchiga final javob berishdan **oldin**, shu faylni yangila:

- yangi route qo'shilsa
- yangi model yoki migration qo'shilsa
- yangi service qo'shilsa
- yangi Python endpoint qo'shilsa
- yangi strategy/agent qo'shilsa
- test natijasi o'zgarsa
- arxitektura flow o'zgarsa
- README'dagi etap holati o'zgarsa
- yangi arxitektura qarori yoki roadmap g'oyasi tasdiqlansa
- eski qaror bekor qilinsa yoki boshqa etapga ko'chirilsa
- ochiq muammo, texnik qarz yoki blocker aniqlansa

Yangilash formati:

```text
1. Nima o'zgardi?
2. Qaysi fayllar o'zgardi?
3. DB/API/UI flow qanday o'zgardi?
4. Qanday test qilindi va aniq natija nima?
5. Nima hali qilinmagan?
6. Keyingi eng mantiqiy qadam nima?
```

Muhim qoidalar:

- Faqat tekshirilgan test natijasini yoz.
- Rejani bajarilgan ish bilan aralashtirma.
- Mavjud user o'zgarishlarini o'chirma yoki qayta yozma.
- Qaror sababini saqla; faqat yakuniy kod holatini emas.
- Yangi entity, jadval, route, service va dashboard sahifasini tegishli arxitektura bo'limiga ham qo'sh.
- Katta etap tugasa, `updated` frontmatter sanasini yangila.
- Obsidian ichida fayl ochilganda heading, callout, code block va ichki linklar buzilmasligi kerak.

### Obsidian Sifatida Ishlatish

Repository root papkasini Obsidian vault sifatida ochish mumkin; `PROJECT_CONTEXT.md` oddiy Markdown bo'lgani uchun alohida konvertatsiya talab qilmaydi.

Asosiy entry point:

```text
[[PROJECT_CONTEXT]]
```

Bog'liq hujjatlar:

- [[README]]
- [[docs/mvp-spec]]
- [[docs/ai-service-contract]]
- [[docs/AUTO_TRAINING_SCHEDULER]]

Kelajakda kontekst kattalashsa, shu fayl master index bo'lib qoladi; detallar `docs/context/` ichidagi alohida Obsidian note'larga ko'chiriladi va bu yerdan `[[wikilink]]` bilan bog'lanadi. Agent baribir avval `PROJECT_CONTEXT.md`ni o'qiydi.

## Canonical Strategic Roadmap: 15–25

> [!WARNING]
> 15-etap AI Trading Scientist Engine'dan 25-etap Autonomous Theory Generation Engine'gacha `[IMPLEMENTED]`. 26+ etaplar hali future/deferred konseptlar; ular production kod sifatida tasdiqlanmagan. Bu roadmap oddiy feature ro'yxati emas; loyihani backtest platformadan o'z bilimini yig'adigan, o'z bilimlarini shubha ostiga qo'yadigan, ko'p agentli organization sifatida ishlaydigan, quant qonunlari, sababiy mexanizmlari va nazariyalarini yaratadigan Artificial Quant Science tizimiga aylantiruvchi canonical yo'nalishdir.

### 15-ETAP — AI Trading Scientist Engine `[IMPLEMENTED]`

#### Maqsad

Har trade oddiy BUY/SELL natijasi emas, tekshiriladigan kichik ilmiy tajriba bo'ladi. Tizim `nima qildim?`, `nega qildim?`, `nima bo'lishini kutdim?` va `gipoteza tasdiqlandimi?` savollariga javob beradi.

#### Asosiy flow

```text
Decision
 -> Hypothesis
 -> Trade Outcome
 -> Hypothesis Evaluation
 -> Counterfactual Analysis
 -> Belief Update
 -> Scientist Journal
 -> Knowledge Candidate
```

#### Layerlar

1. **Decision Brain** — decision, confidence, market regime, volatility va decision evidence snapshot'ini saqlaydi.
2. **Hypothesis Engine** — signal bilan birga horizon va measurable target yozadi. Masalan: keyingi 20 candle ichida kamida `1.5 ATR` harakat.
3. **Scientist Journal** — session yakunida observations, failed hypotheses, regime dependence va conclusion yozadi.
4. **Belief System** — trend following, RSI confirmation, breakout follow-through kabi belief'larni evidence bilan yangilaydi.
5. **Why Engine** — qaror sababini indicator snapshot, historical context va calibration bilan tushuntiradi.
6. **Counterfactual Engine** — kechroq entry, boshqa filter yoki boshqa ATR stop natijasini bir xil market data ustida tekshiradi.
7. **Knowledge Extraction** — takrorlangan va OOS tasdiqlangan natijalarni Knowledge Graph uchun candidate qiladi.

#### Implementatsiya qilingan entity/jadvallar

```text
agent_hypotheses
agent_beliefs
scientist_journals
counterfactual_runs
knowledge_facts
```

#### Ilmiy ishonchlilik qoidalari

- LLM xulosa va explanation yozishi mumkin, lekin metric yoki evidence o'ylab topmaydi.
- Belief faqat foiz emas: `score`, `sample_size`, confidence interval, regime va `last_updated` saqlanadi.
- Knowledge faqat yetarli sample, out-of-sample confirmation va bir nechta sessionda takrorlangandan keyin tasdiqlanadi.
- Counterfactual test causal certainty deb ko'rsatilmaydi; u alternative simulation hisoblanadi.

#### Dashboard

```text
AI Scientist
  - Hypotheses
  - Beliefs
  - Discoveries
  - Journals
  - Knowledge Base
```

#### Implementatsiya fayllari

```text
backend-laravel/app/Services/TradingScientistService.php
backend-laravel/app/Http/Controllers/AiScientistController.php
backend-laravel/app/Models/AgentHypothesis.php
backend-laravel/app/Models/AgentBelief.php
backend-laravel/app/Models/ScientistJournal.php
backend-laravel/app/Models/KnowledgeFact.php
backend-laravel/app/Models/CounterfactualRun.php
backend-laravel/database/migrations/2026_06_24_150000_create_ai_trading_scientist_tables.php
backend-laravel/resources/views/ai-scientist/index.blade.php
backend-laravel/tests/Feature/AiTradingScientistTest.php
```

### 16-ETAP — Agent Consciousness / Metacognition Engine `[IMPLEMENTED]`

#### Maqsad

Bu haqiqiy ong emas. Texnik nomi **AI Metacognition Engine**, UI/marketing nomi **Agent Mind**. Agent market va qarorlaridan tashqari o'z performance holatini ham kuzatadi.

#### Ichki holat

```json
{
  "confidence": 87,
  "stress": 22,
  "stability": 81,
  "adaptation_pressure": 17,
  "trust_in_strategy": 91,
  "learning_rate": 0.12
}
```

- `stress` hissiyot emas; losing streak, degradation va model drift proxy'si.
- `trust` global emas, market regime bo'yicha hisoblanadi.
- `reputation` PnLning o'zi emas; calibration, stability, drawdown, OOS va hypothesis accuracy kombinatsiyasi.
- `adaptation_pressure` market/strategy mismatch uzoqroq davom etsa ko'tariladi.

#### State machine

```text
STABLE -> WATCH -> STRESSED -> ADAPTATION_REQUIRED
 -> EVOLVING -> RECOVERY -> STABLE
```

Evolution bitta noiseli thresholdda ishga tushmaydi. Confirmation window va cooldown talab qilinadi:

```text
adaptation_pressure > 85
AND stress > 70
AND degradation >= 3 sessions
AND cooldown inactive
```

#### Asosiy engine'lar

- Self Reflection Engine
- Internal Debate Engine: BUY / NO / WAIT argumentlari va yakuniy consensus
- Agent Reputation System
- Contextual Memory Engine
- Evolution Trigger

Memory faqat o'sha qaror vaqtigacha mavjud ma'lumotdan foydalanadi; future leakage taqiqlanadi.

#### Implementatsiya qilingan entity/jadvallar

```text
agent_psychology_snapshots
agent_self_reflections
agent_memories
internal_debates
debate_arguments
agent_reputations
evolution_triggers
```

#### Dashboard

```text
Agent Mind
  - Psychology
  - Memory
  - Beliefs
  - Stress
  - Trust
  - Reputation
  - Self Reflections
```

#### Implementatsiya fayllari

```text
backend-laravel/app/Services/AgentMindService.php
backend-laravel/app/Http/Controllers/AgentMindController.php
backend-laravel/app/Models/AgentPsychologySnapshot.php
backend-laravel/app/Models/AgentSelfReflection.php
backend-laravel/app/Models/AgentMemory.php
backend-laravel/app/Models/InternalDebate.php
backend-laravel/app/Models/DebateArgument.php
backend-laravel/app/Models/AgentReputation.php
backend-laravel/app/Models/EvolutionTrigger.php
backend-laravel/database/migrations/2026_06_24_160000_create_agent_mind_tables.php
backend-laravel/resources/views/agent-mind/index.blade.php
backend-laravel/tests/Feature/AgentMindTest.php
```

### 17-ETAP — Evolution Genome Engine `[IMPLEMENTED]`

#### Maqsad

Oddiy version history emas, strategy lineage va artificial evolution tarixini yaratish. Strategy parametrlari immutable genome sifatida saqlanadi.

#### Asosiy tushunchalar

- **Genotype** — parametrlar, indikatorlar va entry/exit/risk qoidalari.
- **Phenotype** — genome turli market regime'larida qanday natija bergani.
- **Mutation** — bitta yoki bir nechta gene o'zgarishi.
- **Crossover** — ikki compatible parent genome'dan child strategy.
- **Lineage DAG** — crossover sabab oddiy tree emas, directed acyclic graph.
- **Extinction** — o'chirish emas; archived genome, death reason va barcha evidence saqlanadi.

#### Evolution flow

```text
Immutable Genome
 -> Frozen Experiment
 -> Phenotype
 -> Contextual Fitness
 -> Selection
 -> Mutation / Crossover
 -> New Genome
```

Fitness faqat profit emas:

```text
OOS Return
+ Hypothesis Accuracy
+ Regime Robustness
+ Stability
- Drawdown
- Complexity Penalty
- Overfitting Risk
```

Selection faqat global Top 10 bilan cheklanmaydi. Global champion, regime specialist, novelty champion va young genome'lar saqlanib, genetic diversity himoya qilinadi.

#### Implementatsiya qilingan entity/jadvallar

```text
strategy_genomes
genome_genes
genome_lineages
genome_mutations
genome_crossovers
evolution_generations
fitness_evaluations
selection_events
extinction_events
genome_discoveries
```

Parent va child bir xil frozen dataset, fee, slippage, seed va validation protocol'da solishtirilishi shart.

#### Dashboard

```text
Evolution Lab
  - Genome Tree / Lineage DAG
  - Mutations
  - Cross Breeding
  - Extinct Agents
  - Discoveries
  - Evolution Efficiency
  - Genome Heatmap
```

#### Implementatsiya fayllari

```text
backend-laravel/app/Services/EvolutionGenomeService.php
backend-laravel/app/Http/Controllers/EvolutionLabController.php
backend-laravel/app/Models/StrategyGenome.php
backend-laravel/app/Models/GenomeGene.php
backend-laravel/app/Models/GenomeLineage.php
backend-laravel/app/Models/GenomeMutation.php
backend-laravel/app/Models/GenomeCrossover.php
backend-laravel/app/Models/EvolutionGeneration.php
backend-laravel/app/Models/FitnessEvaluation.php
backend-laravel/app/Models/SelectionEvent.php
backend-laravel/app/Models/ExtinctionEvent.php
backend-laravel/app/Models/GenomeDiscovery.php
backend-laravel/database/migrations/2026_06_24_170000_create_evolution_genome_tables.php
backend-laravel/resources/views/evolution-lab/index.blade.php
backend-laravel/tests/Feature/EvolutionGenomeTest.php
```

### 18-ETAP — Market Reality / Intelligence Engine `[IMPLEMENTED]`

#### Maqsad

Strategiyani emas, strategiya yashaydigan market muhitini o'rganish. OHLCV'dan trend, momentum, compression, expansion, panic, trap va boshqa probabilistic latent state'lar chiqariladi.

#### Muhim aniqlik

OHLCV'dan topilgan state mutlaq “reality” emas, confidence'ga ega taxminiy latent state. Faqat OHLCV mavjud bo'lsa `liquidity` emas, `liquidity_proxy` deb yoziladi; haqiqiy liquidity uchun order book/trade data kerak.

```json
{
  "state": "volatile_fake_breakout",
  "confidence": 0.82,
  "trend": 0.71,
  "momentum": 0.64,
  "liquidity_proxy": 0.29,
  "alternatives": {
    "bull_expansion": 0.13,
    "unknown": 0.05
  }
}
```

#### Asosiy imkoniyatlar

- Causal rolling window asosida Market Genome
- Versioned Market Species taxonomy
- Real-time estimated state va post-hoc confirmed state'ni alohida saqlash
- Market Memory va historical similarity scanner
- Reality Replay va state explanation
- Market transition probability modeli
- Strategy × Species contextual performance

Market species uchun bitta umumiy winrate berilmaydi. Natija strategy/action bo'yicha shartlanadi: bir species breakout uchun xavfli, mean reversion uchun foydali bo'lishi mumkin.

#### Implementatsiya qilingan entity/jadvallar

```text
market_state_snapshots
market_state_probabilities
market_genomes
market_species
market_species_versions
market_memories
market_similarity_matches
market_discoveries
strategy_species_performance
```

#### Dashboard

```text
Market Intelligence
  - Species Library
  - Market Memories
  - Discoveries
  - Current Market Genome
  - Similarity Scanner
  - Reality Replay
```

17–18 integratsiyasi:

```text
Strategy Genome x Market Species -> Contextual Fitness
```

#### Implementatsiya fayllari

```text
backend-laravel/app/Services/MarketRealityService.php
backend-laravel/app/Http/Controllers/MarketIntelligenceController.php
backend-laravel/app/Models/MarketSpecies.php
backend-laravel/app/Models/MarketSpeciesVersion.php
backend-laravel/app/Models/MarketStateSnapshot.php
backend-laravel/app/Models/MarketStateProbability.php
backend-laravel/app/Models/MarketGenome.php
backend-laravel/app/Models/MarketMemory.php
backend-laravel/app/Models/MarketSimilarityMatch.php
backend-laravel/app/Models/MarketDiscovery.php
backend-laravel/app/Models/StrategySpeciesPerformance.php
backend-laravel/database/migrations/2026_06_24_180000_create_market_reality_tables.php
backend-laravel/resources/views/market-intelligence/index.blade.php
backend-laravel/tests/Feature/MarketRealityEngineTest.php
```

#### Hozirgi cheklov

`market_transitions` alohida jadval sifatida hali qo'shilmagan; probability distribution `market_state_probabilities`da saqlanadi. Reality Replay hozir snapshot explanation + similarity/memory orqali ishlaydi, alohida candle replay detail sahifasi keyingi yaxshilashga qoladi.

### 19-ETAP — Universal Trading Knowledge Graph `[IMPLEMENTED]`

#### Maqsad

Trades, sessions, hypotheses, beliefs, genomes, market species, discoveries va failures'ni bitta evidence-backed epistemic graph'da bog'lash. Graph vizual effekt emas; tizim nimani bilishi, nega bilishi va qanchalik ishonishini saqlaydi.

#### Knowledge modeli

```text
Claim -> Evidence -> Context -> Confidence -> Validity Period
```

Node va edge misollari:

```text
Strategy --PERFORMS_WELL_IN--> Market Species
Mutation --IMPROVED--> Fitness Metric
Hypothesis --SUPPORTED_BY--> Experiment
Claim --CONTRADICTED_BY--> Session
Agent --DESCENDED_FROM--> Parent Agent
Failure --ASSOCIATED_WITH--> Regime Mismatch
```

#### Knowledge lifecycle

```text
Observation -> Pattern -> Hypothesis -> Validation -> Knowledge
 -> Challenged -> Deprecated
```

#### Asosiy qoidalar

- Association va causation edge'lari alohida.
- Har claim session, trade, experiment, dataset va model versiongacha provenance saqlaydi.
- Qarama-qarshi evidence o'chirilmaydi, contradiction sifatida ulanadi.
- Bilim vaqt va market regime bo'yicha versionlanadi.
- Confidence LLM tomonidan belgilanmaydi; sample size, effect size, OOS validation, stability va recency orqali hisoblanadi.
- Past sample'li discovery `candidate_knowledge`; u hali tasdiqlangan fact emas.
- Failed va extinct strategiyalar survivorship bias'ni kamaytirish uchun graph'da qoladi.

#### Implementatsiya qilingan entity/jadvallar

```text
knowledge_graph_nodes
knowledge_graph_edges
knowledge_claims
knowledge_evidence
knowledge_queries
knowledge_mining_runs
```

#### Knowledge Center

```text
Knowledge Center
  - Knowledge Graph
  - Discoveries
  - Research Assistant
  - Failure Analysis
  - Pattern Explorer
  - Knowledge Timeline
```

Research Copilot faqat graph'dagi claim va evidence'ga tayangan holda javob beradi, citation/evidence count, confidence va alternative explanation'ni ko'rsatadi.

#### Implementatsiya fayllari

```text
backend-laravel/app/Services/UniversalKnowledgeGraphService.php
backend-laravel/app/Http/Controllers/KnowledgeCenterController.php
backend-laravel/app/Console/Commands/MineKnowledgeGraph.php
backend-laravel/app/Models/KnowledgeGraphNode.php
backend-laravel/app/Models/KnowledgeGraphEdge.php
backend-laravel/app/Models/KnowledgeClaim.php
backend-laravel/app/Models/KnowledgeEvidence.php
backend-laravel/app/Models/KnowledgeQuery.php
backend-laravel/app/Models/KnowledgeMiningRun.php
backend-laravel/database/migrations/2026_06_24_190000_create_universal_knowledge_graph_tables.php
backend-laravel/resources/views/knowledge-center/index.blade.php
backend-laravel/tests/Feature/UniversalKnowledgeGraphTest.php
```

#### Hozirgi cheklov

Graph hozir relational DB node/edge modeli sifatida ishlaydi; Neo4j yoki vector search ishlatilmaydi. Meta Intelligence audit trail canonical 21 sifatida qo'shildi, lekin `knowledge_contexts`, `knowledge_versions`, `knowledge_validation_runs` va `knowledge_citations` kabi chuqur validation entity'lari hali keyingi chuqurlashtirishga qoladi. Research Assistant rule-based graph retrieval qiladi; LLM chatbot emas va javobni faqat stored node/edge/claim evidence'dan yig'adi.

### 20-ETAP — Future Simulation & Planning Engine `[IMPLEMENTED]`

#### Maqsad

Bitta kelajakni “bashorat qilish” emas, ko'plab ehtimoliy market future'larini generatsiya qilib, qaysi qaror ularning ko'pida omon qolishini aniqlash.

```text
Current Market State
 -> Conditional Scenario Generation
 -> Probability / Uncertainty
 -> Strategy Survival
 -> Tail-risk Stress Test
 -> Risk-adjusted Planning Decision
 -> Real Outcome
 -> Forecast Calibration
```

#### Scenario generatorlar

- historical block bootstrap;
- Market Species transition modeli;
- volatility/liquidity stress modeli;
- conditional generative quant model;
- Knowledge Graph'dan versioned prior probabilities.

LLM future price path yaratmaydi. Quant simulation engine statistical scenario'larni yaratadi; AI natijani izohlaydi va rejalashtiradi.

#### Planning modeli

```text
Decision Utility =
  Expected Return
  - Drawdown Penalty
  - Tail Risk
  - Uncertainty Penalty
  - Regime Mismatch
```

Current confidence yuqori, ammo future robustness past bo'lsa position size kamaytiriladi yoki WAIT tanlanadi.

`Scenario survival` aniq limitlarga ega bo'ladi: maximum drawdown, risk of ruin, minimum profit factor va market branch coverage.

#### Forecast verification

Har forecast keyinchalik actual outcome bilan tekshiriladi. Calibration uchun Brier score, log loss, calibration curve va interval coverage ishlatiladi. Model version, input snapshot, Knowledge Graph snapshot va random seed saqlanadi.

#### Implementatsiya qilingan entity/jadvallar

```text
future_simulation_runs
future_scenarios
future_probability_nodes
future_timeline_forecasts
strategy_survival_forecasts
future_stress_tests
future_discoveries
```

#### Dashboard

```text
Future Intelligence
  - Future Map
  - Probability Tree
  - Scenario Lab
  - Survival Forecast
  - Future Stress Tests
  - Market Futures
```

Knowledge Graph simulator uchun prior berishi mumkin, lekin simulation natijasi real outcome bilan tasdiqlanmaguncha knowledge fact bo'lmaydi. Bu circular evidence'ni oldini oladi.

#### Implementatsiya fayllari

```text
backend-laravel/app/Services/FutureSimulationService.php
backend-laravel/app/Http/Controllers/FutureIntelligenceController.php
backend-laravel/app/Console/Commands/RunFutureSimulation.php
backend-laravel/app/Models/FutureSimulationRun.php
backend-laravel/app/Models/FutureScenario.php
backend-laravel/app/Models/FutureProbabilityNode.php
backend-laravel/app/Models/FutureTimelineForecast.php
backend-laravel/app/Models/StrategySurvivalForecast.php
backend-laravel/app/Models/FutureStressTest.php
backend-laravel/app/Models/FutureDiscovery.php
backend-laravel/database/migrations/2026_06_24_200000_create_future_simulation_tables.php
backend-laravel/resources/views/future-intelligence/index.blade.php
backend-laravel/tests/Feature/FutureSimulationEngineTest.php
```

#### Hozirgi cheklov

Hozirgi implementation statistical scenario planningning deterministic v1 ko'rinishi: latest Market Genome + Knowledge Graph priorlardan aggregated 1000 scenario branch hisoblaydi. `planning_decisions`, `forecast_outcomes`, `forecast_calibrations`, `simulation_model_versions` hali alohida jadvallar sifatida qo'shilmagan. Real forecast verification va calibration future/deferred Research Director layer hamda Meta Intelligence bilan chuqurlashadi.

### Future/Deferred Layer — Autonomous Research Director `[DECIDED, NOT PRODUCTION]`

#### Maqsad

Tizim faqat o'rganmaydi; **nimani keyin o'rganish eng qimmatli ekanini** aniqlaydi. AI yordamchi yoki oddiy experiment generator emas, laboratoriyaning evidence-driven ilmiy direktori sifatida research opportunity topadi, tajribalarni pre-register qiladi, cheklangan research budget'ni taqsimlaydi va natijalarni institutional memory'ga qaytaradi.

#### Asosiy flow

```text
Knowledge Gaps + Failures + Contradictions + Forecast Errors
 -> Research Opportunity Mining
 -> Research Council Deliberation
 -> Pre-registered Proposal
 -> Cost / Risk / Expected Information Gain
 -> Portfolio Prioritization
 -> Budget Allocation
 -> Sandboxed Experiment
 -> Independent Evaluation
 -> Knowledge Graph Update
 -> Research Roadmap Revision
```

#### Research Opportunity manbalari

- Market Species bo'yicha strategy performance gap;
- takrorlanayotgan failed hypothesis va extinct genome sabablari;
- Knowledge Graph'dagi contradiction yoki low-confidence claim;
- Future Engine forecast miscalibration'i;
- yuqori adaptation pressure va contextual reputation pasayishi;
- yaxshi natija bergan, lekin sabab mexanizmi noma'lum discovery;
- yetarli coverage bo'lmagan symbol, timeframe yoki market regime.

#### Pre-registered Experiment Contract

Har tajriba ishga tushishidan oldin o'zgarmas contract yaratadi:

```json
{
  "research_question": "Can a compression filter improve breakout survival in Species #41?",
  "hypothesis": "Filter increases OOS survival by at least 5 percentage points",
  "intervention": "add_volatility_compression_filter",
  "control": "breakout_parent_genome",
  "dataset_snapshot": "xauusd_h1_2026q2",
  "primary_metric": "oos_survival_rate",
  "guardrail_metrics": ["max_drawdown", "trade_coverage", "complexity"],
  "success_threshold": 5.0,
  "stop_conditions": ["budget_exhausted", "ruin_limit_exceeded"],
  "estimated_cost": 12,
  "random_seed_policy": "fixed_and_recorded"
}
```

Metric, success threshold yoki dataset experiment natijasi ko'rilgandan keyin yashirincha o'zgartirilmaydi. O'zgarish kerak bo'lsa yangi experiment version yaratiladi.

#### Research Priority va Economy

Expected impact'ning o'zi yetarli emas. Priority quyidagilarni birlashtiradi:

```text
Research Priority =
  Expected Information Gain
  + Expected Reusable Impact
  + Strategic Relevance
  + Urgency / Regime Drift
  + Evidence Gap Reduction
  - Compute / Time Cost
  - Experiment Risk
  - Duplication Penalty
  - Overfitting Risk
```

`Expected Impact` va `Confidence` keyinchalik actual result bilan calibration qilinadi. Research Director doim optimistik yoki pessimistik proposal berayotgan bo'lsa uning planning reputation'i pasayadi.

Research budget bitta tajribaga berilmaydi; portfolio sifatida taqsimlanadi:

- exploitation — mavjud kuchli knowledge'ni yaxshilash;
- exploration — yangi mechanism va strategy family izlash;
- replication — muhim discovery'larni qayta tekshirish;
- maintenance — forecast calibration, drift va data quality muammolari;
- reserve — kutilmagan market regime uchun qoladigan budget.

#### Research Council

Council rollari alohida evidence va objective bilan qatnashadi:

```text
Strategy Agents      -> performance muammosi va opportunity
Market Intelligence  -> regime/species o'zgarishi
Future Engine        -> probable future stress va uncertainty
Knowledge Graph      -> existing evidence, conflict va prior work
Risk Auditor         -> tail risk, leakage va invalid experiment
Skeptic Agent        -> alternative explanation va falsification test
Research Director    -> yakuniy ranked proposal portfolio
```

Council consensus majburiy emas. Dissent va rejected argumentlar ham saqlanadi; bu groupthink va chiroyli, lekin bir tomonlama AI narrative'ni kamaytiradi.

#### Failed Research Archive

Failed experiment o'chirilmaydi va “foydasiz” deb yopilmaydi. Quyidagilar saqlanadi:

- original hypothesis va pre-registration;
- dataset/model/code version;
- failure reason va guardrail violation;
- null/negative result;
- qaysi sharoitda qayta sinash mumkinligi;
- duplicate research'ni bloklaydigan similarity fingerprint.

Bir xil failed experiment faqat yangi evidence, boshqa regime yoki aniq metodologik tuzatish mavjud bo'lsa qayta ochiladi.

#### Discovery Impact

Discovery impact faqat nechta strategy ishlatganiga qarab emas, causal attribution va downstream value bilan hisoblanadi:

```text
Impact =
  Replicated Improvement
  x Number of Independent Consumers
  x Persistence Over Time
  x Risk Reduction
  x Confidence
```

Discovery ishlatilgan bo'lsa-yu natijani real yaxshilamagani aniqlansa impact qayta pasaytiriladi.

#### Governance va Autonomy Chegaralari

> [!CAUTION]
> Autonomous Research Director sandbox ichida avtonom. U real pul bilan trade ochmaydi, production/live strategiyani approval'siz deploy qilmaydi, research budget limitini oshirmaydi va o'z evaluation/governance qoidalarini mustaqil o'zgartirmaydi.

Human approval talab qilinadigan holatlar:

- live trading yoki production promotion;
- yangi external data/API xarajati;
- compute/time budget limitidan chiqish;
- evaluation metric yoki risk guardrail'ni o'zgartirish;
- yangi strategy code primitive yoki xavfli execution capability;
- yuqori blast-radius migration yoki data deletion.

Research Director proposal yaratishi va tasdiqlangan sandbox experimentlarni avtomatik yuritishi mumkin. Approval, execution va evaluation rollari audit uchun alohida saqlanadi.

#### Rejalashtirilgan entity/jadvallar

```text
research_opportunities
research_proposals
research_dependencies
research_queue_items
research_budgets
research_allocations
research_council_sessions
research_council_arguments
research_experiments
experiment_registrations
experiment_runs
experiment_outcomes
failed_research_archive
discovery_impact_scores
research_roadmap_versions
research_approvals
research_director_calibrations
```

#### Research State Machine

```text
OPPORTUNITY
 -> PROPOSED
 -> COUNCIL_REVIEW
 -> APPROVED / REJECTED
 -> QUEUED
 -> RUNNING
 -> EVALUATING
 -> CONFIRMED / FAILED / INCONCLUSIVE
 -> KNOWLEDGE_UPDATED
 -> CLOSED / REPLICATION_REQUIRED
```

`INCONCLUSIVE` natija `FAILED` bilan bir xil emas. Yetarli power yoki data bo'lmasa tizim soxta xulosa chiqarmaydi.

#### Dashboard

```text
Research Directorate
  - Research Queue
  - Research Budget
  - Research Council
  - Active Experiments
  - Failed Experiments
  - Research Roadmap
  - Top Discoveries
  - Director Calibration
  - Approval Inbox
```

#### 19–21 integratsiyasi

```text
Knowledge Graph   -> nimalar ma'lum va qayerda knowledge gap bor
Future Engine     -> qaysi muammo kelajakda qimmatga tushishi mumkin
Research Director -> qaysi savolni qachon va qancha budget bilan tekshirish kerak
Experiment Result -> Knowledge Graph va Director calibration'iga qaytadi
```

### 22-ETAP — Meta Intelligence Engine `[DECIDED]`

#### Maqsad

Tizim market, agent va research'ni kuzatish bilan cheklanmaydi; **o'z bilimlarining ishonchliligi, eskirishi, qarama-qarshiligi va chegaralarini** muntazam audit qiladi. Bu oddiy weekly AI summary emas, Knowledge Graph ustidagi mustaqil epistemic governance qatlamidir.

Meta Intelligence quyidagi savollarga javob beradi:

```text
Biz bilamiz deb o'ylayotgan narsa hali ham to'g'rimi?
Qaysi claim eskiryapti yoki context'dan chiqib ketdi?
Qaysi bilimlar bir-biriga zid?
Qayerda evidence yetarli emas?
Qaysi market hududini umuman o'rganmaganmiz?
Research Director noto'g'ri objective yoki pattern ortidan ketmayaptimi?
```

#### Asosiy flow

```text
Knowledge Claims + Beliefs + Forecasts + Research Decisions
 -> Risk-based Audit Scheduler
 -> Fresh Holdout / New Time Window / Replication
 -> Drift + Contradiction + Coverage + Calibration Checks
 -> Falsification Attempt
 -> Epistemic Status / Confidence Update
 -> Quarantine / Abstain / Research Ticket
 -> Knowledge Graph Version
 -> Meta Self-Critique
```

#### Layer 1 — Knowledge Audit

Har claim bir xil weekly intervalda emas, risk va impact bo'yicha audit qilinadi. High-impact yoki live decision'ga ta'sir qiladigan knowledge tez-tez, past-impact archived claim esa kamroq tekshiriladi.

Audit original training evidence'ni qayta hisoblash bilangina cheklanmaydi. U quyidagilardan kamida bittasini talab qiladi:

- yangi vaqt segmenti;
- untouched holdout dataset;
- boshqa symbol yoki timeframe replication'i;
- boshqa implementation/model orqali independent reproduction;
- claim'ni rad etishga qaratilgan falsification test.

Har audit original claim, data snapshot, code/model version, test protocol va result'ga immutable link saqlaydi.

#### Layer 2 — Belief Decay va Knowledge Aging

Belief confidence faqat vaqt o'tgani uchun ko'r-ko'rona kamaymaydi. Decay policy quyidagilarga bog'liq:

```text
Effective Confidence =
  Evidence Strength
  x Replication Factor
  x Recency Weight
  x Regime Relevance
  x Calibration Quality
  x Provenance Quality
  - Contradiction Penalty
  - Drift Penalty
```

Har knowledge family o'z half-life'iga ega bo'lishi mumkin. Masalan, market microstructure claim tezroq, fundamental matematik invariant sekinroq eskiradi. Yangi evidence yo'q bo'lsa confidence pasayishi mumkin, lekin claim avtomatik “false” bo'lib qolmaydi.

#### Layer 3 — Contradiction Detector

Contradiction uch turga ajratiladi:

- **direct contradiction** — bir xil context va metric'da qarama-qarshi natija;
- **contextual divergence** — turli regime, timeframe yoki population sabab ikkalasi ham lokal to'g'ri bo'lishi mumkin;
- **methodological conflict** — dataset, cost model, leakage yoki evaluation farqi natijani o'zgartirgan.

Conflict aniqlansa claim darhol o'chirilmaydi. U `CHALLENGED` yoki yuqori riskda `QUARANTINED` holatiga o'tadi va Research Director uchun falsification/replication ticket ochiladi.

#### Layer 4 — Unknown Zone Detector

“Men bilmayman” hissiy matn emas, measurable out-of-distribution va evidence coverage qaroridir:

```text
Unknown Territory =
  low historical similarity
  OR low effective sample size
  OR high model disagreement
  OR poor calibration coverage
  OR unseen Market Genome combination
```

Unknown zone aniqlansa:

- confidence va position-size ceiling pasayadi;
- high-risk action uchun `ABSTAIN/WAIT` tavsiya qilinadi;
- Future Engine scenario uncertainty'ni kengaytiradi;
- Research Director'ga data collection yoki exploration opportunity yuboriladi.

#### Layer 5 — Blind Spot Finder

Blind spot faqat kam uchragan combination emas. U qaror uchun muhim, ammo coverage, experiment yoki valid knowledge yetarli bo'lmagan hududdir.

Coverage cube misoli:

```text
Symbol x Timeframe x Market Species x Volatility
x Liquidity Proxy x Strategy Family x Action
```

Blind Spot priority uning rarity'si bilan emas, exposure, possible loss, future probability, uncertainty va researchability bilan hisoblanadi. O'lchab bo'lmaydigan hudud “kashfiyot” emas, `unobservable limitation` sifatida qayd qilinadi.

#### Layer 6 — Knowledge Health Score

Bitta 87% score kritik muammoni yashirmasligi kerak. Dashboard composite score bilan birga alohida dimension va hard alert'larni ko'rsatadi:

```text
Freshness
Replication
Coverage
Calibration
Contradiction Rate
Provenance Completeness
Reproducibility
Unknown Exposure
Critical Quarantined Claims
```

Masalan global health 87% bo'lsa ham live risk sizing'da ishlatilayotgan claim quarantined bo'lsa dashboard qizil critical alert beradi.

#### Layer 7 — AI Self Critic

Self Critic yangi fakt yoki confidence yaratmaydi. U audit result'laridan evidence-linked meta report tuzadi:

```text
Self Critique
 -> What was overestimated/underestimated?
 -> Which evidence changed?
 -> Which systems were affected?
 -> What action was taken?
 -> What remains unknown?
 -> What could falsify the new conclusion?
```

Self-critique matni audit ID, claim ID, evidence va action bilan bog'lanmasa authoritative hisoblanmaydi.

#### Epistemic State Machine

```text
CANDIDATE
 -> ACTIVE
 -> AGING
 -> CHALLENGED
 -> QUARANTINED
 -> REVALIDATED / DEPRECATED / INVALIDATED
```

- `DEPRECATED` — oldin foydali bo'lgan, hozir context/vaqt sabab ishlatilmaydi.
- `INVALIDATED` — yetarli counter-evidence bilan rad etilgan.
- `QUARANTINED` — audit tugamaguncha live decision va research prior sifatida ishlatilmaydi.
- Oldingi confidence va statuslar o'zgartirib yozilmaydi; yangi version/history record yaratiladi.

#### Epistemic Firewall

> [!CAUTION]
> Meta Engine evidence'ni o'zgartirmaydi yoki o'chirmaydi. U faqat immutable audit result asosida claim status, confidence va usage policy uchun yangi version taklif qiladi.

Self-confirmation loop'ni oldini olish uchun:

- claim yaratgan ayni model yolg'iz auditor bo'la olmaydi;
- audit uchun fresh holdout yoki yangi evidence kerak;
- Knowledge Graph o'z claim'ini o'ziga evidence qilib bera olmaydi;
- Research Director va Meta Auditor rollari ajratiladi;
- audit metric va threshold'lari natijadan oldin versionlanadi;
- high-impact invalidation uchun human review talab qilinadi.

#### Multiple Testing va False Discovery nazorati

Minglab pattern audit qilinganda tasodifiy “discovery”lar paydo bo'ladi. Meta Engine quyidagilarni hisobga oladi:

- multiple hypothesis correction / false discovery rate;
- selection va survivorship bias;
- repeated tuning natijasidagi data snooping;
- effect size va confidence interval;
- minimum effective sample size;
- independent replication.

Statistik significance economic relevance va robustness o'rnini bosmaydi.

#### Meta Actions

Audit natijasiga qarab tizim:

- claim confidence'ni qayta hisoblaydi;
- knowledge'ni quarantine/deprecate qiladi;
- Future Engine prior'ini pasaytiradi;
- strategy position-size ceiling'ini tushiradi;
- Research Director'ga replication yoki contradiction ticket ochadi;
- xavfli dependent experimentlarni pause qiladi;
- human approval inbox'ga epistemic incident yuboradi.

#### Rejalashtirilgan entity/jadvallar

```text
knowledge_audits
knowledge_audit_runs
audit_schedules
falsification_tests
claim_confidence_history
knowledge_decay_policies
knowledge_health_snapshots
meta_contradiction_cases
unknown_zones
knowledge_coverage_maps
blind_spots
unobservable_limitations
knowledge_quarantines
epistemic_incidents
meta_actions
meta_self_critiques
meta_auditor_calibrations
```

19-etapdagi `knowledge_conflicts`, `knowledge_versions`, `knowledge_evidence` va `knowledge_validation_runs` qayta yaratilmaydi; Meta Engine shu entity'lar bilan relation orqali ishlaydi.

#### Dashboard

```text
Meta Intelligence
  - Knowledge Health
  - Knowledge Audit
  - Belief Health
  - Contradictions
  - Blind Spots
  - Unknown Zones
  - Quarantined Knowledge
  - Epistemic Incidents
  - Self Critiques
  - Auditor Calibration
```

#### 19-Meta Feedback Loop

```text
Knowledge Graph      -> current claims, evidence va dependencies
Future Engine        -> forecast errors va unknown exposure
Research Director    -> research choices va experiment outcomes
Meta Intelligence   -> audit, doubt, quarantine va blind spots
Research Director    -> replication/falsification research
Knowledge Graph      -> versioned corrected knowledge
```

Meta Engine Research Director'dan yuqori hokim emas. U mustaqil epistemic auditor: nima tadqiqot qilinishini direktor boshqaradi, olingan bilim qanchalik ishonchli ekanini Meta Engine tekshiradi.

### 23-ETAP — Artificial Quant Civilization / Organization Engine `[DECIDED]`

#### Maqsad va nomlash

Texnik nomi **Artificial Quant Organization Engine**, UI va konseptual nomi **AI Civilization**. Maqsad agentlarni antropomorfik “jamiyat” qilib ko'rsatish emas; mustaqil rollar, vakolatlar, javobgarlik, xotira, resurs taqsimoti va governance orqali self-directed quant organization yaratish.

Tizim endi faqat `qaysi strategy yaxshi?` yoki `qaysi research kerak?` demaydi. U:

```text
Qaysi institution rollari kerak?
Kim nima uchun javobgar?
Qaysi qaror qaysi vakolat bilan qabul qilinadi?
Qaysi maqsadlar bir-biriga zid?
Tashkilot qaysi capability'ni rivojlantirishi kerak?
```

savollarini boshqaradi.

#### Civilization Member va Institutional Roles

Har member versionlangan identity, charter, capability va permission scope'ga ega:

```text
Research Agent       -> opportunity va experiment proposal
Risk Agent           -> ruin, drawdown va guardrail nazorati
Market Agent         -> Market Species va regime intelligence
Evolution Agent      -> genome mutation/crossover proposal
Knowledge Agent      -> claim, provenance va retrieval
Prediction Agent     -> scenario va forecast calibration
Meta Auditor         -> knowledge/research audit va quarantine
Resource Steward     -> compute/research budget accounting
Council Facilitator  -> protocol, quorum va decision ledger
```

Bir agent bir nechta conflict qiluvchi rolni bir qarorda bajarmaydi. Masalan proposal yaratgan agent o'sha proposal'ning mustaqil auditor yoki yakuniy approver'i bo'la olmaydi.

#### Agent Lifecycle

```text
PROPOSED
 -> SANDBOXED
 -> PROBATION
 -> ACTIVE
 -> LIMITED / SUSPENDED
 -> RETIRED / ARCHIVED
```

Yangi agent identity yaratish tekin emas. U duplicate capability va “sybil” agentlar ko'payishini oldini oluvchi admission review'dan o'tadi. Agent retire bo'lsa uning knowledge, decisions, failures va provenance'i institutional memory'da qoladi; reputation esa yangi version'ga avtomatik ko'chmaydi.

#### Internal Economy

`Research Credits` pul, mulk yoki agentning shaxsiy boyligi emas. Ular non-transferable, expiry'ga ega, audit qilinadigan compute/time/data budget birliklaridir.

Credit allocation quyidagilarga tayangan holda beriladi:

```text
Verified Information Gain
+ Replicated Impact
+ Prevented Risk
+ Calibration Quality
+ Reusable Institutional Value
- Compute Waste
- Duplicate Research
- Guardrail Violations
- Overclaiming / Poor Calibration
```

Credit faqat discovery soni uchun berilmaydi. Aks holda agent ko'p, lekin arzimas pattern ishlab Goodhart loop yaratadi. Reward delayed outcome va independent attribution'dan keyin final bo'ladi.

Asosiy economy qoidalari:

- agent credits'ni bir-biriga sotmaydi yoki yashirin transfer qilmaydi;
- credit issuance va burn faqat versioned policy orqali;
- har transaction'ning purpose, approver va outcome link'i bor;
- unused budget hoarding uchun reputation bonus bermaydi;
- exploration, replication, maintenance va emergency reserve alohida envelope'larda saqlanadi;
- yuqori reputation cheksiz budget yoki voting power bermaydi.

#### Multidimensional Agent Reputation

Bitta global reputation noto'g'ri. Har agent domain va context bo'yicha score oladi:

```text
Forecast Calibration
Research Impact
Risk Prevention
Evidence Quality
Reproducibility
Cost Efficiency
Abstention Quality
Governance Reliability
Regime / Domain Scope
```

Prediction Agent uchun “87% forecast to'g'ri” yetarli emas; base rate, Brier/log loss, calibration, coverage va qachon `UNKNOWN/ABSTAIN` degani ham hisoblanadi.

Reputation:

- evidence va attribution'dan hisoblanadi, LLM bermaydi;
- decay va confidence interval'ga ega;
- agent versioni va vazifa context'i bilan bog'langan;
- o'z-o'zini baholash orqali o'zgarmaydi;
- council voting power'iga cheklangan va domain-specific ta'sir qiladi.

#### Agent Council va Decision Protocol

Council oddiy majority vote emas. Har proposal uchun decision contract yaratiladi:

```text
Proposal
 -> Disclosed Evidence
 -> Conflict-of-Interest Check
 -> Domain Expert Arguments
 -> Skeptic / Counterargument
 -> Risk and Epistemic Review
 -> Quorum
 -> Decision Rule
 -> Approve / Reject / Defer / Request Evidence
 -> Outcome Review
```

Decision rule proposal riskiga qarab farqlanadi:

- low-risk sandbox research — domain-weighted majority;
- high compute budget — Resource Steward approval;
- quarantined knowledge ishlatish — Meta Auditor approval;
- risk guardrail violation — Risk Agent veto;
- live/production deployment — human approval majburiy;
- constitution yoki goal hierarchy o'zgarishi — human ratification majburiy.

Veto ham cheksiz emas: sabab, evidence va scope bilan yoziladi; abuse yoki doimiy overblocking keyinchalik audit qilinadi. Dissent, abstention va minority report collective memory'da qoladi.

#### Agent Politics uchun Mechanism Design

“Politics” narrative emas, competing objectives'ni shaffof hal qilish mexanizmidir. Quyidagi xavflar nazorat qilinadi:

- reputation gaming;
- agent collusion va reciprocal voting;
- budget capture;
- sybil agents;
- reputation laundering orqali yangi version ochish;
- majority groupthink;
- Risk Agent'ning hamma yangilikni bloklashi;
- Research Agent'ning novelty ortidan overfitting qilishi.

Nazoratlar:

- voting power cap;
- conflict-of-interest declaration;
- randomized independent reviewer;
- immutable deliberation log;
- delayed outcome-based reputation;
- rotating council membership;
- appeal va post-decision audit;
- suspicious voting/correlation detector.

#### Civilization Memory va Decision Ledger

Collective memory faqat discovery library emas. Har muhim institutional decision uchun quyidagilar saqlanadi:

```text
Context Snapshot
Proposal and Alternatives
Arguments and Evidence
Votes / Veto / Abstentions
Expected Outcome
Actual Outcome
Counterfactual Alternatives
Who Benefited / Who Was Harmed
Policy Lesson
Knowledge Graph Links
```

Shunda organization “qaysi qaror qabul qilingan?”dan tashqari `qaror jarayoni yaxshi edimi?` va `to'g'ri natija yaxshi reasoning'danmi yoki omad tufaylimi?` savollarini ham audit qiladi.

#### Institutional Knowledge va Succession

Knowledge agent, strategy yoki model version hayotiga bog'lanmaydi. Member retire/extinct bo'lsa:

- valid claim Knowledge Graph'da qoladi;
- ownership institutional custodian'ga o'tadi;
- dependent systems aniqlanadi;
- orphaned knowledge uchun new steward tayinlanadi;
- tacit decision lesson structured memory'ga ko'chiriladi.

Agentning o'zi bilan uning bilimi bir xil entity emas.

#### Civilization Constitution

Organization o'z ultimate objective va safety boundary'larini mustaqil almashtirmaydi. Human-ratified constitution quyidagilarni belgilaydi:

```text
Mission
Permitted Actions
Forbidden Actions
Risk Limits
Data / Compute Budget Ceilings
Approval Boundaries
Evidence Standards
Agent Rights and Responsibilities
Goal Amendment Protocol
Emergency Stop / Recovery
```

Agentlar constitution ichida policy yoki subgoal proposal bera oladi, lekin constitution'ni o'zlari ratifikatsiya qilmaydi.

#### Civilization Goals

Profit yagona scalar objective bo'lmaydi. Goal hierarchy multi-objective va constraint-based bo'ladi:

```text
Survival and Capital Preservation     [hard constraint]
Epistemic Integrity                   [hard constraint]
Legal / Operational Safety            [hard constraint]
Risk-adjusted Sustainable Performance [objective]
Adaptability                          [objective]
Unknown-zone Reduction                [objective]
Forecast Calibration                  [objective]
Knowledge Coverage and Reuse          [objective]
Research Efficiency                   [objective]
```

Goals Pareto trade-off bilan baholanadi. Masalan knowledge coverage oshgani uchun ruin risk oshishi qabul qilinmaydi. AI yangi subgoal va roadmap taklif qilishi mumkin; ultimate goal yoki hard constraint o'zgarishi human approval talab qiladi.

#### Governance State Machine

```text
DRAFT
 -> DELIBERATION
 -> EVIDENCE_REQUESTED
 -> READY_FOR_VOTE
 -> APPROVED / REJECTED / DEFERRED / VETOED
 -> EXECUTING
 -> OUTCOME_REVIEW
 -> RATIFIED / REVERSED / POLICY_UPDATED
```

#### Rejalashtirilgan entity/jadvallar

```text
civilization_charters
governance_rules
governance_rule_versions
civilization_goals
goal_versions
agent_identities
agent_roles
agent_memberships
agent_permissions
agent_lifecycle_events
agent_credit_accounts
agent_credit_transactions
resource_budget_envelopes
agent_reputation_dimensions
agent_reputation_snapshots
council_sessions
council_proposals
council_arguments
council_votes
council_vetoes
conflict_of_interest_declarations
council_decisions
decision_outcome_reviews
civilization_memories
institutional_knowledge_stewards
governance_incidents
```

Oldingi `research_council_sessions`, `research_council_arguments` va research budget entity'lari duplicate qilinmaydi; ular umumiy council/governance modeliga migrate yoki relation qilinadi.

#### Dashboard

```text
AI Civilization
  - Council
  - Economy
  - Reputation
  - Collective Memory
  - Institutional Knowledge
  - Civilization Goals
  - Agent Society
  - Constitution
  - Decision Ledger
  - Governance Incidents
```

#### 21–23 Institutional Loop

```text
Research Director -> research agenda va proposals
Meta Intelligence -> evidence health, unknowns va epistemic veto
AI Council        -> scoped institutional decision
Economy           -> budget va resource allocation
Agent Members     -> role-bounded execution
Decision Ledger   -> expected vs actual outcome
Reputation        -> calibrated institutional accountability
Collective Memory -> future policy va council decisions
```

23-etap agentlarni “erkin siyosiy mavjudot”ga aylantirmaydi. U ko'p agentli tizimni kompaniya darajasidagi separation of duties, memory, incentive design va governance bilan boshqaradi.

### 24-ETAP — Universal Quant Laws Discovery Engine `[DECIDED]`

#### Maqsad va ilmiy terminologiya

Maqsad minglab local discovery orasidan strategy, parameter va alohida dataset'dan yuqoriroq turadigan, turli kontekstlarda takrorlanuvchi **quant invariantlar va general principles**ni topish.

> [!IMPORTANT]
> Trading bozori non-stationary, reflexive va ishtirokchilar xatti-harakatiga bog'liq. Shu sabab `Universal Law` mutlaq fizik qonun degani emas. U aniq scope, assumptions, uncertainty va falsification shartlariga ega **provisional probabilistic law** hisoblanadi. Tizim “universal law mavjud emas” degan natijani ham to'g'ri ilmiy natija sifatida qabul qiladi.

Pattern va law farqi:

```text
Pattern:
EMA Fast 40–55 XAUUSD H1 trend-up segmentida yaxshi ishladi.

Candidate invariant:
Excessive trend dependency turli strategy family va marketlarda
out-of-sample adaptability pasayishi bilan takroran bog'landi.

Provisional law:
Belgilangan domain va assumptions ichida trend dependency oshishi
kelajak regime transferability'ni measurable effect bilan pasaytiradi;
independent replications va falsification tests'dan o'tgan.
```

#### Law Discovery Flow

```text
Discoveries + Failures + Genome History + Market Species
 -> Cross-context Pattern Abstraction
 -> Law Candidate
 -> Formal Definition and Scope
 -> Pre-registered Multi-domain Validation
 -> Heterogeneity and Invariance Tests
 -> Independent Replication
 -> Falsification Attempts
 -> Provisional Law / Conditional Principle / Rejected Candidate
 -> Meta Audit and Temporal Revalidation
```

#### Law Candidate Contract

Har candidate oddiy AI matni emas, formal tekshiriladigan obyekt:

```json
{
  "statement": "High trend dependency reduces forward adaptability",
  "law_type": "probabilistic_directional",
  "variables": {
    "exposure": "trend_dependency",
    "outcome": "forward_adaptability"
  },
  "direction": "negative",
  "scope": {
    "strategy_families": "multiple_independent",
    "assets": "specified",
    "timeframes": "specified",
    "market_species": "specified"
  },
  "assumptions": ["cost_model_fixed", "no_future_leakage"],
  "minimum_effect_size": 0.10,
  "primary_falsifier": "no negative effect in prospective replication",
  "status": "candidate"
}
```

Formal statement o'zgarishi yangi law version yaratadi. Natijani ko'rgandan keyin scope'ni toraytirib candidate'ni sun'iy “tasdiqlash” mumkin emas; post-hoc scope yangi conditional candidate sifatida saqlanadi.

#### Law Turlari

```text
Descriptive Invariant  -> takrorlanuvchi empirical regularity
Predictive Principle   -> future outcome'ni generalize qiladi
Causal Mechanism       -> intervention/falsification bilan qo'llangan sababiy aloqa
Trade-off Law          -> bir xususiyat oshganda boshqasi tizimli kamayadi
Boundary Law           -> qaysi sharoitda boshqa law ishlamasligini belgilaydi
Conservation Constraint-> risk/return/coverage kabi almashinuv chegarasi
```

Association hech qachon avtomatik `causal law`ga promote qilinmaydi.

#### Multi-Evidence Validation

Candidate law maqomiga chiqish uchun evidence diversity talab qilinadi:

- bir-biridan mustaqil strategy families;
- turli agent/genome lineages;
- turli assets va timeframes;
- turli Market Species va volatility/liquidity states;
- turli tarixiy davrlar;
- untouched holdout va prospective test;
- boshqa implementation yoki independent evaluator;
- successful replication bilan birga failed/null evidence.

`87,000 trades` avtomatik kuchli evidence emas. Correlated trade'lar mustaqil sample hisoblanmaydi. Effective sample size quyidagi hierarchy bo'yicha baholanadi:

```text
Trade < Session < Strategy Genome < Strategy Family
< Market Species < Asset < Time Period < Independent Replication
```

Bir strategy'ning minglab trade'i ko'p mustaqil family va davrda takrorlangan result o'rnini bosa olmaydi.

#### Universality va Confidence

Law uchun bitta chiroyli `97%` son yetarli emas. Law card quyidagilarni ko'rsatadi:

```text
Posterior / Calibrated Confidence
Effect Size + Confidence/Credible Interval
Effective Sample Size
Independent Replication Count
Strategy-family Coverage
Market/Asset/Time Coverage
Heterogeneity
Out-of-sample Stability
Falsification Attempts Passed/Failed
Known Exceptions
Applicability Scope
Last Meta Audit
```

Universality score taxminan quyidagi dimensionlardan tuziladi:

```text
Universality =
  Cross-family Invariance
  x Cross-market Stability
  x Temporal Persistence
  x Independent Replication
  x Mechanism Support
  x Falsification Survival
  - Heterogeneity Penalty
  - Exception Penalty
  - Data-dependence Penalty
```

Confidence va universality alohida: tor scope'dagi law juda ishonchli, ammo universal bo'lmasligi mumkin.

#### Validation Methodology

Engine quyidagilarni qo'llaydi:

- hierarchical/meta-analytic models;
- invariant risk / environment tests;
- leave-one-family-out validation;
- leave-one-market/species-out validation;
- rolling temporal holdouts;
- prospective pre-registered replications;
- heterogeneity va interaction tests;
- sensitivity va robustness analysis;
- negative controls va placebo tests;
- multiple-testing / false-discovery correction;
- causal intervention faqat mumkin bo'lgan joyda.

Law candidate topilgan data uning final universal validation'i bo'la olmaydi.

#### Law State Machine

```text
OBSERVATION
 -> REPEATED_PATTERN
 -> LAW_CANDIDATE
 -> VALIDATING
 -> REPLICATED_PRINCIPLE
 -> PROVISIONAL_LAW
 -> ACTIVE
 -> CHALLENGED
 -> CONDITIONAL / WEAKENED / DEPRECATED / INVALIDATED
```

- `CONDITIONAL` — universal emas, aniq boundary conditions ichida ishlaydi.
- `WEAKENED` — direction saqlangan, ammo effect yoki coverage kamaygan.
- `DEPRECATED` — tarixan foydali, hozirgi market ecology uchun active emas.
- `INVALIDATED` — yetarli counter-evidence bilan rad etilgan.
- Oldingi law version va confidence history o'chirilmaydi.

#### Law Graph

Universal Laws Knowledge Graph ustiga alohida semantic layer sifatida quriladi:

```text
Trend Dependency --REDUCES--> Adaptability
Confirmation Depth --REDUCES--> False Entry Rate
Confirmation Depth --TRADES_OFF_WITH--> Responsiveness
Regime Awareness --MODERATES--> Strategy Survival
Law A --BOUNDED_BY--> Low-liquidity Species
Law B --CONTRADICTS--> Law C
Evidence Set --SUPPORTS / WEAKENS / FALSIFIES--> Law Version
```

Law node har doim original evidence, discovery, experiment va auditlargacha provenance beradi.

#### Law Conflicts va Boundary Discovery

Ikki law zid ko'rinsa darhol bittasi noto'g'ri deb olinmaydi. Engine tekshiradi:

- scope farqi;
- hidden moderator;
- temporal regime shift;
- methodology/cost model farqi;
- Simpson's paradox yoki aggregation bias;
- data leakage va selection bias;
- haqiqiy direct contradiction.

Ko'pincha conflict yangi **boundary law** yaratadi: qaysi sharoitda qaysi principle ishlashini aniqlaydi. Direct conflict esa Research Director'ga pre-registered resolution ticket ochadi va Meta Engine ikkala law'ni audit qiladi.

#### Law Evolution va Audit

Laws ham versioned va time-aware:

```text
2027: confidence 91%, scope broad
2029: confidence 58%, heterogeneity increased
Action: WEAKENED yoki CONDITIONAL; dependent policies re-evaluated
```

Meta Intelligence:

- high-impact law'larni risk-based schedule bilan audit qiladi;
- decay, contradiction va new exceptions'ni kuzatadi;
- outdated law'ni quarantine qiladi;
- dependent strategy, Future prior, Council policy va research roadmap'ni qayta tekshirtiradi.

Law statusi institutional vote bilan “haqiqat”ga aylantirilmaydi. Council adoption policy'ni belgilashi mumkin, scientific status esa evidence protocol bilan belgilanadi.

#### Top Drivers / “Holy Grail” Analysis

UI'da marketing nomi ishlatilishi mumkin, lekin engine **Holy Grail mavjud** deb taxmin qilmaydi. Texnik modul `Universal Driver Analysis` bo'ladi.

U:

- repeated high-impact variables;
- cross-domain causal/mechanistic support;
- downstream strategy va risk impact;
- stability va redundancy;
- manipulability/actionability;
- known trade-offs va failure boundaries

bo'yicha top driver'larni rank qiladi.

Top driver avtomatik strategy yoki live rule bo'lmaydi; u Research Director uchun high-value research/policy candidate hisoblanadi.

#### Rejalashtirilgan entity/jadvallar

```text
law_candidates
law_definitions
law_versions
law_scopes
law_assumptions
law_evidence
law_validation_protocols
law_validation_runs
law_replications
law_falsification_tests
law_effect_estimates
law_heterogeneity_results
law_applicability_tests
law_conflicts
law_boundaries
law_dependencies
law_confidence_history
law_audits
universal_driver_rankings
```

19 va 22-etaplardagi Knowledge Graph, evidence, conflict, version va audit entity'lari duplicate qilinmaydi; Law Engine ularga typed relation va specialized validation recordlar orqali ulanadi.

#### Dashboard

```text
Quant Laws
  - Active Laws
  - Emerging Laws
  - Conditional Laws
  - Weak / Aging Laws
  - Contradictions
  - Evidence and Replications
  - Falsification Tests
  - Law Graph
  - Applicability Checker
  - Universal Driver Analysis
  - Law Timeline
```

#### 19–24 Scientific Loop

```text
Knowledge Graph      -> discoveries, evidence va candidate abstractions
Research Director    -> multi-domain validation va falsification agenda
Meta Intelligence   -> confidence, aging, contradiction va audit
AI Civilization     -> resources, independent roles va governance
Law Engine          -> provisional invariant, scope va boundary laws
Future/Strategy Lab -> applicability tests; yangi evidence
Knowledge Graph      -> versioned scientific capital
```

24-etapning eng qimmat mahsuloti ko'p law chiqarish emas. Aksincha, minglab local pattern orasidan juda oz, lekin scope'i aniq, rad etishga ochiq va mustaqil takrorlangan principles'ni ajratib olishdir.

### 25-ETAP — Causal Intelligence Engine `[DECIDED]`

#### Maqsad

Knowledge Graph va Quant Laws'dagi association'larni sabab deb e'lon qilish emas; qaysi relation uchun causal effect identifikatsiya qilinishi mumkinligini aniqlash, explicit assumptions ostida intervention va counterfactual'larni baholash, so'ng sababga yo'naltirilgan experiment/evolution proposal yaratish.

```text
Pattern:      X va Y birga o'zgaradi.
Prediction:   X kuzatilsa Y ehtimoli oshadi.
Intervention: X'ni majburan o'zgartirsak Y qanday o'zgaradi?
Counterfactual: Aynan shu holatda X boshqacha bo'lganida Y nima bo'lardi?
```

> [!CAUTION]
> Counterfactual simulation causal proof emas. U Structural Causal Model va uning assumptions'iga shartlangan estimate. Agar causal effect observational data'dan identifikatsiya qilinmasa engine `NOT_IDENTIFIABLE` qaytaradi; chiroyli raqam ishlab chiqarmaydi.

#### Pearl Causal Ladder

Engine uch darajani qat'iy ajratadi:

```text
1. Association   P(Y | X)          -> nima kuzatildi?
2. Intervention  P(Y | do(X=x))    -> X'ni o'zgartirsak nima bo'ladi?
3. Counterfactual Y_x(u)            -> shu individual holatda boshqacha bo'lsa-chi?
```

Yuqori daraja uchun pastki darajadagi correlation yetarli emas. Har dashboard natijasi qaysi ladder level'da ekanini ko'rsatadi.

#### Causal Intelligence Flow

```text
Law / Discovery / Failure
 -> Causal Question
 -> Candidate DAG / Dynamic SCM
 -> Domain and Temporal Constraints
 -> Confounder / Mediator / Collider Review
 -> Identifiability Gate
 -> Pre-registered Estimand and Adjustment Set
 -> Experiment / Quasi-experiment / Observational Estimation
 -> Sensitivity + Falsification + Negative Controls
 -> Replication and Transportability
 -> Causal Edge / Conditional Cause / Not Identifiable / Refuted
 -> Intervention Policy Proposal
```

#### Structural Causal Model

Trading system feedback va vaqtga bog'liq bo'lgani uchun bitta static DAG yetmaydi. Engine time-indexed Dynamic Structural Causal Model ishlatadi:

```text
Market State(t)
 -> Signal Features(t)
 -> Decision(t)
 -> Position / Exposure(t)
 -> Outcome(t+1...t+h)
 -> Agent Belief / Evolution(t+h)

Past Outcome(t-1) -> Current Strategy / Market Selection(t)
```

Cycle'lar bitta vaqt kesimida yashirilmaydi; time-unrolled graph orqali feedback qaysi vaqtda sodir bo'lgani ko'rsatiladi.

Har causal node/edge quyidagilarni saqlaydi:

```text
Cause and Effect Variables
Time Lag / Horizon
Edge Status
Estimand
Adjustment Set
Assumptions
Evidence Type
Effect Estimate + Interval
Heterogeneity / Moderators
Transportability Scope
Known Violations
Falsifier
Model and Data Version
```

#### Causal Edge Statuslari

```text
HYPOTHESIZED
 -> OBSERVATIONALLY_SUPPORTED
 -> IDENTIFIABLE
 -> INTERVENTION_SUPPORTED
 -> REPLICATED_CAUSAL
 -> CHALLENGED / CONTEXTUAL / NOT_IDENTIFIABLE / REFUTED
```

Graph discovery algoritmi topgan arrow faqat `HYPOTHESIZED`. `REPLICATED_CAUSAL` uchun independent intervention yoki kuchli quasi-experimental evidence va transportability test talab qilinadi.

#### Identifiability Gate

Causal estimate chiqarishdan oldin engine tekshiradi:

- temporal ordering;
- known va plausible confounders;
- collider'ga noto'g'ri condition qilinmaganligi;
- mediator adjustment estimand'ni buzmasligi;
- positivity/overlap;
- consistency;
- selection va survivorship bias;
- measurement error;
- interference/SUTVA violation — bir strategy yoki agent boshqasiga ta'sir qilishi;
- missing-not-at-random data;
- target population va transportability.

Natijalar:

```text
IDENTIFIABLE
PARTIALLY_IDENTIFIABLE / BOUNDED
IDENTIFIABLE_ONLY_UNDER_STRONG_ASSUMPTIONS
NOT_IDENTIFIABLE
```

Strong assumption dashboardda yashirilmaydi va confidence'dan alohida ko'rsatiladi.

#### Causal Discovery Engine

Causal discovery algoritmlari candidate graph yaratishi mumkin, lekin final truth emas. Ular:

- temporal order va impossible edge constraints;
- domain knowledge;
- multiple environment invariance;
- score/constraint-based discovery agreement;
- latent confounder possibility;
- stability bootstrap

bilan cheklanadi.

Model disagreement bo'lsa bir nechta Markov-equivalent graph saqlanadi va qaysi intervention ularni ajratishi Research Director'ga yuboriladi.

#### Quant Experiment Turlari

Evidence hierarchy:

```text
Randomized Paired Backtest Experiment
 -> Randomized Sandbox / Paper-trading Experiment
 -> Prospective Controlled Rollout
 -> Natural Experiment / Quasi-experiment
 -> Longitudinal Observational Study
 -> Cross-sectional Association
 -> Simulation-only Counterfactual
```

Strategy parameter yoki filter'ni frozen market segments, bir xil seeds, fee/slippage va paired control bilan randomize qilish mumkin. Market panic yoki liquidity'ni real hayotda majburan yaratib bo'lmaydi; bunday claim uchun natural experiment, multiple environments yoki model-dependent stress simulation ishlatiladi va evidence level pastroq ko'rsatiladi.

#### Counterfactual Laboratory

Counterfactual request contract:

```json
{
  "factual_case": "strategy_genome_v18_session_442",
  "intervention": {"trend_dependency": 0.50},
  "outcome": "future_survival",
  "horizon": 100,
  "scm_version": "dscm_v7",
  "estimand": "individual_treatment_effect",
  "assumptions": ["structural_invariance", "no_unmeasured_confounding"],
  "uncertainty_required": true
}
```

Natija:

```text
Estimated survival change: +21 percentage points
Interval: [+7, +29]
Evidence level: model-based counterfactual
Sensitivity: effect disappears if hidden confounding > threshold K
Applicability: listed strategy families / market species only
```

`+21%` universal fact yoki guaranteed outcome sifatida ko'rsatilmaydi.

#### Intervention Engine

Root cause topilgach engine bitta tavsiyaga sakramaydi. U candidate intervention'larni solishtiradi:

```text
Expected Causal Effect
Uncertainty
Intervention Cost
Side Effects
Mediator / Downstream Impact
Risk Guardrails
Reversibility
Transportability
Information Gain
```

Masalan trend dependency kamaytirish adaptability'ni oshirishi mumkin, ammo entry coverage yoki trend-market profit'ni pasaytirishi mumkin. Intervention net causal utility va Pareto constraints bilan baholanadi.

Evolution Genome faqat approved sandbox intervention'dan yangi child genome yaratadi. Causal estimate production/live deployment'ni avtomatik tasdiqlamaydi.

#### Discovery Causal Quality

Correlation va causality ikkita oddiy percentage bilan cheklanmaydi. Discovery card:

```text
Association Strength
Causal Identification Level
Effect Size + Interval
Assumption Burden
Unmeasured-confounding Sensitivity
Interventional Evidence
Replication
Transportability
Falsification Survival
Decision Relevance
```

Kuchli correlation, lekin `NOT_IDENTIFIABLE` discovery predictive jihatdan foydali bo'lishi mumkin; u causal explanation sifatida ishlatilmaydi.

#### Root Cause Analysis

Top root cause ranking faqat feature importance, SHAP yoki correlation emas. Attribution va causality ajratiladi.

Root cause candidate:

- outcome'ga temporally oldin keladi;
- intervention orqali outcome'ni o'zgartirishi identifikatsiya qilingan;
- alternative paths/confounders nazorat qilingan;
- multiple contexts'da takrorlangan;
- actionable yoki aniq structural mechanism'ga ega;
- downstream side effect'lari ma'lum.

Population Average Treatment Effect individual strategy uchun ayni effect degani emas. Heterogeneous treatment effect strategy family va Market Species bo'yicha ko'rsatiladi.

#### Causal Conflicts va Model Competition

Bir natija uchun bir nechta plausible SCM parallel saqlanadi. Engine:

- qaysi graph'lar observationally equivalent;
- qaysi missing variable farqni tushuntirishi;
- qaysi intervention eng ko'p information gain berishi;
- qaysi model prospective outcome'ni yaxshi predict qilishi

bo'yicha model competition yuritadi.

Council ovozi sababiy haqiqatni belgilamaydi. Council qaysi experimentni moliyalashtirishni hal qiladi; causal status evidence protocol bilan belgilanadi.

#### Causal State Machine

```text
QUESTION
 -> CAUSAL_HYPOTHESIS
 -> GRAPH_PROPOSED
 -> IDENTIFIABILITY_REVIEW
 -> NOT_IDENTIFIABLE / ESTIMATING
 -> OBSERVATIONAL_SUPPORT
 -> INTERVENTION_TESTING
 -> REPLICATED_CAUSAL / CONDITIONAL_CAUSAL
 -> CHALLENGED / REFUTED / DEPRECATED
```

#### Rejalashtirilgan entity/jadvallar

```text
causal_questions
causal_models
causal_model_versions
causal_nodes
causal_edges
causal_edge_evidence
causal_assumptions
causal_adjustment_sets
causal_identifiability_reviews
causal_estimands
causal_effect_estimates
causal_heterogeneity_results
causal_discovery_runs
causal_model_competitions
causal_experiments
causal_interventions
counterfactual_queries
counterfactual_results
causal_sensitivity_analyses
causal_falsification_tests
causal_transportability_tests
causal_conflicts
root_cause_rankings
intervention_policies
```

15-etapdagi `counterfactual_runs`, 19-etap Knowledge Graph, 21-etap research experiments va 24-etap law evidence duplicate qilinmaydi; Causal Engine ularni typed evidence relation va SCM version orqali bog'laydi.

#### Dashboard

```text
Causal Intelligence
  - Causal Graph
  - Causal Questions
  - Root Causes
  - Interventions
  - Quant Experiments
  - Counterfactual Laboratory
  - Cause Library
  - Identifiability Reviews
  - Sensitivity Analysis
  - Model Competition
  - Causal Timeline
```

#### 19–25 Causal Scientific Loop

```text
Knowledge Graph      -> associations, failures va evidence
Future Engine        -> probabilistic planning
Meta Intelligence    -> audit, assumptions, drift va epistemic doubt
AI Civilization      -> role separation, budget va approval
Quant Laws           -> repeated invariant va causal candidates
Causal Engine        -> identifiability-aware cause, effect va intervention
Theory Engine        -> laws/causes'dan higher-order quant theories
Knowledge/Law/Theory -> versioned scientific capital
```

25-etapning eng qimmat mahsuloti ko'p arrow chizilgan graph emas. U **pattern -> law -> cause -> theory** pog'onasini yaratadigan, qaysi katta tushuntirishlar haqiqatan kuchli ekanini raqobatga qo'yadigan autonomous quant theory system'dir.

### 15–25 Yagona Arxitektura Zanjiri

```text
15 Scientific Evidence
 -> 16 Metacognition
 -> 17 Strategy Evolution
 -> 18 Market Ecology
 -> 19 Institutional Knowledge
 -> 20 Probabilistic Planning
 -> 21 Epistemic Self-Correction
 -> 22 Institutional Multi-Agent Governance
 -> 23 Cross-domain Quant Law Discovery
 -> 24 Causal Mechanism and Intervention Science
 -> 25 Autonomous Theory Generation
```

Loyihaning yakuniy yo'nalishi:

```text
Backtest Platform
 -> AI Trading Scientist
 -> Self-observing Quant Agent
 -> Artificial Evolution Lab
 -> Market Intelligence Lab
 -> Institutional Knowledge System
 -> Probabilistic Decision Laboratory
 -> Self-Correcting Intelligence Laboratory
 -> Artificial Quant Organization
 -> Artificial Quant Science Institute
 -> Causal Quant Science Laboratory
 -> Autonomous Quant Research Institute
```

Asosiy uzoq muddatli aktiv signal yoki alohida strategy emas. Aktiv — provenance, confidence, contradiction va validity period'ga ega **ishonchli trading knowledge base**.

## Context Update Log

### 2026-06-25 - MT5 Market Health, Telegram alert va Auto Recovery hook implementatsiya qilindi

Status: `[IMPLEMENTED]`

1. Nima o'zgardi?
   - Phase 2 foundation ichiga MT5 feed Health Engine qo'shildi.
   - `market:health` har minut MT5 candle oqimini XAUUSD/EURUSD va M15/H1 konfiguratsiyasi bo'yicha tekshiradi.
   - Agar feed `MT5_FEED_LOST_AFTER_SECONDS` thresholdidan oshsa, provider `lost` bo'ladi, Telegram alert attempt qilinadi, `system_events`, `service_health_checks`, `market_provider_health` va `system_logs` yangilanadi.
   - Auto Recovery hook qo'shildi: `.env` orqali yoqilganda yoki `php artisan market:health --recover` ishlatilganda `MT5_RESTART_SCRIPT` chaqiriladi.
   - Ubuntu/Wine uchun `scripts/restart_mt5.sh` qo'shildi; `MT5_COMMAND` berilsa MT5 terminalini qayta start qiladi, aks holda startni skip qilib log yozadi.

2. Qaysi fayllar o'zgardi?
   - `backend-laravel/app/Console/Commands/CheckMarketHealth.php`
   - `backend-laravel/app/Services/MarketHealthService.php`
   - `backend-laravel/app/Services/TelegramAlertService.php`
   - `backend-laravel/app/Services/SystemLogService.php`
   - `backend-laravel/app/Models/MarketProviderHealth.php`
   - `backend-laravel/app/Models/SystemLog.php`
   - `backend-laravel/database/migrations/2026_06_24_293000_create_market_health_tables.php`
   - `backend-laravel/config/services.php`
   - `backend-laravel/.env.example`
   - `backend-laravel/routes/console.php`
   - `backend-laravel/tests/Feature/MarketHealthEngineTest.php`
   - `scripts/restart_mt5.sh`
   - `PROJECT_CONTEXT.md`

3. DB/API/UI flow qanday o'zgardi?
   - Yangi DB jadvallar: `market_provider_health`, `system_logs`.
   - `market_provider_health` provider/symbol/timeframe uchun last candle, age, stale/lost threshold, Telegram alert va recovery attempt holatini saqlaydi.
   - `system_logs` MT5/provider/recovery/Telegram operatsion loglarini saqlaydi.
   - Schedulerga `Schedule::command('market:health')->everyMinute()->withoutOverlapping()->runInBackground()` qo'shildi.
   - Telegram va recovery default o'chiq; `.env` orqali yoqiladi.

4. Qanday test qilindi va aniq natija nima?
   - `php artisan test --filter=MarketHealthEngineTest` -> 3 passed, 13 assertions.
   - `php artisan test` -> 75 passed, 454 assertions.
   - `php artisan migrate --force` lokal smoke check urindi, lekin local MySQL `127.0.0.1:3306` connection refused bo'lgani uchun bajarilmadi; testlar SQLite in-memory schema bilan muvaffaqiyatli o'tdi.

5. Nima hali qilinmagan?
   - MT5 -> Laravel real candle ingestion API va EA hali alohida 21-etap Live Market Infrastructure vazifasi sifatida qolmoqda.
   - Paper Trading Engine, Position Lifecycle, Signal Outcome Tracker va Daily Paper Scientist Report hali qilinmagan.
   - Telegram token/chat id va MT5 restart command production server `.env`ida sozlanishi kerak.

6. Keyingi eng mantiqiy qadam nima?
   - 21-etap Live Market Infrastructure: MT5 EA completed candle'larni `POST /api/v1/market/candles` endpointiga yuborishi, duplicate candle himoyasi va candle received eventlari.

### 2026-06-24 - Instrument Intelligence / Market Profiles foundation implementatsiya qilindi

Status: `[IMPLEMENTED]`

1. Nima o'zgardi?
   - Reality Phase konfiguratsiyasi bitta instrumentdan multi-instrument laboratoriyaga o'zgartirildi.
   - Boshlang'ich instrumentlar: `XAUUSD` va `EURUSD`.
   - Boshlang'ich timeframe'lar: `M15` va `H1`.
   - Boshlang'ich agent oilalari: Trend, Breakout, Mean Reversion.
   - `market_symbols` jadvali `category`, `priority`, `settings` bilan kengaytirildi.
   - `symbol_profiles` qo'shildi: XAUUSD Brain / EURUSD Brain uchun best session, worst session, best strategy, current regime, news sensitivity, volatility profile va trend cleanliness.
   - Yangi `/market-profiles` dashboard qo'shildi.

2. Qaysi fayllar o'zgardi?
   - Yangi migration: `backend-laravel/database/migrations/2026_06_24_292000_create_symbol_profiles_table.php`.
   - Yangi model: `SymbolProfile`.
   - Yangilangan model/seeder: `MarketSymbol`, `MarketSymbolSeeder`.
   - Yangi service: `backend-laravel/app/Services/InstrumentIntelligenceService.php`.
   - Yangi controller/view: `MarketProfilesController`, `resources/views/market-profiles/index.blade.php`.
   - Yangi command: `backend-laravel/app/Console/Commands/RefreshSymbolProfiles.php`.
   - Integration: `routes/web.php`, `routes/console.php`, `layouts/app.blade.php`.
   - Test: `backend-laravel/tests/Feature/InstrumentIntelligenceTest.php`.

3. DB/API/UI flow qanday o'zgardi?
   - Yangi DB jadval: `symbol_profiles`.
   - `market_symbols` endi instrument category va priority saqlaydi: XAUUSD `metals`, EURUSD `forex`.
   - UI menu ichiga `Market Profiles` qo'shildi.
   - `php artisan profiles:refresh` command qo'shildi va schedulerda hourly ishlaydi.

4. Qanday test qilindi va aniq natija nima?
   - `php artisan test --filter=InstrumentIntelligenceTest` -> 2 passed, 12 assertions.
   - `php artisan test --filter=PhaseTwoFoundationTest` -> 2 passed, 16 assertions.
   - `php artisan test --filter=MarketDataPagesTest` -> 2 passed, 10 assertions.
   - `php artisan test` -> 72 passed, 441 assertions.
   - Local browser check uchun `php artisan migrate --force` yuritildi; Symbol Profiles migrationi DONE.
   - `php artisan route:list --path=market-profiles` -> GET `/market-profiles`, POST `/market-profiles/refresh` ro'yxatda.
   - `php artisan db:seed --class=MarketSymbolSeeder` -> XAUUSD/EURUSD/GBPUSD seed qilindi.
   - `php artisan profiles:refresh --symbol=XAUUSD --symbol=EURUSD --timeframe=M15 --timeframe=H1` -> 4 profiles.
   - `GET http://127.0.0.1:8000/market-profiles` -> 200 OK.

5. Nima hali qilinmagan?
   - `symbol_profiles` hozir historical StrategyScore/MarketStateSnapshot/SignalMarketSnapshotlardan hisoblaydi; real paper trades ulanmagan.
   - Live feed hali yo'q; XAUUSD/EURUSD M15/H1 oqimlari Phase 2 / 21 Live Market Infrastructureda ulanadi.
   - News calendar integration yo'q; `news_sensitivity_score` hozir instrument default + volatility proxy asosida.

6. Keyingi eng to'g'ri ish nima?
   - Phase 2 / 21 Live Market Infrastructure: XAUUSD M15/H1 va EURUSD M15/H1 live candle ingestion.
   - Signal Generation Engine Trend, Breakout va Mean Reversion agentlari uchun shu 4 oqimda signal journal yozadi.

### 2026-06-24 - Phase 2 Foundation Layer implementatsiya qilindi

Status: `[IMPLEMENTED]`

1. Nima o'zgardi?
   - Paper Trading Deployment boshlanishidan oldin foundation layer qo'shildi.
   - Event Store qo'shildi: `system_events` lifecycle eventlarni yozadi.
   - Agent Health Center qo'shildi: market feed, market snapshot, signal foundation, event store, agent memory, reality loop va scheduler health checklarini ko'rsatadi.
   - Signal-time Market Snapshot qo'shildi: signal paytidagi trend, volatility, liquidity, momentum, market species, hypothesis va memory match score saqlanadi.
   - Agent Memory Engine kuchaytirildi: `agent_memories` endi market species, outcome, confidence_score, last_matched_at va occurrences maydonlarini ham qo'llaydi.
   - Agent Memory Match qo'shildi: yangi signal contexti bilan oldingi xotira similarity matchini saqlaydi.

2. Qaysi fayllar o'zgardi?
   - Yangi migration: `backend-laravel/database/migrations/2026_06_24_291000_create_phase_two_foundation_tables.php`.
   - Yangi models: `SystemEvent`, `ServiceHealthCheck`, `SignalMarketSnapshot`, `AgentMemoryMatch`.
   - Yangilangan model: `AgentMemory`.
   - Yangi service: `backend-laravel/app/Services/PhaseTwoFoundationService.php`.
   - Yangi controller/view: `AgentHealthController`, `resources/views/agent-health/index.blade.php`.
   - Yangi command: `backend-laravel/app/Console/Commands/RunSystemHealthCheck.php`.
   - Integration: `routes/web.php`, `routes/console.php`, `layouts/app.blade.php`.
   - Test: `backend-laravel/tests/Feature/PhaseTwoFoundationTest.php`.

3. DB/API/UI flow qanday o'zgardi?
   - Yangi DB jadvallar: `system_events`, `service_health_checks`, `signal_market_snapshots`, `agent_memory_matches`.
   - Existing `agent_memories` jadvaliga `market_species`, `outcome`, `confidence_score`, `last_matched_at`, `occurrences` qo'shildi.
   - UI menu ichiga `Agent Health` qo'shildi; dashboardda Service Health, Event Store, Market Snapshot va Agent Memory Matches ko'rinadi.
   - `php artisan system:health-check` command qo'shildi va schedulerda har 5 minutga qo'yildi.

4. Qanday test qilindi va aniq natija nima?
   - `php artisan test --filter=PhaseTwoFoundationTest` -> 2 passed, 16 assertions.
   - `php artisan test --filter=AgentMindTest` -> 2 passed, 23 assertions.
   - `php artisan test --filter=RealityVerificationEngineTest` -> 3 passed, 17 assertions.
   - `php artisan test` -> 70 passed, 429 assertions.
   - Local browser check uchun `php artisan migrate --force` yuritildi; Phase 2 foundation migrationi DONE.
   - `php artisan route:list --path=agent-health` -> GET `/agent-health`, POST `/agent-health/check` ro'yxatda.
   - `php artisan system:health-check` -> command success; hozir live feed/signal snapshot yo'qligi sabab 7 service ichida 3 critical, 1 warning chiqdi.
   - `GET http://127.0.0.1:8000/agent-health` -> 200 OK.

5. Nima hali qilinmagan?
   - Live Market Infrastructure hali boshlanmagan; health center market feed critical ko'rsatishi normal.
   - Paper Trading Engine, paper positions/orders/trades va signal lifecycle hali yo'q.
   - Signal snapshots hozir foundation API orqali yoziladi; real signal generation engine ulanmagan.

6. Keyingi eng to'g'ri ish nima?
   - Phase 2 / 21-etap Live Market Infrastructure: live provider interface va live candle ingestion.
   - Keyin Signal Generation Engine signal paytida `system_events`, `signal_market_snapshots` va memory matchingni avtomatik ishlatadi.

### 2026-06-24 - CTO Roadmap Freeze va Phase 2 Paper Trading Deployment qarori

Status: `[DECIDED]`

1. Nima qaror qilindi?
   - Yangi conceptual etaplar vaqtincha to'xtatildi. 30+, 31+, "super paradigm" kabi modullar yozilmaydi.
   - Loyiha endi Phase 2 Reality/Paper Trading Deploymentga burildi.
   - Multi-Agent Competition Arena juda qimmat konsept sifatida qoldirildi, lekin hozir kodlanmaydi.
   - Multi-Agent Arena faqat 60-90 kun paper trading observationdan keyin ochiladi.

2. Nega?
   - Hozir arxitektura kuchli, lekin real signal/paper trade data yetarli emas.
   - Live Market Data, Paper Trading Engine, Position Manager, Signal Lifecycle va Reality Feedback Loop bo'lmasa, Knowledge Graph/Theory/Civilization qatlamlari model reality ichida qolib ketadi.
   - Eng katta aktiv endi yangi modul emas, kelajakdagi real/paper observation data.

3. Keyingi production roadmap nima?
   - Phase 2 / 21: Live Market Infrastructure.
   - Phase 2 / 22: Signal Generation Engine.
   - Phase 2 / 23: Paper Trading Engine.
   - Phase 2 / 24: Position Lifecycle Engine.
   - Phase 2 / 25: Signal Outcome Tracker.
   - Phase 2 / 26: Daily Paper Scientist Report.
   - Phase 2 / 27: Reality Feedback Loop.
   - Deferred / 28: Multi-Agent Competition Arena, faqat 1000+ signals / 200+ paper trades / 100+ hypothesis outcomes yig'ilgandan keyin.

4. Server readiness holati qanday?
   - Hozir serverda dashboard/backtest/training lab sifatida ishlaydi.
   - 24/7 paper trading research server sifatida hali tayyor emas.
   - Serverga to'liq tayyor bo'lishi uchun live candle feed, paper order/position ledger, signal outcome tracker va scheduler/queue monitoring kerak.

5. Keyingi eng to'g'ri ish nima?
   - `Live Market Infrastructure`dan boshlash.
   - Real order endpoint qo'shmaslik; faqat paper trading.
   - 60-90 kun agentni real market observationda kuzatish.

### 2026-06-24 - Canonical 28-etap Reality Verification Engine implementatsiya qilindi

Status: `[IMPLEMENTED]`

1. Nima o'zgardi?
   - Foydalanuvchi so'roviga ko'ra 28-etap Reality Verification Engine production layer sifatida qo'shildi; mavjud 20 Future Simulation, 21 Meta Intelligence, 22 AI Civilization, 23 Quant Laws, 24 Causal Intelligence va 25 Theory Lab buzilmadi.
   - Reality Score qo'shildi: KnowledgeClaim, QuantLaw, QuantTheory va UnifiedQuantModel uchun original confidence, reality score, drift score va false discovery risk alohida hisoblanadi.
   - Knowledge Lifecycle qo'shildi: `draft`, `backtest_only`, `needs_paper_validation`, `validated`, `institutional_grade`, `reality_failed`.
   - Reality Validation Events qo'shildi: har verification/reverification score/status evolution trail yozadi.
   - Reality Experiment Engine qo'shildi: paper trading validation uchun planned/observed samples, success rate va success criteria saqlanadi.
   - Certified Knowledge qo'shildi: reality score va paper/live evidence yetarli bo'lgan knowledge institutional certificate oladi.
   - Knowledge Cemetery qo'shildi: confidence yuqori bo'lib reality score past chiqqan knowledge/law/theory archived failure sifatida saqlanadi.
   - Skeptic Reports qo'shildi: false discovery risk yuqori yoki paper/live evidence yetarli bo'lmagan source uchun auditor objection va suggested tests yoziladi.
   - Yangi `/reality-center` dashboard qo'shildi.

2. Qaysi fayllar o'zgardi?
   - Yangi migration: `backend-laravel/database/migrations/2026_06_24_280000_create_reality_verification_tables.php`.
   - Yangi models: `RealityVerificationRun`, `RealityScore`, `RealityValidationEvent`, `RealityExperiment`, `KnowledgeCemeteryEntry`, `SkepticReport`, `CertifiedKnowledgeItem`.
   - Yangi service: `backend-laravel/app/Services/RealityVerificationService.php`.
   - Yangi controller/view: `RealityCenterController`, `resources/views/reality-center/index.blade.php`.
   - Yangi command: `backend-laravel/app/Console/Commands/VerifyReality.php`.
   - Integration: `routes/web.php`, `routes/console.php`, `layouts/app.blade.php`.
   - Test: `backend-laravel/tests/Feature/RealityVerificationEngineTest.php`.

3. DB/API/UI flow qanday o'zgardi?
   - Yangi DB jadvallar: `reality_verification_runs`, `reality_scores`, `reality_validation_events`, `reality_experiments`, `knowledge_cemetery_entries`, `skeptic_reports`, `certified_knowledge_items`.
   - UI menu ichiga `Reality Center` qo'shildi; dashboardda Reality Score, Certified Knowledge, Knowledge Cemetery, Reality Validation, Skeptic Reports va Validation Timeline ko'rinadi.
   - `php artisan reality:verify` command qo'shildi va schedulerda dushanba 05:30 ga qo'yildi.
   - Existing knowledge/law/theory records overwrite qilinmaydi; Reality Engine ular ustidan alohida verification ledger yuritadi.

4. Qanday test qilindi va aniq natija nima?
   - `php artisan test --filter=RealityVerificationEngineTest` -> 3 passed, 17 assertions.
   - `php artisan test --filter=AutonomousTheoryGenerationEngineTest` -> 3 passed, 16 assertions.
   - `php artisan test --filter=CausalIntelligenceEngineTest` -> 3 passed, 19 assertions.
   - `php artisan test --filter=UniversalQuantLawsEngineTest` -> 3 passed, 18 assertions.
   - `php artisan test` -> 68 passed, 413 assertions.
   - Local browser check uchun `php artisan migrate --force` yuritildi; Reality Verification migrationi DONE.
   - `php artisan route:list --path=reality-center` -> GET `/reality-center`, POST `/reality-center/verify` ro'yxatda.
   - `php artisan reality:verify` -> command success; local DB'da verification source evidence yo'qligi uchun 0 scored / 0 certified / 0 cemetery entries yaratildi.
   - `GET http://127.0.0.1:8000/reality-center` -> 200 OK.

5. Nima hali qilinmagan?
   - Real broker/live paper signal feed hali ulanmagan; hozir `StrategyScore` operational evidence proxy sifatida ishlatiladi.
   - Certification expiry avtomatik downgrade qilmaydi; `expires_at` yoziladi, lekin scheduled decay/quarantine keyingi chuqurlashtirishga qoladi.
   - Source-specific evidence matching hozir global operational evidence bilan ishlaydi; strategy/species/theory scope bo'yicha nozik matching keyingi versiyada kuchaytiriladi.

6. Keyingi eng mantiqiy qadam nima?
   - Paper trading signal ledger va forecast outcome tracking qo'shib, RealityScore'ni haqiqiy forward observation bilan kalibrlash.
   - Reality detail sahifalari: source drilldown, skeptic report detail, certificate history, cemetery detail va validation experiment outcomes.

### 2026-06-24 - Canonical 25-etap Autonomous Theory Generation Engine implementatsiya qilindi

Status: `[IMPLEMENTED]`

1. Nima o'zgardi?
   - Foydalanuvchi correctioniga ko'ra Autonomous Theory Generation canonical 25 sifatida implementatsiya qilindi; 20 Future Simulation, 21 Meta Intelligence, 22 AI Civilization, 23 Quant Laws va 24 Causal Intelligence buzilmadi.
   - Theory Layer qo'shildi: Quant Laws, CausalEdge va CausalRootCause evidence'lari `quant_theories`ga higher-order theory sifatida birlashtiriladi.
   - Theory Components qo'shildi: har theory qaysi law, causal edge yoki root cause evidence bilan qo'llanganini provenance bilan saqlaydi.
   - Competing Theories qo'shildi: `theory_battles` confidence, explanatory power va predictive power asosida theorylarni solishtiradi.
   - Theory Predictions qo'shildi: theory asosidagi target metric, intervention value va predicted delta saqlanadi.
   - Theory Evolution qo'shildi: theory generated/revalidated bo'lganda status va confidence o'zgarishi yoziladi.
   - Unified Quant Models qo'shildi: kuchli theorylar "Adaptive Resilience Market Survival Model" kabi unified modelga birlashadi.
   - Yangi `/theory-lab` dashboard qo'shildi.

2. Qaysi fayllar o'zgardi?
   - Yangi migration: `backend-laravel/database/migrations/2026_06_24_250000_create_autonomous_theory_generation_tables.php`.
   - Yangi models: `TheoryGenerationRun`, `QuantTheory`, `TheoryComponent`, `TheoryBattle`, `TheoryPrediction`, `TheoryEvolutionEvent`, `UnifiedQuantModel`.
   - Yangi service: `backend-laravel/app/Services/AutonomousTheoryGenerationService.php`.
   - Yangi controller/view: `TheoryLabController`, `resources/views/theory-lab/index.blade.php`.
   - Yangi command: `backend-laravel/app/Console/Commands/GenerateAutonomousTheories.php`.
   - Integration: `routes/web.php`, `routes/console.php`, `layouts/app.blade.php`.
   - Test: `backend-laravel/tests/Feature/AutonomousTheoryGenerationEngineTest.php`.

3. DB/API/UI flow qanday o'zgardi?
   - Yangi DB jadvallar: `theory_generation_runs`, `quant_theories`, `theory_components`, `theory_battles`, `theory_predictions`, `theory_evolution_events`, `unified_quant_models`.
   - UI menu ichiga `Theory Lab` qo'shildi; dashboardda Emerging & Dominant Theories, Theory Battles, Theory Predictions, Unified Models, Theory Components va Theory Evolution ko'rinadi.
   - `php artisan theory:generate` command qo'shildi va schedulerda dushanba 05:00 ga qo'yildi.
   - Theory Engine mavjud laws/causal/root-cause evidence'ni overwrite qilmaydi; o'zining alohida scientific theory ledgerini yuritadi.

4. Qanday test qilindi va aniq natija nima?
   - `php artisan test --filter=AutonomousTheoryGenerationEngineTest` -> 3 passed, 16 assertions.
   - `php artisan test --filter=CausalIntelligenceEngineTest` -> 3 passed, 19 assertions.
   - `php artisan test --filter=UniversalQuantLawsEngineTest` -> 3 passed, 18 assertions.
   - `php artisan test` -> 65 passed, 396 assertions.
   - Local browser check uchun `php artisan migrate --force` yuritildi; Theory Generation migrationi DONE.
   - Birinchi `php artisan theory:generate` migration bilan parallel ketgani uchun jadval topilmadi, migration tugagach qayta yuritildi.
   - `php artisan theory:generate` -> command success; local DB'da yetarli Quant Law/Causal evidence yo'qligi uchun 0 theories / 0 battles / 0 predictions yaratildi.
   - `GET http://127.0.0.1:8000/theory-lab` -> 200 OK.

5. Nima hali qilinmagan?
   - Theory prediction validation hali real future outcome/backtest experiment natijalari bilan bog'lanmagan.
   - Theory battles hozir deterministic scoring; uzoq muddatli holdout validation, falsification va competing experiment queue keyingi chuqurlashtirishga qoladi.
   - Grand Unified Quant Theory hozir birinchi unified model ko'rinishida; full paradigm/research-method engine hali production emas.

6. Keyingi eng mantiqiy qadam nima?
   - TheoryPrediction outcome tracking va Research Director/experiment queue integratsiyasini qo'shish.
   - Theory detail sahifalari: component provenance, battle history, prediction validation va unified model drilldown.

### 2026-06-24 - Canonical 24-etap Causal Intelligence Engine implementatsiya qilindi

Status: `[IMPLEMENTED]`

1. Nima o'zgardi?
   - Foydalanuvchi correctioniga ko'ra Causal Intelligence canonical 24 sifatida implementatsiya qilindi; 20 Future Simulation, 21 Meta Intelligence, 22 AI Civilization va 23 Quant Laws buzilmadi.
   - Causal Graph qo'shildi: Quant Laws driver-target relationlari `causal_nodes` va `causal_edges`ga causal candidate sifatida yoziladi.
   - Causality score va identification status qo'shildi: edge `associational`, `partially_identified` yoki `provisionally_identified` bo'ladi.
   - Causal Effect Estimates qo'shildi: effect estimate, confidence interval va adjustment set saqlanadi.
   - Counterfactual Laboratory qo'shildi: baseline/intervention value va estimated delta yoziladi.
   - Intervention Engine qo'shildi: cause-based strategy/evolution recommendation, expected impact, cost va risk score.
   - Quant Experiments qo'shildi: control group, experimental group va success criteria bilan planned experiment.
   - Root Cause Library qo'shildi: impact/confidence bo'yicha top root causes.
   - Discovery Quality Score qo'shildi: correlation score va causality score alohida baholanadi.
   - Yangi `/causal-intelligence` dashboard qo'shildi.

2. Qaysi fayllar o'zgardi?
   - Yangi migration: `backend-laravel/database/migrations/2026_06_24_240000_create_causal_intelligence_tables.php`.
   - Yangi models: `CausalDiscoveryRun`, `CausalNode`, `CausalEdge`, `CausalEffectEstimate`, `CausalCounterfactual`, `CausalIntervention`, `CausalExperiment`, `CausalRootCause`, `DiscoveryQualityScore`.
   - Yangi service: `backend-laravel/app/Services/CausalIntelligenceService.php`.
   - Yangi controller/view: `CausalIntelligenceController`, `resources/views/causal-intelligence/index.blade.php`.
   - Yangi command: `backend-laravel/app/Console/Commands/DiscoverCausalIntelligence.php`.
   - Integration: `routes/web.php`, `routes/console.php`, `layouts/app.blade.php`.
   - Test: `backend-laravel/tests/Feature/CausalIntelligenceEngineTest.php`.

3. DB/API/UI flow qanday o'zgardi?
   - Yangi DB jadvallar: `causal_discovery_runs`, `causal_nodes`, `causal_edges`, `causal_effect_estimates`, `causal_counterfactuals`, `causal_interventions`, `causal_experiments`, `causal_root_causes`, `discovery_quality_scores`.
   - UI menu ichiga `Causal Intelligence` qo'shildi; dashboardda Causal Graph, Root Causes, Counterfactual Laboratory, Interventions, Experiments va Discovery Quality ko'rinadi.
   - `php artisan causal:discover` command qo'shildi va schedulerda dushanba 04:30 ga qo'yildi.
   - Existing Quant Laws causal proof deb o'zgartirilmadi; Causal Intelligence ular ustidan identifiability-aware assessment qiladi.

4. Qanday test qilindi va aniq natija nima?
   - `php artisan test --filter=CausalIntelligenceEngineTest` -> 3 passed, 19 assertions.
   - `php artisan test --filter=UniversalQuantLawsEngineTest` -> 3 passed, 18 assertions.
   - `php artisan test --filter=QuantCivilizationEngineTest` -> 3 passed, 19 assertions.
   - `php artisan test` -> 62 passed, 380 assertions.
   - Local browser check uchun `php artisan migrate --force` yuritildi; Causal Intelligence migrationi DONE.
   - `php artisan causal:discover` -> command success; local DB'da Quant Law evidence kamligi uchun 0 edges / 0 effects / 0 interventions yaratildi.
   - Birinchi `/causal-intelligence` runtime check migration bilan parallel ketgani uchun 500 bo'ldi; migration tugagach qayta check qilindi.
   - `GET http://127.0.0.1:8000/causal-intelligence` -> 200 OK.

5. Nima hali qilinmagan?
   - Causal effect estimate hozir heuristic/law-adjusted proxy; real randomized/backtest intervention experiment natijalari hali ulanmagan.
   - Full DAG identifiability, do-calculus, sensitivity analysis, transportability va falsification tests keyingi chuqurlashtirishga qoladi.
   - Interventions hozir proposed state; Evolution Proposal yoki Research Director queue'ga avtomatik apply qilinmaydi.

6. Keyingi eng mantiqiy qadam nima?
   - Causal experiments natijalarini backtest/evolution workflow bilan bog'lash va intervention outcome tracking qo'shish.
   - Causal detail sahifalari: edge show, adjustment set, counterfactual simulation detail, intervention ledger va experiment result review.

### 2026-06-24 - Canonical 23-etap Universal Quant Laws Discovery Engine implementatsiya qilindi

Status: `[IMPLEMENTED]`

1. Nima o'zgardi?
   - Foydalanuvchi correctioniga ko'ra Universal Laws konsepti canonical 23 sifatida implementatsiya qilindi; mavjud 20 Future Simulation, 21 Meta Intelligence va 22 AI Civilization buzilmadi.
   - Law Candidate Engine qo'shildi: Strategy DNA, StrategyScore va KnowledgeClaim evidence'dan provisional law candidate yaratadi.
   - Multi-Evidence Validation qo'shildi: strategy_count, species_count, session_count, trade_count, confidence va universality score hisoblanadi.
   - Promoted Quant Laws qo'shildi: candidate confidence/universality thresholddan o'tsa `quant_laws`ga promoted law sifatida yoziladi.
   - Law Graph qo'shildi: driver -> target relationlari `increases/reduces` polarity bilan saqlanadi.
   - Law Conflicts qo'shildi: bir driver-target scope ichida qarama-qarshi directiondagi lawlar conflict sifatida yoziladi.
   - Law Evolution Events qo'shildi: law promoted yoki revalidated bo'lganda confidence delta audit trail yoziladi.
   - Universal Driver Ranking qo'shildi: top driver analysis impact/confidence/evidence bo'yicha ranking qiladi.
   - Yangi `/quant-laws` dashboard qo'shildi.

2. Qaysi fayllar o'zgardi?
   - Yangi migration: `backend-laravel/database/migrations/2026_06_24_230000_create_quant_laws_tables.php`.
   - Yangi models: `QuantLawDiscoveryRun`, `QuantLawCandidate`, `QuantLaw`, `QuantLawEvidence`, `QuantLawGraphEdge`, `QuantLawConflict`, `QuantLawEvolutionEvent`, `UniversalDriverRanking`.
   - Yangi service: `backend-laravel/app/Services/UniversalQuantLawsService.php`.
   - Yangi controller/view: `QuantLawsController`, `resources/views/quant-laws/index.blade.php`.
   - Yangi command: `backend-laravel/app/Console/Commands/DiscoverQuantLaws.php`.
   - Integration: `routes/web.php`, `routes/console.php`, `layouts/app.blade.php`.
   - Test: `backend-laravel/tests/Feature/UniversalQuantLawsEngineTest.php`.

3. DB/API/UI flow qanday o'zgardi?
   - Yangi DB jadvallar: `quant_law_discovery_runs`, `quant_law_candidates`, `quant_laws`, `quant_law_evidences`, `quant_law_graph_edges`, `quant_law_conflicts`, `quant_law_evolution_events`, `universal_driver_rankings`.
   - UI menu ichiga `Quant Laws` qo'shildi; dashboardda Universal Laws Library, Top Drivers, Emerging Law Candidates, Law Conflicts, Law Graph va Evidence ko'rinadi.
   - `php artisan laws:discover` command qo'shildi va schedulerda dushanba 04:00 ga qo'yildi.
   - Existing AI Scientist, Agent Mind, Evolution Genome, Market Intelligence, Knowledge Graph, Future Simulation, Meta Intelligence va AI Civilization flow buzilmadi.
   - Quant Laws causal proof emas; u repeated invariant/provisional law layer. Causal proof keyingi causal intelligence bosqichiga qoladi.

4. Qanday test qilindi va aniq natija nima?
   - `php artisan test --filter=UniversalQuantLawsEngineTest` -> 3 passed, 18 assertions.
   - `php artisan test --filter=QuantCivilizationEngineTest` -> 3 passed, 19 assertions.
   - `php artisan test --filter=MetaIntelligenceEngineTest` -> 3 passed, 19 assertions.
   - `php artisan test` -> 59 passed, 361 assertions.
   - Local browser check uchun `php artisan migrate --force` yuritildi; Quant Laws migrationi DONE.
   - Birinchi runtime check parallel ketgani uchun `laws:discover` migrationdan oldin start bo'lib `tables topilmadi` dedi; migration tugagach qayta run qilindi va success bo'ldi.
   - `php artisan laws:discover` -> command success; local DB'da evidence kamligi uchun 0 candidates / 0 laws / 0 conflicts yaratildi.
   - `GET http://127.0.0.1:8000/quant-laws` -> 200 OK.

5. Nima hali qilinmagan?
   - Multi-evidence validation hozir deterministic heuristic; full cross-market holdout, law falsification protocol va statistical significance hali chuqurlashtirilmagan.
   - Law Library detail sahifalari, provenance drilldown va conflict resolution workflow hali qo'shilmagan.
   - Universal Driver Analysis “holy grail” deb e'lon qilmaydi; top driverlar avtomatik live rule bo'lmaydi.

6. Keyingi eng mantiqiy qadam nima?
   - Causal Intelligence Engine: Quant Laws relationlari ichida qaysilari causal effect sifatida identifikatsiya qilinishi mumkinligini tekshirish.
   - Quant Laws detail sahifalari: law show, evidence matrix, species coverage, law evolution timeline, conflict dossier va driver impact history.

### 2026-06-24 - Canonical 22-etap Artificial Quant Civilization Engine implementatsiya qilindi

Status: `[IMPLEMENTED]`

1. Nima o'zgardi?
   - Foydalanuvchi so'nggi correctioniga ko'ra bu konsept canonical 22 sifatida implementatsiya qilindi; mavjud 20 Future Simulation va 21 Meta Intelligence buzilmadi.
   - AI Civilization Engine agentlarni role-based civilization members sifatida tashkil qiladi: Research, Risk, Market, Evolution, Knowledge, Prediction, Meta va strategy members.
   - Internal Economy qo'shildi: har agentga non-transferable research credits beriladi va `civilization_credit_events` ledgerida saqlanadi.
   - Agent Council qo'shildi: research allocation proposal uchun weighted YES/NO/VETO vote, quorum, consensus va final decision yoziladi.
   - Civilization Goals qo'shildi: adaptability, unknown zone reduction, prediction reliability, knowledge coverage va capital protection.
   - Collective Memory qo'shildi: meta audit va council decisionlar future policy uchun memory sifatida saqlanadi.
   - Institutional Knowledge qo'shildi: KnowledgeClaim va discovery'lar agent/version arxivga tushsa ham preserved library ichida qoladi.
   - Yangi `/ai-civilization` dashboard qo'shildi.

2. Qaysi fayllar o'zgardi?
   - Yangi migration: `backend-laravel/database/migrations/2026_06_24_220000_create_ai_civilization_tables.php`.
   - Yangi models: `CivilizationAgent`, `CivilizationCreditEvent`, `CouncilDecision`, `CouncilVote`, `CivilizationMemory`, `InstitutionalKnowledge`, `CivilizationGoal`.
   - Yangi service: `backend-laravel/app/Services/QuantCivilizationService.php`.
   - Yangi controller/view: `AiCivilizationController`, `resources/views/ai-civilization/index.blade.php`.
   - Yangi command: `backend-laravel/app/Console/Commands/SyncQuantCivilization.php`.
   - Integration: `routes/web.php`, `routes/console.php`, `layouts/app.blade.php`.
   - Test: `backend-laravel/tests/Feature/QuantCivilizationEngineTest.php`.

3. DB/API/UI flow qanday o'zgardi?
   - Yangi DB jadvallar: `civilization_agents`, `civilization_credit_events`, `council_decisions`, `council_votes`, `civilization_memories`, `institutional_knowledge`, `civilization_goals`.
   - UI menu ichiga `AI Civilization` qo'shildi; dashboardda Agent Society, Council Decisions/Votes, Internal Economy, Collective Memory, Institutional Knowledge va Civilization Goals ko'rinadi.
   - `php artisan civilization:sync` command qo'shildi va schedulerda dushanba 03:30 ga qo'yildi.
   - Existing AI Scientist, Agent Mind, Evolution Genome, Market Intelligence, Knowledge Graph, Future Simulation va Meta Intelligence flow buzilmadi; Civilization Engine ular ustidan institutional governance qatlamini yaratadi.
   - Research credits pul yoki transfer qilinadigan token emas; ular audit qilinadigan compute/time/data budget signalidir.

4. Qanday test qilindi va aniq natija nima?
   - `php artisan test --filter=QuantCivilizationEngineTest` -> 3 passed, 19 assertions.
   - `php artisan test --filter=MetaIntelligenceEngineTest` -> 3 passed, 19 assertions.
   - `php artisan test --filter=FutureSimulationEngineTest` -> 3 passed, 17 assertions.
   - `php artisan test` -> 56 passed, 343 assertions.
   - Local browser check uchun `php artisan migrate --force` yuritildi; AI Civilization migrationi DONE.
   - Birinchi MySQL migration urinishida index nomi uzunligi xatosi chiqdi; faqat 22-etap partial jadvallari drop qilinib, index nomlari qisqartirildi va migration qayta DONE bo'ldi.
   - `php artisan civilization:sync` -> command success; local DB'da council decision #1 approved, consensus 100.00%.
   - `GET http://127.0.0.1:8000/ai-civilization` -> 200 OK.

5. Nima hali qilinmagan?
   - Council hozir deterministic institutional vote; real long-running research queue, delayed outcome review va reputation recalibration hali chuqurlashtirilmagan.
   - Credits hozir allocation ledger; expiration, budget burn-down, admission review va sybil prevention v2 keyingi etaplarga qoladi.
   - Human-ratified constitution, council veto policies va cross-agent conflict-of-interest checks hali full production emas.

6. Keyingi eng mantiqiy qadam nima?
   - Autonomous Research Director konseptini Civilization Council va Meta audit natijalaridan research tickets yaratadigan operational layer sifatida ulash.
   - AI Civilization detail sahifalari: agent profile, council decision show, vote reasoning, memory archive va institutional knowledge provenance.

### 2026-06-24 - Canonical 21-etap Meta Intelligence Engine implementatsiya qilindi

Status: `[IMPLEMENTED]`

1. Nima o'zgardi?
   - Foydalanuvchi correctioniga ko'ra bu etap canonical 21 sifatida belgilandi; 20-etap Future Simulation buzilmadi.
   - Meta Intelligence Engine Knowledge Graph va Agent Belief qatlamlari ustidan self-correcting audit yaratadi.
   - Knowledge Audit claim confidence'ni qayta baholaydi: original confidence, audited confidence, decay, verdict va recommended action saqlanadi.
   - Belief Decay agent ishonchlarini non-destructive audit qiladi: eski evidence, failure pressure va sample size bo'yicha decayed score yoziladi.
   - Contradiction Detector bir scope ichidagi qarama-qarshi positive/negative claimlarni topadi.
   - Unknown Zone Detector historical similarity va Knowledge Graph evidence yetarli bo'lmagan market reality holatlarini yozadi.
   - Blind Spot Finder under-sampled market condition kombinatsiyalarini topib suggested research metadata beradi.
   - Knowledge Health Score va Self Critic weekly audit xulosasini beradi.
   - Yangi `/meta-intelligence` dashboard qo'shildi.

2. Qaysi fayllar o'zgardi?
   - Yangi migration: `backend-laravel/database/migrations/2026_06_24_210000_create_meta_intelligence_tables.php`.
   - Yangi models: `MetaAuditRun`, `KnowledgeAudit`, `BeliefDecayEvent`, `KnowledgeContradiction`, `UnknownZone`, `BlindSpot`, `KnowledgeHealthScore`, `SelfCritique`.
   - Yangi service: `backend-laravel/app/Services/MetaIntelligenceService.php`.
   - Yangi controller/view: `MetaIntelligenceController`, `resources/views/meta-intelligence/index.blade.php`.
   - Yangi command: `backend-laravel/app/Console/Commands/RunMetaIntelligenceAudit.php`.
   - Integration: `routes/web.php`, `routes/console.php`, `layouts/app.blade.php`.
   - Test: `backend-laravel/tests/Feature/MetaIntelligenceEngineTest.php`.

3. DB/API/UI flow qanday o'zgardi?
   - Yangi DB jadvallar: `meta_audit_runs`, `knowledge_audits`, `belief_decay_events`, `knowledge_contradictions`, `unknown_zones`, `blind_spots`, `knowledge_health_scores`, `self_critiques`.
   - UI menu ichiga `Meta Intelligence` qo'shildi; dashboardda Knowledge Health, Knowledge Audit, Belief Health, Contradictions, Unknown Zones, Blind Spots, Health Timeline va Self Critiques ko'rinadi.
   - `php artisan meta:audit` command qo'shildi va schedulerda dushanba 03:00 ga qo'yildi.
   - Existing Knowledge Graph, Future Simulation, Market Intelligence, Agent Mind va AI Scientist flow buzilmadi; Meta Engine ular ustidan audit trail yaratadi.
   - Meta Engine hozir `knowledge_claims` va `agent_beliefs`ni overwrite qilmaydi; A-rank evidence saqlanadi, B-rank audit alohida yoziladi.

4. Qanday test qilindi va aniq natija nima?
   - `php artisan test --filter=MetaIntelligenceEngineTest` -> 3 passed, 19 assertions.
   - `php artisan test --filter=FutureSimulationEngineTest` -> 3 passed, 17 assertions.
   - `php artisan test --filter=UniversalKnowledgeGraphTest` -> 3 passed, 17 assertions.
   - `php artisan test` -> 53 passed, 324 assertions.
   - Local browser check uchun `php artisan migrate --force` yuritildi; Meta Intelligence migrationi DONE.
   - `php artisan meta:audit` -> command success; local DB'da Meta audit #1 health 76.00% bilan yaratildi.
   - `GET http://127.0.0.1:8000/meta-intelligence` -> 200 OK.

5. Nima hali qilinmagan?
   - Meta audit hozir deterministic heuristic audit; full holdout validation, falsification tests va multiple-testing control keyingi chuqurlashtirishga qoladi.
   - Contradiction resolution hozir review signal yaratadi; claim quarantine yoki confidence rewrite avtomatik qilinmaydi.
   - Unknown zones va blind spots research ticket queue'ga avtomatik ulanmagan; Research Director/future research layer bilan bog'lanadi.

6. Keyingi eng mantiqiy qadam nima?
   - Autonomous Research Director konseptini Meta audit natijalaridan research queue yaratadigan future/deferred layer sifatida implementatsiya qilish.
   - Meta Intelligence detail sahifalari: audit run show, contradiction detail, unknown zone drilldown, belief decay history va self-critique archive.

### 2026-06-24 - Canonical 20-etap Future Simulation & Planning Engine implementatsiya qilindi

Status: `[IMPLEMENTED]`

1. Nima o'zgardi?
   - 20-etap endi documentation-only emas, Future Simulation & Planning Engine sifatida real kodga qo'shildi.
   - Latest `MarketStateSnapshot` + `MarketGenome` hamda `KnowledgeClaim` priorsdan probabilistic future simulation run yaratiladi.
   - Aggregated 1000 scenario branch modeli qo'shildi: `bull_continuation`, `range_reversion`, `panic_event`, `fake_breakout`, `trend_reversal`.
   - Probability Tree, Timeline Forecast (10/25/50 candle), Strategy Survival Forecast, Future Stress Tests va Future Discoveries qatlamlari yaratildi.
   - Strategy survival forecast current confidence, future confidence, survival probability, future robustness, recommended action va position size multiplier beradi.
   - `future:simulate` command qo'shildi va schedulerda har kuni 02:30 ga qo'yildi.
   - Yangi `/future-intelligence` dashboard qo'shildi.

2. Qaysi fayllar o'zgardi?
   - Yangi migration: `backend-laravel/database/migrations/2026_06_24_200000_create_future_simulation_tables.php`.
   - Yangi models: `FutureSimulationRun`, `FutureScenario`, `FutureProbabilityNode`, `FutureTimelineForecast`, `StrategySurvivalForecast`, `FutureStressTest`, `FutureDiscovery`.
   - Yangi service: `backend-laravel/app/Services/FutureSimulationService.php`.
   - Yangi controller/view: `FutureIntelligenceController`, `resources/views/future-intelligence/index.blade.php`.
   - Yangi command: `backend-laravel/app/Console/Commands/RunFutureSimulation.php`.
   - Integration: `StrategyLabController`, `RunAutoTrainingSession`, `routes/web.php`, `routes/console.php`, `layouts/app.blade.php`.
   - Test: `backend-laravel/tests/Feature/FutureSimulationEngineTest.php`.

3. DB/API/UI flow qanday o'zgardi?
   - Manual `/strategy-lab/run-all` va `php artisan trading:auto-train` endi `UniversalKnowledgeGraphService`dan keyin `FutureSimulationService::simulate()` chaqiradi.
   - Yangi DB jadvallar: `future_simulation_runs`, `future_scenarios`, `future_probability_nodes`, `future_timeline_forecasts`, `strategy_survival_forecasts`, `future_stress_tests`, `future_discoveries`.
   - UI menu ichiga `Future Intelligence` qo'shildi; dashboardda Future Map, Probability Tree, Scenario Lab, Survival Forecast, Future Stress Tests va Market Futures Discoveries ko'rinadi.
   - Existing AI Scientist, Agent Mind, Evolution Genome, Market Intelligence, Knowledge Graph, Strategy Lab va Auto Training flow buzilmadi; Future Simulation ulardan planning layer sifatida foydalanadi.

4. Qanday test qilindi va aniq natija nima?
   - `php artisan test --filter=FutureSimulationEngineTest` -> 3 passed, 17 assertions.
   - `php artisan test --filter=StrategyLabRunAllTest` -> 9 passed, 40 assertions.
   - `php artisan test --filter=AutoTrainingWorkflowTest` -> 4 passed, 32 assertions.
   - `php artisan test --filter=UniversalKnowledgeGraphTest` -> 3 passed, 17 assertions.
   - `php artisan test --filter=MarketRealityEngineTest` -> 3 passed, 21 assertions.
   - `php artisan test` -> 50 passed, 305 assertions.
   - Local browser check uchun `php artisan migrate --force` yuritildi; Future Simulation migrationi DONE.
   - MySQL index nomi uzunligi xatosi topildi va migration index nomlari qisqartirildi; partial future jadvallar tozalab qayta migration qilindi.
   - `GET http://127.0.0.1:8000/future-intelligence` -> 200 OK.
   - `php artisan future:simulate --symbol=XAUUSD --timeframe=H1 --scenarios=1000` -> command success; local DB'da latest Market Genome yo'qligi uchun guardrail bilan simulyatsiya yaratilmadi.

5. Nima hali qilinmagan?
   - Hozirgi engine deterministic aggregated scenario planning qiladi; full stochastic candle-by-candle path storage hali yo'q.
   - `planning_decisions`, `forecast_outcomes`, `forecast_calibrations`, `simulation_model_versions` hali alohida jadvallar sifatida qo'shilmagan.
   - Forecast verification/calibration actual future candles kelgandan keyin alohida baholanmaydi.
   - Scenario probabilities causal proof emas; ular Market Genome + Knowledge Graph priors asosidagi planning probabilities.

6. Keyingi eng mantiqiy qadam nima?
   - 21-etap endi foydalanuvchi correctioniga ko'ra Meta Intelligence Engine sifatida implementatsiya qilingan; Autonomous Research Director future/deferred research-director layer sifatida saqlanadi.
   - Future Intelligence detail sahifalari: run show, scenario branch drilldown, forecast calibration, strategy survival dossier va planning decision ledger.

### 2026-06-24 - Canonical 19-etap Universal Trading Knowledge Graph implementatsiya qilindi

Status: `[IMPLEMENTED]`

1. Nima o'zgardi?
   - 19-etap endi documentation-only emas, Universal Trading Knowledge Graph sifatida real kodga qo'shildi.
   - Strategy, session, symbol, timeframe, metric, parameter, market species, strategy genome, hypothesis, belief, discovery va failure cause node'lari uchun graph schema yaratildi.
   - Node'lar orasida typed edge'lar yoziladi: `OBSERVED_STRATEGY`, `ACHIEVED_METRIC`, `USES_PARAMETER`, `HAS_GENOME`, `PERFORMS_IN_MARKET_SPECIES`, `HAS_FAILURE_CAUSE`, `PRODUCED_HYPOTHESIS`, `HAS_BELIEF`.
   - Knowledge Claim qatlamida strategy-species performance, failure cause, genome pattern, market pattern va agent belief claimlari confidence/evidence/status bilan saqlanadi.
   - Research Assistant savollarga stored graph evidence asosida javob beradi va matched node/edge ids bilan `knowledge_queries`ga yozadi.
   - `knowledge:mine` command qo'shildi va schedulerda har kuni 02:00 ga qo'yildi.
   - Yangi `/knowledge-center` dashboard qo'shildi.

2. Qaysi fayllar o'zgardi?
   - Yangi migration: `backend-laravel/database/migrations/2026_06_24_190000_create_universal_knowledge_graph_tables.php`.
   - Yangi models: `KnowledgeGraphNode`, `KnowledgeGraphEdge`, `KnowledgeClaim`, `KnowledgeEvidence`, `KnowledgeQuery`, `KnowledgeMiningRun`.
   - Yangi service: `backend-laravel/app/Services/UniversalKnowledgeGraphService.php`.
   - Yangi controller/view: `KnowledgeCenterController`, `resources/views/knowledge-center/index.blade.php`.
   - Yangi command: `backend-laravel/app/Console/Commands/MineKnowledgeGraph.php`.
   - Integration: `StrategyLabController`, `RunAutoTrainingSession`, `routes/web.php`, `routes/console.php`, `layouts/app.blade.php`.
   - Test: `backend-laravel/tests/Feature/UniversalKnowledgeGraphTest.php`.

3. DB/API/UI flow qanday o'zgardi?
   - Manual `/strategy-lab/run-all` va `php artisan trading:auto-train` endi `MarketRealityService`dan keyin `UniversalKnowledgeGraphService::recordTrainingSession()` chaqiradi.
   - Yangi DB jadvallar: `knowledge_graph_nodes`, `knowledge_graph_edges`, `knowledge_claims`, `knowledge_evidence`, `knowledge_queries`, `knowledge_mining_runs`.
   - UI menu ichiga `Knowledge Center` qo'shildi; dashboardda Knowledge Graph, Discoveries, Research Assistant, Failure Analysis, Pattern Explorer va Knowledge Timeline ko'rinadi.
   - Existing AI Scientist, Agent Mind, Evolution Genome, Market Intelligence, Strategy Lab va Auto Training flow buzilmadi; Knowledge Graph ular ustidan evidence-backed institutional memory qatlamini yaratadi.

4. Qanday test qilindi va aniq natija nima?
   - `php artisan test --filter=UniversalKnowledgeGraphTest` -> 3 passed, 17 assertions.
   - `php artisan test --filter=StrategyLabRunAllTest` -> 9 passed, 40 assertions.
   - `php artisan test --filter=AutoTrainingWorkflowTest` -> 4 passed, 32 assertions.
   - `php artisan test --filter=MarketRealityEngineTest` -> 3 passed, 21 assertions.
   - `php artisan test` -> 47 passed, 288 assertions.
   - Local browser check uchun `php artisan migrate --force` yuritildi; Knowledge Graph migrationi DONE.
   - `GET http://127.0.0.1:8000/knowledge-center` -> 200 OK.
   - `php artisan knowledge:mine` -> success; mavjud local DB'da yangi evidence kamligi uchun 0 nodes / 0 edges / 0 claims yaratdi.

5. Nima hali qilinmagan?
   - Research Assistant hozir rule-based graph retrieval; LLM yoki semantic vector search ishlatilmaydi.
   - `knowledge_contexts`, `knowledge_versions`, validation run va citation detail jadvallari hali qo'shilmagan.
   - Contradiction resolver, belief decay va epistemic audit canonical 21 Meta Intelligence bilan productionga kirdi; full validation/falsification hali chuqurlashtirishga qoladi.
   - Graph visualization hozir table-based; interactive graph canvas/keyingi visual explorer hali qo'shilmagan.

6. Keyingi eng mantiqiy qadam nima?
   - 20-etap Future Simulation & Planning Enginedan keyin user correction bo'yicha 21-etap Meta Intelligence Engine implementatsiya qilindi; Autonomous Research Director future/deferred research-director layer sifatida saqlanadi.
   - Knowledge Center detail sahifalari: node show, claim provenance, edge evidence, contradiction view va strategy-specific research dossier.

### 2026-06-24 - Canonical 18-etap Market Reality / Intelligence Engine implementatsiya qilindi

Status: `[IMPLEMENTED]`

1. Nima o'zgardi?
   - 18-etap endi oddiy regime label emas, Market Reality / Intelligence Engine sifatida real kodga qo'shildi.
   - `MarketRealityService` OHLCV candlelardan rolling 20-candle feature chiqaradi: trend, panic, compression, expansion, momentum va `liquidity_proxy`.
   - Har candle uchun `MarketStateSnapshot` yoziladi: market_state, liquidity_state, momentum_state, structure_state, confidence_score va explanation.
   - Market Genome vectori yaratildi: trend, panic, compression, momentum, liquidity_proxy va genome_hash.
   - Market Species library qo'shildi: Slow Bull Expansion, Volatile Fake Breakout, Liquidity Vacuum, Fear Expansion, Volatility Compression va boshqa state nomlari.
   - Panic/trap/compression holatlaridan Market Memory, repeated statedan Market Discovery, latest genome'dan Similarity Scanner yoziladi.
   - Training session tugaganda StrategyScore latest Market Species bilan `strategy_species_performance` orqali bog'lanadi.
   - Yangi `/market-intelligence` dashboard qo'shildi.

2. Qaysi fayllar o'zgardi?
   - Yangi migration: `backend-laravel/database/migrations/2026_06_24_180000_create_market_reality_tables.php`.
   - Yangi models: `MarketSpecies`, `MarketSpeciesVersion`, `MarketStateSnapshot`, `MarketStateProbability`, `MarketGenome`, `MarketMemory`, `MarketSimilarityMatch`, `MarketDiscovery`, `StrategySpeciesPerformance`.
   - Yangi service: `backend-laravel/app/Services/MarketRealityService.php`.
   - Yangi controller/view: `MarketIntelligenceController`, `resources/views/market-intelligence/index.blade.php`.
   - Integration: `MarketDataService`, `StrategyLabController`, `RunAutoTrainingSession`, `routes/web.php`, `layouts/app.blade.php`.
   - Test: `backend-laravel/tests/Feature/MarketRealityEngineTest.php`.

3. DB/API/UI flow qanday o'zgardi?
   - `MarketDataService` candles saqlagandan keyin `MarketRealityService::analyzeSymbol()` chaqiradi.
   - Manual `/strategy-lab/run-all` va `php artisan trading:auto-train` endi `EvolutionGenomeService`dan keyin `MarketRealityService::recordStrategyPerformance()` chaqiradi.
   - Yangi DB jadvallar: `market_species`, `market_species_versions`, `market_state_snapshots`, `market_state_probabilities`, `market_genomes`, `market_memories`, `market_similarity_matches`, `market_discoveries`, `strategy_species_performance`.
   - UI menu ichiga `Market Intelligence` qo'shildi; dashboardda Current Market Genome, Species Library, Market Memories, Similarity Scanner, Discoveries va Strategy x Species ko'rinadi.
   - Existing AI Scientist, Agent Mind, Evolution Genome, Strategy Lab, Market Data, Walk Forward va Monte Carlo flow buzilmadi; Market Reality ular ustidan market-context intelligence qatlamini yaratadi.

4. Qanday test qilindi va aniq natija nima?
   - `php artisan test --filter=MarketRealityEngineTest` -> 3 passed, 21 assertions.
   - `php artisan test --filter=MarketDataPagesTest` -> 2 passed, 10 assertions.
   - `php artisan test --filter=StrategyLabRunAllTest` -> 9 passed, 40 assertions.
   - `php artisan test` -> 44 passed, 271 assertions.
   - Local browser check uchun `php artisan migrate --force` yuritildi; Market Reality migrationi DONE.
   - `GET http://127.0.0.1:8000/market-intelligence` -> 200 OK.

5. Nima hali qilinmagan?
   - `liquidity_proxy` OHLCV'dan taxmin qilinadi; haqiqiy liquidity/order-book data hali yo'q.
   - Reality Replay hozir snapshot explanation, memories va similarity scanner orqali ishlaydi; alohida candle-by-candle replay detail sahifasi hali qo'shilmagan.
   - Market transition matrix alohida jadvalga ajratilmadi; hozir alternative state probabilities `market_state_probabilities`da saqlanadi.
   - Species taxonomy heuristic; keyinchalik clustering/validation, out-of-sample species stability va Unknown/Novel species gate kerak.

6. Keyingi eng mantiqiy qadam nima?
   - 19-etap Universal Trading Knowledge Graph endi implementatsiya qilingan; keyingi canonical qadam 20-etap Future Simulation & Planning Engine.
   - Market Intelligence detail sahifalari: species show, genome replay, memory recall, similarity detail va strategy-by-species drilldown.

### 2026-06-24 - Canonical 17-etap Evolution Genome Engine implementatsiya qilindi

Status: `[IMPLEMENTED]`

1. Nima o'zgardi?
   - 17-etap oddiy Model Version history emas, Evolution Genome Engine sifatida real kodga qo'shildi.
   - Har StrategyScore/ModelVersion parametri immutable `StrategyGenome` sifatida saqlanadi: family, version, generation, genome_hash, genes, phenotype, fitness_score va evolution_efficiency.
   - Proposal apply qilinganda child genome yaratiladi, parent-child `GenomeLineage` yoziladi va `GenomeMutation` ichida old/new parameter diff saqlanadi.
   - Training session tugaganda fitness evaluation, generation aggregate, top-10 survival selection, archived/extinction event, crossover candidate va genome discovery engine ishlaydi.
   - Cross breeding hozir sandbox candidate sifatida `genome_crossovers`ga yoziladi; production strategy avtomatik yaratilmaydi.
   - Numeric gene heatmap/discovery high-fitness gene range'larni topadi va `GenomeDiscovery` hamda `KnowledgeFact`ga candidate bilim sifatida yozadi.
   - Yangi `/evolution-lab` dashboard qo'shildi.

2. Qaysi fayllar o'zgardi?
   - Yangi migration: `backend-laravel/database/migrations/2026_06_24_170000_create_evolution_genome_tables.php`.
   - Yangi models: `StrategyGenome`, `GenomeGene`, `GenomeLineage`, `GenomeMutation`, `GenomeCrossover`, `EvolutionGeneration`, `FitnessEvaluation`, `SelectionEvent`, `ExtinctionEvent`, `GenomeDiscovery`.
   - Yangi service: `backend-laravel/app/Services/EvolutionGenomeService.php`.
   - Yangi controller/view: `EvolutionLabController`, `resources/views/evolution-lab/index.blade.php`.
   - Integration: `StrategyLabController`, `RunAutoTrainingSession`, `EvolutionProposalController`, `ModelVersion`, `TrainingSession`, `StrategyScore`, `routes/web.php`, `layouts/app.blade.php`.
   - Test: `backend-laravel/tests/Feature/EvolutionGenomeTest.php`.

3. DB/API/UI flow qanday o'zgardi?
   - Manual `/strategy-lab/run-all` va `php artisan trading:auto-train` endi `AgentMindService`dan keyin `EvolutionGenomeService::recordTrainingSession()` chaqiradi.
   - `POST /evolution-proposals/{proposal}/apply` endi child ModelVersion yaratgandan keyin `EvolutionGenomeService::recordAppliedProposal()` chaqiradi.
   - Yangi DB jadvallar: `strategy_genomes`, `genome_genes`, `genome_lineages`, `genome_mutations`, `genome_crossovers`, `evolution_generations`, `fitness_evaluations`, `selection_events`, `extinction_events`, `genome_discoveries`.
   - UI menu ichiga `Evolution Lab` qo'shildi; dashboardda Genome Tree, Mutations, Cross Breeding, Evolution Efficiency, Genome Heatmap, Extinct Agents va Discoveries ko'rinadi.
   - Existing ModelVersion, EvolutionProposal, AI Scientist, Agent Mind, Strategy DNA, Walk Forward va Monte Carlo flow buzilmadi; Genome Engine ular ustidan lineage/evolution history qatlamini yaratadi.

4. Qanday test qilindi va aniq natija nima?
   - `php artisan test --filter=EvolutionGenomeTest` -> 3 passed, 27 assertions.
   - `php artisan test --filter=StrategyLabRunAllTest` -> 9 passed, 40 assertions.
   - `php artisan test --filter=EvolutionProposalPagesTest` -> 3 passed, 17 assertions.
   - `php artisan test` -> 41 passed, 250 assertions.
   - Local browser check uchun `php artisan migrate --force` yuritildi; Evolution Genome migrationi DONE.
   - `GET http://127.0.0.1:8000/evolution-lab` -> 200 OK.

5. Nima hali qilinmagan?
   - Crossover hozir proposed candidate; child strategy Python registry/ModelVersion sifatida avtomatik yaratilmaydi.
   - Selection global top-10 alive genomes bo'yicha ishlaydi; keyinchalik regime specialist, novelty champion va young genome diversity quota qo'shilishi kerak.
   - Frozen dataset/fee/slippage/seed protocol hozir metadata darajasida; full experiment registry 21-etap Research Director bilan chuqurlashadi.

6. Keyingi eng mantiqiy qadam nima?
   - 18-etap Market Reality / Intelligence Engine endi implementatsiya qilingan; keyingi canonical qadam 19-etap Universal Trading Knowledge Graph.
   - Evolution Lab detail sahifalari: genome show, lineage graph, mutation detail, crossover approval va extinction archive.

### 2026-06-24 - Canonical 16-etap Agent Mind / Metacognition Engine implementatsiya qilindi

Status: `[IMPLEMENTED]`

1. Nima o'zgardi?
   - 16-etap oddiy DNA Engine emas, Agent Mind / Metacognition Engine sifatida real kodga qo'shildi.
   - Agent endi market va qarorlarini kuzatishdan tashqari o'z ichki holatini ham kuzatadi: confidence, stress, trust, adaptation pressure, stability, learning rate va state.
   - Psychology snapshot 15-etapdagi hypotheses/beliefs, StrategyScore, Walk Forward, Monte Carlo va Strategy DNA evidence'dan hisoblanadi.
   - Self Reflection Engine session yakunida agentning o'z performance holati bo'yicha reflection va suggested action yozadi.
   - Contextual Memory Engine stress yoki market mismatch bo'lganda regime/volatility lesson saqlaydi.
   - Internal Debate Engine session ichida agentlar uchun BUY/NO/WAIT argumentlari va final consensus yaratadi.
   - Agent Reputation System ko'p sessionlik trust, calibration, stability va survival asosida reputation score saqlaydi.
   - Evolution Trigger stress > 80 yoki adaptation_pressure > 85 bo'lsa pending trigger yozadi.
   - Yangi `/agent-mind` dashboard qo'shildi.

2. Qaysi fayllar o'zgardi?
   - Yangi migration: `backend-laravel/database/migrations/2026_06_24_160000_create_agent_mind_tables.php`.
   - Yangi models: `AgentPsychologySnapshot`, `AgentSelfReflection`, `AgentMemory`, `InternalDebate`, `DebateArgument`, `AgentReputation`, `EvolutionTrigger`.
   - Yangi service: `backend-laravel/app/Services/AgentMindService.php`.
   - Yangi controller/view: `AgentMindController`, `resources/views/agent-mind/index.blade.php`.
   - Integration: `StrategyLabController`, `RunAutoTrainingSession`, `TrainingSession`, `StrategyScore`, `routes/web.php`, `layouts/app.blade.php`.
   - Test: `backend-laravel/tests/Feature/AgentMindTest.php`.

3. DB/API/UI flow qanday o'zgardi?
   - Manual `/strategy-lab/run-all` va `php artisan trading:auto-train` endi `TradingScientistService`dan keyin `AgentMindService::recordTrainingSession()` chaqiradi.
   - Yangi DB jadvallar: `agent_psychology_snapshots`, `agent_self_reflections`, `agent_memories`, `internal_debates`, `debate_arguments`, `agent_reputations`, `evolution_triggers`.
   - UI menu ichiga `Agent Mind` qo'shildi; dashboardda Psychology, Reputation, Self Reflections, Memory, Internal Debate va Evolution Triggers ko'rinadi.
   - Existing AI Scientist, Strategy DNA, Walk Forward, Monte Carlo va Agent Evolution flow buzilmadi; Agent Mind ular ustidan metacognitive layer sifatida ishlaydi.

4. Qanday test qilindi va aniq natija nima?
   - `php artisan test --filter=AgentMindTest` -> 2 passed, 23 assertions.
   - `php artisan test --filter=AiTradingScientistTest` -> 2 passed, 23 assertions.
   - `php artisan test --filter=StrategyLabRunAllTest` -> 9 passed, 40 assertions.
   - `php artisan test` -> 38 passed, 223 assertions.
   - Local browser check uchun `php artisan migrate --force` yuritildi; Agent Mind migrationi DONE.
   - `GET http://127.0.0.1:8000/agent-mind` -> 200 OK.

5. Nima hali qilinmagan?
   - Evolution Trigger hozir pending review signal sifatida yoziladi; production mutation/proposal queue'ga avtomatik apply qilmaydi.
   - Internal Debate hozir session-level stored argument; live signal paytidagi real-time debate hali qo'shilmagan.
   - Agent Memory retrieval hali dashboardda ko'rinadi, lekin strategy decision payloadiga qayta uzatilmaydi.

6. Keyingi eng mantiqiy qadam nima?
   - 17-etap Evolution Genome Engine uchun `evolution_triggers`, `agent_reputations`, `agent_memories` va Strategy DNA'dan genome mutation/crossover foundation yaratish.
   - Agent Mind detail sahifalarini qo'shish: psychology history, stress timeline, memory recall va reputation breakdown.

### 2026-06-24 - Canonical 15-etap AI Trading Scientist Engine implementatsiya qilindi

Status: `[IMPLEMENTED]`

1. Nima o'zgardi?
   - 15-etap endi oddiy Explainability emas, AI Trading Scientist Engine sifatida real kodga qo'shildi.
   - Har StrategyScore/raw trade natijasidan agent hypothesis yaratiladi: decision, confidence, market regime, volatility regime, measurable target, actual outcome va confirmed/failed/inconclusive status.
   - Failed hypothesis uchun Counterfactual Engine alternative reality analysis yozadi: delayed entry, wider ATR stop, RSI filtersiz yoki breakout confirmation kuchaytirilgan scenario.
   - Belief System har strategy uchun trend_following, regime_adaptability, survival_under_drawdown, rsi_confirmation yoki breakout_follow_through score'larini sample count va confidence interval bilan yangilaydi.
   - Scientist Journal har training sessiondan ilmiy summary, observations, most failed hypothesis va conclusion chiqaradi.
   - Knowledge Extraction regime performance'dan provisional/validated knowledge facts yaratadi.
   - Yangi `/ai-scientist` dashboard qo'shildi.

2. Qaysi fayllar o'zgardi?
   - Yangi migration: `backend-laravel/database/migrations/2026_06_24_150000_create_ai_trading_scientist_tables.php`.
   - Yangi models: `AgentHypothesis`, `AgentBelief`, `ScientistJournal`, `KnowledgeFact`, `CounterfactualRun`.
   - Yangi service: `backend-laravel/app/Services/TradingScientistService.php`.
   - Yangi controller/view: `AiScientistController`, `resources/views/ai-scientist/index.blade.php`.
   - Integration: `StrategyLabController`, `RunAutoTrainingSession`, `TrainingSession`, `StrategyScore`, `routes/web.php`, `layouts/app.blade.php`.
   - Test: `backend-laravel/tests/Feature/AiTradingScientistTest.php`.

3. DB/API/UI flow qanday o'zgardi?
   - Manual `/strategy-lab/run-all` va `php artisan trading:auto-train` session saqlangandan keyin `TradingScientistService::recordTrainingSession()` chaqiradi.
   - Yangi DB jadvallar: `agent_hypotheses`, `agent_beliefs`, `scientist_journals`, `knowledge_facts`, `counterfactual_runs`.
   - UI menu ichiga `AI Scientist` qo'shildi; dashboardda Hypotheses, Beliefs, Scientist Journals, Knowledge Base va Counterfactuals ko'rinadi.
   - Existing Strategy DNA, Walk Forward, Monte Carlo va Agent Evolution flow buzilmadi; Scientist layer ular ustidan evidence chiqaradi.

4. Qanday test qilindi va aniq natija nima?
   - `php artisan test --filter=AiTradingScientistTest` -> 2 passed, 23 assertions.
   - `php artisan test --filter=StrategyLabRunAllTest` -> 9 passed, 40 assertions.
   - `php artisan test` -> 36 passed, 200 assertions.
   - Local browser check uchun `php artisan migrate --force` yuritildi; AI Scientist migrationlari DONE.
   - `GET http://127.0.0.1:8000/ai-scientist` -> 200 OK.

5. Nima hali qilinmagan?
   - Python backtester hozir raw trades'dan faqat oxirgi 20 trade qaytaradi; barcha trade uchun per-trade hypothesis kerak bo'lsa payload hajmi va storage strategiyasi alohida optimizatsiya qilinadi.
   - Counterfactual Engine hozir deterministic heuristic sandbox estimate; causal proof emas va real re-backtest/re-simulation engine keyingi chuqurlashtirishga qoladi.
   - Why Engine hozir evidence_snapshot shaklida saqlanadi; alohida per-hypothesis detail page hali qo'shilmagan.

6. Keyingi eng mantiqiy qadam nima?
   - AI Scientist detail sahifalarini qo'shish: hypothesis show, belief history, knowledge fact provenance va counterfactual comparison.
   - Python tarafda explicit hypothesis payload va optional full-trades export qo'shish.
   - 16-etap Agent Mind uchun `agent_beliefs` va `scientist_journals`dan stress/trust/adaptation_pressure signalini chiqarish.

### 2026-06-24 - 13-15 etaplar implementatsiyasi contextga kiritildi

Status: `[IMPLEMENTED]`

1. Nima o'zgardi?
   - 13-etap Walk Forward Validation Engine, 14-etap Monte Carlo Risk Simulation Engine va legacy 15-etap Strategy DNA & Personality Engine kod bazasida implementatsiya qilingan holat sifatida contextga yakuniy log qilindi.
   - Strategy Lab oddiy leaderboarddan train/validation/forward robustness, sequence-risk survival va DNA/personality metrikalarini ko'rsatadigan validation laboratoriyasiga kengaydi.
   - Bu entry yozilgan paytda canonical 15-25 roadmap hali production implementatsiya emas edi; Strategy DNA `legacy 15-etap Strategy DNA implementation` sifatida belgilandi.

2. Qaysi fayllar o'zgardi?
   - Python: `ai-service-python/app/main.py`, `ai-service-python/app/schemas.py`, `ai-service-python/app/services/backtester.py`, `ai-service-python/app/services/walk_forward.py`, `ai-service-python/app/services/monte_carlo.py`, `ai-service-python/app/services/strategy_dna.py`, `ai-service-python/tests/`.
   - Laravel: `backend-laravel/app/Http/Controllers/StrategyLabController.php`, `backend-laravel/app/Http/Controllers/TrainingSessionController.php`, `backend-laravel/app/Console/Commands/RunAutoTrainingSession.php`, `backend-laravel/app/Services/AgentEvolutionService.php`, `backend-laravel/app/Services/OverfitDetectorService.php`, `backend-laravel/app/Models/StrategyScore.php`, `backend-laravel/app/Models/StrategyDnaProfile.php`.
   - DB/UI: walk-forward, Monte Carlo va DNA migrationlari; Strategy Lab, Training Session detail, DNA Laboratory views, layout nav va route.
   - Docs: `README.md`, `docs/ai-service-contract.md`, `PROJECT_CONTEXT.md`.

3. DB/API/UI flow qanday o'zgardi?
   - Python `/api/backtest/run-all` endi har strategy uchun `train_score`, `validation_score`, `forward_score`, `robustness_score`, `is_overfit`, `result.walk_forward`, `result.monte_carlo` va `result.strategy_dna` qaytaradi.
   - `strategy_scores` jadvaliga walk-forward va Monte Carlo ustunlari qo'shildi; `strategy_dna_profiles` jadvali StrategyScore bilan one-to-one bog'landi.
   - Model status qoidalari risk-aware shartlardan tashqari robustness, overfit, risk of ruin va worst drawdown guardrail'larini ham hisobga oladi.
   - UI'da Strategy Lab leaderboard, Training Session charts va yangi `/strategy-lab/dna-laboratory` sahifasi walk-forward, Monte Carlo va DNA ko'rsatkichlarini chiqaradi.
   - Evolution proposal logic overfit, high Monte Carlo risk va DNA weakness signaliga qarab parametr takliflarini boyitadi.

4. Qanday test qilindi va aniq natija nima?
   - `python -m unittest discover -s tests` -> 8 passed.
   - `python -m compileall app` -> OK.
   - `php artisan test` -> 34 passed, 177 assertions.

5. Nima hali qilinmagan?
   - Canonical 15-25 roadmap production implementatsiyasi hali boshlanmagan.
   - Live broker/data provider, multi-symbol training, queue-based long training va real parameter optimizer hali backlogda.
   - Strategy DNA legacy implementation keyinchalik 16-etap Agent Mind va 17-etap Evolution Genome foundationiga moslab qayta nomlanishi yoki integratsiya qilinishi mumkin.

6. Keyingi eng mantiqiy qadam nima?
   - Canonical 15-etap AI Trading Scientist Engine keyingi logda implementatsiya qilindi; 13-15 validation natijalari hypothesis/evidence schema uchun foundation sifatida ishlatildi.
   - Yoki qisqa muddatda Strategy parameter optimizer va mutation/v3 generation bilan mavjud validation pipeline'dan foydalanish.

### 2026-06-22 — Project memory va canonical roadmap yangilandi

Status: `[IMPLEMENTED DOCUMENTATION]`

- `PROJECT_CONTEXT.md` loyiha Source of Truth sifatida qat'iy belgilandi.
- Majburiy read-before-work va update-before-final protokoli yozildi.
- Obsidian vault va `[[wikilink]]` ishlatish tartibi qo'shildi.
- Implementatsiya, qaror va reja bir-biridan status belgilar orqali ajratildi.
- Mavjud legacy Strategy DNA implementatsiyasi saqlandi va roadmap conflict'i ochiq qayd qilindi.
- Canonical 15–20-etaplarning maqsadi, flow'i, entity'lari, ishonchlilik qoidalari, dashboardlari va o'zaro integratsiyasi to'liq yozildi.
- Bu update documentation-only; Laravel/Python runtime kodi o'zgartirilmadi va test ishga tushirish talab qilinmadi.

### 2026-06-22 — Autonomous Research Director konsepti qo'shildi

Status: `[IMPLEMENTED DOCUMENTATION]` / roadmap status: `[DECIDED / DEFERRED]`

- Canonical roadmap 15–20'dan 15–21'gacha kengaytirildi.
- Research opportunity mining, pre-registered experiments, Research Council, research economy, capital allocation, failed research archive va discovery impact modeli yozildi.
- Expected impact'dan tashqari expected information gain, replication, novelty, cost, risk va calibration talablari qo'shildi.
- Research Director uchun sandbox autonomy, human approval gate va o'z governance mezonlarini o'zgartirmaslik chegaralari belgilandi.
- Rejalashtirilgan DB entity'lari, research state machine, dashboard va 19–21 integratsiyasi hujjatlashtirildi.
- Bu update documentation-only; Laravel/Python runtime kodi o'zgartirilmadi va test ishga tushirish talab qilinmadi.
- 2026-06-24 correction: canonical 21 production etap Meta Intelligence Engine bo'ldi; Autonomous Research Director future/deferred research-director layer sifatida saqlanadi.

### 2026-06-22 — Meta Intelligence Engine konsepti qo'shildi

Status: `[IMPLEMENTED DOCUMENTATION]` / roadmap status: `[IMPLEMENTED AS CANONICAL 21 ON 2026-06-24]`

- Canonical roadmap 15–21'dan 15–22'gacha kengaytirildi.
- Knowledge audit, context-aware belief decay, contradiction classification, unknown-zone detection, blind-spot coverage va multidimensional Knowledge Health modeli yozildi.
- Epistemic state machine, quarantine, falsification test, multiple-testing/false-discovery nazorati va Meta Actions hujjatlashtirildi.
- Self Critic narrative'ni evidence-linked audit report bilan cheklandi.
- Meta Engine va Research Director vakolatlari ajratildi; independent audit, fresh holdout, immutable audit trail va human review talablari qo'shildi.
- Rejalashtirilgan DB entity'lari, dashboard va 19–22 feedback loop yozildi.
- Bu update documentation-only; Laravel/Python runtime kodi o'zgartirilmadi va test ishga tushirish talab qilinmadi.
- 2026-06-24 correction: foydalanuvchi Meta Intelligence'ni 21-etap deb belgiladi; production implementatsiya `Canonical 21-etap Meta Intelligence Engine implementatsiya qilindi` changelogida yozilgan.

### 2026-06-22 — Artificial Quant Civilization Engine konsepti qo'shildi

Status: `[IMPLEMENTED DOCUMENTATION]` / roadmap status: `[IMPLEMENTED AS CANONICAL 22 ON 2026-06-24]`

- Canonical roadmap 15–22'dan 15–23'gacha kengaytirildi.
- Texnik nom `Artificial Quant Organization Engine`, UI/konseptual nom `AI Civilization` sifatida belgilandi.
- Institutional role'lar, agent lifecycle, non-transferable research credits, multidimensional reputation va council decision protocol yozildi.
- Simple majority o'rniga domain-weighted decision, risk/meta veto, quorum, conflict-of-interest, dissent va outcome review qoidalari qo'shildi.
- Goodhart, collusion, sybil, budget capture, reputation laundering va groupthink uchun mechanism-design nazoratlari hujjatlashtirildi.
- Civilization Memory, Decision Ledger, institutional succession, human-ratified Constitution va multi-objective goal hierarchy yozildi.
- Rejalashtirilgan DB entity'lari, governance state machine, dashboard va 21–23 institutional loop qo'shildi.
- Bu update documentation-only; Laravel/Python runtime kodi o'zgartirilmadi va test ishga tushirish talab qilinmadi.
- 2026-06-24 correction: foydalanuvchi bu konseptni 22-etap deb belgiladi; production implementatsiya `Canonical 22-etap Artificial Quant Civilization Engine implementatsiya qilindi` changelogida yozilgan.

### 2026-06-22 — Universal Quant Laws Discovery Engine konsepti qo'shildi

Status: `[IMPLEMENTED DOCUMENTATION]` / roadmap status: `[IMPLEMENTED AS CANONICAL 23 ON 2026-06-24]`

- Canonical roadmap 15–23'dan 15–24'gacha kengaytirildi.
- `Universal Law` mutlaq fizik qonun emas, scope va falsification shartlariga ega provisional probabilistic law sifatida belgilandi.
- Law candidate contract, law turlari, multi-evidence validation, effective sample hierarchy, universality/confidence ajratilishi va formal validation metodlari yozildi.
- Law lifecycle, Law Graph, conflict/boundary discovery, temporal evolution va Meta Intelligence audit integratsiyasi qo'shildi.
- “Holy Grail Detector” texnik jihatdan `Universal Driver Analysis` sifatida chegaralandi; top driver avtomatik live rule bo'lmasligi belgilandi.
- Rejalashtirilgan DB entity'lari, dashboard va 19–24 scientific loop hujjatlashtirildi.
- Bu update documentation-only; Laravel/Python runtime kodi o'zgartirilmadi va test ishga tushirish talab qilinmadi.
- 2026-06-24 correction: foydalanuvchi bu konseptni 23-etap deb belgiladi; production implementatsiya `Canonical 23-etap Universal Quant Laws Discovery Engine implementatsiya qilindi` changelogida yozilgan.

### 2026-06-22 — Causal Intelligence Engine konsepti qo'shildi

Status: `[IMPLEMENTED DOCUMENTATION]` / roadmap status: `[IMPLEMENTED AS CANONICAL 24 ON 2026-06-24]`

- Canonical roadmap 15–24'dan 15–25'gacha kengaytirildi.
- Association, intervention va counterfactual darajalari qat'iy ajratildi; simulation causal proof emasligi qayd qilindi.
- Dynamic Structural Causal Model, causal edge statuslari, identifiability gate, assumptions va `NOT_IDENTIFIABLE` natijasi yozildi.
- Causal discovery'ning faqat hypothesis generator ekanligi, experiment evidence hierarchy, counterfactual contract va intervention utility modeli qo'shildi.
- Causal quality, root-cause mezonlari, heterogeneous effects, model competition, sensitivity va transportability talablari hujjatlashtirildi.
- Rejalashtirilgan DB entity'lari, dashboard, causal state machine va 19–25 scientific loop yozildi.
- Bu update documentation-only; Laravel/Python runtime kodi o'zgartirilmadi va test ishga tushirish talab qilinmadi.
- 2026-06-24 correction: foydalanuvchi bu konseptni 24-etap deb belgiladi; production implementatsiya `Canonical 24-etap Causal Intelligence Engine implementatsiya qilindi` changelogida yozilgan.

## Keyingi Ehtimoliy Etaplar

Canonical 15–25'dan tashqaridagi backlog:

- Strategy parameter optimizer
- Agent mutation / v3 generation
- Twelve Data live-feed hardening
- Multi-symbol support
- Queue jobs for long training
- Export reports

### 2026-08-19 — Dual-Track Constitutional Intelligence qo‘shildi

Status: `[IMPLEMENTED SHADOW MODE]`

- Paper signal oqimida Champion raw strategy lane va typed Council lane bir xil immutable snapshot ustida explicit projection sifatida yoziladi.
- `dual_track_runs` immutable observation ledger, fail-closed adjudicator va capability-cell router qo‘shildi.
- `DUAL_TRACK_MODE=shadow` default bo‘lib, mavjud incumbent paper ownership va council fallback o‘zgarmaydi.
- Qarama-qarshi actionable signal yoki actionable-vs-WAIT disagreement dual-track projectionda `WAIT` bo‘ladi.
- Operator monitoring: `php artisan trading:monitor-dual-track XAUUSD --timeframe=H1 --json`.
- Laravel targeted dual-track/council tests va Python `unittest discover` (104 test) o‘tdi; Laravel full suite 240 soniyalik limitda timeout bo‘ldi.
- Active cell routing faqat mustaqil forward/paper evidence, baseline parity, canary va anchor-ablation shartlari bajarilgandan keyin operator tomonidan yoqiladi.

### 2026-08-19 — Dual-Track outcome/evolution control plane joriy qilindi

Status: `[IMPLEMENTED EVIDENCE-GATED CONTROL PLANE]`

- `dual_track_outcomes` orqali Champion/Council natijasi, counterfactual regret, avoided-loss/missed-opportunity va risk faktlari settlementdan keyin yoziladi.
- Per-cell `dual_track_cell_policies` Wilson lower-bound, sample minimum, score margin va risk violation orqali faqat tavsiya/certification holatini hisoblaydi; `DUAL_TRACK_ACTIVATE_CERTIFIED_CELLS=false` default saqlanadi.
- Mustaqil `DualTrackRiskShieldService` active yo'lda constitution, snapshot, drawdown, risk-of-ruin, disagreement, calibration va confidence gate'larini tekshiradi; yetarli dalil bo'lmasa `WAIT`/reduced-size qaytaradi.
- Evaluator calibration, layered memory lessons va evolution-island events production parent/constitution/modelni avtomatik o'zgartirmaydi; barcha yozuvlarda `promotion_evidence=false` invarianti saqlanadi.
- Monitor endi run, outcome, cell policy, evaluator calibration, memory va evolution event statistikalarini bitta operator reportida qaytaradi.

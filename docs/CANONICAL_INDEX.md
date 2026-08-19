# NeuroTrader Lab canonical index

Bu fayl loyiha holatini topish uchun yagona kirish nuqtasi. Bir-biriga zid
yozuvlar uchrasa, quyidagi ustuvorlik tartibi qo‘llanadi.

1. Migrations, testlar va runtime konfiguratsiyasi — amaldagi bajariladigan haqiqat.
2. `docs/project-memory/project-index.json` — modul va ownership navigatsiyasi.
3. `docs/project-memory/ai-learning-laboratory.md` — AI Laboratory gate va lifecycle qoidalari.
4. `docs/operations/` — operator recovery/deployment qoidalari.
5. `docs/architecture/` va `docs/ENVIRONMENT.md` — strukturaviy va konfiguratsion reference.
6. `PROJECT_CONTEXT.md` — tarixiy qarorlar; undagi eski test sonlari current status emas.
7. Root `README.md` — qisqa onboarding, tafsilot manbasi emas.

## Hozirgi tizim

| Qism | Canonical manba | Owner chegarasi |
| --- | --- | --- |
| AI Laboratory va evolution | `docs/project-memory/ai-learning-laboratory.md` | Laravel evidence/gate; Python deterministic replay |
| Adaptive evolution | `docs/project-memory/adaptive-evolution.md` | Laboratoriya population va mutation siyosati |
| Market data | `docs/project-memory/market-data-continuity.md` | Provider, continuity va immutable data evidence |
| Operatsiya | `docs/project-memory/operations.md`, `docs/operations/`, `docs/operations/backup.md`, `docs/operations/lab-bottleneck-recovery.md` | Scheduler, queue, G: backup, Redis va laboratory recovery |
| Modul xaritasi | `docs/project-memory/modules.md` | Domain ownership va service catalog |
| Environment | `docs/ENVIRONMENT.md` | `.env.example` parametrlarining ma’nosi |

## Hujjatni yangilash qoidasi

Yangi feature yoki gate o‘zgarganda shu PR/commit ichida: modul indexi, tegishli
project-memory fayli, environment reference (yangi env bo‘lsa) va runbook
(operatsion ta’sir bo‘lsa) yangilanadi. README faqat onboarding yoki top-level
arxitektura o‘zgarsa tahrirlanadi.

# Service domain layout

`app/Services` tarixiy compatibility qatlami. Yangi service faqat o‘z domaini
ostida yaratiladi; eski namespace’dan foydalanayotgan consumerlar uchun kichik
deprecated adapter saqlanadi va katta ko‘chirish bitta change ichida qilinmaydi.

| Domain | Joylashuv | Mas’uliyat |
| --- | --- | --- |
| Laboratory | `app/Domains/Laboratory/Services` | agent constitution, population, replay evidence, evolution |
| MarketData | `app/Services/MarketData` -> `app/Domains/MarketData/Services` | provider, continuity va canonical candle flow |
| Trading | `app/Domains/Trading/Services` | signal, paper execution, risk va portfolio lifecycle |
| Intelligence | `app/Domains/Intelligence/Services` | market reality, graph, causal/theory research |
| Operations | `app/Domains/Operations/Services` | runtime health, scheduler, recovery va external transport |

## Migration qoidasi

1. Service’ni yangi domain namespace’ga ko‘chiring.
2. Eski `App\Services\*` nomida faqat `extends` compatibility adapter qoldiring.
3. Yangi injection/importlar domain class’dan foydalansin.
4. Adapterga endi yangi logic yozmang; major-version cleanup’da o‘chiring.

`AgentConstitutionService` ushbu usuldagi birinchi migratsiya bo‘lib, mavjud
command/job/test injectionlarini buzmasdan `Laboratory` domainiga o‘tkazilgan.

# Loyihani arxivlash va keyin backupdan tiklash

2026-10-23. Faqat `neurotrader_lab` bazasi arxivlanadi. Loyiha kodi,
datasetlar, eski tajriba dalillari va foydalanuvchi qaydlari o‘chirilmaydi.
Research yoki trading natijalarining maqomi o‘zgarmaydi.

## Yakuniy holat

**2026-10-23 23:38:02 Toshkent (18:38:02 UTC)** da faqat
`neurotrader_lab` bazasi native SQL `DROP DATABASE` bilan o‘chirildi.
Oldidan barcha jadvallarning aniq son/checksum/DDL inventari qayta olinib,
yangi backupdan beri o‘zgarmagani tekshirildi. C: bo‘sh joyi shu operatsiyada
13,859,082,240 dan 34,259,218,432 baytga oshdi: **20,400,136,192 bayt
(taxminan 19 GiB)** qaytdi. Asl MySQL data fayllari qo‘lda o‘chirilmagan.

Loyiha qayta yoqilmaydi: Windows recovery task Disabled, saqlangan PM2
startup fayllari arxivlangan, Laravel HTTP maintenance saqlangan.
Umumiy MySQL serveri o‘chirilmagan; boshqa loyihalar va ularning bazalariga
tegilmadi. Keyingi ishga tushirishdan **oldin** quyidagi tiklash tartibi shart.

## Arxiv manzili va tekshiruv

Arxiv papkasi: `D:/NeuroTrader/backups/neurotrader_lab-paused-20261023`.
Yangi fayl: **`neurotrader_20261023_165757.sql.gz`**, hajmi **2,570,475,070
bayt** (2.57 GB; 2.39 GiB). SQL oqimi siqilmaganda 18,452,899,494 bayt.
Yonidagi `.sql.gz.manifest.json` bilan bir juftlik hisoblanadi. To‘liq gzip
CRC va raw/compressed SHA tekshiruvi 17:16:37 UTC’da yakunlangan.

SHA-256: `27e275824ed2525268ca0fc71758394d4cb7cea447de711cc74e58070b5266cc`.

Haqiqiy native tiklash tekshiruvi **2026-10-23 18:31:59 UTC** da tugadi:
**358/358 jadval, jami 11,498,904 qator**. Har jadvalning qator soni,
extended checksum va DDL’i asl bazaga mos. Gzip CRC, to‘liq SQL va siqilgan
fayl hash/hajmi ham qayta tasdiqlandi. Tekshiruv 4348 soniya davom etdi;
tekshirilgan scratch jadvallari va oxirida vaqtinchalik baza/hisob olib
tashlandi. Asl baza bu tekshiruvda o‘zgartirilmadi.

Haqiqiy tiklash tekshiruvi va bazani o‘chirishning yakuniy holati quyidagi
receiptlar bilan tasdiqlanadi; backup yaratilgani ularning o‘rnini bosmaydi.

Papka ichidagi dalillar:

- `inventory.json`: asl bazadagi barcha 358 jadvalning aniq qator soni,
  `CHECKSUM TABLE EXTENDED`, `SHOW CREATE TABLE`, server va collation.
- `table-verification.jsonl`: backupdagi har bir jadval alohida, cheklangan
  hisobli vaqtinchalik bazada tiklanib, asl ro‘yxatga solishtirilgan receipt.
  Bu butun dastur yoki ilmiy natija acceptance’i emas.
- `pre-deletion-proof.json` va `deletion-receipt.json`: faqat tekshirilgan
  backup mavjudligi va asl baza o‘zgarmaganligi tasdiqlangandan keyingi
  o‘chirish dalillari. Receipt yo‘qligi operatsiya bajarilganini anglatmaydi.
- `private-original.env`: maxfiy konfiguratsiya, jumladan asl `APP_KEY`.
  Uni chatga yoki Git’ga yubormang. Papkaga kirish cheklangan.
- Mavjud bo‘lsa `private-neurotrader-dump.rdb`: to‘xtatilgan Redis snapshot’i;
  `preserved-files.json` uning va konfiguratsiyaning hashlarini saqlaydi.
- `private-pm2-dump.pm2`, `private-pm2-dump.pm2.bak` va
  `pm2-cold-stop.json`: PM2’ning har birida faqat shu loyihaga tegishli 14 ta
  saqlangan jarayon bo‘lgan ikkala holat fayli hash tekshiruvi bilan shu yerga
  ko‘chirildi. Ular maxfiy environment qiymatlarini saqlashi mumkin. Asl
  `C:/Users/MK_99/.pm2` dagi primary va fallback dump endi yo‘q; PM2 daemon
  boshlanmagan. Boshqa loyiha yozuvlari yoki jarayonlari o‘zgartirilmagan.

G: dagi eski backuplar bu arxiv orqali prune qilinmaydi. Bir diskdagi backup
diskning o‘zi buzilishidan himoya qilmaydi: imkon bo‘lganda tekshirilgan juftlik
va maxfiy sidecarlarni shifrlangan boshqa tashuvchiga ham ko‘chiring.

## Nimalarni saqlash kerak?

SQL quyidagi fayllarning o‘rnini bosmaydi. Hozir ular loyiha ichida saqlanadi:

- `backend-laravel/.env`, ayniqsa **o‘sha APP_KEY**. Yangisini generatsiya
  qilish bazadagi shifrlangan qiymatlarni ochib bermaydi.
- `backend-laravel/storage/app/lab-evidence` va boshqa original artifactlar.
- `backend-laravel/storage/app/lab-datasets`, manba CSV/manifestlari.
- `.runtime/research-source-artifacts`, source ZIP/manifestlari.
- Redis RDB, kerakli private tokenlar, source va dependency lock fayllari.
- `.runtime` dagi saqlangan tadqiqot worktree’lari va foydalanuvchi qaydlari.

Faqat SQL’ni boshqa kompyuterga olib borish to‘liq loyiha tiklanishi emas.

## 1. Tiklashdan oldin fon ishlarini o‘chiq qoldiring

`NeuroTrader Autonomous Runtime` Windows task’i Disabled bo‘lsin. PM2,
scheduler, worker, AI service va Redis consumerlarini hali boshlamang.
Laravel maintenance rejimini saqlang; MySQL serverining o‘zi ishlashi kerak.
Shared XAMPP/MySQL boshqa loyihalarga ham xizmat qilishi mumkin.

Saqlangan eski PM2 dump’larini joyiga ko‘r-ko‘rona qaytarmang va
`pm2 resurrect` qilmang: ular oldingi online holatni avtomatik boshlashi
mumkin. Ularning D: dagi nusxalari tarixiy ma’lumot uchun saqlangan.
Tiklashdan keyin tekshirilgan amaldagi runtime konfiguratsiyasidan quyidagi
bosqichli ishga tushirish tartibini qayta tuzing.

Bu kompyuterdagi server: MariaDB **10.4.27**, host `127.0.0.1:3306`.
Baza: **`neurotrader_lab`**, charset `utf8mb4`, collation
`utf8mb4_general_ci`. Birinchi tiklashni mos serverda bajaring. Versiya
almashtirishni arxivni tiklash bilan bir operatsiyaga qo‘shmang.

Asl serverning `@@max_allowed_packet` qiymati **1073741824 (1 GiB)**.
Yangi serverdagi qiymatni ham tekshiring: faqat client limitini oshirish
serverdagi kichik limitni o‘zgartirmaydi. Shared server sozlamasini boshqa
loyihalarga ta’sirini tekshirmasdan avtomatik o‘zgartirmang.

Diskda SQL kengaytmasi, jadvallar, indekslar va vaqtinchalik ishlar uchun
yetarli joy bo‘lsin. `.sql.gz` hajmi bazaning tiklangan hajmi emas.
Asl baza papkasidagi fayllar 20,399,254,829 bayt (taxminan 19 GiB) edi;
tiklashdan oldin kamida 25–30 GB bo‘sh joy rejalang va real sarfni kuzating.

## 2. Backup yaxlitligini tekshiring

PowerShell’da:

```powershell
$archive = 'D:\NeuroTrader\backups\neurotrader_lab-paused-20261023'
$manifestFiles = @(Get-ChildItem -LiteralPath $archive -Filter '*.sql.gz.manifest.json')
if ($manifestFiles.Count -ne 1) { throw 'Bitta aniq manifest kerak' }
$manifest = Get-Content -LiteralPath $manifestFiles[0].FullName -Raw | ConvertFrom-Json
if ($manifest.database -ne 'neurotrader_lab') { throw 'Baza nomi mos emas' }
$backup = Join-Path $archive $manifest.file
if ((Get-FileHash -LiteralPath $backup -Algorithm SHA256).Hash.ToLowerInvariant() -ne $manifest.sha256) {
    throw 'Backup SHA256 mos emas; import qilmang'
}
if ((Get-Item -LiteralPath $backup).Length -ne $manifest.bytes) { throw 'Hajm mos emas' }
```

Hash mos kelmasa importni davom ettirmang. Oldingi muvaffaqiyatli tekshiruv
keyinchalik buzilgan faylni avtomatik yaroqli qilmaydi.

## 3. Bo‘sh bazani yarating, keyin import qiling

`.env` dagi ulanishni tekshiring. `DB_DATABASE=neurotrader_lab` va original
`APP_KEY` saqlansin. Backup `--databases` bilan olinmagan: import komandasi
o‘chirilgan bazani o‘zi yaratmaydi.

MySQL mijozida `.env` dagi mos foydalanuvchi bilan ulaning; parolni buyruq
matniga yozmang. Quyidagi SQL’ni faqat shu nomdagi baza yo‘q bo‘lsa bajaring:

```sql
CREATE DATABASE `neurotrader_lab`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
```

Baza allaqachon mavjud bo‘lsa, uni avtomatik o‘chirmang yoki ustiga import
qilmang. Avval u nimani saqlayotganini aniqlang va alohida backup oling.

Loyiha rootidan PowerShell’da:

```powershell
Set-Location 'C:\x_programs\xamp\htdocs\laravel_projects\ai_agent\backend-laravel'
$env:MYSQL_BINARY = 'C:\x_programs\xamp\mysql\bin\mysql.exe'
& 'C:\x_programs\xamp\php\php.exe' -d browscap= artisan config:clear
if ($LASTEXITCODE -ne 0) { throw 'Config tekshiruvi kerak; importni boshlamang' }
& 'C:\x_programs\xamp\php\php.exe' -d browscap= artisan ops:restore-database $backup --confirm=RESTORE
if ($LASTEXITCODE -ne 0) { throw 'Import tugamadi; runtime boshlanmasin' }
```

`-d browscap=` bu kompyuterdagi eskirgan shared PHP ini yo‘lining bir martalik
workaround’i; shared `php.ini` o‘zgartirilmaydi. Import gzip’ni oqimda ochadi,
diskda alohida katta `.sql` fayl yaratilmaydi. Aynan `per_table_v1` formatida
1 MiB gacha literal INSERT batch va taxminan 8 MiB transaction qo‘llanadi;
yakka katta original qator bo‘linmaydi va alohida commit qilinadi. Xotira
sarfi eng katta original SQL qatori va cheklangan bufferlarga bog‘liq, butun
dump hajmiga emas. Legacy manifestlarning resource yo‘li o‘zgarmagan.
Client packet limiti 1 GiB, komanda timeout’i 7200 soniya. Butun bazani bitta
targetda aynan shu komanda bilan import qilish vaqti o‘lchanmagan: hozirgi
haqiqiy acceptance alohida jadvallarni tiklash orqali bajariladi. Limit
yetishmasa, muvaffaqiyat deb
qabul qilmang: qisman importni tekshirib, boshqariladigan tiklashni rejalang.
`config:clear` eski cached ulanish `.env` o‘rniga boshqa bazani tanlashining
oldini oladi; joriy shell’dagi DB_* va DB_URL override’larini ham tekshiring.
MYSQL_BINARY bu yerda faqat joriy PowerShell sessiyasi uchun belgilanadi.

## 4. Ishga tushirishdan oldin tiklangan holatni tekshiring

Runtime hali o‘chiq va maintenance saqlangan holatda:

1. Barcha 358 jadval, aniq qator sonlari, checksum va DDL’ni
   `inventory.json` bilan solishtiring. Faqat jadval sonini tekshirish yetmaydi.
2. Asl migration ledger va tajriba/generation/receipt ID’lari saqlanganini
   tekshiring. `migrate:fresh`, `db:wipe`, reseed yoki avtomatik upgrade yo‘q.
3. `.env`, APP_KEY, artifact va dataset manzillari hamda hashlarini tekshiring.
   SQL to‘g‘ri import bo‘lgani yetishmayotgan CSV/artifactni yaratmaydi.
4. Redis’ni faqat o‘zini, consumersiz boshlang. Oldingi pending/reserved/delayed
   navbatlarni va ownershipni tekshiring; `FLUSHALL`, navbatni ko‘r-ko‘rona
   tozalash yoki eski job’larni qayta yuborish yo‘q. RDB eski snapshot bo‘lsa,
   SQL bilan mosligi aniqlanmaguncha consumer boshlanmang.
5. `php -d browscap= artisan ai:status --json` PAUSED bo‘lishini tekshiring.
   Import eski generation natijalarini yangi scientific evidence qilmaydi.

## 5. Faqat yangi qaror bilan loyihani davom ettiring

Bu arxivlash topshirig‘i qayta ishga tushirishga ruxsat emas. Qaytishga qaror
qilinganda avval yuqoridagi tekshiruvlar, source/release va loaded-worker hash
preflight’i bajariladi. Audit qilingan navbatlar bilan mavjud PM2 yoki manual
yashirin owner orqali staged boot qilinadi: PAUSE saqlanib AI/API va zarur
workerlar, haqiqiy loaded-code tekshiruvi, so‘ng scheduler cadence eng oxirida.
Eski pending joblar ham admission talab qiladi. Runtime-gated fallback launcher
PAUSE’da jarayonlarni boshlamaydi; undan staged boot uchun foydalanmang.

Asl boshqaruv PAUSE bo‘lgani uchun o‘sha lineage uchun amaldagi owner:

```powershell
& 'C:\x_programs\xamp\php\php.exe' -d browscap= artisan ai:resume --json
```

Bu komandani faqat davom ettirishga tayyor bo‘lganda bering. `ai:start`
safety_halt/STOP uchun boshqa kontrakt; PAUSE’ni qo‘lda DB tahriri bilan
almashtirmang. `backend-laravel/scripts/run-laravel-workers-hidden.vbs` faqat
ongli full-runtime RESUME’dan keyingi alternativ launcher: u scheduler’ni
birinchi boshlaydi, shuning uchun yuqoridagi xavfsiz staged boot o‘rniga
qo‘yilmaydi. PM2 va fallbackni parallel ishga tushirmang. Maintenance’dan
chiqish (`artisan up`) va Windows task’ini qayta yoqish ham faqat shu qarordan
keyin. Batafsil owner chegaralari: [avtonom runtime tartibi](autonomous-research-run.md).

Keyingi tekshiruv: `ai:status --json`, so‘ng
`trading:run-research-loop --dry-run --json`. Yangi generationni qo‘lda
yaratmang, eski receiptlarni o‘zgartirmang. **2026+ paper-only** chegarasi
arxivlash yoki tiklash sababli o‘zgarmaydi.

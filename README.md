# Облік інвентарних книг

Адмінка системи обліку та розпізнавання музейних облікових (інвентарних) книг: скани книг зі сховища → розпізнавання Gemini → звірка зі сканом → AI-перевірка → адаптація до шаблону РМФУ → експорт.

Стек: Laravel 13 + Filament 5, PHP 8.3, PostgreSQL (локально SQLite), черга Laravel (database).

## Швидкий старт

```bash
cd admin
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan filament:assets
php artisan serve                      # http://127.0.0.1:8000/admin
php artisan queue:work --queue=interactive,default   # окремо: розпізнавання, AI-перевірка, адаптація, експорт
php artisan schedule:work              # окремо: синхронізація за розкладом, відновлення черги
```

Демо-користувачі (пароль `password` або значення `ADMIN_PASSWORD` з `.env`):

| Логін | Роль | Що може |
|---|---|---|
| admin@books.test | Адміністратор | усе, користувачі, налаштування, журнал |
| operator@books.test | Оператор (+ адаптація й експорт) | синхронізація, книги, черга, розпізнавання, адаптація, експорт |
| verifier@books.test | Верифікатор | звірка записів зі сканом, правки, зауваження, AI-перевірка |
| viewer@books.test | Перегляд | лише перегляд книг, записів і сканів |

Демо-дані: Лисичанський краєзнавчий музей → Документальний фонд (Д) → «Інвентарна книга Д-ІІІ (документи)», 4 розвороти, записи № 1685–1819 з прикладу `Інвентарна книга музею розпізнаний текст.xlsx`, адаптації до РМФУ і пробний експорт. **Зображення розворотів згенеровані** з розпізнаного тексту (`tools/make_demo_scans.py`), це не фото справжньої книги.

## Gemini

Ключ тільки в `.env`: `GEMINI_API_KEY=...`. Без ключа працює демо-режим: розпізнавання повертає попередній результат, AI-перевірка пропонує значення із зауважень верифікатора. Модель, ліміти запитів, місячний бюджет і промпти задаються в адмінці: Адміністрування → Налаштування → Gemini AI.

## Що де

- `admin/app/Services/`: `StorageSync` (обхід сховища за правилами папок), `GeminiClient`, `RecognitionService`, `AiCheckService`, `AdaptationService`, `ExportService`, `QueueManager`.
- `admin/app/Jobs/RunProcessingJob.php`: виконання завдань черги (пауза, ліміт, повтори з відступом, бюджет).
- `admin/app/Filament/`: ресурси, сторінки «Налаштування», «Дерево сканів», екран звірки `Records/Pages/ReviewRecord`.
- `admin/database/migrations/`: усі таблиці (структура → записи → реєстр РМФУ).
- `admin/database/seeders/RegistrySeeder.php`: шаблон міграції РМФУ (42 поля), довідники, правила адаптації.
- Команди: `php artisan books:sync {path?} {--checksum}`, `php artisan books:recover`.

## Ролі та права

Доступ описано в `App\Filament\Support\Access`: кожен ресурс задає `viewAccess()` / `editAccess()` (`all`, `staff`, `operate`, `verify`, `adapt`, `admin`). Оператор і верифікатор бачать лише призначені їм музеї або книги (якщо призначення є).

## Деплой

Ще не налаштований. План: окремий піддомен, Docker-образ збирається на GitHub Actions (не на VPS), секрети в GitHub Secrets.

# Архів: система розпізнавання архівних документів — контекст для Claude Code

Мова спілкування із замовником: українська. Замовник: Дмитро (emuseum). Почалося 06.11.2026: копія системи eBooks
(облік і розпізнавання музейних інвентарних книг, репо emuseum-code/books) під архівні документи.
Це окрема система; сайт Архіву МЗС (emuseum-code/earchive) — інший проєкт, його не чіпати.

## Модель

- Структура: територія → архів (`Museum`) → фонд (`Fund`, `code` = номер фонду) → опис (`books.inventory_no`) → справа (`Book`) → скани аркушів (`Scan`).
  Назви класів і таблиць лишилися з eBooks (museum, book), у адмінці — «архів», «справа».
- Документ (`Record`) — поля внутрішнього опису й ISAD(G): `Record::FIELDS` (№, індекс, дата, аркуші, архівний шифр, вид, заголовок,
  короткий зміст, автор, адресат, місце, мова, справжність, спосіб відтворення, особи, географічні назви, установи, повний текст, стан, примітки).
- Документ на кількох аркушах: Gemini ставить `continuation=true`, текст дописується в `full_text` попереднього документа (`RecognitionService::appendContinuation`).
- Адаптація = описова стаття ISAD(G) (`RegistrySeeder`: схема, довідники видів документів, мов, справжності, способу відтворення, стану).
- Демо: `DemoSeeder` + `database/seeders/data/demo-case.json`, скани `storage/demo-scans` генерує `tools/make_demo_case.py` (документи вигадані).

## Правила

- Кожну готову зміну пушити в GitHub. Деплой лише вручну (Actions → Deploy) і після узгодження з координатором: VPS спільний.
- Docker-образи не збирати на VPS, тільки на GitHub Actions. Сервіси `archive-*`, `/opt/archive`, compose-проєкт `archive`,
  усі сервіси в мережі `mincult_default` (своєї мережі не створюємо: на VPS скінчились підмережі), Caddy-блок `/opt/caddy-sites/archive.caddy`.
- Ключі (GEMINI_API_KEY, паролі) тільки в `.env` або GitHub Secrets, у код не писати.

## Підводні камені Filament 5

- У замиканнях параметри мають називатися `$record`, `$query`, `$data`, `$state`. Типізований `Builder $q` отримує новий екземпляр із контейнера, і фільтр не працює.
- Після `composer install` треба `php artisan filament:assets`, інакше сторінки без стилів.
- Моделям потрібні `$attributes` з дефолтами статусів, бо після `create()` атрибутів з БД немає.

## Перевірка

`cd admin && php artisan migrate:fresh --seed && php artisan test`, далі сторінки під admin/operator/verifier/viewer@archive.test. Екран звірки: `/admin/records/{id}/review`.

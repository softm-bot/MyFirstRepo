# Формализация ПНТД

Машиночитаемая и человекочитаемая модель домена **ПНТД** (перечень / линейка нормативно-технической документации) на основе публичных поверхностей:

| Поверхность | URL | Роль |
|-------------|-----|------|
| Открытый указатель | https://www.pntd.ru/ | Карточки НТД, классификаторы, поиск (с 2006) |
| Закрытый прототип линейки | https://gost.info/pntd/ | Gate + UX каталога/тарифов/комплектов (гости: HTTP 401) |
| Смежный реестр | https://gost.info/gost-documents/ | Внутренний учёт документов Norma Soft (отдельное приложение) |

Секреты и обход gate **не входят** в этот пакет. Исследование — только публичные/анонимные поверхности.

## Состав

| Путь | Назначение |
|------|------------|
| [docs/FORMALIZATION.md](docs/FORMALIZATION.md) | Формализация v0.2: сущности, статусы, lifecycle, нумерация, связи, критерии приёмки |
| [docs/GLOSSARY.md](docs/GLOSSARY.md) | Глоссарий терминов |
| [docs/SURFACE-MAP.md](docs/SURFACE-MAP.md) | Карта поверхностей pntd.ru ↔ gost.info/pntd ↔ gost-documents |
| [schema/domain.yaml](schema/domain.yaml) | Онтология / доменная модель (YAML) |
| [schema/ntd-document.schema.json](schema/ntd-document.schema.json) | JSON Schema карточки НТД |
| [schema/product-line.schema.json](schema/product-line.schema.json) | JSON Schema линейки (SKU, тарифы, комплекты) |
| [schema/ddl.sql](schema/ddl.sql) | SQL DDL черновика хранилища |
| [examples/](examples/) | Примеры экземпляров по публичным карточкам |

## Источники сверки (2026-09-27)

- Главная и метаданные https://www.pntd.ru/ (~62 999+ карточек)
- Карточки `/nd/{id}.html`, классификаторы `/ok/oks|kgs|okstu.php`, `/grnti.php`, поиск `/search.php`
- Gate https://gost.info/pntd/ (401): код `PNTD-GATE`, темы UI, вход через `/forum2/pntd_gate.php`
- Публичный JS/CSS: `/pntd/assets/app.js`, `app.css` (price-form, kit, bases, copy-form, themes)

## Связь с другими ветками репозитория

Не путать с:

- `gost-system-backup-module/` / `PROJECT-CONTEXT.md` — учёт **внутренних** документов на gost-documents
- `tools/restamp/` — метки `НД-XXXX-XXXX` в Word КП

Этот каталог описывает **указатель НТД и продуктовую линейку PNTD**, а не журнал исходящих КП.

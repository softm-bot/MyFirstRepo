# Карта поверхностей ПНТД

Сверка публичных URL и их роли в домене (2026-09-27).

## 1. Сводная схема

```
                    ┌─ /ok/oks.php ─── дерево ОКС
                    ├─ /ok/kgs.php ─── дерево КГС
pntd.ru ────────────┼─ /ok/okstu.php ─ дерево ОКСТУ
  (указатель)       ├─ /grnti.php ──── дерево ГРНТИ
                    ├─ /search.php?q= ─ поиск
                    └─ /nd/{id}.html ─ карточка НТД
                              │
                              │ бренд / предмет НТД
                              ▼
gost.info/pntd/ ──── gate 401 ──► forum2/pntd_gate.php ──► progost forum
  (линейка)         │
                    ├─ /pntd/assets/app.js   (каталог, цены, kit)
                    ├─ /pntd/assets/app.css
                    └─ /pntd/assets/theme-*.css
                              │
                              │ компания / хостинг
                              ▼
gost.info/gost-documents/ ──── внутренний реестр КП (др. домен)
```

## 2. pntd.ru — маршруты

| Маршрут | Назначение | Auth |
|---------|------------|------|
| `/` | Главная: бренд ПНТД, о проекте, справка обозначений, вход в классификаторы | public |
| `/search.php?q=` | Поиск по обозначению/тексту | public |
| `/nd/{id}.html` | Карточка документа | public |
| `/ok/oks.php`, `/ok/{nn}_oks.php` | ОКС | public |
| `/ok/kgs.php`, `/ok/kgs/{Раздел}.php` | КГС | public |
| `/ok/okstu.php`, `/ok/okstu/{code}.php` | ОКСТУ | public |
| `/grnti.php`, `/grnti/{nn}.php` | ГРНТИ | public |
| `/app/assets/site.css`, `logo-r.svg` | Оформление указателя | public |

Цветовой акцент сайта: `#1E4BB5` (theme-color).

## 3. gost.info/pntd — маршруты

| Маршрут | Наблюдение | Auth |
|---------|------------|------|
| `/pntd/` | Gate «Допуск — PNTD», `PNTD-GATE`, темы | **401** guest |
| `/pntd/?theme={id}` | Выбор темы (список на gate) | 401 HTML; query отражается в UI gate |
| `/pntd/assets/app.js` | Логика price/kit/copy/theme | **200** public |
| `/pntd/assets/app.css` | Стили контура | **200** public |
| `/pntd/assets/theme-*.css` | Темы | **200** public |
| `/forum2/pntd_gate.php?return=%2Fpntd%2F` | Выдача допуска после логина форума | redirect/forum |

## 4. Соответствие концептов

| Концепт | pntd.ru | gost.info/pntd |
|---------|---------|----------------|
| Документ НТД | `DocumentCard` | не видно в публичном JS |
| Классификаторы | ОКС/КГС/ОКСТУ/ГРНТИ | нет |
| Статус НТД | Действует/Заменен/… | статус **контура** (допуск) |
| Цена / тариф | нет | `Price`, `Tariff`, `Kit` |
| SKU / артикул | нет | catalog / software_code / base_article |
| Тема UI | единый site.css | десятки `theme-*` |
| Поиск | search.php | н/д публично |
| Доступ | открыт | forum gate |

## 5. Что не смешивать

1. **`/nd/2728.html`** (карточка ГОСТ) ≠ метка **`НД-CZF5-QH2M`** в Word КП.
2. **ПНТД-указатель** ≠ **gost-documents** (исходящие КП двух ООО).
3. **Закрытый прототип линейки** ≠ полный текст стандартов и ≠ открытый указатель.

## 6. Рекомендуемый мост на будущее (не реализован)

Опциональный внешний ключ между плоскостями:

```yaml
CatalogItem:
  optional_links:
    - type: ntd_designation   # "ГОСТ 24297-2013"
    - type: ntd_card_id       # 52077
    - type: pntd_ru_url       # https://www.pntd.ru/nd/52077.html
```

Пока в публичном `app.js` такого моста нет — в схеме `product-line` поле `ntdRef` помечено как optional extension.

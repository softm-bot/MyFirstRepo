# HANDOFF → Claude / локальный Cursor

**Полное управление передаётся локальному Claude.**  
Cloud Agent больше не ведёт разработку — работайте с кодом на диске и FTP сайта.

Локальный workspace: `C:\Users\andre\Desktop\кп\gost-documents`  
Живой сайт: https://gost.info/gost-documents/

Бэкап системы **сделан** (см. ниже). Можно спокойно менять код и БД.

---

## С чего продолжить сейчас

Открытый журнал договоров:

https://gost.info/gost-documents/?journal=contract&q=&filter_status=&filter_recipient=0&per_page=20

Дальше — полное сопровождение системы (журналы, договоры, нумерация, импорт, баги UI/PHP) локальным Claude.

---

## Доступы

### Веб
| | |
|--|--|
| URL | https://gost.info/gost-documents/ |
| andrey (admin) | `andrey` / `NormaSoft2026!` |
| anna | `anna` / `NormaSoft2026!` |

### FTP (код)
| | |
|--|--|
| Хост | `37.140.192.169:21` |
| Сайт (FileZilla) | `u1534553_andrey` — пароль у владельца |
| Агент | `u1534553_cursor` / `eM0gI3uU4iyA8aY7` |
| Корень сайта на диске | `/var/www/u1534553/data/www/gost.info/gost-documents/` |
| FTP-путь | `www/gost.info/gost-documents/` (`public/`, `src/`, `bin/`, `storage/`, `config.php`) |

`u1534553_cursor` **попадает** в живой `public/` (проверено). LIST/RETR часто таймаутятся; надёжнее мелкие STOR с ретраями или заливка через FileZilla от andrey.

### MySQL
| | |
|--|--|
| Хост | `37.140.192.169` (на сайте обычно `localhost`) |
| БД | `u1534553_docum_bd` |
| Пользователь | `u1534553_andrey` |
| Пароль | `iQ8fG6iK6skV7wP5` |
| phpMyAdmin | https://vip190.hosting.reg.ru/phpmyadmin/ |

`config.php` на хосте: ключи `$config['db']['dsn']`, `user`, `password`.

### Панель ISPmanager
https://vip190.hosting.reg.ru/manager — известные пароли не подходили, не брутфорсить.

---

## Бэкап системы (готово)

- Админ-меню: **«Сохранение системы»** → `?view=system_backup`
- Хранилище ZIP на диске/FTP:  
  **`/var/www/u1534553/data/www/backup/gost_info_docums/`**  
  (рядом с сайтами: каталог `backup/gost_info_docums/`)
- В ZIP: код + `storage/` + `database_full.sql` + `RESTORE.txt` + `MANIFEST.json` + `restore_config.php`
- Пароль архива **необязателен** (можно с паролем / без)
- Модуль в репо: `gost-system-backup-module/` (ветка `cursor/document-registry-0959`, PR #2)
- На сервере: `src/SystemBackup.php`, `public/system_backup.php`, `bin/weekly_backup.php`
- Cron (добавить в панели, если ещё нет):

```
0 3 * * 0 /usr/bin/php /var/www/u1534553/data/www/gost.info/gost-documents/bin/weekly_backup.php
```

Перед крупными правками — создать новый ZIP из «Сохранение системы».

---

## Что уже сделано на gost.info

1. Две организации раздельно: ООО «Норма софт» (id=1) и ООО «Нормасофт» (id=2); переключатель в шапке.
2. Журналы: исходящие / входящие / внутренние (`?journal=`).
3. Роль `admin` у **andrey**; меню «Пользователи», «Сохранение системы».
4. Коды поиска вида `НД-XXXX-XXXX` (`search_code`).
5. Документ id=205 «Реквизиты ООО «НормаСофт»» (№67, `НД-XUCW-QBAX`) переведён из `outgoing` → **`internal`**.
6. Полный бэкап + хранилище `backup/gost_info_docums`.
7. Версия UI на сайте была порядка **v1.2.0** (смотреть бейдж в шапке).

---

## Схема (ориентир)

Основные таблицы: `documents`, `document_files`, `organizations`, `recipients`, `users`, `settings`, `system_backups`.

`documents.type` — как минимум `outgoing|incoming|internal`; для договоров смотреть живую схему (`SHOW COLUMNS` / enum) — журнал `?journal=contract` уже открывается в UI, тип/таблица могли появиться после локальных доработок.

Поля документов (известные):  
`organization_id`, `type`, `document_number`, `document_date`, `recipient`, `recipient_id`, `subject`, `uploaded_by`, `created_at`, `registry_number`, `search_code`.

---

## Известные риски / баги

- Счётчики `next_*` в `organizations` раньше расходились с `MAX(document_number)` — при нумерации лучше опираться на max по org+type.
- PHP: в загрузке файла ключ `$_FILES[...]['name']`, не `original_name`.
- Cloud FTP нестабилен (PASV timeout) — локально FileZilla надёжнее.
- Старые ZIP могли оставаться в `gost-documents/storage/backups/`; актуальный путь — только `…/backup/gost_info_docums/`.

---

## Репозиторий GitHub

- https://github.com/softm-bot/MyFirstRepo  
- Ветка модуля бэкапа: `cursor/document-registry-0959`  
- PR: https://github.com/softm-bot/MyFirstRepo/pull/2  

Живой код сайта править через FTP/локальную копию `gost-documents`, не полагаться только на этот репозиторий (там не полный дамп сайта).

---

## Инструкция для Claude

1. Открыть workspace `C:\Users\andre\Desktop\кп\gost-documents`.
2. Войти на сайт как andrey, проверить журналы и `?journal=contract`.
3. При необходимости скачать свежий код с FTP (`u1534553_andrey` или cursor).
4. Перед крупными изменениями — бэкап в «Сохранение системы».
5. Вести доработки локально → заливать на хостинг → проверять в браузере.

Cloud Agent с этой задачи **снят**: дальше только локальный Claude/Cursor.

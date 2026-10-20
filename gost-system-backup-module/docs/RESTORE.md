# Восстановление gost-documents на голом хостинге

Каждый полный бэкап (ZIP в `/var/www/u1534553/data/www/backup/gost_info_docums/`) содержит:

- весь код приложения
- `storage/` — файлы документов
- `database_full.sql` — полный дамп MySQL
- `MANIFEST.json` — метаданные
- `restore_config.php` — помощник записи `config.php`
- `RESTORE.txt` — краткая инструкция

Архивы из хранилища бэкапов (`/var/www/u1534553/data/www/backup/gost_info_docums/`) в новый ZIP **не** включаются.

## Шаги

1. Распакуйте ZIP с паролем администратора (andrey).
2. Залейте папку `gost-documents/` на хостинг.
3. Создайте MySQL-базу и пользователя.
4. Импортируйте `database_full.sql`.
5. Откройте `restore_config.php` или пропишите `config.php` вручную.
6. Права на запись для `storage/` (755/775).
7. Удалите `restore_config.php` и `public/install*.php`.
8. Войдите как **andrey** → «Сохранение системы» → задайте пароль архива.
9. Cron (раз в неделю):

```
0 3 * * 0 /usr/bin/php /полный/путь/к/gost-documents/bin/weekly_backup.php
```

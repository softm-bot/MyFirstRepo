# Модуль «Сохранение системы» (полный бэкап)

Для администратора **andrey**: запароленный ZIP со всей системой
(код + `storage/` + полный SQL), хранение в `/var/www/u1534553/data/www/backup/gost_info_docums/`,
развёртывание на голом хостинге.

## На gost.info уже установлено

- Меню админа: **Сохранение системы** (`?view=system_backup`)
- Хранилище: `/var/www/u1534553/data/www/backup/gost_info_docums/`
- Первый полный архив создан (пароль архива задаёт andrey в UI)
- Cron (добавьте в панели хостинга):

```
0 3 * * 0 /usr/bin/php /var/www/u1534553/data/www/gost.info/gost-documents/bin/weekly_backup.php
```

## Состав ZIP

- код приложения
- `storage/` (файлы документов)
- `database_full.sql`
- `MANIFEST.json`
- `restore_config.php` — помощник записи config на новом хосте
- `RESTORE.txt`

Архивы из `/var/www/u1534553/data/www/backup/gost_info_docums/` в новый ZIP не включаются.

## Восстановление на голом хостинге

См. `docs/RESTORE.md` и `RESTORE.txt` внутри архива.

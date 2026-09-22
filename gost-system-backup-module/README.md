# Модуль «Сохранение системы» (полный бэкап)

## Уже сделано в БД
- `andrey` = role `admin`
- таблица `settings` для пароля архива

## Установка на gost.info (FileZilla)

1. Подключитесь FTP к сайту `gost.info` (u1534553_andrey).
2. Залейте файл:
   - в `gost-documents/public/` → `install_system_backup.php`
   (он сам создаст `src/SystemBackup.php`, `bin/weekly_backup.php`, `public/system_backup.php` и пропатчит `index.php`)
3. Откройте в браузере:
   `https://gost.info/gost-documents/install_system_backup.php?key=NormaBackupInstall2026`
4. Должно ответить `OK installed`. Инсталлятор самоудалится.
5. Войдите как **andrey** и откройте **«Сохранение системы»** (или `?view=system_backup`).
6. Задайте пароль архива (≥8 символов) → «Создать запароленный ZIP».
7. В панели хостинга добавьте cron:
   `0 3 * * 0 /usr/bin/php /var/www/u1534653/data/www/gost.info/gost-documents/bin/weekly_backup.php`

## Альтернатива: ручная заливка файлов
Из папки `deploy/` / модуля:
- `SystemBackup.php` → `gost-documents/src/`
- `weekly_backup.php` → `gost-documents/bin/`
- `system_backup.php` → `gost-documents/public/`
- затем `install_system_backup.php` для патча меню

## Состав ZIP-бэкапа
- весь код приложения
- `storage/` (файлы документов)
- `database_full.sql` (все таблицы)
- `RESTORE.txt`

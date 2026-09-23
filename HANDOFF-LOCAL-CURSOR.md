# HANDOFF: Система учёта документов gost.info

Передайте этот файл локальному Cursor (откройте папку `C:\Users\andre\Desktop\кп\gost-documents` как workspace).

Cloud Agent (https://cursor.com/agents/bc-719d4883-c635-43c3-9cff-4c87dc410959) **не видит диск C:** — FTP скачивание кода с хостинга с cloud-VM ломается (data channel timeout).

---

## Задача от заказчика

1. **Доработать** существующую PHP-систему на https://gost.info/gost-documents/
2. **Исправить ошибки**
3. **ООО «Норма софт» и ООО «Нормасофт» — две разные фирмы**
   - Документы не должны лежать в общей куче
   - Обязательно разделение по организациям (дальше пойдут внутренние документы)
4. Можно **импортировать исходящие**
5. **Нумерацию брать из документов** (файлов), не из сломанных счётчиков в БД
6. Папка исходящих на ПК: `C:\Users\andre\Desktop\кп\документооборот`
7. Сделать роль **администратора** (сейчас только andrey и anna без ролей)

Локальный код сайта: `C:\Users\andre\Desktop\кп\gost-documents\`

---

## URL и доступы

### Веб-приложение
- URL: https://gost.info/gost-documents/
- Логин: `andrey` / `NormaSoft2026!`
- Логин: `anna` / `NormaSoft2026!`
- Пользователи видят обе организации, прав одинаковые, админа нет

### FTP (код сайта)
- Хост: `37.140.192.169`
- Порт: `21`
- Пользователь (сайт gost.info в FileZilla): `u1534553_andrey` — **пароль спросить у владельца** (в cloud не передавался открыто)
- Пользователь агента: `u1534553_cursor` / `jF6iA8qL5rtQ0wQ8`
- Путь на сервере (из PHP warning):  
  `/var/www/u1534653/data/www/gost.info/gost-documents/public/index.php`
- FTP-путь: `www/gost.info/gost-documents/`  
  (public/: index.php ~51297 bytes, install.php, .htaccess)

### База данных MySQL
- Хост: `37.140.192.169`
- БД: `u1534553_docum_bd`
- Пользователь: `u1534553_andrey`
- Пароль: `iQ8fG6iK6skV7wP5`
- phpMyAdmin: https://vip190.hosting.reg.ru/phpmyadmin/

### Панель хостинга ISPmanager
- https://vip190.hosting.reg.ru/manager
- Логин/пароль панели **не подошли** из известных (не угадывать дальше — риск блокировки)

---

## Схема БД (актуальная)

Таблицы: `documents`, `document_files`, `organizations`, `recipients`, `users`

### organizations (2 фирмы)
| id | name | next_out | next_in | next_int | факт max исходящего |
|----|------|----------|---------|----------|---------------------|
| 1 | ООО «Норма софт» | 1 (СЛОМАН) | 1 | 1 | **22** → следующий должен быть **23** |
| 2 | ООО «Нормасофт» | 21 (СЛОМАН) | 1 | 1 | **41** → следующий должен быть **42** |

### documents
- 47 записей, **все type=outgoing**
- org 1: 12 шт., номера 4…22
- org 2: 35 шт., номера 1…41
- поля: organization_id, type(enum outgoing|incoming|internal), document_number, document_date, recipient, recipient_id, subject, uploaded_by, created_at

### document_files
- original_name, stored_name, mime_type, file_size → FK document_id

### recipients
- 50 шт., inn (unique), name

### users
- id=1 andrey (Западаев Андрей Иванович), andrey@ns52.ru
- id=2 anna, anna@ns52.ru, full_name=NULL
- **Нет поля role** — добавить admin/user

---

## Известные баги

1. **PHP Warning line ~683**: `Undefined array key "original_name"`  
   В `$_FILES` ключ — `name`, не `original_name`. Чинить при edit, когда файл не меняют.

2. **Счётчики next_number_* рассинхрон** с реальными номерами документов.  
   Пересчитать: `MAX(CAST(document_number AS UNSIGNED))+1` по org+type.  
   При автонумерации — брать max из documents, не доверять только счётчику (или чинить оба атомарно в транзакции).

3. **Документы двух фирм в одном списке** — UI допускает «все организации».  
   Нужно: выбранная фирма = контекст сессии; списки/создание/нумерация только внутри неё. Без «общей кучи».

4. Входящие и внутренние — типы в БД есть, данных нет. Готовить UI под раздельные журналы.

---

## Что сделать локальному агенту

### A. Код
1. Открыть `C:\Users\andre\Desktop\кп\gost-documents\`
2. Найти `public/index.php` (+ config, storage)
3. Исправить `original_name` → `name` (+ безопасная обработка пустого файла при edit)
4. Жёстко разделить UI по `organization_id` (сессия / обязательный выбор фирмы, не смесь)
5. Автономер: из max документа этой org+type (и поправить next_number_* в organizations)
6. Добавить роль admin (миграция users.role), страница пользователей
7. Подготовить разделы/фильтры: исходящие / входящие / внутренние **внутри фирмы**

### B. Импорт исходящих
1. Источник: `C:\Users\andre\Desktop\кп\документооборот`
2. Разобрать имена файлов (часто «КП … №NN.docx») → номер, org (Норма софт vs Нормасофт), тема
3. Не дублировать уже существующие в БД (сверить org+type+number)
4. Нумерацию/счётчики выставить по фактическим номерам файлов и БД

### C. Деплой
1. Залить через FileZilla на `www/gost.info/gost-documents/` (аккаунт u1534553_andrey)
2. Проверить веб: логин andrey, переключение фирм, edit без файла, новый документ с автономером

### D. Не делать
- Не коммитить пароли в публичный git
- Не удалять боевые документы без явного запроса
- Не подбирать пароль ISPmanager

---

## Структура приложения (ожидаемая)

```
gost-documents/
  public/
    index.php      # основной монолит (~51KB)
    install.php
    .htaccess
  storage/         # файлы документов (stored_name)
  config.php / config.example.php
  database.sql
```

На cloud в `/workspace/gost-documents/` лежат только JSON-дампы БД и analysis/*.md — **исходников PHP там нет**.

---

## Критерий готовности

- [ ] Нет PHP warning при редактировании
- [ ] Фирма A и фирма B — отдельные журналы, без общей кучи
- [ ] Автономер исходящих = max(номер)+1 по фирме
- [ ] Импорт из `документооборот` без дублей (или отчёт что уже в БД)
- [ ] Есть пользователь-админ / поле role
- [ ] Готовность к внутренним документам (тип internal не ломает UX)

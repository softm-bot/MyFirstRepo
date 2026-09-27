# Контекст проекта (указатель для агентов)

**Пароли и секреты в этот файл не входят.**

## ПНТД — формализация

| Что | Значение |
|-----|----------|
| Определение | **ПНТД** = Перечень нормативно-технической документации |
| Каталог в репо | [`pntd/`](pntd/) |
| Главный документ | [`pntd/docs/FORMALIZATION.md`](pntd/docs/FORMALIZATION.md) (v0.2) |
| Открытый указатель | https://www.pntd.ru/ |
| Закрытый прототип линейки | https://gost.info/pntd/ |
| Смежный реестр КП | https://gost.info/gost-documents/ |

Две плоскости ПНТД (указатель НТД + коммерческая линейка) **не** являются журналом исходящих КП. Метка `НД-XXXX-XXXX` в Word относится к gost-documents, не к `/nd/{id}.html`.

## Смежные ветки (gost-documents)

| Ветка | Содержание |
|-------|------------|
| `cursor/restore-document-registry-faca` | Операционный снимок БД / handoff учёта КП |
| `cursor/restamp-word-labels-3697` | Restamp меток `НД-*` + PDF |
| `cursor/document-registry-0959` | Модуль системного бэкапа |

Логины веб-приложения gost-documents (без паролей): `andrey` (admin), `anna` (user).

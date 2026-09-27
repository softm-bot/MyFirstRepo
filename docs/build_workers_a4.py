#!/usr/bin/env python3
"""Печатный А4-комплект задания для рабочих по перегородкам."""

from pathlib import Path

from PIL import Image, ImageOps
from reportlab.lib.colors import Color, black, white
from reportlab.lib.pagesizes import A4, landscape
from reportlab.lib.units import mm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfgen import canvas

OUT_NAME = "Перегородки_задание_для_рабочих_А4_27.09.2026.pdf"
CROPS = Path("/tmp/pdf_pages/final_crops")
FONT_REG = "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf"
FONT_BOLD = "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"

RED = Color(0.55, 0.05, 0.05)
DARK = Color(0.08, 0.08, 0.08)
GRAY = Color(0.22, 0.22, 0.22)
RULE = Color(0.15, 0.15, 0.15)
BOX = Color(0.05, 0.05, 0.05)
LIGHT = Color(0.93, 0.93, 0.93)
CREAM = Color(0.97, 0.97, 0.94)
WARN_BG = Color(1, 0.93, 0.90)


def register_fonts():
    pdfmetrics.registerFont(TTFont("DejaVu", FONT_REG))
    pdfmetrics.registerFont(TTFont("DejaVuBold", FONT_BOLD))


def trim_white(path, pad=18):
    im = Image.open(path).convert("RGB")
    arr = im.load()
    w, h = im.size
    def ink(x, y):
        r, g, b = arr[x, y]
        return r < 248 or g < 248 or b < 248
    l, t, r, btm = w, h, 0, 0
    for y in range(h):
        for x in range(w):
            if ink(x, y):
                l = min(l, x)
                r = max(r, x)
                t = min(t, y)
                btm = max(btm, y)
    l = max(0, l - pad)
    t = max(0, t - pad)
    r = min(w, r + pad + 1)
    btm = min(h, btm + pad + 1)
    return im.crop((l, t, r, btm))


def wrap(text, font, size, max_w):
    words = text.split()
    lines, cur = [], ""
    for w in words:
        trial = (cur + " " + w).strip()
        if pdfmetrics.stringWidth(trial, font, size) <= max_w:
            cur = trial
        else:
            if cur:
                lines.append(cur)
            cur = w
    if cur:
        lines.append(cur)
    return lines


def footer(c, w, h, page, total, extra=""):
    c.setStrokeColor(RULE)
    c.setLineWidth(0.8)
    c.line(12 * mm, 9 * mm, w - 12 * mm, 9 * mm)
    c.setFillColor(GRAY)
    c.setFont("DejaVu", 8)
    left = "ЖК «Дельвиг», Н. Новгород  ·  только А4  ·  27.09.2026"
    if extra:
        left += "  ·  " + extra
    c.drawString(12 * mm, 5 * mm, left)
    c.setFont("DejaVuBold", 8)
    c.drawRightString(w - 12 * mm, 5 * mm, f"стр. {page} / {total}")


def header_bar(c, w, h, title, subtitle):
    c.setFillColor(DARK)
    c.rect(0, h - 16 * mm, w, 16 * mm, fill=1, stroke=0)
    c.setFillColor(white)
    c.setFont("DejaVuBold", 13)
    c.drawString(12 * mm, h - 7.2 * mm, title)
    c.setFont("DejaVu", 8.5)
    c.drawString(12 * mm, h - 13.2 * mm, subtitle)


def draw_image_box(c, img_path, x, y, box_w, box_h, label):
    c.setFillColor(white)
    c.setStrokeColor(BOX)
    c.setLineWidth(1.4)
    c.rect(x, y, box_w, box_h, fill=1, stroke=1)

    im = Image.open(img_path).convert("RGB")
    im = ImageOps.expand(im, border=3, fill="white")
    tmp = Path("/tmp/pdf_pages/final_crops") / ("_fit_" + Path(img_path).name)
    # keep sharpness for print
    im.save(tmp, dpi=(200, 200))

    pad = 3 * mm
    inner_w, inner_h = box_w - 2 * pad, box_h - 2 * pad
    iw, ih = im.size
    scale = min(inner_w / iw, inner_h / ih)
    dw, dh = iw * scale, ih * scale
    ix = x + (box_w - dw) / 2
    iy = y + (box_h - dh) / 2
    c.drawImage(str(tmp), ix, iy, width=dw, height=dh, preserveAspectRatio=True, mask="auto")

    # label strip
    c.setFillColor(DARK)
    c.rect(x, y + box_h, box_w, 7.2 * mm, fill=1, stroke=0)
    c.setFillColor(white)
    c.setFont("DejaVuBold", 9.5)
    c.drawCentredString(x + box_w / 2, y + box_h + 2.1 * mm, label)


def bullets(c, x, y, width, items, size=9.5, leading=12.6):
    c.setFillColor(DARK)
    for item in items:
        lines = wrap(item, "DejaVu", size, width - 6 * mm)
        c.setFont("DejaVuBold", size)
        c.drawString(x, y, "•")
        c.setFont("DejaVu", size)
        for i, line in enumerate(lines):
            c.drawString(x + 4.2 * mm, y, line)
            y -= leading
        y -= 1.2
    return y


def page_title(c, total):
    w, h = A4
    c.setFillColor(DARK)
    c.rect(0, h - 42 * mm, w, 42 * mm, fill=1, stroke=0)
    c.setFillColor(white)
    c.setFont("DejaVuBold", 18)
    c.drawString(14 * mm, h - 16 * mm, "ЗАДАНИЕ ДЛЯ РАБОЧИХ")
    c.setFont("DejaVuBold", 14)
    c.drawString(14 * mm, h - 24 * mm, "Перегородки. Печатный комплект только А4")
    c.setFont("DejaVu", 10)
    c.drawString(14 * mm, h - 32 * mm, "ЖК «Дельвиг», г. Нижний Новгород")
    c.drawString(14 * mm, h - 38 * mm, "Дата комплекта: 27.09.2026   ·   Источник: Перегородки обновлённые + сантехника, 20.09.2026")

    y = h - 50 * mm
    c.setFillColor(DARK)
    c.setFont("DejaVuBold", 12)
    c.drawString(14 * mm, y, "ЧТО ВАЖНЕЕ ЧЕРТЕЖА МЕБЕЛИ")
    y -= 6 * mm
    c.setFont("DejaVu", 10)
    note = (
        "Работать по актуальной схеме (фрагменты слева на листах сопоставления) "
        "и по 4-й странице исходного PDF — «План возводимых конструкций». "
        "В штампе полного альбома этот чертёж помечен как лист 5. "
        "Штамп альбома «лист 4» — расстановка мебели: размеры с него для монтажа перегородок НЕ брать."
    )
    for line in wrap(note, "DejaVu", 10, w - 28 * mm):
        c.drawString(14 * mm, y, line)
        y -= 4.3 * mm

    y -= 3 * mm
    c.setFillColor(DARK)
    c.setFont("DejaVuBold", 12)
    c.drawString(14 * mm, y, "ЛЕГЕНДА (актуальная схема)")
    y -= 8 * mm

    # legend swatches
    c.setStrokeColor(Color(0.75, 0.18, 0.12))
    c.setDash(3, 2)
    c.setLineWidth(2.2)
    c.line(16 * mm, y + 1.5 * mm, 34 * mm, y + 1.5 * mm)
    c.setDash()
    c.setFillColor(DARK)
    c.setFont("DejaVuBold", 10)
    c.drawString(38 * mm, y, "Красный пунктир — направляющие линии.")
    y -= 5 * mm
    c.setFont("DejaVu", 10)
    for line in wrap(
        "Каркасные перегородки и облицовки выставлять СТРОГО по этим линиям. "
        "Границы конструкций должны совпасть с направляющими.",
        "DejaVu",
        10,
        w - 42 * mm,
    ):
        c.drawString(38 * mm, y, line)
        y -= 4.3 * mm

    y -= 2 * mm
    c.setFillColor(Color(0.65, 0.22, 0.14))
    c.rect(16 * mm, y - 0.5 * mm, 18 * mm, 3.2 * mm, fill=1, stroke=0)
    c.setFillColor(DARK)
    c.setFont("DejaVuBold", 10)
    c.drawString(38 * mm, y, "Сплошная красно-коричневая — перегородки из блоков 115 мм.")
    y -= 7 * mm
    c.setFillColor(Color(0.45, 0.55, 0.75))
    c.rect(16 * mm, y - 0.5 * mm, 18 * mm, 3.2 * mm, fill=1, stroke=0)
    c.setFillColor(DARK)
    c.setFont("DejaVuBold", 10)
    c.drawString(38 * mm, y, "Голубая / синяя — каркасные звукоизоляционные перегородки и облицовки.")
    y -= 7 * mm
    c.setFillColor(Color(0.15, 0.15, 0.15))
    c.rect(16 * mm, y - 0.5 * mm, 18 * mm, 3.2 * mm, fill=1, stroke=0)
    c.setFillColor(DARK)
    c.setFont("DejaVuBold", 10)
    c.drawString(38 * mm, y, "Тёмная заливка — существующие стены, колонны, вентканал.")

    y -= 10 * mm
    c.setFillColor(DARK)
    c.setFont("DejaVuBold", 12)
    c.drawString(14 * mm, y, "ПОРЯДОК РАБОТ")
    y -= 6.5 * mm
    order = [
        "1. Проверить демонтаж старых пазогребневых перегородок 70 мм. База — существующие ж/б стены и колонны.",
        "2. На полу и потолке отбить направляющие (красный пунктир актуальной схемы). Без этого каркас не ставить.",
        "3. Перегородку из блоков выставить на одной линии с колонной между спальней и гостиной.",
        "4. Тип 2 у колонны — по краю существующей ж/б колонны + облицовка Обл 1 со стороны гостиной.",
        "5. Тип 4.1 (блоки 115 мм) — по штрихпунктиру: бок воздуховодов кухни и торец колонны в прихожей.",
        "6. Правый угол / окно: блоки Тип 4.1 по краю существующего вентканала. Тип 4.2 — 70 мм, где указано.",
        "7. Каркас Тип 1 (облицовка) и Тип 3 (влажная зона) — только по направляющим. Шаг стоек не реже 400 мм.",
        "8. В среднем участке Тип 4.1 — пустотелый силикатный блок под водопровод (выводы заранее).",
        "9. Усиление фанерой от уровня чистого пола до отм. +1,800 — где указано на плане конструкций.",
        "10. Размеры со звёздочкой (*) уточнять по месту. Не закрывать коммуникации до проверки.",
    ]
    c.setFont("DejaVu", 9.4)
    for item in order:
        for line in wrap(item, "DejaVu", 9.4, w - 28 * mm):
            c.drawString(14 * mm, y, line)
            y -= 4.15 * mm
        y -= 0.8 * mm

    y -= 2 * mm
    donts = [
        "Сдвигать каркас и облицовки с красных направляющих — даже «на глаз» или «чтобы проще».",
        "Ставить блоки не в одну линию с колонной спальня–гостиная.",
        "Брать размеры с плана мебели (штамп альбома «лист 4») для монтажа перегородок.",
        "Монтировать Тип 2 у колонны без облицовки Обл 1 со стороны гостиной.",
        "Зашивать водопровод и вентканал до вывода коммуникаций и сверки со звёздочками (*).",
        "Менять тип конструкции без согласования с заказчиком.",
    ]
    # высота блока по числу строк
    lines_h = 8 * mm
    for d in donts:
        lines_h += 4.15 * mm * max(1, len(wrap(d, "DejaVu", 9.2, w - 38 * mm))) + 0.5 * mm
    box_bottom = max(16 * mm, y - 4 * mm - lines_h)
    c.setFillColor(WARN_BG)
    c.setStrokeColor(RED)
    c.setLineWidth(1.8)
    c.rect(12 * mm, box_bottom, w - 24 * mm, y - 2 * mm - box_bottom, fill=1, stroke=1)
    c.setFillColor(RED)
    c.setFont("DejaVuBold", 12)
    c.drawString(16 * mm, y - 8 * mm, "НЕЛЬЗЯ")
    c.setFillColor(DARK)
    y2 = y - 14 * mm
    for d in donts:
        c.setFont("DejaVuBold", 9.2)
        c.drawString(16 * mm, y2, "✗")
        c.setFont("DejaVu", 9.2)
        for line in wrap(d, "DejaVu", 9.2, w - 38 * mm):
            c.drawString(22 * mm, y2, line)
            y2 -= 4.15 * mm
        y2 -= 0.5 * mm

    footer(c, w, h, 1, total, "титул")


def page_compare(c, total, page, node_title, left_img, right_img, points):
    w, h = landscape(A4)
    header_bar(
        c,
        w,
        h,
        node_title,
        "Слева — актуальный фрагмент (крупно). Справа — то же место, лист 4 исходного PDF (план возводимых конструкций).",
    )
    margin = 10 * mm
    gap = 6 * mm
    top = h - 18 * mm
    label_h = 7.2 * mm
    bottom_block = 46 * mm
    box_h = top - bottom_block - label_h - 4 * mm
    box_w = (w - 2 * margin - gap) / 2
    y_box = bottom_block

    draw_image_box(c, left_img, margin, y_box, box_w, box_h, "Фрагмент (актуально)")
    draw_image_box(
        c,
        right_img,
        margin + box_w + gap,
        y_box,
        box_w,
        box_h,
        "То же место, лист 4",
    )

    c.setFillColor(LIGHT)
    c.rect(0, 11 * mm, w, bottom_block - 12 * mm, fill=1, stroke=0)
    c.setFillColor(DARK)
    c.setFont("DejaVuBold", 10)
    c.drawString(margin, bottom_block - 6.5 * mm, "ЧТО СДЕЛАТЬ НА ОБЪЕКТЕ")
    bullets(c, margin, bottom_block - 12.5 * mm, w - 2 * margin, points, size=9.3, leading=5.6 * mm)
    footer(c, w, h, page, total)


def page_sheet4(c, total):
    w, h = landscape(A4)
    header_bar(
        c,
        w,
        h,
        "ЛИСТ 4 ИСХОДНОГО PDF — ПЛАН ВОЗВОДИМЫХ КОНСТРУКЦИЙ",
        "4-я страница файла от 20.09.2026. В штампе альбома этот чертёж — лист 5. Смотреть вместе с актуальной схемой.",
    )
    img = str(CROPS / "sheet4_full.png")
    draw_image_box(c, img, 8 * mm, 22 * mm, w - 16 * mm, h - 42 * mm, "Лист 4 целиком (растр исходного PDF)")
    footer(c, w, h, 5, total, "лист 4 целиком")


def page_order(c, total):
    w, h = A4
    header_bar(
        c,
        w,
        h,
        "ТИПЫ КОНСТРУКЦИЙ И КОНТРОЛЬ",
        "Краткая выписка с плана возводимых конструкций и ведомости. Для печати на А4.",
    )
    y = h - 24 * mm
    c.setFillColor(DARK)
    c.setFont("DejaVuBold", 11)
    c.drawString(14 * mm, y, "ТИПЫ (не путать)")
    y -= 6.2 * mm
    types = [
        "Тип 1 — двухслойная звукоизоляционная облицовка ГВЛ на каркасе ПН 50×40 / ПС 50×50, отнесённом от стены.",
        "Тип 2 — каркасная перегородка с двухслойными обшивками ГВЛ на одинарном каркасе 50 мм.",
        "Тип 3 — влажная облицовка ГВЛВ в два слоя (санузел), каркас отнесён от стены.",
        "Тип 4.1 — блоки 115 мм (газосиликат / силикат). Это «красные» перегородки актуальной схемы.",
        "Тип 4.2 — блоки 70 мм — только где стоит марка на плане.",
        "Обл 1 — однослойная облицовка ГВЛ на клею (у колонны со стороны гостиной).",
        "Тип 6 — шумоизоляция оконных откосов (по решению заказчика / бригады).",
    ]
    y = bullets(c, 14 * mm, y, w - 28 * mm, types, size=9.2, leading=4.2 * mm)

    y -= 2 * mm
    c.setFont("DejaVuBold", 11)
    c.drawString(14 * mm, y, "УЗЕЛ У КОЛОННЫ СПАЛЬНЯ–ГОСТИНАЯ")
    y -= 5.8 * mm
    col = [
        "На актуальной схеме стрелка: выставить блоки в одну линию с колонной.",
        "На листе 4: Тип 2 по краю ж/б колонны; Обл 1 — со стороны гостиной.",
        "Размеры привязки со звёздочкой (*) снять по месту после облицовки колонны.",
        "Усиление листами фанеры от У.Ч.П. до +1,800 — по выноске на плане.",
    ]
    y = bullets(c, 14 * mm, y, w - 28 * mm, col, size=9.2, leading=4.2 * mm)

    y -= 2 * mm
    c.setFont("DejaVuBold", 11)
    c.drawString(14 * mm, y, "ПРАВЫЙ УГОЛ / ОКНО / ВЕНТКАНАЛ")
    y -= 5.8 * mm
    win = [
        "Окно и угол кладовой-постирочной: блоки Тип 4.1 вести по краю существующего вентканала.",
        "Тип 4.1 также по границе, совпадающей с боковой плоскостью воздуховодов кухни.",
        "Средний участок Тип 4.1 — пустотелый силикат под водопровод; трубы вывести заранее.",
        "Каркас у окна и облицовки — по красному пунктиру, не по раме окна «на глаз».",
    ]
    y = bullets(c, 14 * mm, y, w - 28 * mm, win, size=9.2, leading=4.2 * mm)

    y -= 2 * mm
    c.setFont("DejaVuBold", 11)
    c.drawString(14 * mm, y, "ПЕРЕД СДАЧЕЙ УЗЛА")
    y -= 5.8 * mm
    chk = [
        "Направляющие на полу и потолке совпадают, каркас не уплыл.",
        "Плоскость блоков = плоскость колонны спальня–гостиная (шнур / правило).",
        "У колонны есть Обл 1 со стороны гостиной; проём ПР1 по размеру.",
        "Вентканал не подрезан, блоки стоят по его краю.",
        "Выводы воды в пустотах блоков на месте; ничего не зашито «на потом».",
        "Типы на стенах совпадают с марками листа 4 (1 / 2 / 3 / 4.1 / 4.2 / Обл 1).",
    ]
    y = bullets(c, 14 * mm, y, w - 28 * mm, chk, size=9.2, leading=4.2 * mm)

    c.setFillColor(CREAM)
    c.setStrokeColor(BOX)
    c.setLineWidth(1)
    c.rect(12 * mm, 16 * mm, w - 24 * mm, 28 * mm, fill=1, stroke=1)
    c.setFillColor(DARK)
    c.setFont("DejaVuBold", 10)
    c.drawString(16 * mm, 38 * mm, "СМОТРЕТЬ ВМЕСТЕ")
    c.setFont("DejaVu", 9)
    txt = (
        "Этот комплект + лист 4 (план конструкций) + актуальные фрагменты. "
        "В альбоме дополнительно: схема соотношения перегородок (штамп лист 7), "
        "ведомость (лист 8) и спецификация каркаса (лист 9). Сантехнику (стр. 9 файла) "
        "не ставить до готовности штраб и выводов в блоках."
    )
    yy = 32.5 * mm
    for line in wrap(txt, "DejaVu", 9, w - 36 * mm):
        c.drawString(16 * mm, yy, line)
        yy -= 4 * mm

    footer(c, w, h, 6, total, "типы и контроль")


def prepare_crops():
    CROPS.mkdir(parents=True, exist_ok=True)
    assets = Path("/home/ubuntu/.cursor/projects/workspace/assets")
    sources = {
        "frag_overall": assets / "fd024d1e-3b77-4d34-a9ad-5dbb32fab020.png",
        "frag_column": assets / "ac579402-890d-4b23-aa19-11bc9b29cc99.png",
        "frag_window": assets / "48210ea7-7617-4809-bb27-88724aa358d1.png",
    }
    for name, src in sources.items():
        im = Image.open(src).convert("RGBA")
        bg = Image.new("RGB", im.size, (255, 255, 255))
        bg.paste(im, mask=im.split()[-1])
        tmp = CROPS / f"{name}_raw.png"
        bg.save(tmp)
        trim_white(tmp, pad=22).save(CROPS / f"{name}.png")

    hi4 = Image.open("/tmp/pdf_pages/hi-4.png")
    boxes = {
        "sheet4_overall": (180, 155, 2140, 1565),
        "sheet4_column": (240, 330, 1280, 1340),
        "sheet4_window": (1240, 760, 2068, 1460),
    }
    for name, box in boxes.items():
        hi4.crop(box).convert("RGB").save(CROPS / f"{name}.png")
    hi4.crop((140, 30, 3280, 2310)).convert("RGB").save(CROPS / "sheet4_full.png")


def main():
    register_fonts()
    prepare_crops()
    dests = [
        Path("/workspace/docs") / OUT_NAME,
        Path("/opt/cursor/artifacts") / OUT_NAME,
    ]
    # build once to first dest, copy to second
    out = dests[0]
    out.parent.mkdir(parents=True, exist_ok=True)
    Path("/opt/cursor/artifacts").mkdir(parents=True, exist_ok=True)

    total = 6
    c = canvas.Canvas(str(out), pageCompression=1)
    c.setTitle("Перегородки — задание для рабочих А4 27.09.2026")
    c.setAuthor("Комплект для бригады")

    c.setPageSize(A4)
    page_title(c, total)
    c.showPage()

    pairs = [
        (
            2,
            "УЗЕЛ 1. ОБЩИЙ ПЛАН ПЕРЕГОРОДОК",
            CROPS / "frag_overall.png",
            CROPS / "sheet4_overall.png",
            [
                "Сверить контур квартиры: спальня слева-сверху, гостиная–кухня справа, санузел и прихожая снизу-слева.",
                "Сначала отбить все красные направляющие. Каркас и облицовки — только по ним.",
                "Красные сплошные линии схемы — блоки 115 мм (Тип 4.1 на листе 4).",
                "Голубые линии схемы — каркас Тип 1 / Тип 2 / облицовки. На листе 4 они синие и зелёные.",
                "Не монтировать по плану мебели. Звёздочки (*) — замер по месту после базы.",
            ],
        ),
        (
            3,
            "УЗЕЛ 2. БЛОКИ У КОЛОННЫ (СПАЛЬНЯ — ГОСТИНАЯ)",
            CROPS / "frag_column.png",
            CROPS / "sheet4_column.png",
            [
                "Указанную перегородку из блоков выставить на одной линии с колонной между спальней и гостиной.",
                "На листе 4: Тип 2 по краю существующей ж/б колонны; Обл 1 — однослойная облицовка со стороны гостиной.",
                "Проём ПР1 и размер 900 уточнить после облицовки колонны. Привязки со * — по месту.",
                "Усиление фанерой от У.Ч.П. до +1,800 — по выноске. Плоскость блоков = плоскость грани колонны.",
                "Не сдвигать ось «колонна — блоки — направляющая» ради удобства каркаса.",
            ],
        ),
        (
            4,
            "УЗЕЛ 3. ОКНО И ПРАВЫЙ УГОЛ (ВЕНТКАНАЛ)",
            CROPS / "frag_window.png",
            CROPS / "sheet4_window.png",
            [
                "Это окно и угол у кладовой-постирочной / кухни. На актуальной схеме — красный угол и пунктир направляющих.",
                "Блоки Тип 4.1 выполнить по краю существующего вентканала. Канал не рубить и не обходить «внутрь».",
                "Тип 4.2 (70 мм) — только на коротком участке, где стоит марка. Остальное — 115 мм.",
                "Направляющие (пунктир) держат каркас и облицовку у окна. Граница каркаса = направляющая.",
                "Размеры 1040 / 2030* / 350 / 700 — ориентир; звёздочку перемерить. Воздуховоды кухни — вторая база оси.",
            ],
        ),
    ]
    for page, title, left, right, pts in pairs:
        c.setPageSize(landscape(A4))
        page_compare(c, total, page, title, str(left), str(right), pts)
        c.showPage()

    c.setPageSize(landscape(A4))
    page_sheet4(c, total)
    c.showPage()

    c.setPageSize(A4)
    page_order(c, total)
    c.save()

    dests[1].write_bytes(out.read_bytes())
    print("WROTE", out, "bytes", out.stat().st_size)
    print("WROTE", dests[1], "bytes", dests[1].stat().st_size)


if __name__ == "__main__":
    main()

#!/usr/bin/env python3
"""Restamp DOCX: put search code НД-XXXX-XXXX right-aligned on the last content line."""

from __future__ import annotations

import argparse
import re
import shutil
import struct
import zipfile
import zlib
from io import BytesIO
from pathlib import Path

from docx import Document
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Pt, Twips

CODE_RE = re.compile(
    r"(?:Код\s+документа\s*:\s*)?НД-[A-Z0-9]{4}-[A-Z0-9]{4}",
    re.IGNORECASE,
)
ORPHAN_RE = re.compile(
    r"^\s*(?:Код\s+документа\s*:\s*)?НД-[A-Z0-9]{4}-[A-Z0-9]{4}\s*$",
    re.IGNORECASE,
)


def rebuild_truncated_docx(data: bytes) -> bytes:
    """Rebuild a DOCX missing EOCD from local zip headers (common IncompleteRead artifact)."""
    pos = 0
    files: list[dict] = []
    while True:
        i = data.find(b"PK\x03\x04", pos)
        if i < 0 or i + 30 > len(data):
            break
        method = struct.unpack_from("<H", data, i + 8)[0]
        comp = struct.unpack_from("<I", data, i + 18)[0]
        fnlen, exlen = struct.unpack_from("<HH", data, i + 26)
        name = data[i + 30 : i + 30 + fnlen]
        start = i + 30 + fnlen + exlen
        next_pk = data.find(b"PK\x03\x04", start)
        next_cd = data.find(b"PK\x01\x02", start)
        ends = [len(data)]
        if next_pk >= 0:
            ends.append(next_pk)
        if next_cd >= 0:
            ends.append(next_cd)
        avail = min(ends) - start
        take = min(comp if comp else avail, avail)
        payload = data[start : start + take]
        files.append({"name": name, "method": method, "payload": payload})
        pos = start + len(payload)
        if avail < (comp or 0):
            break

    buf = BytesIO()
    with zipfile.ZipFile(buf, "w") as zf:
        for f in files:
            fname = f["name"].decode("utf-8", "replace")
            if f["method"] == 8:
                try:
                    raw = zlib.decompress(f["payload"], -15)
                except Exception:
                    raw = zlib.decompress(f["payload"])
                zf.writestr(fname, raw, compress_type=zipfile.ZIP_DEFLATED)
            elif f["method"] == 0:
                zf.writestr(fname, f["payload"], compress_type=zipfile.ZIP_STORED)
    return buf.getvalue()


def ensure_openable(path: Path) -> Path:
    data = path.read_bytes()
    try:
        with zipfile.ZipFile(BytesIO(data)) as zf:
            zf.getinfo("word/document.xml")
        return path
    except Exception:
        fixed = rebuild_truncated_docx(data)
        out = path.with_name(path.stem + "_rebuilt.docx")
        out.write_bytes(fixed)
        with zipfile.ZipFile(BytesIO(fixed)) as zf:
            zf.getinfo("word/document.xml")
        return out


def _clear_runs(paragraph) -> None:
    for child in list(paragraph._p):
        if child.tag == qn("w:r"):
            paragraph._p.remove(child)


def _set_right_tab(paragraph, pos_twips: int) -> None:
    p_pr = paragraph._p.get_or_add_pPr()
    tabs = p_pr.find(qn("w:tabs"))
    if tabs is None:
        tabs = OxmlElement("w:tabs")
        p_pr.append(tabs)
    else:
        for old in list(tabs):
            tabs.remove(old)
    tab = OxmlElement("w:tab")
    tab.set(qn("w:val"), "right")
    tab.set(qn("w:pos"), str(int(pos_twips)))
    tabs.append(tab)


def _page_right_tab_twips(document: Document) -> int:
    """OOXML w:tab/@w:pos is in twips, relative to the left text margin."""
    section = document.sections[0]
    # python-docx Length is EMU; OOXML tab pos is twips (1 twip = 635 EMU)
    usable_emu = int(section.page_width) - int(section.left_margin) - int(section.right_margin)
    pos = usable_emu // 635 - 80
    return max(pos, 4000)


def _strip_code_from_text(text: str) -> str:
    cleaned = CODE_RE.sub("", text)
    cleaned = re.sub(r"[ \t]{2,}", " ", cleaned)
    return cleaned.strip(" \t")


def strip_existing_stamps(document: Document) -> None:
    # Remove orphan stamp paragraphs (from end backwards)
    for p in list(document.paragraphs):
        if ORPHAN_RE.match(p.text or ""):
            _clear_runs(p)
            p._element.getparent().remove(p._element)

    for p in document.paragraphs:
        if not p.text:
            continue
        if CODE_RE.search(p.text):
            new_text = _strip_code_from_text(p.text)
            # rebuild runs simply
            _clear_runs(p)
            if new_text:
                run = p.add_run(new_text)
                run.font.size = Pt(11)

    # tables
    for table in document.tables:
        for row in table.rows:
            for cell in row.cells:
                for p in cell.paragraphs:
                    if ORPHAN_RE.match(p.text or ""):
                        _clear_runs(p)
                    elif CODE_RE.search(p.text or ""):
                        new_text = _strip_code_from_text(p.text)
                        _clear_runs(p)
                        if new_text:
                            p.add_run(new_text)


def _last_content_paragraph(document: Document):
    last = None
    for p in document.paragraphs:
        if (p.text or "").strip():
            last = p
    return last


def _remove_trailing_empty_paragraphs(document: Document) -> None:
    body = document.element.body
    # Walk paragraphs from end; stop at tables/sectPr
    from docx.oxml.ns import qn as _qn

    children = list(body)
    for child in reversed(children):
        if child.tag == _qn("w:sectPr"):
            continue
        if child.tag != _qn("w:p"):
            break
        texts = [t.text or "" for t in child.findall(".//" + _qn("w:t"))]
        if any(t.strip() for t in texts):
            break
        body.remove(child)


def _tighten_paragraph(paragraph) -> None:
    p_pr = paragraph._p.get_or_add_pPr()
    spacing = p_pr.find(qn("w:spacing"))
    if spacing is None:
        spacing = OxmlElement("w:spacing")
        p_pr.append(spacing)
    spacing.set(qn("w:before"), "0")
    spacing.set(qn("w:after"), "0")
    spacing.set(qn("w:line"), "240")
    spacing.set(qn("w:lineRule"), "auto")


def apply_right_stamp(document: Document, code: str) -> None:
    stamp = code.strip()
    if not stamp.startswith("НД-"):
        stamp = f"НД-{stamp}"

    strip_existing_stamps(document)
    _remove_trailing_empty_paragraphs(document)
    last = _last_content_paragraph(document)
    if last is None:
        last = document.add_paragraph()

    # Keep body text; append right-tab + code on the same paragraph
    body = _strip_code_from_text(last.text or "")
    _clear_runs(last)

    # Prefer keeping justification for KP body; tab handles right edge for code
    if last.alignment is None:
        last.alignment = WD_ALIGN_PARAGRAPH.JUSTIFY

    _set_right_tab(last, _page_right_tab_twips(document))
    _tighten_paragraph(last)

    if body:
        body = body.rstrip()
        run_body = last.add_run(body)
        if run_body.font.size is None:
            run_body.font.size = Pt(11)
        last.add_run("\t")
    else:
        last.add_run("\t")

    run_code = last.add_run(stamp)
    run_code.font.size = Pt(8)
    run_code.font.name = "Times New Roman"
    r_pr = run_code._element.get_or_add_rPr()
    r_fonts = r_pr.find(qn("w:rFonts"))
    if r_fonts is None:
        r_fonts = OxmlElement("w:rFonts")
        r_pr.append(r_fonts)
    for attr in ("w:ascii", "w:hAnsi", "w:cs"):
        r_fonts.set(qn(attr), "Times New Roman")

    _remove_trailing_empty_paragraphs(document)


def restamp_file(src: Path, code: str, dst: Path | None = None) -> Path:
    src = ensure_openable(src)
    document = Document(str(src))
    apply_right_stamp(document, code)
    out = dst or src
    out.parent.mkdir(parents=True, exist_ok=True)
    document.save(str(out))
    # sanity
    with zipfile.ZipFile(out) as zf:
        zf.getinfo("word/document.xml")
    return out


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("src")
    ap.add_argument("--code", required=True)
    ap.add_argument("-o", "--output", default=None)
    args = ap.parse_args()
    out = restamp_file(Path(args.src), args.code, Path(args.output) if args.output else None)
    doc = Document(str(out))
    last = _last_content_paragraph(doc)
    print(f"OK {out} size={out.stat().st_size} last={last.text!r}")


if __name__ == "__main__":
    main()

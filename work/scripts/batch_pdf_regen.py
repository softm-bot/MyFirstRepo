#!/usr/bin/env python3
"""Download restamped DOCX, convert to 1-page PDF, upload via putpdf.php, update counts."""

from __future__ import annotations

import base64
import json
import os
import pickle
import re
import subprocess
import sys
import time
import zipfile
from concurrent.futures import ThreadPoolExecutor, as_completed
from io import BytesIO
from pathlib import Path

import pymysql
import requests
from pypdf import PdfReader, PdfWriter, Transformation

ROOT = Path(__file__).resolve().parents[1]
BASE = os.environ.get("GOST_BASE", "https://gost.info/gost-documents/")
PUTPDF = BASE.rstrip("/") + "/public/putpdf.php"
COOKIE_PKL = Path("/tmp/gost_session.pkl")
DL_DIR = ROOT / "docs_raw"
PDF_DIR = ROOT / "pdfs_out"
META_JSON = ROOT / "docs_meta_live.json"
RESULTS = ROOT / "pdf_regen_results.json"

USER = os.environ["GOST_USER"]
PASSWORD = os.environ["GOST_PASSWORD"]
DB = dict(
    host=os.environ.get("GOST_DB_HOST", "127.0.0.1"),
    user=os.environ["GOST_DB_USER"],
    password=os.environ["GOST_DB_PASS"],
    database=os.environ["GOST_DB_NAME"],
    charset="utf8mb4",
)


def db_meta() -> list[dict]:
    conn = pymysql.connect(**DB, connect_timeout=30)
    cur = conn.cursor(pymysql.cursors.DictCursor)
    cur.execute(
        """
        SELECT d.id, d.organization_id AS org, d.type, d.document_number AS num,
               d.search_code AS code, f.id AS fid, f.stored_name AS stored_name,
               f.original_name AS orig, f.file_size, f.pdf_stored_name AS pdf,
               f.pdf_file_size AS pdf_size
        FROM documents d
        JOIN document_files f ON f.document_id = d.id
        WHERE d.search_code IS NOT NULL AND d.search_code <> ''
          AND (LOWER(f.original_name) LIKE '%.docx' OR LOWER(f.stored_name) LIKE '%.docx')
        ORDER BY d.id
        """
    )
    rows = cur.fetchall()
    conn.close()
    return rows


def login(session: requests.Session) -> None:
    if COOKIE_PKL.is_file():
        try:
            session.cookies.update(pickle.load(open(COOKIE_PKL, "rb")))
            r = session.get(BASE, timeout=45)
            if "Исходящие" in r.text or "Внутренние" in r.text:
                return
        except Exception:
            pass
    for attempt in range(8):
        r = session.get(BASE, timeout=45)
        m = re.search(r'name="csrf" value="([^"]+)"', r.text)
        if not m:
            time.sleep(5 + attempt)
            continue
        r = session.post(
            BASE,
            data={"csrf": m.group(1), "username": USER, "password": PASSWORD, "login": "1"},
            timeout=45,
            allow_redirects=True,
        )
        if "Исходящие" in r.text or "Внутренние" in r.text:
            pickle.dump(session.cookies, open(COOKIE_PKL, "wb"))
            return
        time.sleep(3)
    raise RuntimeError("login failed")


def download_docx(session: requests.Session, fid: int, org: int) -> bytes:
    session.get(f"{BASE}?switch_org={org}", timeout=45)
    with session.get(f"{BASE}?download={fid}", stream=True, timeout=180) as resp:
        chunks: list[bytes] = []
        try:
            for chunk in resp.raw.stream(65536, decode_content=False):
                chunks.append(chunk)
        except Exception:
            pass
        data = b"".join(chunks)
    if data[:2] != b"PK":
        raise RuntimeError(f"not zip fid={fid} size={len(data)}")
    with zipfile.ZipFile(BytesIO(data)) as zf:
        zf.getinfo("word/document.xml")
    return data


def stamp_ok(data: bytes, code: str) -> bool:
    with zipfile.ZipFile(BytesIO(data)) as zf:
        xml = zf.read("word/document.xml").decode("utf-8", "replace")
    if "Код документа" in xml:
        return False
    return code in xml and 'w:pos="9781"' in xml and "<w:tab/>" in xml


def convert_docx_to_pdf(docx: Path, out_dir: Path) -> Path:
    out_dir.mkdir(parents=True, exist_ok=True)
    # LibreOffice writes <stem>.pdf into out_dir
    cmd = [
        "soffice",
        "--headless",
        "--nologo",
        "--nolockcheck",
        "--nodefault",
        "--norestore",
        "--convert-to",
        "pdf:writer_pdf_Export",
        "--outdir",
        str(out_dir),
        str(docx),
    ]
    subprocess.run(cmd, check=True, capture_output=True, timeout=180)
    pdf = out_dir / (docx.stem + ".pdf")
    if not pdf.is_file():
        raise RuntimeError(f"LO did not produce {pdf}")
    return pdf


def fit_to_one_page(src: Path, dst: Path) -> tuple[int, int]:
    """If multi-page, scale+translate page 1 content so everything fits one A4 page."""
    reader = PdfReader(str(src))
    pages = len(reader.pages)
    if pages <= 1:
        dst.write_bytes(src.read_bytes())
        return 1, 1

    # Stack: measure combined height of all pages, scale to fit page 1 height
    page0 = reader.pages[0]
    w = float(page0.mediabox.width)
    h = float(page0.mediabox.height)
    total_h = sum(float(p.mediabox.height) for p in reader.pages)
    # Leave small bottom margin for stamp visibility
    scale = min(1.0, (h * 0.985) / total_h)
    writer = PdfWriter()
    blank = writer.add_blank_page(width=w, height=h)
    y = h
    for p in reader.pages:
        ph = float(p.mediabox.height)
        y -= ph * scale
        blank.merge_transformed_page(
            p,
            Transformation().scale(scale, scale).translate(0, y),
        )
    with open(dst, "wb") as f:
        writer.write(f)
    return pages, 1


def putpdf_upload(session: requests.Session, doc_id: int, pdf: Path, chunk: int = 48000) -> str:
    data = pdf.read_bytes()
    if not data.startswith(b"%PDF"):
        raise RuntimeError("not a pdf")
    r = session.get(f"{PUTPDF}?k=R26&id={doc_id}&a=r", timeout=60)
    if r.status_code != 200:
        raise RuntimeError(f"reset {r.status_code} {r.text[:80]}")
    off = 0
    while off < len(data):
        part = data[off : off + chunk]
        rr = session.post(
            f"{PUTPDF}?k=R26&id={doc_id}&a=p",
            data={"d": base64.b64encode(part).decode("ascii")},
            timeout=120,
        )
        if rr.status_code != 200 or not rr.text.startswith("p"):
            raise RuntimeError(f"chunk fail @ {off}: {rr.status_code} {rr.text[:120]}")
        off += len(part)
        time.sleep(0.05)
    rr = session.get(f"{PUTPDF}?k=R26&id={doc_id}&a=c", timeout=60)
    if rr.status_code != 200 or not rr.text.startswith("ok"):
        raise RuntimeError(f"commit fail: {rr.status_code} {rr.text[:200]}")
    return rr.text.strip()


def process_one(session: requests.Session, d: dict, force: bool = False) -> dict:
    doc_id = int(d["id"])
    out: dict = {"id": doc_id, "num": d["num"], "code": d["code"]}
    docx_path = DL_DIR / f"{doc_id}.docx"
    pdf_raw = PDF_DIR / "lo" / f"{doc_id}.pdf"
    pdf_one = PDF_DIR / "one" / f"{doc_id}.pdf"
    PDF_DIR.joinpath("lo").mkdir(parents=True, exist_ok=True)
    PDF_DIR.joinpath("one").mkdir(parents=True, exist_ok=True)

    try:
        if not docx_path.is_file() or force:
            data = download_docx(session, int(d["fid"]), int(d["org"]))
            docx_path.write_bytes(data)
        else:
            data = docx_path.read_bytes()
        ok = stamp_ok(data, d["code"])
        out["stamp_ok"] = ok
        if not ok:
            out["ok"] = False
            out["error"] = "stamp missing/broken"
            return out

        if not pdf_raw.is_file() or force:
            convert_docx_to_pdf(docx_path, PDF_DIR / "lo")
        pages_before, pages_after = fit_to_one_page(pdf_raw, pdf_one)
        out["pages_before"] = pages_before
        out["pages_after"] = pages_after
        out["pdf_size"] = pdf_one.stat().st_size

        # Skip re-upload if live already has matching size and we only verify — always upload missing
        msg = putpdf_upload(session, doc_id, pdf_one)
        out["upload"] = msg
        out["ok"] = True
    except Exception as exc:
        out["ok"] = False
        out["error"] = str(exc)
    return out


def main() -> None:
    only = {int(x) for x in sys.argv[1:]} if len(sys.argv) > 1 else None
    force = os.environ.get("FORCE", "") == "1"
    rows = db_meta()
    META_JSON.write_text(json.dumps(rows, ensure_ascii=False, indent=2, default=str), encoding="utf-8")
    if only:
        rows = [r for r in rows if int(r["id"]) in only]
    # Prefer missing PDFs first, then 127
    rows.sort(key=lambda r: (1 if r.get("pdf") else 0, int(r["id"]) != 127, int(r["id"])))

    DL_DIR.mkdir(parents=True, exist_ok=True)
    PDF_DIR.mkdir(parents=True, exist_ok=True)

    session = requests.Session()
    session.headers["User-Agent"] = "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36"
    login(session)

    results = []
    # Sequential LO convert is safer; downloads can be parallel beforehand
    print(f"docs={len(rows)} force={force}")

    # Phase 1: download missing in parallel
    need_dl = [r for r in rows if force or not (DL_DIR / f"{r['id']}.docx").is_file()]

    def _dl(r):
        s = requests.Session()
        s.headers.update(session.headers)
        s.cookies.update(session.cookies)
        data = download_docx(s, int(r["fid"]), int(r["org"]))
        (DL_DIR / f"{r['id']}.docx").write_bytes(data)
        return int(r["id"]), len(data), stamp_ok(data, r["code"])

    if need_dl:
        print(f"downloading {len(need_dl)}...")
        with ThreadPoolExecutor(max_workers=4) as ex:
            futs = [ex.submit(_dl, r) for r in need_dl]
            for fut in as_completed(futs):
                try:
                    did, sz, ok = fut.result()
                    print(f"  dl {did} size={sz} stamp={ok}")
                except Exception as exc:
                    print(f"  dl FAIL {exc}")

    # Phase 2: convert + upload sequentially (LibreOffice)
    for i, d in enumerate(rows):
        print(f"[{i+1}/{len(rows)}] id={d['id']} №{d['num']} {d['code']}")
        # refresh session cookies periodically
        if i % 20 == 0 and i > 0:
            try:
                login(session)
            except Exception:
                pass
        res = process_one(session, d, force=force)
        results.append(res)
        print(" ", "OK" if res.get("ok") else "FAIL", res.get("upload") or res.get("error"),
              f"pages {res.get('pages_before')}->{res.get('pages_after')}")
        time.sleep(0.2)

    summary = {
        "total": len(results),
        "ok": sum(1 for r in results if r.get("ok")),
        "fail": sum(1 for r in results if not r.get("ok")),
        "stamp_bad": sum(1 for r in results if r.get("stamp_ok") is False),
        "multi_before": sum(1 for r in results if (r.get("pages_before") or 0) > 1),
        "results": results,
    }
    RESULTS.write_text(json.dumps(summary, ensure_ascii=False, indent=2), encoding="utf-8")
    print("DONE", summary["ok"], "/", summary["total"], "fail", summary["fail"])


if __name__ == "__main__":
    main()

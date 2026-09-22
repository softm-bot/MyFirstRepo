#!/usr/bin/env python3
"""Download all DOCX with search_code, restamp, re-upload via edit form, fix file_size."""

from __future__ import annotations

import json
import os
import pickle
import re
import sys
import time
import zipfile
from io import BytesIO
from pathlib import Path

import pymysql
import requests

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(Path(__file__).resolve().parent))
from restamp_docx import rebuild_truncated_docx, restamp_file  # noqa: E402

BASE = os.environ.get("GOST_BASE", "https://gost.info/gost-documents/")
META = ROOT / "docs_meta.json"
DL_DIR = ROOT / "docs_raw"
OUT_DIR = ROOT / "restamped"
COOKIE_PKL = Path("/tmp/gost_cookies.pkl")
USER = os.environ["GOST_USER"]
PASSWORD = os.environ["GOST_PASSWORD"]
DB_HOST = os.environ.get("GOST_DB_HOST", "127.0.0.1")
DB_USER = os.environ["GOST_DB_USER"]
DB_PASS = os.environ["GOST_DB_PASS"]
DB_NAME = os.environ["GOST_DB_NAME"]


def login(session: requests.Session) -> None:
    for attempt in range(8):
        r = session.get(BASE, timeout=45)
        if not r.text or "csrf" not in r.text:
            if "Исходящие" in r.text or "Выход" in r.text:
                return
            print(f"login page empty/blocked (try {attempt+1}, len={len(r.text)})")
            time.sleep(10 + attempt * 5)
            continue
        csrf = re.search(r'name="csrf" value="([^"]+)"', r.text).group(1)
        r = session.post(
            BASE,
            data={"csrf": csrf, "username": USER, "password": PASSWORD, "login": "1"},
            timeout=45,
            allow_redirects=True,
        )
        if "Выход" in r.text or "Исходящие" in r.text or "Внутренние" in r.text:
            pickle.dump(session.cookies, open(COOKIE_PKL, "wb"))
            return
        print("login failed", r.status_code, len(r.text))
        time.sleep(5)
    raise RuntimeError("cannot login")


def download_bytes(session: requests.Session, fid: int, org: int) -> bytes:
    session.get(f"{BASE}?switch_org={org}", timeout=45)
    with session.get(f"{BASE}?download={fid}", stream=True, timeout=180) as resp:
        chunks: list[bytes] = []
        try:
            for chunk in resp.raw.stream(65536, decode_content=False):
                chunks.append(chunk)
        except Exception as exc:  # IncompleteRead
            data = b"".join(chunks)
            if data[:2] == b"PK":
                return data
            raise RuntimeError(f"download {fid} failed: {exc}") from exc
        data = b"".join(chunks)
        if not data:
            raise RuntimeError(f"download {fid} empty status={resp.status_code}")
        return data


def openable_docx(data: bytes) -> bytes:
    try:
        with zipfile.ZipFile(BytesIO(data)) as zf:
            zf.getinfo("word/document.xml")
        return data
    except Exception:
        fixed = rebuild_truncated_docx(data)
        with zipfile.ZipFile(BytesIO(fixed)) as zf:
            zf.getinfo("word/document.xml")
        return fixed


def get_edit_csrf(session: requests.Session, doc_id: int, org: int) -> tuple[str, str]:
    session.get(f"{BASE}?switch_org={org}", timeout=45)
    r = session.get(f"{BASE}?edit={doc_id}", timeout=45)
    if not r.text:
        raise RuntimeError("edit page empty")
    m = re.search(r'name="csrf" value="([^"]+)"', r.text)
    if not m:
        raise RuntimeError("no csrf on edit page")
    # detect if replace file input exists
    has_file = 'name="document"' in r.text
    return m.group(1), r.text


def upload_replace(session: requests.Session, doc_id: int, org: int, path: Path, original_name: str) -> str:
    csrf, html = get_edit_csrf(session, doc_id, org)
    # Preserve fields from edit form where possible
    def field(name: str, default: str = "") -> str:
        m = re.search(rf'name="{name}"[^>]*value="([^"]*)"', html)
        if m:
            return m.group(1)
        m = re.search(rf'name="{name}"[^>]*>([^<]*)</textarea>', html)
        return m.group(1).strip() if m else default

    data = {
        "csrf": csrf,
        "document_id": str(doc_id),
        "save": "1",
    }
    # common edit fields
    for name in ("document_number", "document_date", "recipient", "recipient_id", "subject", "return_qs"):
        if f'name="{name}"' in html:
            data[name] = field(name)

    files = {
        "document": (
            original_name or path.name,
            path.read_bytes(),
            "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
        )
    }
    r = session.post(BASE, data=data, files=files, timeout=120, allow_redirects=True)
    return f"status={r.status_code} len={len(r.text)} ok={'ошибк' not in r.text.lower()}"


def update_file_size(fid: int, size: int) -> None:
    conn = pymysql.connect(
        host=DB_HOST,
        user=DB_USER,
        password=DB_PASS,
        database=DB_NAME,
        charset="utf8mb4",
        connect_timeout=20,
    )
    cur = conn.cursor()
    cur.execute("UPDATE document_files SET file_size=%s WHERE id=%s", (size, fid))
    conn.commit()
    conn.close()


def main() -> None:
    meta = json.loads(META.read_text(encoding="utf-8"))
    docs = [d for d in meta if d["stored"].endswith(".docx") and d.get("code")]
    only = set(int(x) for x in sys.argv[1:]) if len(sys.argv) > 1 else None
    if only:
        docs = [d for d in docs if d["id"] in only]

    DL_DIR.mkdir(parents=True, exist_ok=True)
    OUT_DIR.mkdir(parents=True, exist_ok=True)

    s = requests.Session()
    s.headers["User-Agent"] = "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36"
    login(s)

    results = []
    for i, d in enumerate(docs):
        print(f"[{i+1}/{len(docs)}] id={d['id']} №{d['num']} {d['code']}")
        raw_path = DL_DIR / f"{d['id']}.docx"
        out_path = OUT_DIR / f"{d['id']}.docx"
        try:
            data = download_bytes(s, d["fid"], d["org"])
            data = openable_docx(data)
            raw_path.write_bytes(data)
            restamp_file(raw_path, d["code"], out_path)
            size = out_path.stat().st_size
            msg = upload_replace(s, d["id"], d["org"], out_path, d.get("orig") or out_path.name)
            update_file_size(d["fid"], size)
            print("  ", msg, "size", size)
            results.append({"id": d["id"], "ok": True, "size": size, "msg": msg})
        except Exception as exc:
            print("  FAIL", exc)
            results.append({"id": d["id"], "ok": False, "error": str(exc)})
        time.sleep(0.4)

    summary = {
        "total": len(results),
        "ok": sum(1 for r in results if r.get("ok")),
        "fail": sum(1 for r in results if not r.get("ok")),
        "results": results,
    }
    (ROOT / "restamp_results.json").write_text(json.dumps(summary, ensure_ascii=False, indent=2), encoding="utf-8")
    print("DONE", summary["ok"], "/", summary["total"])


if __name__ == "__main__":
    main()

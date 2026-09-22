# Restamp Word search codes (НД-XXXX-XXXX)

## restamp_docx.py
Puts `НД-XXXX-XXXX` on the last content paragraph with a **right tab** (right edge of the last line).
Also rebuilds truncated ZIP (missing EOCD) when needed.

```bash
python3 restamp_docx.py input.docx --code 'НД-CZF5-QH2M' -o out.docx
```

## batch_restamp_upload.py
Downloads DOCX via web session, restamps, re-uploads via edit form, updates `file_size`.
Requires env: `GOST_USER`, `GOST_PASSWORD`, `GOST_DB_USER`, `GOST_DB_PASS`, `GOST_DB_NAME`, optional `GOST_DB_HOST`, `GOST_BASE`.

Prefer server installer: `artifacts/install_restamp_codes.php` (FileZilla).

## PDF regen (`batch_pdf_regen.py`)
Requires env: `GOST_USER`, `GOST_PASSWORD`, `GOST_DB_USER`, `GOST_DB_PASS`, `GOST_DB_NAME`.
Optional: `GOST_DB_HOST`, `GOST_BASE`, `FORCE=1`.
Downloads DOCX, converts with LibreOffice, fits to 1 page, uploads via `putpdf.php?k=…`.

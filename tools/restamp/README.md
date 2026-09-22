# Restamp search codes (НД-XXXX-XXXX)

## Goal
Place internal `search_code` at the end of the last body paragraph of each DOCX,
right-aligned via a right tab stop. Keep commercial proposals (КП) on one page
when regenerating PDF.

## Live installer
Upload `install_restamp_codes.php` to `gost-documents/public/` and open:

`/install_restamp_codes.php?key=…&limit=25&offset=0`

Options: `id=127`, `dry=1`, `delete=1` (self-delete after success).

## PHP stamp logic
`stampDocxFile()` in `index.php` appends a right-tab + code run to the last
non-empty body paragraph (no separate «Код документа:» line).

## Notes
- Hosting has no LibreOffice; PDF cache is cleared on restamp. Regenerate PDFs
  offline (LibreOffice) and upload, or fit 2-page LO exports to 1 page when needed.
- Do not commit passwords or FTP credentials.

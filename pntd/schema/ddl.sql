-- PNTD formalization DDL (draft 0.1)
-- Plane A: open index (pntd.ru)
-- Plane B: closed product line (gost.info/pntd)
-- No secrets. Not a dump of production.

BEGIN;

CREATE TABLE ntd_document (
  id              INTEGER PRIMARY KEY,
  designation     TEXT NOT NULL,
  designation_kind TEXT CHECK (designation_kind IN ('GOST','GOST_R','SP','TU','OTHER')),
  title_ru        TEXT NOT NULL,
  title_en        TEXT,
  status          TEXT NOT NULL CHECK (status IN ('active','replaced','cancelled','adopted','unknown')),
  status_label_ru TEXT,
  effective_from  DATE,
  limited_until   DATE,
  standard_kind   TEXT,
  requirement_kind TEXT,
  developer_org   TEXT,
  introduced_by_cis_org TEXT,
  mnd_developer   TEXT,
  rostekh_department TEXT,
  interstate_tc   TEXT,
  tc_number       TEXT,
  tc_assignment_order_number TEXT,
  tc_assignment_order_date DATE,
  last_edition_date DATE,
  amendment_notes TEXT,
  page_count      INTEGER CHECK (page_count IS NULL OR page_count >= 0),
  abstract        TEXT,
  terms_note      TEXT,
  source_url      TEXT,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX ntd_document_designation_uidx ON ntd_document (designation);
CREATE INDEX ntd_document_status_idx ON ntd_document (status);

CREATE TABLE ntd_document_keyword (
  document_id INTEGER NOT NULL REFERENCES ntd_document(id) ON DELETE CASCADE,
  keyword     TEXT NOT NULL,
  PRIMARY KEY (document_id, keyword)
);

CREATE TABLE classifier_node (
  system      TEXT NOT NULL CHECK (system IN ('OKS','KGS','OKSTU','GRNTI')),
  code        TEXT NOT NULL,
  name        TEXT NOT NULL,
  parent_code TEXT,
  document_count INTEGER,
  PRIMARY KEY (system, code),
  FOREIGN KEY (system, parent_code) REFERENCES classifier_node(system, code)
);

CREATE TABLE ntd_classifier_binding (
  document_id  INTEGER NOT NULL REFERENCES ntd_document(id) ON DELETE CASCADE,
  system       TEXT NOT NULL CHECK (system IN ('OKS','KGS','OKSTU','GRNTI')),
  current_code TEXT NOT NULL,
  path_codes   TEXT[] NOT NULL,
  PRIMARY KEY (document_id, system, current_code),
  FOREIGN KEY (system, current_code) REFERENCES classifier_node(system, code)
);

CREATE TABLE ntd_relation (
  from_id           INTEGER NOT NULL REFERENCES ntd_document(id) ON DELETE CASCADE,
  to_id             INTEGER NOT NULL REFERENCES ntd_document(id) ON DELETE CASCADE,
  relation_type     TEXT NOT NULL CHECK (relation_type IN (
                      'replaces','replaced_by','normative_ref_gost','normative_ref_other','cited_by')),
  to_designation    TEXT,
  to_status_marker  TEXT CHECK (to_status_marker IS NULL OR to_status_marker IN ('Д','З','У','?')),
  PRIMARY KEY (from_id, to_id, relation_type),
  CHECK (from_id <> to_id)
);

CREATE INDEX ntd_relation_to_idx ON ntd_relation (to_id);

-- Plane B: product line

CREATE TABLE pntd_gate_state (
  id           SMALLINT PRIMARY KEY DEFAULT 1 CHECK (id = 1),
  code         TEXT NOT NULL DEFAULT 'PNTD-GATE',
  status       TEXT NOT NULL CHECK (status IN ('closed_no_access','access_granted')),
  brand        TEXT NOT NULL DEFAULT 'P — project · professional · protect',
  entry_path   TEXT NOT NULL DEFAULT '/forum2/pntd_gate.php',
  return_path  TEXT NOT NULL DEFAULT '/pntd/',
  forum_base   TEXT NOT NULL DEFAULT 'https://progost.info/forum2/',
  updated_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE pntd_ui_theme (
  id TEXT PRIMARY KEY
);

CREATE TABLE pntd_catalog_item (
  id          BIGSERIAL PRIMARY KEY,
  kind        TEXT NOT NULL CHECK (kind IN ('software','base')),
  code        TEXT NOT NULL,
  sku         TEXT,
  name        TEXT,
  description TEXT,
  price       INTEGER CHECK (price IS NULL OR price >= 1),
  ntd_document_id INTEGER REFERENCES ntd_document(id),
  ntd_designation TEXT,
  UNIQUE (kind, code)
);

CREATE TABLE pntd_catalog_tariff (
  catalog_item_id BIGINT NOT NULL REFERENCES pntd_catalog_item(id) ON DELETE CASCADE,
  tariff_key      TEXT NOT NULL,
  price           INTEGER CHECK (price IS NULL OR price >= 1),
  PRIMARY KEY (catalog_item_id, tariff_key)
);

CREATE TABLE pntd_kit_case (
  id               TEXT PRIMARY KEY,
  months           INTEGER NOT NULL CHECK (months BETWEEN 4 AND 100),
  discount_percent NUMERIC(5,2) NOT NULL DEFAULT 40 CHECK (discount_percent >= 0 AND discount_percent <= 100)
);

CREATE TABLE pntd_kit_line (
  kit_id     TEXT NOT NULL REFERENCES pntd_kit_case(id) ON DELETE CASCADE,
  line_no    INTEGER NOT NULL,
  code       TEXT NOT NULL,
  kind       TEXT NOT NULL CHECK (kind IN ('software','base')),
  tariff     TEXT,
  name       TEXT,
  qty        INTEGER NOT NULL CHECK (qty BETWEEN 1 AND 9999),
  unit_price INTEGER CHECK (unit_price IS NULL OR unit_price >= 1),
  PRIMARY KEY (kit_id, line_no)
);

CREATE TABLE pntd_copy_block (
  id     TEXT PRIMARY KEY,
  fields JSONB NOT NULL DEFAULT '{}'::jsonb
);

COMMIT;

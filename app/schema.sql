CREATE TABLE IF NOT EXISTS vehicle (
  id INTEGER PRIMARY KEY, year INT, make TEXT, model TEXT, trim TEXT, engine TEXT, transmission TEXT,
  vin TEXT, color TEXT, plate TEXT, mileage INT, mileage_updated TEXT, photo TEXT, notes TEXT, mods TEXT
);
CREATE TABLE IF NOT EXISTS mileage_log (id INTEGER PRIMARY KEY, logged_at TEXT, miles INT, note TEXT);
CREATE TABLE IF NOT EXISTS mechanics (id INTEGER PRIMARY KEY, name TEXT, phone TEXT, email TEXT, maps_url TEXT, address TEXT, notes TEXT, is_primary INT DEFAULT 0);
CREATE TABLE IF NOT EXISTS issues (
  id INTEGER PRIMARY KEY, title TEXT, system TEXT, location TEXT, severity INT, status TEXT,
  summary TEXT, evidence TEXT, likely_causes TEXT, owner_guidance TEXT, mechanic_guidance TEXT,
  opened_on TEXT, resolved_on TEXT, caused_by_visit_id INT, est_cost TEXT, links TEXT,
  created_at TEXT, updated_at TEXT
);
CREATE TABLE IF NOT EXISTS issue_notes (id INTEGER PRIMARY KEY, issue_id INT, noted_at TEXT, miles INT, body TEXT);
CREATE TABLE IF NOT EXISTS obd_codes (id INTEGER PRIMARY KEY, code TEXT UNIQUE, title TEXT, system TEXT, severity INT, description TEXT, causes TEXT, c300_note TEXT);
CREATE TABLE IF NOT EXISTS code_events (id INTEGER PRIMARY KEY, code TEXT, seen_on TEXT, miles INT, status TEXT, note TEXT);
CREATE TABLE IF NOT EXISTS common_issues (
  id INTEGER PRIMARY KEY, title TEXT, system TEXT, location TEXT, severity INT, mileage_window TEXT,
  symptoms TEXT, diagnosis TEXT, fix TEXT, typical_cost TEXT, parts_link TEXT, status TEXT, this_car_note TEXT
);
CREATE TABLE IF NOT EXISTS maintenance (
  id INTEGER PRIMARY KEY, item TEXT, category TEXT, interval_miles INT, interval_months INT,
  last_miles INT, last_date TEXT, spec TEXT, parts_link TEXT, notes TEXT, priority INT
);
CREATE TABLE IF NOT EXISTS checks (id INTEGER PRIMARY KEY, category TEXT, item TEXT, how_to TEXT, frequency TEXT, sort INT, active INT DEFAULT 1);
CREATE TABLE IF NOT EXISTS inspections (id INTEGER PRIMARY KEY, done_on TEXT, miles INT, kind TEXT, summary TEXT);
CREATE TABLE IF NOT EXISTS inspection_results (id INTEGER PRIMARY KEY, inspection_id INT, check_id INT, result TEXT, note TEXT);
CREATE TABLE IF NOT EXISTS visits (
  id INTEGER PRIMARY KEY, mechanic_id INT, scheduled_on TEXT, status TEXT, title TEXT,
  planned_work TEXT, completed_work TEXT, cost REAL, parts_cost REAL, labor_cost REAL, miles INT,
  outcome TEXT, signed_off INT DEFAULT 0, signed_at TEXT, rating INT, created_at TEXT
);
CREATE TABLE IF NOT EXISTS visit_issues (visit_id INT, issue_id INT);
CREATE TABLE IF NOT EXISTS photos (id INTEGER PRIMARY KEY, kind TEXT, ref_id INT, stage TEXT, path TEXT, caption TEXT, taken_on TEXT, created_at TEXT);
CREATE TABLE IF NOT EXISTS parts (id INTEGER PRIMARY KEY, name TEXT, supplier TEXT, url TEXT, part_no TEXT, price REAL, status TEXT, category TEXT, notes TEXT, issue_id INT, created_at TEXT);
CREATE TABLE IF NOT EXISTS links (id INTEGER PRIMARY KEY, title TEXT, url TEXT, category TEXT, notes TEXT);
CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT);
CREATE TABLE IF NOT EXISTS specs (id INTEGER PRIMARY KEY, category TEXT, item TEXT, value TEXT, source TEXT, confidence TEXT, notes TEXT, sort INT);
CREATE TABLE IF NOT EXISTS manual_pages (id INTEGER PRIMARY KEY, edition TEXT, page INT, text TEXT);
CREATE TABLE IF NOT EXISTS manual_facts (id INTEGER PRIMARY KEY, category TEXT, item TEXT, value TEXT, page INT, notes TEXT, sort INT);
CREATE TABLE IF NOT EXISTS recalls (id INTEGER PRIMARY KEY, vehicle_id INTEGER NOT NULL DEFAULT 1, campaign TEXT, component TEXT, summary TEXT, consequence TEXT, remedy TEXT,
  report_date TEXT, park_it INT DEFAULT 0, status TEXT DEFAULT 'open', found_at TEXT, UNIQUE (vehicle_id, campaign));
CREATE TABLE IF NOT EXISTS shares (id INTEGER PRIMARY KEY, vehicle_id INTEGER NOT NULL DEFAULT 1, token TEXT UNIQUE, created_at TEXT, revoked INT DEFAULT 0,
  include_issues INT DEFAULT 1, include_costs INT DEFAULT 1, include_vin INT DEFAULT 0, views INT DEFAULT 0, last_view TEXT);
CREATE TABLE IF NOT EXISTS customers (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL DEFAULT 1, name TEXT, phone TEXT, email TEXT, notes TEXT, created_at TEXT);
CREATE TABLE IF NOT EXISTS diag_reports (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL DEFAULT 1, vehicle_id INT, customer_id INT, vehicle_json TEXT, codes TEXT, freeze TEXT,
  symptoms TEXT, notes TEXT, source TEXT, created_at TEXT);
CREATE TABLE IF NOT EXISTS tool_links (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL DEFAULT 1, grp TEXT, name TEXT, url TEXT, notes TEXT, sort INT DEFAULT 0);
CREATE TABLE IF NOT EXISTS shop_profile (user_id INTEGER PRIMARY KEY, data TEXT);
CREATE TABLE IF NOT EXISTS jobs (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL DEFAULT 1, customer_id INT, vehicle_id INT, stage TEXT DEFAULT 'estimate', est_no TEXT, inv_no TEXT,
  title TEXT, complaint TEXT, cause TEXT, correction TEXT, odometer_in INT, odometer_out INT, promised TEXT, notes_customer TEXT, notes_internal TEXT, discount REAL DEFAULT 0,
  labor_rate REAL, tax_parts REAL, tax_labor REAL, supplies_pct REAL, supplies_cap REAL, token TEXT UNIQUE, sent_at TEXT, approved_at TEXT, approved_by TEXT, approve_note TEXT,
  seen_approval INT DEFAULT 1, invoiced_at TEXT, paid_at TEXT, diag_report_id INT, total REAL DEFAULT 0, paid REAL DEFAULT 0, created_at TEXT, updated_at TEXT);
CREATE TABLE IF NOT EXISTS job_lines (id INTEGER PRIMARY KEY, job_id INT NOT NULL, grp TEXT, kind TEXT, description TEXT, part_no TEXT, brand TEXT, supplier TEXT,
  qty REAL DEFAULT 1, cost REAL DEFAULT 0, price REAL DEFAULT 0, taxable INT DEFAULT 1, status TEXT DEFAULT 'pending', pstatus TEXT DEFAULT 'quoted', catalog_id INT, sort INT DEFAULT 0);
CREATE TABLE IF NOT EXISTS job_payments (id INTEGER PRIMARY KEY, job_id INT NOT NULL, amount REAL, method TEXT, paid_on TEXT, note TEXT, created_at TEXT);
CREATE TABLE IF NOT EXISTS canned_jobs (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL DEFAULT 1, name TEXT, category TEXT, lines TEXT, sort INT DEFAULT 0);
CREATE TABLE IF NOT EXISTS parts_catalog (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL DEFAULT 1, part_no TEXT, name TEXT, brand TEXT, supplier TEXT, cost REAL DEFAULT 0,
  price REAL, qty REAL DEFAULT 0, location TEXT, notes TEXT, updated_at TEXT);
CREATE INDEX IF NOT EXISTS idx_jobs_user ON jobs (user_id, stage);
CREATE INDEX IF NOT EXISTS idx_job_lines_job ON job_lines (job_id);
CREATE TABLE IF NOT EXISTS job_auth (id INTEGER PRIMARY KEY, job_id INT NOT NULL, amount REAL, method TEXT, by_name TEXT, note TEXT, at TEXT);
CREATE TABLE IF NOT EXISTS job_inspect (id INTEGER PRIMARY KEY, job_id INT NOT NULL, section TEXT, item TEXT, rating TEXT, note TEXT, sort INT DEFAULT 0);
-- Shop operations (2026-11-08, part 2): appointments, technicians and time clock, vendors and purchase orders.
CREATE TABLE IF NOT EXISTS appointments (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, customer_id INT, vehicle_id INT, job_id INT, tech_id INT, starts_at TEXT, minutes INT DEFAULT 60,
  title TEXT, concern TEXT, status TEXT DEFAULT 'scheduled', source TEXT DEFAULT 'shop', name TEXT, phone TEXT, email TEXT, vehicle_text TEXT, vin TEXT, plate TEXT, waiting INT DEFAULT 0,
  notes TEXT, reminded_at TEXT, seen INT DEFAULT 1, created_at TEXT, updated_at TEXT);
CREATE INDEX IF NOT EXISTS idx_appt_user ON appointments (user_id, starts_at);
CREATE TABLE IF NOT EXISTS techs (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, name TEXT, role TEXT DEFAULT 'tech', pay_type TEXT DEFAULT 'hourly', pay_rate REAL DEFAULT 0,
  color TEXT, pin_hash TEXT, active INT DEFAULT 1, sort INT DEFAULT 0, created_at TEXT);
CREATE TABLE IF NOT EXISTS time_entries (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, tech_id INT NOT NULL, kind TEXT NOT NULL, job_id INT, grp TEXT, started_at INT NOT NULL, ended_at INT, note TEXT, edited INT DEFAULT 0);
CREATE INDEX IF NOT EXISTS idx_time_tech ON time_entries (tech_id, started_at);
CREATE INDEX IF NOT EXISTS idx_time_job ON time_entries (job_id);
CREATE TABLE IF NOT EXISTS vendors (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, name TEXT, account_no TEXT, contact TEXT, phone TEXT, email TEXT, website TEXT, notes TEXT);
CREATE TABLE IF NOT EXISTS purchase_orders (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, vendor_id INT, po_no TEXT, status TEXT DEFAULT 'draft', ref TEXT, notes TEXT, ordered_at TEXT, received_at TEXT,
  total REAL DEFAULT 0, created_at TEXT, updated_at TEXT);
CREATE TABLE IF NOT EXISTS po_lines (id INTEGER PRIMARY KEY, po_id INT NOT NULL, job_line_id INT, catalog_id INT, part_no TEXT, brand TEXT, description TEXT, qty REAL DEFAULT 1, qty_received REAL DEFAULT 0, cost REAL DEFAULT 0);

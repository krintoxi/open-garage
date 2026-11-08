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

# Open Garage

A self-hosted maintenance log for the vehicles you own: cars, SUVs, motorcycles and scooters.
One PHP app, one SQLite file, no framework and no build step. It's built for the owner rather than the mechanic:
it tells you what to do this week, keeps the history a shop can't argue with, and makes the car look good doing it.

![Garage page](docs/garage.png)

## What it does

- **Garage of vehicles.** Add a vehicle by **VIN**: the form decodes it with the U.S. government's free NHTSA database
  (year, make, model, trim, engine, vehicle type), or you fill the boxes in yourself.
- **Showroom dashboard.** Your own photo on a lit, tilting stage. With no photo, the app looks for a freely licensed
  stand-in photo of the same make and model on Wikimedia Commons (with credit), and otherwise draws one in the vehicle's
  colours, with issue markers on the drawing. Vehicles with a 3D model (optional, `.glb`) get an orbitable 3D view
  with clickable issue hotspots and an X-ray mode.
- **Issues** with severity, "for me" and "for the mechanic" guidance, notes over time, photos, and the visit that
  caused them.
- **Mechanic visits:** plan the work, print a brief for the shop, then record what was actually done, cost, mileage
  and before/after photos, and sign it off.
- **Maintenance schedule** by miles and months (starter schedules for cars and for scooters/motorcycles), weekly
  check-lists, mileage log, parts and upgrades list, OBD-II code library with a log of codes seen, links.
- **Phone-first.** Works as a home-screen app; photos are re-encoded on upload (location data stripped, rotation fixed,
  HEIC converted when the server has a converter).
- **Private.** One password, strict sessions, login throttling, photos streamed only to signed-in users, and it refuses
  to come up open to the internet before a password is set.

| Stand-in drawing (no photo yet) | On a phone |
|---|---|
| ![Showroom with drawing](docs/showroom-drawing.png) | ![Phone](docs/phone.png) |

![Showroom with stand-in photo](docs/showroom-photo.png)

## Run it

Requirements: PHP 8.2+ with `pdo_sqlite`, `gd` (photos), `curl` (VIN lookup and stand-in photos); optionally `exif`
and ImageMagick or libheif (iPhone HEIC photos).

```bash
git clone https://github.com/krintoxi/open-garage.git && cd open-garage
./run.sh            # http://localhost:8300 , then add your first vehicle
```

On a server, point the web root at this folder (`index.php` is the only entry point), copy
`app/config.local.example.php` to `app/config.local.php` to keep the database and photos outside the web root, and
set the password from the server before exposing it:

```bash
php app/tools/set-password.php
```

Until a password exists the app only answers requests from the machine itself. Back up with
Vehicle & settings → "Download database backup" (and your `UPLOAD_DIR`).

## Notes

- Internal PHP files refuse direct requests, and `app/.htaccess` / `uploads/.htaccess` deny them on Apache. On other
  servers, block `/app`, `/data` and `/uploads` too.
- Outbound requests happen only when you add a vehicle or look up a VIN: `vpic.nhtsa.dot.gov` and
  `commons.wikimedia.org`.
- The OBD-II library covers the standard generic codes; add your own notes and codes from the OBD codes page.
- 3D: see [assets/models/README.txt](assets/models/README.txt). No 3D model is included.
- Screenshot credit: the Honda Civic stand-in photo is by MB-one, CC BY-SA 4.0, via Wikimedia Commons.

## License

MIT, see [LICENSE](LICENSE).

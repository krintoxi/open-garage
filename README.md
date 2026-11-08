# Open Garage

A self-hosted maintenance log for the vehicles you own: cars, SUVs, motorcycles and scooters.
One PHP app, one SQLite file, no framework and no build step. It's built for the owner rather than the mechanic:
it tells you what to do this week, keeps the history a shop can't argue with, and makes the car look good doing it.

![Garage page](docs/garage.png)

## What it does

- **Add a vehicle in a minute.** A three-step wizard: pick the year, make and model from lists (or scan the **VIN**, decoded
  with the free U.S. NHTSA database), snap a photo, then tap what's going on ("oil change due", "tire worn",
  "check engine light"...). Each answer becomes an issue with plain-language next steps.
- **Showroom photo.** Your photo is cut out of its background and rendered as a "3D photo" that tilts with your phone or
  mouse, on a lit stage with a reflection. This uses the optional [garage-vision](vision/) service on any machine with a
  GPU. Without it you get your photo on the stage; without a photo, a matching freely licensed stand-in photo
  (Wikimedia Commons, credited) or a drawing in your vehicle's colours, with issue markers on it.
- **Experimental 3D model** from one photo (TripoSR), and full 3D view if you supply a `.glb` model.
- **Issues** with severity, "for me" and "for the mechanic" guidance, notes over time, photos, and the visit that
  caused them. **Mechanic visits:** plan the work, print a brief for the shop, record what was done, cost and before/after
  photos, and sign it off.
- **Maintenance schedule** by miles and months (starter schedules for cars and for scooters/motorcycles), weekly
  check-lists, mileage log, parts list, OBD-II code library, links.
- **One owner or many.** Run it for yourself with one password, or as a hosted service with email sign-up
  (`MULTI_USER`): every account sees only its own vehicles, with password reset by email, per-account limits, sign-up
  throttling and a JSON export of your data.
- **Phone-first and private.** Works as a home-screen app; photos are re-encoded on upload (location data stripped);
  photos are only ever streamed to their owner.

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

On a server, point the web root at this folder (`index.php` is the only entry point) and copy
`app/config.local.example.php` to `app/config.local.php`. There you keep the database and photos outside the web root,
and choose the mode:

- **Just you:** leave `MULTI_USER` off and set the password from the server: `php app/tools/set-password.php`.
  Until a password exists the app only answers requests from the machine itself.
- **A hosted garage for others:** `define('MULTI_USER', true);` plus `BASE_URL`, and `MAIL_KEY` / `MAIL_FROM` (a
  [Resend](https://resend.com) key) for confirmation and password-reset emails. The first account to sign up is the admin.
- **Showroom photos / 3D:** run [vision/](vision/) on a GPU machine and set `VISION_URL` / `VISION_TOKEN`.

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

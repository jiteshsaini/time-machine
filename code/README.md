# Time Machine

A self-hosted slideshow + photo curation tool for a local photo library on disk.

Designed to run on a small home server (Raspberry Pi, NAS, old laptop) and drive an always-on slideshow on a connected TV / monitor, while letting you curate the library from your phone or laptop.

---

## What this is

- **Slideshow-first**: the primary surface is a full-screen photo loop on a large display.
- **Filesystem-as-truth**: photos live as files on disk in `time_machine/images/`. There's no database. Move/rename/back-up files with normal tools and they just work.
- **Web-based**: management runs in any modern browser; viewer is plain HTML5 (works on a Pi-driven kiosk browser, smart-TV browser, etc.).
- **Local-only**: built for a LAN with no cloud sync, no upload-elsewhere, no telemetry.

## What this is NOT

- Not a Google Photos / iCloud replacement — no face recognition, no smart search, no automatic sync.
- Not a competitor to Immich / Photoprism — those have bigger feature sets for general photo management; this is a focused slideshow-and-curate tool.
- Not multi-user with logins. The "action password" in `var.php` is a soft confirmation against accidents, **not** a security boundary. Network isolation is the real boundary.

---

## Requirements

- **PHP 7.4 or newer** (uses arrow functions and `??`).
- **Apache** (or any PHP-capable web server) serving the `code/` directory as a web root or sub-path.
- **Python 3** with `Pillow` (`PIL`) and `piexif`, used by the resize / crop / rotate utilities.
- **`sudo`** on the server account that runs PHP — `img_resize.py` runs `sudo chmod -R 777` to normalize permissions, and crop / rotate / resize run their Python as root so they can put back each photo's original modified date (only a file's owner or root may set it). Configure `sudoers` accordingly.
- A modern browser (Chromium / Firefox / Safari) for both the slideshow viewer and management UI.

---

## Install

On a Raspberry Pi, `setup_time_machine.sh` at the top of the repository does all of this — Apache, PHP, the Python libraries, the kiosk browser, the Samba share and the code. Run it with `sudo bash setup_time_machine.sh`. Otherwise, by hand:

1. Drop the project on your server so the web root sees three folders side-by-side:

   ```
   <docroot>/time_machine/code/      ← this codebase
   <docroot>/time_machine/images/    ← your photos (you create + populate)
   <docroot>/time_machine/trash/     ← auto-created when first item is trashed
   ```

   The folder name is not fixed: `code/var.php` takes `$appName` from the folder the code sits in.

2. Make sure PHP can read `code/` and read/write `code/txt/`, plus read/write/move files in `images/` and `trash/`.

3. Install Python dependencies on the server (Debian / Raspberry Pi OS packages — recent releases block a system-wide `pip3 install`):
   ```
   sudo apt install python3-pil python3-piexif
   ```

4. **Slideshow URL** (point your Pi's kiosk browser at):
   ```
   http://<server-ip>/time_machine/code/        ← currently loads slideshow
   ```

5. **Management URL** (open from your phone/laptop):
   ```
   http://<server-ip>/time_machine/code/manage.php
   ```

6. On first load, the management page will show your `images/` folder. Click into a folder, use the per-folder *Add to slideshow* button to start curating what plays.

---

## How it works

### Two parts

**Viewer** (`code/index.html`):
- Polls `code/api/get_images.php` for the list of images to play.
- Reads delay, shuffle, fit-mode, date-overlay settings from `txt/` state files via the small `api/get_*.php` endpoints.
- Crossfades between images using two stacked `<img>` layers.
- Polls `txt/change_status.txt` mtime every 5 s; reloads when it changes — so settings tweaks from your phone propagate live without a manual refresh.

**Management** (`code/manage.php`, served by `code/manage_ops.php`):
- Browse your library (list view + grid view).
- Toggle which folders play in the slideshow (drops them into `txt/paths.txt`).
- Star individual images (renames file to add `~star` before the extension; tracked in `txt/starred_paths.txt` if explicitly added to slideshow as a single-file inclusion).
- Skip (hide from slideshow) by renaming file/folder to start with `_`.
- Crop, rotate, resize, find duplicates, set play order — see *Workflow tools* below.
- Trash → restore / permanent-delete — see *Trash*.

### The slideshow's "what to play" rule

The slideshow plays the **union** of:

1. **All non-hidden, non-skipped images** inside any folder listed in `txt/paths.txt`, recursively.
2. **Individual files** listed in `txt/starred_paths.txt` (the file-set playlist for favorites).

With one exception:

- Anything whose path contains a `_*` or `.*` segment is **always excluded**. So `images/_archived/foo.jpg` and `images/.hidden/bar.jpg` never play, even if their parent folder is otherwise in `paths.txt`. This is the "skip" convention — rename with a leading underscore to keep a file/folder on disk but out of the slideshow.

### File-naming conventions

| Pattern | Meaning |
|---|---|
| `100~photo.jpg` | Ordering prefix — sorts within folder by the number before `~`. Used by *Set Order*. |
| `_photo.jpg`, `_2024/` | **Skipped** — kept on disk, excluded from slideshow + most management views. |
| `photo~star.jpg` | **Starred** — favorite marker. Appears in the *Starred* page. |
| `100~_photo~star.jpg` | All three combined: ordered, skipped, starred. Valid. |

---

## Configuration

Everything tunable lives in `code/var.php`. Key knobs:

```php
$appName = basename(dirname(__DIR__));  // the app's folder under docroot

define('RESIZE_MAX_RECURSIVE', 5000);  // max images per Resize session
define('RESIZE_BATCH_SIZE',    200);   // images processed per "Resize next" click

define('APP_CREDIT_LABEL', 'helloworld.co.in');  // footer credit
define('APP_CREDIT_URL',   'https://helloworld.co.in');

$actionPassword  = 'abc789';        // soft-confirmation password (NOT security)
$requirePassword = true;            // flip to false to disable confirm prompts
```

---

## File layout

```
code/
├── index.html              ← Slideshow viewer (the page the Pi loads).
├── manage.php              ← Management UI (grid + in-page list toggle).
├── manage_ops.php          ← Backend operations endpoint (AJAX + POST handlers).
├── manage_util.php         ← Shared chrome (header, status bar, modals,
│                              folder-row helpers, count helpers).
├── var.php                 ← Config + small shared helpers
│                              (is_skipped_name, is_starred_name, state_file,
│                              app_credit_html, …).
│
├── api/                    ← Thin AJAX endpoints (returns JSON or fragment HTML).
│   ├── get_images.php      ← Slideshow's source of truth — union of paths.txt
│   │                          and starred_paths.txt, with skip filtering.
│   ├── get_delay.php
│   ├── get_shuffle.php
│   ├── get_fit_mode.php
│   ├── get_show_date.php
│   ├── get_change_status.php  ← Returns mtime of change_status.txt for the
│   │                              viewer's reload poll.
│   ├── get_folder_rows.php ← AJAX fragment for the list view's tree expansion.
│   └── search_folders.php  ← Folder search across the whole library.
│
├── pages/                  ← Workflow tools — focused single-purpose pages.
│   ├── pages_util.php      ← Shared `page_header()` + chrome CSS for these.
│   ├── crop.php            ← Single-image crop (Cropper.js + util/img_crop.py).
│   ├── set_order.php       ← Drag-to-order images within a folder.
│   ├── find_duplicates.php ← Byte-identical duplicate finder + bulk trash.
│   ├── trash.php           ← Card-grid trash with restore / delete forever.
│   └── starred.php         ← Browse all starred favorites across the library.
│
├── util/                   ← Action endpoints. POST-only, return JSON.
│   ├── toggle_star.php     ← Add/remove ~star marker on a file.
│   ├── toggle_skip.php     ← Add/remove leading _ on a file.
│   ├── toggle_shuffle.php  ← Flip shuffle.txt.
│   ├── toggle_fit.php      ← Flip fit_mode.txt (contain/cover).
│   ├── toggle_show_date.php← Flip show_date.txt.
│   ├── send_to_trash.php   ← Move image(s) from images/ → trash/.
│   ├── restore_from_trash.php ← Move back from trash/ → images/.
│   ├── delete_permanent.php ← Hard delete from trash/. Includes "empty trash".
│   ├── img_crop.php        ← Thin wrapper that runs img_crop.py.
│   ├── img_crop.py         ← Cropping logic (Pillow + EXIF preservation).
│   ├── img_rot.php         ← Rotate 90° clockwise via PIL.
│   ├── img_rot.py
│   ├── img_resize.php      ← Resize UI page (polls progress, "next batch" button).
│   ├── img_resize_worker.php  ← Thin wrapper that runs img_resize.py for one batch.
│   ├── img_resize.py       ← Resizing logic (Pillow, batched, EXIF preserving).
│   ├── manage_starred_paths.php ← Add/remove file paths in starred_paths.txt.
│   └── system_commands.php ← Reboot / restart Apache / etc.
│
└── txt/                    ← All state. Single source of truth for "the app's mind".
    ├── paths.txt           ← Absolute folder paths currently in slideshow.
    ├── starred_paths.txt   ← Absolute file paths individually in slideshow.
    ├── playlists.json      ← Saved folder-set playlists.
    ├── delay.txt           ← Seconds between images.
    ├── shuffle.txt         ← "1" or "0".
    ├── fit_mode.txt        ← "contain" or "cover".
    ├── show_date.txt       ← "1" or "0".
    ├── change_status.txt   ← Mtime-only signal for live viewer refresh.
    └── audit.log           ← Append-only log of state-changing actions.
```

---

## Day-to-day workflows

### Starting a slideshow
1. Open `manage.php` from your phone or laptop.
2. Click into a folder.
3. Click *Add to slideshow* next to the breadcrumb.
4. The Pi's slideshow updates within ~5 seconds.

### Curating favorites
1. In list or grid view, click the ★ icon on any image.
2. Visit *Starred* (★ pill in status bar) to see all favorites across the library.
3. Add individual favorites to the slideshow with the per-card ⊕ button, or *Add all to slideshow*.

### Cleaning up
1. **Trash** the photos you don't want (single click, recoverable).
2. Visit *Trash* (🗑 pill in status bar) to review and permanently delete or restore.
3. *Find Duplicates* inside any folder to clean redundant copies — uses byte-identical SHA-1 match.

### Reorganizing on disk
- All filenames respect three conventions: `100~`-prefix for order, leading `_` for skip, `~star`-suffix for favorite. Combine freely.
- Move folders around in your file manager — `paths.txt` entries by absolute path will go stale; you'll need to re-add. (Don't move things while paths.txt references them.)
- Add new images via SCP, USB, or the in-app *Upload* on a folder.

### Resizing large originals
- *Resize* recursively shrinks anything above 1920×1080 inside a folder (preserves EXIF, ICC, mtime).
- Capped at **5,000 images per session** (configurable). If a folder has more, split into subfolders first.
- Processes in batches of 200 with a *Resize next batch* button. Each batch takes ~1–3 min depending on disk speed.

---

## Backup

Everything that makes this *your* library lives in two places:

1. **`images/`** — the photos. Back up however you back up other photos (cloud sync, external drive, etc.).
2. **`code/txt/`** — the curation state (which folders play, what's starred, delay setting, playlists). Small files, **easy to forget**. Recommended: a daily cron that zips them:
   ```
   0 3 * * *  zip -r /backups/time_machine_state_$(date +\%Y\%m\%d).zip /var/www/html/time_machine/code/txt/
   ```

`trash/` is recoverable photos waiting on deletion — back up if you care; otherwise transient.

The PHP/Python source code is just files — back up `code/` if you've made local edits.

---

## Troubleshooting

**Slideshow shows nothing**
- Open `code/api/get_images.php` in a browser. It returns a JSON array. Empty array means no folders are currently in `paths.txt` — go to manage and add one.

**Settings change on phone, slideshow doesn't update**
- The viewer polls `change_status.txt` every 5 seconds. Make sure that file exists and is writable by the web server user (PHP can write but reload may be cached at the browser — hard-refresh once to verify).

**"Permission denied" when trashing/restoring/cropping/resizing**
- All these operations involve PHP renaming/moving files. The PHP process must own (or have write access to) `images/` and `trash/`. Most common fix: `chmod -R 775 images/ trash/` and ensure your web server user is in the owning group.

**Python crop/resize/rotate fails silently**
- Check `dpkg -l python3-pil python3-piexif` — Pillow + piexif must be installed system-wide, since the scripts run as root via `sudo python3`. Run `python3 code/util/img_resize.py /full/path/to/folder` from a shell as the same user to surface errors directly.

**Trash count never goes down even after permanent delete**
- We're about to introduce a count cache. If you see stale counts after `txt/count_cache.json` exists, delete it manually — the next page load will rebuild.

---

## Development

On the Pi the app lives in two folders, never one:

| Folder | What it is |
|---|---|
| `/var/www/html/time_machine` | **The install** — what the kiosk shows, with the real library in `images/`. Put there by `setup_time_machine.sh`, no `.git`. Never edit here. |
| `/var/www/html/tm` | **The checkout** — a git clone where you edit, commit and push. Served at `http://<pi>/tm/code/`, it works on its own `images/` (the sample albums), so testing can never touch the library. |

The workflow:

1. Edit and test in `/var/www/html/tm`.
2. Commit and push from there.
3. Update the install: `sudo bash setup_time_machine.sh --code-only` fetches the new code from GitHub and carries the photos, the trash and the settings over.

Optional: `setup_syncthing.sh` sets up Syncthing to keep a copy of the library on a phone. `--restore <dir>` reuses an identity saved from a previous card, so the phone stays paired.

---

## License / credit

Footer on every management page links to **helloworld.co.in**. Defined in `code/var.php` (`APP_CREDIT_LABEL`, `APP_CREDIT_URL`) — single source of truth.

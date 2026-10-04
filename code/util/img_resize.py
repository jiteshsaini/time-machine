#!/usr/bin/env python3
"""
Recursive image resizer.

Walks the given folder and all subfolders, resizes any image with a side
larger than `max_dimension`, preserves EXIF + ICC + filesystem timestamps,
deletes macOS / Android junk files, replaces spaces in filenames with
underscores.

Writes live progress to:
    <folder_path>/.resize_progress.json
... so the PHP front-end can poll it and animate a progress bar. The file
is removed when finished.
"""

import json
import os
import re
import sys
import tempfile
import time
from PIL import Image, UnidentifiedImageError

MAX_DIMENSION = 1920
# Skip files already smaller than this — a well-compressed high-resolution
# JPEG (e.g., 2000x3008 at 277 KB) gains little or nothing from resizing and
# may even lose quality through re-encoding. Tune to taste:
#   300_000  — aggressive (skip only very compact files)
#   500_000  — balanced (default)
#   1_000_000 — lenient (skip anything under 1 MB regardless of resolution)
MIN_SIZE_BYTES_TO_RESIZE = 500 * 1024
IMAGE_EXTS    = ('.png', '.jpg', '.jpeg', '.gif')
JUNK_PREFIXES = ('._', '.trashed-')
JUNK_NAMES    = {'.DS_Store'}

# Resolve the per-display "txt" folder so progress lives next to other state.
# We accept either a folder path or a CLI flag for the progress file location.
def progress_path(folder_path):
    return os.path.join(folder_path, '.resize_progress.json')


def write_progress(progress_file, payload):
    """Write atomically so the PHP poller never reads a half-written file."""
    try:
        tmp = progress_file + '.tmp'
        with open(tmp, 'w') as f:
            json.dump(payload, f)
        os.replace(tmp, progress_file)
    except OSError:
        pass  # progress is best-effort; never block the resize on it


def save_keeping_dates(image, path, **save_kwargs):
    """Replace the file at `path` with `image`, keeping its dates and mode.

    Only a file's owner may set its mtime, and a photo is often owned by
    someone other than the web server. So the picture is written to a hidden
    temporary file beside the photo - which we do own - given the photo's
    timestamps and mode, and swapped in. The swap is a single step, so an
    interrupted save leaves the original untouched. Needs write access to
    the folder; raises OSError if any step fails.
    """
    st = os.stat(path)
    mode = st.st_mode & 0o7777
    if st.st_uid != os.geteuid():
        # The new file is ours, not the old owner's - give group and others
        # what the owner had, so whoever owned the photo can still use it.
        owner_bits = (mode >> 6) & 7
        mode |= owner_bits << 3 | owner_bits
    folder, name = os.path.split(path)
    # Leading dot: the slideshow and the sync tools skip hidden files, and
    # the name keeps its extension so PIL still picks the right format.
    tmp = os.path.join(folder, '.tmp-' + name)
    try:
        image.save(tmp, **save_kwargs)
        os.chmod(tmp, mode)
        os.utime(tmp, ns=(st.st_atime_ns, st.st_mtime_ns))
        os.replace(tmp, path)
    except OSError:
        try:
            os.remove(tmp)
        except OSError:
            pass
        raise


def open_permissions(root):
    """Best-effort: make everything we own under `root` writable by all, as
    the installer does. Files owned by someone else are left as they are."""
    for dirpath, dirnames, filenames in os.walk(root):
        for name in [''] + dirnames + filenames:
            p = os.path.join(dirpath, name)
            if os.path.islink(p):
                continue
            try:
                os.chmod(p, 0o777)
            except OSError:
                pass


def is_junk(name):
    return name in JUNK_NAMES or name.startswith(JUNK_PREFIXES)


def needs_resize(path):
    """True iff the file is genuinely a resize candidate — both bigger than
    our size floor AND has at least one dimension over MAX_DIMENSION.
    Opens the image only for its size metadata (no pixel load), so this is
    fast enough to run on thousands of files at scan time."""
    try:
        if os.path.getsize(path) <= MIN_SIZE_BYTES_TO_RESIZE:
            return False
        with Image.open(path) as img:
            w, h = img.size
        return w > MAX_DIMENSION or h > MAX_DIMENSION
    except (UnidentifiedImageError, OSError):
        return False


def collect_candidates(root):
    """Enumerate image files that actually need resizing (recursive). Junk
    files (._foo, .DS_Store, .trashed-*) are deleted as we walk. Hidden (.)
    and user-skipped (_) folders/files are not touched at all."""
    found = []
    for dirpath, dirnames, filenames in os.walk(root):
        dirnames[:] = [d for d in dirnames if not d.startswith('.') and not d.startswith('_')]
        for name in filenames:
            if name.startswith('_'):
                continue
            full = os.path.join(dirpath, name)
            if is_junk(name):
                try:
                    os.remove(full)
                    print(f"Deleted junk: {full}")
                except OSError as e:
                    print(f"Could not delete junk {full}: {e}")
                continue
            if not name.lower().endswith(IMAGE_EXTS):
                continue
            if needs_resize(full):
                found.append(full)
    return found


def resize_one(file_path):
    """Resize a single file, replacing it. Returns (status, info_str)."""
    # Replace spaces in the basename
    folder = os.path.dirname(file_path)
    new_name = re.sub(r'\s+', '_', os.path.basename(file_path))
    if new_name != os.path.basename(file_path):
        new_path = os.path.join(folder, new_name)
        os.rename(file_path, new_path)
        file_path = new_path

    size_before = os.path.getsize(file_path)

    # Skip well-compressed files regardless of pixel dimensions — re-encoding
    # them wouldn't reclaim meaningful disk space.
    if size_before <= MIN_SIZE_BYTES_TO_RESIZE:
        return ('skip', f"{os.path.basename(file_path)} already small ({size_before/1024:.1f} KB)")

    # Open + validate
    try:
        image = Image.open(file_path)
        image.load()
        exif_bytes  = image.info.get('exif', b'')
        icc_profile = image.info.get('icc_profile')
        image = image.convert('RGB')
    except (UnidentifiedImageError, OSError) as e:
        try:
            os.remove(file_path)
            return ('removed', f"corrupted, deleted: {os.path.basename(file_path)} ({e})")
        except OSError:
            return ('error', f"corrupted, could not delete: {os.path.basename(file_path)}")

    width, height = image.size
    if width <= MAX_DIMENSION and height <= MAX_DIMENSION:
        return ('skip', f"{os.path.basename(file_path)} already within limits")

    if width > height:
        new_width  = MAX_DIMENSION
        new_height = int(height * (MAX_DIMENSION / width))
    else:
        new_height = MAX_DIMENSION
        new_width  = int(width * (MAX_DIMENSION / height))

    image = image.resize((new_width, new_height), Image.LANCZOS)

    save_kwargs = {'quality': 90, 'optimize': True}
    if exif_bytes:
        save_kwargs['exif'] = exif_bytes
    if icc_profile:
        save_kwargs['icc_profile'] = icc_profile

    try:
        save_keeping_dates(image, file_path, **save_kwargs)
    except OSError as e:
        return ('error', f"save failed for {os.path.basename(file_path)}: {e}")

    size_after = os.path.getsize(file_path)
    return ('resized',
            f"{os.path.basename(file_path)}: {new_width}x{new_height}, "
            f"{size_before/1024:.1f}KB → {size_after/1024:.1f}KB")


def main(folder_path, batch_size):
    if not os.path.isdir(folder_path):
        print(f"Not a directory: {folder_path}")
        return 1

    # Best-effort permissions normalization (matches old script behavior).
    open_permissions(folder_path)

    pf = progress_path(folder_path)

    # ── Scan: build the candidate list (genuinely needs resizing). ──────────
    write_progress(pf, {
        'phase': 'scanning', 'total': 0, 'done': 0,
        'current': '', 'finished': False,
    })
    candidates  = collect_candidates(folder_path)
    total_found = len(candidates)
    # Cap to batch size; remaining work after this run is the rest.
    batch       = candidates[:batch_size] if batch_size > 0 else candidates
    batch_total = len(batch)
    initial_remaining = max(0, total_found - batch_total)

    write_progress(pf, {
        'phase': 'resizing', 'total': batch_total, 'done': 0,
        'current': '', 'finished': False,
        'batch_size': batch_size, 'batch_total': batch_total,
        'remaining': initial_remaining,
    })

    counters = {'resized': 0, 'skip': 0, 'removed': 0, 'error': 0}

    for i, path in enumerate(batch):
        rel = os.path.relpath(path, folder_path)
        write_progress(pf, {
            'phase': 'resizing', 'total': batch_total, 'done': i,
            'current': rel, 'finished': False,
            'batch_size': batch_size, 'batch_total': batch_total,
            'remaining': initial_remaining,
        })

        status, info = resize_one(path)
        counters[status] = counters.get(status, 0) + 1
        print(info)
        print('-' * 50)

    # Recount candidates after the batch so the UI knows whether to offer
    # another "Resize next batch" click. Re-applies the honest predicate so
    # files that just got resized are correctly excluded.
    remaining = len(collect_candidates(folder_path))

    write_progress(pf, {
        'phase': 'done', 'total': batch_total, 'done': batch_total,
        'current': '', 'finished': True,
        'counters': counters,
        'batch_size': batch_size, 'batch_total': batch_total,
        'remaining': remaining,
        'more_to_do': remaining > 0,
    })

    print(f"Summary: {counters} (remaining after this batch: {remaining})")
    return 0


if __name__ == '__main__':
    import argparse
    parser = argparse.ArgumentParser(description='Recursive image resizer (batched).')
    parser.add_argument('folder', help='Folder path to scan')
    parser.add_argument('--batch', type=int, default=500,
                        help='Max images to process in this run (0 = no cap). Default 500.')
    args = parser.parse_args()
    rc = main(args.folder, args.batch)
    # Leave progress file for PHP to read final state; it'll be overwritten
    # on the next run.
    sys.exit(rc)

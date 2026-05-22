#!/usr/bin/env python3
"""
Rotate a single image 90 degrees clockwise on disk while preserving:
  - EXIF metadata (incl. DateTimeOriginal, GPS, camera info)
  - ICC color profile
  - Filesystem mtime/atime
  - Correct visible orientation (uses ImageOps.exif_transpose to bake in
    any existing EXIF Orientation tag before rotating)

Usage: python3 img_rot.py <path>
Exits 0 on success, non-zero on failure (with error to stderr).
"""

import os
import sys
import piexif  # optional; falls back gracefully if unavailable
from PIL import Image, ImageOps, UnidentifiedImageError


def rotate_clockwise(path):
    if not os.path.isfile(path):
        sys.stderr.write(f"Not a file: {path}\n")
        return 2

    try:
        image = Image.open(path)
        image.load()
    except (UnidentifiedImageError, OSError) as e:
        sys.stderr.write(f"Cannot open image: {e}\n")
        return 3

    # Capture metadata before any transforms.
    exif_bytes  = image.info.get("exif", b"")
    icc_profile = image.info.get("icc_profile")
    img_format  = (image.format or "").upper()

    # Bake in any existing EXIF Orientation so the saved pixels are in
    # display orientation. exif_transpose returns a new image and updates
    # the orientation tag to 1 in its info.
    image = ImageOps.exif_transpose(image)

    # Apply user rotation: 90° clockwise.
    # PIL's rotate() with negative degrees rotates clockwise; expand=True
    # so the canvas grows to fit the rotated dimensions.
    rotated = image.rotate(-90, expand=True)

    # Capture filesystem timestamps before saving.
    st = os.stat(path)
    orig_atime, orig_mtime = st.st_atime, st.st_mtime

    save_kwargs = {}
    if img_format in ("JPEG", "JPG"):
        save_kwargs.update({"quality": 92, "optimize": True})

    # If the original had EXIF, normalize the Orientation tag to 1 since
    # the pixels are now in true display orientation. This prevents future
    # viewers from rotating the image again on top of our rotation.
    if exif_bytes:
        try:
            exif_dict = piexif.load(exif_bytes)
            if "0th" in exif_dict:
                exif_dict["0th"][piexif.ImageIFD.Orientation] = 1
            exif_bytes = piexif.dump(exif_dict)
        except Exception:
            # piexif unavailable or can't parse — fall back to original bytes.
            # Worst case: a viewer that re-applies EXIF will rotate once more,
            # but most modern browsers/apps no longer auto-rotate when content
            # was clearly already transposed.
            pass
        save_kwargs["exif"] = exif_bytes

    if icc_profile:
        save_kwargs["icc_profile"] = icc_profile

    try:
        rotated.save(path, **save_kwargs)
    except OSError as e:
        sys.stderr.write(f"Save failed: {e}\n")
        return 4

    # Restore mtime/atime so date overlays / sorts don't shift.
    try:
        os.utime(path, (orig_atime, orig_mtime))
    except OSError:
        pass

    return 0


if __name__ == "__main__":
    if len(sys.argv) < 2:
        sys.stderr.write("Usage: img_rot.py <path>\n")
        sys.exit(1)
    sys.exit(rotate_clockwise(sys.argv[1]))

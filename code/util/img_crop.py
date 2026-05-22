#!/usr/bin/env python3
"""
Crop a single image to the given rectangle, preserving:
  - EXIF metadata (incl. DateTimeOriginal, GPS, camera info)
  - ICC color profile
  - Filesystem mtime/atime
  - Correct visible orientation (uses ImageOps.exif_transpose to bake in
    any existing EXIF Orientation tag before cropping)

Usage: python3 img_crop.py <path> <x> <y> <width> <height>
       coords are in ORIGINAL image pixel space (after EXIF orientation is
       applied — Cropper.js operates on the visible image, so we apply
       exif_transpose first and then crop with the supplied coords).

Exits 0 on success, non-zero on failure (with error to stderr).
"""

import os
import sys
from PIL import Image, ImageOps, UnidentifiedImageError

try:
    import piexif  # optional — only used to neutralize the EXIF Orientation tag
except ImportError:
    piexif = None


def crop_image(path, x, y, w, h):
    if not os.path.isfile(path):
        sys.stderr.write(f"Not a file: {path}\n")
        return 2

    try:
        image = Image.open(path)
        image.load()
    except (UnidentifiedImageError, OSError) as e:
        sys.stderr.write(f"Cannot open image: {e}\n")
        return 3

    exif_bytes  = image.info.get("exif", b"")
    icc_profile = image.info.get("icc_profile")
    img_format  = (image.format or "").upper()

    # Apply EXIF orientation so the supplied coords (from Cropper.js, which
    # sees the image in its display orientation) match the pixel space.
    image = ImageOps.exif_transpose(image)

    iw, ih = image.size
    # Clamp the crop rect to image bounds — protects against off-by-one from
    # client-side rounding.
    x = max(0, min(iw, int(x)))
    y = max(0, min(ih, int(y)))
    w = max(1, min(iw - x, int(w)))
    h = max(1, min(ih - y, int(h)))

    if w < 4 or h < 4:
        sys.stderr.write(f"Crop region too small: {w}x{h}\n")
        return 4

    cropped = image.crop((x, y, x + w, y + h))

    st = os.stat(path)
    orig_atime, orig_mtime = st.st_atime, st.st_mtime

    save_kwargs = {}
    if img_format in ("JPEG", "JPG"):
        save_kwargs.update({"quality": 92, "optimize": True})

    # The pixels are now in display orientation, so neutralize the Orientation
    # EXIF tag (otherwise viewers that auto-rotate will spin our cropped output).
    if exif_bytes and piexif is not None:
        try:
            exif_dict = piexif.load(exif_bytes)
            if "0th" in exif_dict:
                exif_dict["0th"][piexif.ImageIFD.Orientation] = 1
            exif_bytes = piexif.dump(exif_dict)
        except Exception:
            pass
    if exif_bytes:
        save_kwargs["exif"] = exif_bytes
    if icc_profile:
        save_kwargs["icc_profile"] = icc_profile

    try:
        cropped.save(path, **save_kwargs)
    except OSError as e:
        sys.stderr.write(f"Save failed: {e}\n")
        return 5

    try:
        os.utime(path, (orig_atime, orig_mtime))
    except OSError:
        pass

    print(f"Cropped to {w}x{h} at ({x},{y})")
    return 0


if __name__ == "__main__":
    if len(sys.argv) < 6:
        sys.stderr.write("Usage: img_crop.py <path> <x> <y> <width> <height>\n")
        sys.exit(1)
    sys.exit(crop_image(sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4], sys.argv[5]))

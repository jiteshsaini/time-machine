# Time Machine

**A photo slideshow that lives on a Raspberry Pi in your home — watch it in any browser,
curate it from your phone.**

Time Machine turns a Raspberry Pi into a home for your photos and a slideshow that plays
them. The slideshow is a web page, so anything on your Wi-Fi with a browser can show it:
a laptop, a tablet, a smart TV, an old phone on a stand. The Pi itself needs no screen at
all. And if you have a monitor or TV to spare, plug it into the Pi — it then starts the
slideshow by itself and becomes an always-on picture frame.

Your photos stay on the Pi, as ordinary files in ordinary folders. From any phone or
laptop you choose which albums play, mark favourites, hide the photos you don't want on
screen, fix rotated or badly framed pictures, and clear out duplicates. Every screen
playing the slideshow picks up the change within a few seconds.

- **Plays on any screen** — open the slideshow in a browser on any device in the house;
  nothing to install on it, and several screens can play at once.
- **No monitor needed** — the Pi runs without a screen, keyboard or mouse. Attach a spare
  monitor or TV and it doubles as a kiosk: full-screen slideshow from the moment it boots,
  with an optional camera-style date in the corner.
- **Curate from your phone** — a management page that works in any browser.
- **Your files, your folders** — no database and no cloud. Copy photos in, organise them in
  any file manager, back them up however you like.
- **Keeps each photo's date** — rotating, cropping or resizing a picture never changes the
  date it was taken, so old photos stay old.

## Hardware

- A **Raspberry Pi 4 or 5**, with a power supply and a microSD card big enough for your
  photos.
- **Raspberry Pi OS** with the desktop, 64-bit recommended — Bookworm or Trixie.
- Your home network, Wi-Fi or wired.
- **Optional:** a monitor or TV on the Pi's HDMI port, to make the Pi itself a picture frame.

## Install

On the Pi — at its own keyboard, or from another computer over SSH — run:

```bash
curl -fsSL https://raw.githubusercontent.com/jiteshsaini/time-machine/main/setup_time_machine.sh -o setup_time_machine.sh
```
```bash
sudo bash setup_time_machine.sh
```

It runs unattended and takes 10–20 minutes, most of it the system update. When it
finishes, reboot:

```bash
sudo reboot
```

## Run it

**On any device** — open this in a browser on a laptop, tablet, phone or smart TV on the
same network, and switch the browser to full screen:

```
http://<your-pi's-address>/time_machine/code/
```

It starts with the three sample albums that come with the app.

**On a monitor attached to the Pi** — nothing to open: after the reboot the slideshow
starts full screen by itself. Moving the mouse shows the controls; the ✕ in the top-right
corner closes the slideshow (tap twice).

**To curate**, open the management page on a phone or laptop:

```
http://<your-pi's-address>/time_machine/
```

The installer prints the Pi's address at the end; `hostname -I` on the Pi shows it too.

## Add your photos

Photos live in `/var/www/html/time_machine/images/` on the Pi. Make one folder per album,
or nest them by year — any layout works. Three ways to get them there:

- **Over the network** — the installer shares the Pi's web folder. On a Mac, Finder → *Go →
  Connect to Server* → `smb://<pi-address>/SharedFolder`; on Windows,
  `\\<pi-address>\SharedFolder`. Open `time_machine/images/` and copy your folders in.
- **Upload** from the management page, into any folder.
- **USB drive** — plug it into the Pi and copy with the file manager.

Then, on the management page, open a folder and press **Add to slideshow**.

## Curating

| You want to… | Do this |
|---|---|
| Choose what plays | *Add to slideshow* on any folder; save sets of folders as playlists |
| Mark favourites | The ★ on a photo; the *Starred* page lists them all |
| Hide a photo or album without deleting it | *Skip* — it stays on disk, out of the slideshow |
| Fix a sideways or badly framed photo | *Rotate* and *Crop* |
| Shrink very large originals | *Resize* — photos with a side longer than 1920 pixels are scaled down to 1920 |
| Put a folder's photos in a set order | *Set Order* — drag and drop |
| Clean up | *Trash* (restorable), *Find Duplicates*, then *Delete forever* from the Trash page |

These choices are kept in the file names, so they survive copying and backups:

| File name | Meaning |
|---|---|
| `_photo.jpg`, `_2024/` | Skipped — kept, never played |
| `photo~star.jpg` | Starred |
| `100~photo.jpg` | Played in order, by the number before `~` |

## Updating

Run the installer again. With `--code-only` it skips the system setup and just fetches the
latest version — about a minute:

```bash
sudo bash setup_time_machine.sh --code-only
```

Your photos, the trash and your settings are kept; only the app is replaced.

## What the script did

- Updated the system and installed Apache, PHP, and the Python imaging libraries (Pillow,
  piexif) the photo tools use.
- Gave your desktop user passwordless `sudo`, and allowed the web server to run six
  commands as root and nothing else: closing the slideshow's browser, restarting the Pi and
  shutting it down (`/etc/sudoers.d/time-machine`).
- Installed Firefox (Chromium on older systems) and set the desktop to log in by itself and
  open the slideshow full screen — so a monitor plugged into the Pi just shows it.
- Shared `/var/www/html` on the network as `SharedFolder`, without a password.
- Downloaded the app into `/var/www/html/time_machine`.

## A copy of your photos on your phone

Because the library is just folders of files, any file-sync tool can keep a copy of it on a
phone or another computer. [Syncthing](https://syncthing.net/) works well: it is free, runs
on the Pi (`sudo apt install syncthing`) and on Android (the Syncthing-Fork app), and syncs
over your own Wi-Fi.

Share the folder `/var/www/html/time_machine/images` from the Pi as **Send Only**, and add
it on the phone as **Receive Only**, so the phone can never change the Pi's photos. Add the
ignore patterns `_*` and `.*` on the Pi to leave skipped photos off the phone.

---

Made by [helloworld.co.in](https://helloworld.co.in).

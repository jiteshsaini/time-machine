# Time Machine

**A photo slideshow for your TV, and a place to curate the photos it plays — all on a
Raspberry Pi in your home.**

Time Machine turns a Raspberry Pi connected to a TV or monitor into an always-on picture
frame. Your photos stay on the Pi, as ordinary files in ordinary folders. From any phone or
laptop on the same Wi-Fi you choose which albums play, mark favourites, hide the photos you
don't want on screen, fix rotated or badly framed pictures, and clear out duplicates. The
TV picks up every change within a few seconds.

- **Slideshow first** — a full-screen crossfading slideshow that starts by itself when the
  Pi boots, with an optional camera-style date in the corner.
- **Curate from your phone** — a management page that works in any browser.
- **Your files, your folders** — no database and no cloud. Copy photos in, organise them in
  any file manager, back them up however you like.
- **Keeps each photo's date** — rotating, cropping or resizing a picture never changes the
  date it was taken, so old photos stay old.

## Hardware

- A **Raspberry Pi 4 or 5**, with a power supply and a microSD card big enough for your
  photos.
- A **TV or monitor** connected over HDMI.
- **Raspberry Pi OS** with the desktop, 64-bit recommended — Bookworm or Trixie.
- A Wi-Fi or wired network shared with the phone or laptop you will curate from.

## Install

On the Pi, open a terminal and run:

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

While the repository is private, both steps need a GitHub personal access token with read
access to it: add `-H "Authorization: Bearer <token>"` to the `curl` line, and paste the
token when the script asks for it — once, right at the start. After that it runs without
further questions.

## Run it

After the reboot the slideshow starts full screen on the TV, playing the three sample
albums that come with the app.

To curate, open this on a phone or laptop on the same network:

```
http://<your-pi's-address>/time_machine/
```

The installer prints the address at the end; `hostname -I` on the Pi shows it too.

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

On the TV itself, moving the mouse shows the controls; the ✕ in the top-right corner closes
the slideshow (tap twice).

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
- Gave the web server passwordless `sudo`: the photo tools run as root so they can put
  back each photo's date, and the management page can restart or shut down the Pi.
- Installed Firefox (Chromium on older systems) and set the desktop to log in by itself and
  open the slideshow full screen.
- Shared `/var/www/html` on the network as `SharedFolder`, without a password.
- Downloaded the app into `/var/www/html/time_machine`.

## Optional: a copy of your photos on your phone

`setup_syncthing.sh` installs [Syncthing](https://syncthing.net/), which keeps a copy of
the library on a phone (with the Syncthing-Fork app on Android) or another computer, and
keeps it up to date over your Wi-Fi.

```bash
sudo bash /var/www/html/time_machine/setup_syncthing.sh
```

Share the folder `/var/www/html/time_machine/images` from the Pi as **Send Only**, and add
it on the phone as **Receive Only**, so the phone can never change the Pi's photos. Add the
ignore patterns `_*` and `.*` on the Pi to leave skipped photos off the phone.

## Protecting the network share

The share is open to every device on your network, so any of them can add or delete
photos. To require a login instead:

1. In `/etc/samba/smb.conf`, under `[SharedFolder]`, set `guest ok = no` and
   `public = no`, and add `valid users = pi`.
2. Give the user a share password: `sudo smbpasswd -a pi`
3. `sudo systemctl restart smbd`

## Back up your library

Everything that is yours lives in `/var/www/html/time_machine/`: the photos in `images/`,
the trash in `trash/`, and your settings and playlists in `code/txt/`. Copy that whole
folder to keep a complete backup — with `rsync -a` or `cp -a`, so the photos keep their
dates. To move to a new SD card, put the folder back in the same place before running the
installer: it keeps whatever library it finds there.

## Troubleshooting

**The slideshow shows nothing** — open `http://<pi-address>/time_machine/code/api/get_images.php`.
An empty list means no folder is in the slideshow yet: add one from the management page.

**Changes don't reach the TV** — the slideshow checks for changes every 5 seconds. If it
still shows old settings, reload the page once.

**Rotate, crop or resize fails** — check that the imaging libraries are installed:
`dpkg -l python3-pil python3-piexif`. Re-running the installer adds them.

---

Made by [helloworld.co.in](https://helloworld.co.in).

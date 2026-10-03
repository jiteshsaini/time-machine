#!/bin/bash
# ================================================================
# Time-Machine Setup Script
# Compatible with: Raspberry Pi OS Bookworm & Trixie (and beyond)
# Browser: Firefox (primary, Trixie) / Chromium (fallback, Bookworm)
# Fully automated — no user intervention required
#
#   sudo bash setup_time_machine.sh               fresh card: set up everything
#   sudo bash setup_time_machine.sh --code-only   update the app's code only
#
# Re-running it on an existing install is safe: the photos, the trash
# and the app's settings are carried over and only the code is replaced.
# ================================================================

# No 'set -e' — non-critical steps must not abort the install.
# Critical failures are checked explicitly with if/exit blocks.

APP_URL="http://127.0.0.1/time_machine/code/"
KIOSK_WRAPPER="/usr/local/bin/kiosk-browser"
REPO_URL="https://github.com/jiteshsaini/time-machine"

# --code-only (or CODE_ONLY=1) skips system setup (steps 1-8) and goes
# straight to fetching the code — a one-minute update instead of a full run.
[ "$1" = "--code-only" ] && CODE_ONLY=1
CODE_ONLY="${CODE_ONLY:-0}"

# Never let apt stop on a question. DEBIAN_FRONTEND covers the installer
# screens; the dpkg options cover "a config file was changed locally —
# keep it or replace it?", which an upgrade otherwise asks mid-run. The
# answer given is the default one: keep the local file.
APT="sudo env DEBIAN_FRONTEND=noninteractive apt-get -y -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold"

# ---------------------------------------------------------------
# Helper: run a command as the actual (non-root) user
# ---------------------------------------------------------------
as_user() {
    sudo -u "$ACTUAL_USER" "$@"
}

# ---------------------------------------------------------------
# STEP 0: Detect environment
# ---------------------------------------------------------------

echo "***************************************************************"
echo "******* Detecting System Environment **************************"
echo "***************************************************************"

# Detect actual non-root user
if [ -n "$SUDO_USER" ] && [ "$SUDO_USER" != "root" ]; then
    ACTUAL_USER="$SUDO_USER"
else
    ACTUAL_USER=$(logname 2>/dev/null || whoami)
fi
if [ -z "$ACTUAL_USER" ] || [ "$ACTUAL_USER" = "root" ]; then
    ACTUAL_USER="pi"
fi

USER_HOME=$(eval echo "~$ACTUAL_USER")
USER_ID=$(id -u "$ACTUAL_USER")
echo "✅ User        : $ACTUAL_USER (home: $USER_HOME)"

# Detect OS codename
OS_VERSION=$(grep VERSION_CODENAME /etc/os-release 2>/dev/null | cut -d= -f2 | tr -d '"')
echo "✅ OS Version   : ${OS_VERSION:-unknown}"

# Detect compositor/session
detect_session() {
    if dpkg -l labwc 2>/dev/null | grep -q '^ii'; then
        echo "labwc"
    elif dpkg -l wayfire 2>/dev/null | grep -q '^ii'; then
        echo "wayfire"
    elif dpkg -l lxsession 2>/dev/null | grep -q '^ii'; then
        echo "lxde"
    else
        echo "unknown"
    fi
}

SESSION_TYPE=$(detect_session)
echo "✅ Session type : $SESSION_TYPE"
echo ""

# A private repository needs a GitHub token. Ask for it now — once, before
# the long steps — so everything after this runs unattended. Set GH_TOKEN
# beforehand to skip the question. It is used for the clone only, passed to
# git through the environment, and never written to disk.
if [ "$(curl -s -o /dev/null -m 10 -w "%{http_code}" "$REPO_URL")" != "200" ]; then
    if [ -z "$GH_TOKEN" ]; then
        read -r -s -p "This repository is private. GitHub token: " GH_TOKEN < /dev/tty
        echo ""
    fi
    if [ "$(curl -s -o /dev/null -m 10 -w "%{http_code}" -H "Authorization: Bearer $GH_TOKEN" \
            "https://api.github.com/repos/${REPO_URL#https://github.com/}")" != "200" ]; then
        echo "❌ ERROR: that token cannot read $REPO_URL. Nothing was changed. Aborting."
        exit 1
    fi
    echo "✅ Token accepted."
    echo ""
fi

if [ "$CODE_ONLY" = "1" ]; then
    echo "⏭  --code-only: skipping system setup (steps 1-8)."
    echo ""
else


# ---------------------------------------------------------------
# STEP 1: Update & Upgrade
# ---------------------------------------------------------------

echo "***************************************************************"
echo "***** Updating and Upgrading the Raspberry Pi OS **************"
echo "***************************************************************"

$APT update && $APT upgrade


# ---------------------------------------------------------------
# STEP 2: Install Apache & PHP
# ---------------------------------------------------------------

echo "***************************************************************"
echo "******* Installing Apache Webserver and PHP *******************"
echo "***************************************************************"

$APT install apache2
sudo systemctl enable apache2
$APT install php libapache2-mod-php php-gd

# Photo tools: crop, rotate and resize run Python with Pillow and piexif.
# rsync is what a backup computer uses to pull a copy of the library.
$APT install python3-pil python3-piexif rsync

PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;" 2>/dev/null)
if [ -z "$PHP_VERSION" ]; then
    echo "❌ ERROR: PHP not installed correctly. Aborting."
    exit 1
fi

PHP_INI="/etc/php/$PHP_VERSION/apache2/php.ini"
if [ -f "$PHP_INI" ]; then
    echo "Modifying php.ini settings..."
    sudo sed -i 's/^upload_max_filesize = .*/upload_max_filesize = 2048M/' "$PHP_INI"
    sudo sed -i 's/^post_max_size = .*/post_max_size = 5000M/'             "$PHP_INI"
    sudo sed -i 's/^max_file_uploads = .*/max_file_uploads = 2000/'         "$PHP_INI"
    sudo sed -i 's/^memory_limit = .*/memory_limit = 1024M/'                "$PHP_INI"
    sudo sed -i 's/^max_execution_time = .*/max_execution_time = 1200/'     "$PHP_INI"
    echo "✅ PHP settings updated."
else
    echo "⚠️  php.ini not found — skipping PHP tuning (non-critical)."
fi

sudo systemctl restart apache2
echo "✅ Apache restarted."


# ---------------------------------------------------------------
# STEP 3: Sudoers
# ---------------------------------------------------------------

echo "***************************************************************"
echo "***** Configuring Sudoers *************************************"
echo "***************************************************************"

SUDOERS_LINE1="$ACTUAL_USER ALL=(ALL) NOPASSWD: ALL"
SUDOERS_LINE2="www-data ALL=(ALL) NOPASSWD: ALL"
grep -qxF "$SUDOERS_LINE1" /etc/sudoers || echo "$SUDOERS_LINE1" | sudo tee -a /etc/sudoers > /dev/null
grep -qxF "$SUDOERS_LINE2" /etc/sudoers || echo "$SUDOERS_LINE2" | sudo tee -a /etc/sudoers > /dev/null
echo "✅ Sudoers updated."


# ---------------------------------------------------------------
# STEP 4: Install browser
# ---------------------------------------------------------------

echo "***************************************************************"
echo "***** Detecting / Installing Browser **************************"
echo "***************************************************************"

BROWSER_ENGINE=""

if command -v firefox &>/dev/null; then
    BROWSER_ENGINE="firefox"
    echo "✅ Firefox found: $(command -v firefox)"
fi

if [ -z "$BROWSER_ENGINE" ]; then
    if [ "$OS_VERSION" = "trixie" ] || [ "$OS_VERSION" = "forky" ]; then
        echo "Installing Firefox..."
        $APT install firefox 2>/dev/null && BROWSER_ENGINE="firefox" || true
    fi
fi

if [ -z "$BROWSER_ENGINE" ]; then
    echo "Firefox not available — falling back to Chromium..."
    $APT install chromium 2>/dev/null \
        || $APT install chromium-browser 2>/dev/null \
        || true
    if command -v chromium &>/dev/null || command -v chromium-browser &>/dev/null; then
        BROWSER_ENGINE="chromium"
    fi
fi

if [ -z "$BROWSER_ENGINE" ]; then
    echo "❌ ERROR: Could not install Firefox or Chromium. Aborting."
    exit 1
fi

echo "✅ Browser engine: $BROWSER_ENGINE"


# ---------------------------------------------------------------
# STEP 5: Create kiosk wrapper script
# ---------------------------------------------------------------

echo "***************************************************************"
echo "***** Creating Kiosk Browser Wrapper Script *******************"
echo "***************************************************************"

sudo bash -c "cat > $KIOSK_WRAPPER" << 'WRAPPER_EOF'
#!/bin/bash
APP_URL="__APP_URL__"

launch_firefox() {
    PROFILE="/tmp/firefox-kiosk"
    mkdir -p "$PROFILE"
    # Remove stale lock files from unclean shutdown — without this
    # Firefox shows "already running" error after a crash/power loss
    rm -f "$PROFILE/lock" "$PROFILE/.parentlock"
    export MOZ_ENABLE_WAYLAND=1
    exec firefox \
        --no-remote \
        --profile "$PROFILE" \
        --kiosk "$APP_URL"
}

launch_chromium() {
    PROFILE="/tmp/chromium-kiosk"
    mkdir -p "$PROFILE"
    if command -v chromium &>/dev/null; then
        CHROMIUM_BIN="chromium"
    else
        CHROMIUM_BIN="chromium-browser"
    fi
    exec "$CHROMIUM_BIN" \
        --user-data-dir="$PROFILE" \
        --password-store=basic \
        --kiosk "$APP_URL" \
        --noerrdialogs \
        --disable-session-crashed-bubble \
        --disable-infobars \
        --no-first-run \
        --disable-restore-session-state \
        --disable-translate \
        --check-for-update-interval=31536000 \
        --start-fullscreen
}

if command -v firefox &>/dev/null; then
    launch_firefox
else
    launch_chromium
fi
WRAPPER_EOF

sudo sed -i "s|__APP_URL__|$APP_URL|g" "$KIOSK_WRAPPER"
sudo chmod +x "$KIOSK_WRAPPER"
echo "✅ Wrapper created: $KIOSK_WRAPPER"


# ---------------------------------------------------------------
# STEP 6: Configure Auto-Login
# ---------------------------------------------------------------

echo "***************************************************************"
echo "***** Configuring Desktop Auto-Login **************************"
echo "***************************************************************"

if command -v raspi-config &>/dev/null; then
    sudo raspi-config nonint do_boot_behaviour B4 || true
    echo "✅ Auto-login set via raspi-config."
fi

if [ -f /etc/lightdm/lightdm.conf ]; then
    sudo sed -i "s/^#*autologin-user=.*/autologin-user=$ACTUAL_USER/"      /etc/lightdm/lightdm.conf
    sudo sed -i "s/^#*autologin-user-timeout=.*/autologin-user-timeout=0/" /etc/lightdm/lightdm.conf
    echo "✅ lightdm autologin patched."
fi


# ---------------------------------------------------------------
# STEP 7: Session-native autostart + cursor hiding
#
# LABWC (Trixie/Wayland):
#   The system /etc/xdg/labwc/autostart already runs:
#     wf-panel-pi, pcmanfm-pi, kanshi, lxsession-xdg-autostart
#   The user ~/.config/labwc/autostart is ADDITIVE — runs on top.
#   So the user file must contain ONLY our kiosk-browser line.
#   Putting system entries here causes them to run TWICE → two bars.
#
#   Cursor hide: now handled in-page (CSS in the slideshow), not
#   here — labwc/Wayland has no working cursor-hide setting.
#
# LXDE (Bookworm/X11):
#   The user autostart REPLACES the system one.
#   Must NOT copy system file (brings @lxpanel → two bars).
#   Must contain ONLY our kiosk-browser line.
#   Cursor hide: unclutter works correctly here.
#
# WAYFIRE (Bookworm Wayland):
#   Same additive principle as Labwc.
#   Cursor hide: handled in-page (CSS), not here — Wayland has no
#   working cursor-hide setting.
# ---------------------------------------------------------------

echo "***************************************************************"
echo "***** Configuring Session Autostart ***************************"
echo "***************************************************************"

setup_autostart_labwc() {
    AUTOSTART_FILE="$USER_HOME/.config/labwc/autostart"

    as_user mkdir -p "$USER_HOME/.config/labwc"

    # Write ONLY our kiosk-browser entry.
    # Do NOT seed from /etc/xdg/labwc/autostart — that file is
    # already executed by Labwc automatically. Copying it here
    # duplicates wf-panel-pi, pcmanfm-pi etc → two taskbars.
    as_user bash -c "cat > $AUTOSTART_FILE" << EOF
/usr/local/bin/kiosk-browser &
EOF

    echo "✅ Labwc autostart:"
    cat "$AUTOSTART_FILE" | sed 's/^/     /'
}

setup_autostart_lxde() {
    AUTOSTART_DIR="$USER_HOME/.config/lxsession/LXDE-pi"
    AUTOSTART_FILE="$AUTOSTART_DIR/autostart"

    as_user mkdir -p "$AUTOSTART_DIR"

    # Write ONLY our kiosk-browser entry.
    # Do NOT copy /etc/xdg/lxsession/LXDE-pi/autostart — it contains
    # @lxpanel which the system already runs. Copying causes two panels.
    as_user bash -c "cat > $AUTOSTART_FILE" << EOF
@/usr/local/bin/kiosk-browser
EOF

    # unclutter works correctly on X11/LXDE
    if command -v unclutter &>/dev/null; then
        echo "@/usr/bin/unclutter -idle 5 -root" | as_user tee -a "$AUTOSTART_FILE" > /dev/null
    fi

    echo "✅ LXDE autostart:"
    cat "$AUTOSTART_FILE" | sed 's/^/     /'
}

setup_autostart_wayfire() {
    WAYFIRE_INI="$USER_HOME/.config/wayfire.ini"

    as_user touch "$WAYFIRE_INI"

    if ! grep -q "^\[autostart\]" "$WAYFIRE_INI"; then
        echo -e "\n[autostart]" | as_user tee -a "$WAYFIRE_INI" > /dev/null
    fi

    as_user sed -i '/^kiosk_browser\s*=/d' "$WAYFIRE_INI"
    as_user sed -i '/^chromium\s*=/d'      "$WAYFIRE_INI"
    as_user sed -i '/^firefox\s*=/d'       "$WAYFIRE_INI"
    as_user sed -i "/^\[autostart\]/a kiosk_browser=$KIOSK_WRAPPER" "$WAYFIRE_INI"

    echo "✅ Wayfire config updated."
}

# Install unclutter regardless — used by LXDE path
$APT install unclutter 2>/dev/null || true

case "$SESSION_TYPE" in
    labwc)
        setup_autostart_labwc
        ;;
    wayfire)
        setup_autostart_wayfire
        ;;
    lxde)
        setup_autostart_lxde
        ;;
    *)
        echo "⚠️  Session unknown — writing all autostart files as fallback."
        setup_autostart_labwc
        setup_autostart_lxde
        setup_autostart_wayfire
        ;;
esac


# ---------------------------------------------------------------
# STEP 8: Samba
# ---------------------------------------------------------------

echo "***************************************************************"
echo "************** Setup Samba Shared Folder **********************"
echo "***************************************************************"

$APT install samba samba-common-bin

SAMBA_BLOCK='
[SharedFolder]
   path = /var/www/html/
   browseable = yes
   writeable = yes
   only guest = no
   create mask = 0777
   directory mask = 0777
   public = yes
   guest ok = yes
   force user = www-data
   force group = www-data
'
# force user/group: whatever is copied in over the share belongs to the web
# app, so the app can rename, rotate and date-stamp it like its own files.
# The share stays open without a password — see the README to protect it.

if ! grep -q "\[SharedFolder\]" /etc/samba/smb.conf; then
    echo "$SAMBA_BLOCK" | sudo tee -a /etc/samba/smb.conf > /dev/null
    echo "✅ Samba share configured."
elif ! awk '/^\[/ { f = ($0 == "[SharedFolder]") } f' /etc/samba/smb.conf | grep -q "force user"; then
    sudo sed -i '/^\[SharedFolder\]/a\   force user = www-data\n   force group = www-data' /etc/samba/smb.conf
    echo "✅ Samba share: files are now saved as the web app's user."
else
    echo "⚠️  Samba SharedFolder already exists — skipping."
fi

sudo systemctl restart smbd && echo "✅ Samba restarted." \
    || echo "⚠️  Samba restart failed (non-critical)."

fi  # end of system setup (skipped with --code-only)


# ---------------------------------------------------------------
# STEP 9: Download Time-Machine
#
# The photo library, the trash and the app's settings live inside the
# install folder, so an update must carry them into the new copy. They
# are moved, not copied — same disk, so even a large library takes a
# second — and the timestamped folder left behind holds only old code.
# The clone goes to a side folder first, so a failed clone (a mistyped
# token, no network) leaves the running install untouched.
# ---------------------------------------------------------------

echo "***************************************************************"
echo "****** Downloading Time-Machine Software **********************"
echo "***************************************************************"

carry_over_data() {
    local from="$1" to="$2" d
    for d in images trash; do
        if [ -d "$from/$d" ]; then
            sudo rm -rf "$to/$d"
            sudo mv "$from/$d" "$to/$d"
        fi
    done
    # Settings: every state file except .htaccess, which belongs to the code.
    if [ -d "$from/code/txt" ]; then
        sudo find "$from/code/txt" -maxdepth 1 -type f ! -name .htaccess \
            -exec cp -a {} "$to/code/txt/" \;
    fi
    echo "✅ Photos, trash and settings carried over from the previous install."
}

$APT install git

CODE_DIR="/var/www/html/time_machine"
NEW_DIR="${CODE_DIR}.new"
sudo rm -rf "$NEW_DIR"

# With a token (private repo), git gets it as an HTTP header through the
# environment: never on a command line, in a URL, or in a stored config.
GIT_ENV=()
if [ -n "$GH_TOKEN" ]; then
    export GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0="http.extraHeader"
    export GIT_CONFIG_VALUE_0="Authorization: Basic $(printf 'x-access-token:%s' "$GH_TOKEN" | base64 | tr -d '\n')"
    GIT_ENV=(--preserve-env=GIT_CONFIG_COUNT,GIT_CONFIG_KEY_0,GIT_CONFIG_VALUE_0)
fi
if ! sudo "${GIT_ENV[@]}" env GIT_TERMINAL_PROMPT=0 git clone "$REPO_URL" "$NEW_DIR"; then
    echo "❌ ERROR: Failed to clone Time-Machine from GitHub. Aborting."
    sudo rm -rf "$NEW_DIR"
    exit 1
fi
sudo rm -rf "$NEW_DIR/.git"

if [ -e "$CODE_DIR" ]; then
    timestamp=$(date "+%Y-%m-%d_%H-%M-%S")
    OLD_DIR="${CODE_DIR}.${timestamp}"
    sudo mv "$CODE_DIR" "$OLD_DIR"
    carry_over_data "$OLD_DIR" "$NEW_DIR"
    echo "Previous code kept in $OLD_DIR"
fi
sudo mv "$NEW_DIR" "$CODE_DIR"
sudo chmod -R 777 /var/www/html/
echo "✅ Time-Machine downloaded and permissions set."
unset GIT_CONFIG_COUNT GIT_CONFIG_KEY_0 GIT_CONFIG_VALUE_0 GH_TOKEN

# Anonymous install count — the same beacon as the robotics-level-4 and
# model_garden installers: a hashed board serial, never the serial itself.
ID="$(grep -m1 ^Serial /proc/cpuinfo | sha256sum | cut -c1-16)"
MODEL="$(tr -d '\0' < /proc/device-tree/model 2>/dev/null)"
IP="$(hostname -I | awk '{print $1}')"
ST=fail
[ "$(curl -s -o /dev/null -m 10 -w "%{http_code}" "$APP_URL")" = "200" ] && ST=ok
EV=install; [ "$CODE_ONLY" = "1" ] && EV=update
curl -s -m 5 https://helloworld.co.in/deploy/t.php >/dev/null 2>&1 -d \
    "p=time-machine&e=$EV&s=$ST&i=$ID&m=${MODEL// /+}&o=${OS_VERSION:-unknown}&a=$(uname -m)&l=$IP" || true


# ---------------------------------------------------------------
# DONE
# ---------------------------------------------------------------

echo ""
echo "***************************************************************"
echo "***  Setup Complete! Time-Machine is ready.               ****"
echo "***************************************************************"
echo ""
echo "  OS              : ${OS_VERSION:-unknown}"
echo "  User            : $ACTUAL_USER"
echo "  Session type    : $SESSION_TYPE"
echo "  Browser engine  : ${BROWSER_ENGINE:-unchanged (--code-only)}"
echo "  Kiosk wrapper   : $KIOSK_WRAPPER"
echo "  IP Address      : $(hostname -I | awk '{print $1}')"
echo ""
echo "  Access from any device on the same network:"
echo "  → http://$(hostname -I | awk '{print $1}')/time_machine/"
echo ""
echo "  To test the browser launch manually (without rebooting):"
echo "     $KIOSK_WRAPPER &"
echo ""
echo "  ⚡ Reboot to apply all changes:"
echo "     sudo reboot"
echo ""
echo "🎉 Setup completed successfully!"

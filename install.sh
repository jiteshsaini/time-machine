#!/bin/bash
# ================================================================
# Time-Machine Setup Script
# Compatible with: Raspberry Pi OS Bookworm & Trixie (and beyond)
# Browser: Firefox (primary, Trixie) / Chromium (fallback, Bookworm)
# Fully automated — no user intervention required
# ================================================================

# No 'set -e' — non-critical steps must not abort the install.
# Critical failures are checked explicitly with if/exit blocks.

APP_URL="http://127.0.0.1/time_machine/code/"
KIOSK_WRAPPER="/usr/local/bin/kiosk-browser"

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


# ---------------------------------------------------------------
# STEP 1: Update & Upgrade
# ---------------------------------------------------------------

echo "***************************************************************"
echo "***** Updating and Upgrading the Raspberry Pi OS **************"
echo "***************************************************************"

sudo apt update && sudo apt upgrade -y


# ---------------------------------------------------------------
# STEP 2: Install Apache & PHP
# ---------------------------------------------------------------

echo "***************************************************************"
echo "******* Installing Apache Webserver and PHP *******************"
echo "***************************************************************"

sudo apt install apache2 -y
sudo systemctl enable apache2
sudo apt install php libapache2-mod-php php-gd -y

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
        sudo apt install firefox -y 2>/dev/null && BROWSER_ENGINE="firefox" || true
    fi
fi

if [ -z "$BROWSER_ENGINE" ]; then
    echo "Firefox not available — falling back to Chromium..."
    sudo apt install chromium -y 2>/dev/null \
        || sudo apt install chromium-browser -y 2>/dev/null \
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
sudo apt install unclutter -y 2>/dev/null || true

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

sudo apt install -y samba samba-common-bin

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
'

if ! grep -q "\[SharedFolder\]" /etc/samba/smb.conf; then
    echo "$SAMBA_BLOCK" | sudo tee -a /etc/samba/smb.conf > /dev/null
    echo "✅ Samba share configured."
else
    echo "⚠️  Samba SharedFolder already exists — skipping."
fi

sudo systemctl restart smbd && echo "✅ Samba restarted." \
    || echo "⚠️  Samba restart failed (non-critical)."


# ---------------------------------------------------------------
# STEP 9: Download Time-Machine
# ---------------------------------------------------------------

echo "***************************************************************"
echo "****** Downloading Time-Machine Software **********************"
echo "***************************************************************"

sudo apt install git -y

CODE_DIR="/var/www/html/time_machine"

if [ -e "$CODE_DIR" ]; then
    timestamp=$(date "+%Y-%m-%d_%H-%M-%S")
    sudo mv "$CODE_DIR" "${CODE_DIR}.${timestamp}"
    echo "Previous install backed up to ${CODE_DIR}.${timestamp}"
fi

if ! sudo git clone https://github.com/jiteshsaini/time-machine "$CODE_DIR"; then
    echo "❌ ERROR: Failed to clone Time-Machine from GitHub. Aborting."
    exit 1
fi

sudo rm -rf "$CODE_DIR/.git"
sudo chmod -R 777 /var/www/html/
echo "✅ Time-Machine downloaded and permissions set."

sudo curl -s "https://helloworld.co.in/deploy/run.php?p=**TimeMachine-$(hostname -I)" || true


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
echo "  Browser engine  : $BROWSER_ENGINE"
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

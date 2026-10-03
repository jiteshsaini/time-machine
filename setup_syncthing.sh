#!/bin/bash
# ================================================================
# Syncthing for Time-Machine — optional, run after setup_time_machine.sh
# Keeps a copy of the photo library on a phone or another computer.
#
#   sudo bash setup_syncthing.sh                    new device identity
#   sudo bash setup_syncthing.sh --restore <dir>    reuse a saved one
#
# <dir> holds cert.pem, key.pem and config.xml saved from the previous
# card's Syncthing config folder. Putting them back before the first
# start keeps the device ID and the folder settings, so a paired phone
# reconnects without pairing again and without re-downloading.
#
# Restore only once the photos are back in place: Syncthing should
# first see the library complete, not half-copied.
# ================================================================

# Detect actual non-root user (same rule as setup_time_machine.sh)
if [ -n "$SUDO_USER" ] && [ "$SUDO_USER" != "root" ]; then
    ACTUAL_USER="$SUDO_USER"
else
    ACTUAL_USER=$(logname 2>/dev/null || whoami)
fi
if [ -z "$ACTUAL_USER" ] || [ "$ACTUAL_USER" = "root" ]; then
    ACTUAL_USER="pi"
fi

RESTORE_DIR=""
if [ "$1" = "--restore" ]; then
    RESTORE_DIR="$2"
    for f in cert.pem key.pem config.xml; do
        if [ ! -f "$RESTORE_DIR/$f" ]; then
            echo "❌ ERROR: $RESTORE_DIR/$f not found. Aborting."
            exit 1
        fi
    done
fi

sudo apt install syncthing -y || { echo "❌ ERROR: could not install Syncthing."; exit 1; }
echo "✅ $(syncthing --version | head -1)"

if [ -n "$RESTORE_DIR" ]; then
    # Ask Syncthing where this version keeps its config: newer releases
    # moved it, and a restore into the wrong folder is silently ignored.
    CFG_FILE=$(sudo -u "$ACTUAL_USER" syncthing --paths | awk '/^Configuration file:/ { getline; print $1 }')
    CFG_DIR=$(dirname "$CFG_FILE")
    sudo systemctl stop "syncthing@$ACTUAL_USER" 2>/dev/null
    sudo -u "$ACTUAL_USER" mkdir -p "$CFG_DIR"
    sudo install -o "$ACTUAL_USER" -g "$ACTUAL_USER" -m 600 \
        "$RESTORE_DIR/cert.pem" "$RESTORE_DIR/key.pem" "$RESTORE_DIR/config.xml" "$CFG_DIR/"
    echo "✅ Identity and settings restored to $CFG_DIR"
fi

sudo systemctl enable --now "syncthing@$ACTUAL_USER"
sleep 3
echo "✅ Syncthing running as $ACTUAL_USER — device ID:"
sudo -u "$ACTUAL_USER" syncthing --device-id 2>/dev/null

echo ""
echo "  Its settings page listens on this Pi only. From another computer:"
echo "     ssh -N -L 8384:localhost:8384 $ACTUAL_USER@$(hostname -I | awk '{print $1}')"
echo "  then open http://localhost:8384"

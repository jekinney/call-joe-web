#!/usr/bin/env bash
# One-time setup of a fresh Ubuntu 24.04 server (run as root): Docker, a firewall, some swap, and the code in /opt/call-joe.
# Usage: bash server-setup.sh [git-url] [branch]
set -euo pipefail

REPO="${1:-https://github.com/jekinney/call-joe-web.git}"
BRANCH="${2:-main}"
DIR=/opt/call-joe

export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y ca-certificates curl git ufw

# Docker (official script).
if ! command -v docker >/dev/null; then
    curl -fsSL https://get.docker.com | sh
fi

# Firewall: SSH, the website, and the UDP ports calls use (Linux hands out 32768-60999 to the voice service).
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw allow 443/udp
ufw allow 32768:60999/udp
ufw --force enable

# Swap, so a build on a small server doesn't run out of memory.
if ! swapon --show | grep -q /swapfile; then
    fallocate -l 2G /swapfile
    chmod 600 /swapfile
    mkswap /swapfile
    swapon /swapfile
    echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

# The code.
if [ ! -d "$DIR/.git" ]; then
    git clone --branch "$BRANCH" "$REPO" "$DIR"
fi

echo
echo "Server is ready. Next: cp $DIR/.env.production.example $DIR/.env, fill it in, then run: bash $DIR/deploy/deploy.sh"

#!/usr/bin/env bash
# fretiq deploy bootstrap — authorize THIS machine for non-interactive deploy.
# See ../memory/deploy.md "## New machine setup". Idempotent; safe to re-run.
set -euo pipefail

HOST=141.94.36.133
SSH_USER=ubuntu
ALIAS=fretiq-prod
KEY="$HOME/.ssh/id_ed25519"

# 1. ensure a key exists on this machine
if [[ ! -f "$KEY" ]]; then
  echo "No $KEY — generating one..."
  ssh-keygen -t ed25519 -f "$KEY" -N "" -C "$(whoami)@$(hostname)"
fi

# 2. authorize this machine if key auth doesn't already work
if ssh -o BatchMode=yes -o ConnectTimeout=8 "$ALIAS" true 2>/dev/null \
   || ssh -o BatchMode=yes -o ConnectTimeout=8 "$SSH_USER@$HOST" true 2>/dev/null; then
  echo "Key auth already works."
else
  echo "Authorizing this machine (enter the server password once)..."
  ssh-copy-id -i "$KEY.pub" "$SSH_USER@$HOST"
fi

# 3. add the portable alias if missing
if ! grep -qiE "^Host[[:space:]]+$ALIAS([[:space:]]|$)" "$HOME/.ssh/config" 2>/dev/null; then
  echo "Adding $ALIAS to ~/.ssh/config..."
  cat >> "$HOME/.ssh/config" <<EOF

Host $ALIAS
    HostName $HOST
    User $SSH_USER
    IdentityFile $KEY
    IdentitiesOnly yes
EOF
  chmod 600 "$HOME/.ssh/config"
fi

# 4. verify non-interactive auth
echo "Verifying..."
ssh -o BatchMode=yes "$ALIAS" 'hostname && git -C /var/www/fretiq rev-parse --short HEAD'
echo "Bootstrap OK — deploy with /custom-server-deploy."

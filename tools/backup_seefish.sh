#!/bin/bash
set -Eeuo pipefail
umask 077

APP_DIR="/var/www/html"
DB_NAME="seefish_db"
BACKUP_DIR="/var/backups/seefish"
KEY_FILE="/root/.seefish-backup.key"
LOCK_FILE="/var/lock/seefish-backup.lock"

mkdir -p "$BACKUP_DIR"
exec 9>"$LOCK_FILE"
flock -n 9 || {
    echo "BACKUP_SKIPPED another backup is running"
    exit 0
}

if [[ ! -r "$KEY_FILE" ]]; then
    echo "ERROR encryption key unavailable"
    exit 1
fi

STAMP=$(date +%Y%m%d-%H%M%S)
WORK=$(mktemp -d "$BACKUP_DIR/.work.XXXXXX")
OUTPUT="$BACKUP_DIR/seefish-$STAMP.tar.gz.enc"

cleanup() {
    rm -rf "$WORK"
}
trap cleanup EXIT

echo "[$(date -Is)] Starting SeeFish backup"

# Dump database menggunakan akun root melalui Unix socket.
mysqldump --protocol=socket -u root \
    --single-transaction --quick \
    --routines --triggers --events \
    --databases "$DB_NAME" > "$WORK/database.sql"

test -s "$WORK/database.sql"

# Arsip source aplikasi termasuk .env dan logs.
tar -czf "$WORK/application.tar.gz" \
    -C "$APP_DIR" \
    --exclude='./.git' \
    --exclude='./tools/__pycache__' \
    --exclude='./tools/_*' \
    --exclude='./tools/*_test.php' \
    --exclude='./tests' \
    --exclude='./.env.example' .

# Gabungkan database dan source.
tar -czf "$WORK/package.tar.gz" \
    -C "$WORK" database.sql application.tar.gz

# Enkripsi sebelum menyimpan hasil backup.
openssl enc -aes-256-cbc -salt -pbkdf2 -iter 200000 \
    -in "$WORK/package.tar.gz" \
    -out "$OUTPUT.tmp" \
    -pass "file:$KEY_FILE"

mv "$OUTPUT.tmp" "$OUTPUT"
chmod 600 "$OUTPUT"

sha256sum "$OUTPUT" > "$OUTPUT.sha256"
chmod 600 "$OUTPUT.sha256"

# Rotasi: pertahankan backup 14 hari terakhir.
find "$BACKUP_DIR" -maxdepth 1 -type f \
    -name 'seefish-*.tar.gz.enc' -mtime +14 -delete

find "$BACKUP_DIR" -maxdepth 1 -type f \
    -name 'seefish-*.tar.gz.enc.sha256' -mtime +14 -delete

echo "BACKUP_OK file=$(basename "$OUTPUT")"
echo "SIZE=$(du -h "$OUTPUT" | cut -f1)"
echo "[$(date -Is)] Backup completed"

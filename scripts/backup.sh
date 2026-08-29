#!/usr/bin/env bash
#
# Sauvegarde de la base de KEneYa WorkFlow.
#
#   ./scripts/backup.sh [dossier-de-destination]
#
# Fonctionne sur les deux topologies de deploiement du projet :
#   - pile Docker Compose (service `db`) ;
#   - installation native (Apache + PHP-FPM + MariaDB de l'hote),
#     ou aucun conteneur `db` n'existe.
#
# Produit un fichier keneya_workflow-AAAAMMJJ-HHMMSS.sql dans le dossier
# indique (par defaut ./backups). Voir README pour la planification par cron.

set -euo pipefail

cd "$(dirname "$0")/.."

DEST="${1:-./backups}"
mkdir -p "$DEST"

# shellcheck disable=SC1091
set -a; [ -f .env ] && . ./.env; set +a

STAMP="$(date +%Y%m%d-%H%M%S)"
FILE="$DEST/${DB_DATABASE:-keneya_workflow}-$STAMP.sql"

DB_NAME="${DB_DATABASE:-keneya_workflow}"
DB_USER="${DB_USERNAME:-keneya}"

# Le mot de passe ne passe jamais en argument de commande : il serait alors
# visible dans `ps` par tout utilisateur de la machine. On le transmet par un
# fichier temporaire lisible du seul proprietaire, ou par l'environnement du
# conteneur.
if docker compose ps -q db 2>/dev/null | grep -q .; then
    docker compose exec -T -e MYSQL_PWD="${DB_PASSWORD:-}" db \
        mariadb-dump \
            --user="$DB_USER" \
            --single-transaction \
            --routines \
            "$DB_NAME" > "$FILE"
else
    DUMP_BIN="$(command -v mariadb-dump || command -v mysqldump || true)"

    if [ -z "$DUMP_BIN" ]; then
        echo "Erreur : ni mariadb-dump ni mysqldump ne sont installes sur cette machine." >&2
        exit 1
    fi

    CNF="$(mktemp)"
    chmod 600 "$CNF"
    trap 'rm -f "$CNF"' EXIT
    printf '[client]\nuser=%s\npassword=%s\n' "$DB_USER" "${DB_PASSWORD:-}" > "$CNF"

    "$DUMP_BIN" \
        --defaults-extra-file="$CNF" \
        --host="${DB_HOST:-127.0.0.1}" \
        --port="${DB_PORT:-3306}" \
        --single-transaction \
        --routines \
        "$DB_NAME" > "$FILE"
fi

# Un dump vide signale un echec silencieux : mieux vaut le dire tout de suite
# que de le decouvrir le jour d'une restauration.
if [ ! -s "$FILE" ]; then
    echo "Erreur : la sauvegarde $FILE est vide, la base n'a pas ete exportee." >&2
    rm -f "$FILE"
    exit 1
fi

chmod 600 "$FILE"

echo "Sauvegarde ecrite : $FILE ($(du -h "$FILE" | cut -f1))"

# Conservation des 30 dernieres sauvegardes.
ls -1t "$DEST"/*.sql 2>/dev/null | tail -n +31 | xargs -r rm --

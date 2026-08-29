#!/usr/bin/env bash
#
# Installe (ou met a jour) la sauvegarde quotidienne de la base dans la crontab
# de l'utilisateur courant.
#
#   ./scripts/install-backup-cron.sh [heure] [dossier-de-destination]
#
# Par defaut : tous les jours a 02h30, vers <projet>/backups.
# Idempotent : relancer le script remplace la ligne existante, il n'y a jamais
# deux taches concurrentes.

set -euo pipefail

PROJET="$(cd "$(dirname "$0")/.." && pwd)"
HEURE="${1:-02:30}"
DEST="${2:-$PROJET/backups}"

MINUTE="${HEURE#*:}"
HH="${HEURE%%:*}"

MARQUEUR="# keneya-workflow-backup"
LIGNE="${MINUTE#0} ${HH#0} * * * cd $PROJET && ./scripts/backup.sh $DEST >> $PROJET/storage/logs/backup.log 2>&1 $MARQUEUR"

# On conserve la crontab existante en retirant uniquement notre propre ligne.
NOUVELLE="$( { crontab -l 2>/dev/null || true; } | grep -v -F "$MARQUEUR" ; echo "$LIGNE" )"

printf '%s\n' "$NOUVELLE" | crontab -

echo "Tache installee : sauvegarde quotidienne a $HEURE vers $DEST"
echo "Verification :   crontab -l | grep keneya"
echo "Premier essai :  cd $PROJET && ./scripts/backup.sh $DEST"

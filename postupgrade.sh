#!/bin/bash
# Octopus Dynamic - postupgrade: Gesichertes zurueckspielen
# Aufruf: command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>

ARGV1=$1
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-octopus}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    BASE=/opt/loxberry
fi

CFGDIR="$BASE/config/plugins/$PFOLDER"
DATADIR="$BASE/data/plugins/$PFOLDER"
LOGDIR="$BASE/log/plugins/$PFOLDER"
mkdir -p "$CFGDIR" "$DATADIR" "$LOGDIR" 2>/dev/null

[ -f "$ARGV1/octopus.json" ] && cp -p "$ARGV1/octopus.json" "$CFGDIR/octopus.json"
[ -f "$ARGV1/zugang.json" ]  && cp -p "$ARGV1/zugang.json"  "$CFGDIR/zugang.json"
[ -f "$ARGV1/history.csv" ]  && cp -p "$ARGV1/history.csv"  "$DATADIR/history.csv"
[ -f "$ARGV1/octopus.log" ]  && cp -p "$ARGV1/octopus.log"  "$LOGDIR/octopus.log"

# Selbstheilung wie in postinstall.sh
BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$CFGDIR/octopus.json"
if [ -f "$BK" ]; then
    if [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; then
        cp -p "$BK" "$CF"
    fi
fi

# Das Token steckt in den Adressen im Miniserver - die Rechte muessen stimmen,
# der Inhalt bleibt unberuehrt.
chmod 640 "$CFGDIR/octopus.json" 2>/dev/null
chmod 600 "$CFGDIR/zugang.json" 2>/dev/null
chown -R loxberry:loxberry "$CFGDIR" "$DATADIR" "$LOGDIR" 2>/dev/null

# Der Zustandsspeicher wird verworfen, damit nach dem Update sofort mit der
# neuen Fassung gerechnet wird.
rm -f /tmp/"$PFOLDER"/state.json 2>/dev/null

echo "<OK> Aktualisierung abgeschlossen. Konfiguration, Zugangsdaten und Historie wurden uebernommen."
exit 0

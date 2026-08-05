#!/bin/bash
# Octopus Dynamic - preupgrade: Konfiguration, Zugangsdaten, Daten und Log sichern
# Aufruf: command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>

ARGV1=$1
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-octopus}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    BASE=/opt/loxberry
fi

mkdir -p "$ARGV1" 2>/dev/null
cp -p "$BASE/config/plugins/$PFOLDER/octopus.json" "$ARGV1/octopus.json" 2>/dev/null
cp -p "$BASE/config/plugins/$PFOLDER/zugang.json"  "$ARGV1/zugang.json"  2>/dev/null
cp -p "$BASE/data/plugins/$PFOLDER/history.csv"    "$ARGV1/history.csv"  2>/dev/null
cp -p "$BASE/log/plugins/$PFOLDER/octopus.log"     "$ARGV1/octopus.log"  2>/dev/null
exit 0

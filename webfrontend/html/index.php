<?php
/**
 * Octopus Dynamic - Endpunkt fuer den Miniserver
 *
 * Liegt bewusst im unangemeldeten Bereich, damit Loxone ihn ohne
 * Zugangsdaten aufrufen kann - aber jeder Aufruf braucht das Token aus den
 * Einstellungen. Verglichen wird mit hash_equals, also in gleichbleibender
 * Zeit; ein einfaches == liesse sich ueber die Antwortzeit Zeichen fuer
 * Zeichen erraten.
 *
 * Aufruf:
 *   /plugins/<Ordner>/index.php?token=<TOKEN>&aktion=status
 *
 * Aktionen:
 *   status        eine Textzeile OCTOPUS;SCHLUESSEL=WERT;... (Vorgabe)
 *   json          kompletter Zustand als JSON, inklusive aller Viertelstunden
 *   debug         alle Viertelstunden als Klartext
 *   refresh       Preise sofort neu abrufen, dann status
 *   say           Testansage abspielen
 *   saytomorrow   Testansage "Preise fuer morgen" abspielen
 *   ptest         Test-Pushnachricht anstossen (setzt ptest fuer 5 Minuten)
 *
 * MQTT ist der Regelweg. Dieser Endpunkt ist die Rueckfallebene fuer alle,
 * die den MQTT-Gateway nicht nutzen wollen - und die Stelle, an der Loxone
 * eine Ansage oder einen Push-Test ausloest.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');
header('Cache-Control: no-store');

require_once __DIR__ . '/oc_lib.php';

function oc_ende($code, $text)
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $text . "\n";
    exit;
}

$cfg = oc_config();

if (empty($cfg['enabled'])) {
    oc_ende(503, 'Das Plugin ist in den Einstellungen abgeschaltet.');
}

$soll = (string) $cfg['aktionstoken'];
if ($soll === '') {
    oc_ende(403, 'Kein Token eingerichtet. Reiter Einstellungen aufrufen und einmal '
               . 'speichern - dann wird eines erzeugt.');
}
$ist = isset($_GET['token']) ? (string) $_GET['token'] : '';
if (!hash_equals($soll, $ist)) {
    oc_ende(403, 'Token falsch.');
}

$erlaubt = array('status', 'json', 'debug', 'refresh', 'say', 'saytomorrow', 'ptest');
$aktion = isset($_GET['aktion']) ? (string) $_GET['aktion'] : 'status';
if (!in_array($aktion, $erlaubt, true)) {
    oc_ende(400, 'Unbekannte Aktion. Erlaubt: ' . implode(', ', $erlaubt));
}

/* ---------- Test-Pushnachricht ---------- */
if ($aktion === 'ptest') {
    @file_put_contents(oc_tmpdir() . '/ptest', '1');
    oc_log('Test-Pushnachricht angefordert (ptest=1 fuer 5 Minuten)');
    oc_ende(200, "PTEST;OK=1;DAUER=300");
}

/* ---------- Testansagen ---------- */
if ($aktion === 'say' || $aktion === 'saytomorrow') {
    $st = oc_state();
    $text = $aktion === 'saytomorrow' ? oc_tomorrow_text($st) : oc_announce_text($st);
    if ($text === '') { $text = oc_t('ANSAGE.TEST_LEER'); }
    $ok = oc_say($text);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'SAY;OK=' . ($ok ? 1 : 0) . ';TEXT=' . $text . "\n";
    exit;
}

$st = oc_state($aktion === 'refresh');

/* ---------- JSON ---------- */
if ($aktion === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    $st['werte'] = oc_werte($st);
    echo json_encode($st, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

/* ---------- Klartext-Auflistung ---------- */
if ($aktion === 'debug') {
    printf("QUELLE=%s  STAND=%s  SLOTS=%d  FEHLER=%s\n",
        $st['demo'] ? 'DEMO (simuliert, kein Octopus-Vertrag)' : 'Octopus (Kraken)',
        $st['stand'] ? date('d.m.Y H:i:s', $st['stand']) : '-',
        $st['slots_n'], $st['fehler'] !== '' ? $st['fehler'] : '-');
    printf("Jetzt %02d:%02d Uhr: %.3f ct/kWh brutto (netto %.3f) | Stunde %.3f | Rang %d von %d | Niveau %d\n",
        $st['stunde'], $st['minute'], $st['cur'], $st['cur_netto'], $st['cur_h'],
        $st['rank'], $st['n'], $st['level']);
    printf("Guenstigstes %d-Stunden-Fenster: ab %02d:%02d Uhr (in %d min), Schnitt %.3f ct\n",
        $st['fenster_len'], $st['fenster']['h'], $st['fenster']['m'],
        $st['fenster']['in'], $st['fenster']['ct']);
    if ($st['co2_ok']) {
        printf("CO2: jetzt %d g/kWh | sauberste Stunde %02d Uhr mit %d g | Schnitt %d g\n",
            $st['co2'], $st['co2_minh'], $st['co2_min'], $st['co2_avg']);
    }
    echo "\n";
    foreach (array('heute' => 'HEUTE', 'morgen' => 'MORGEN') as $k => $label) {
        echo "-- $label --\n";
        if (empty($st[$k]['slots'])) { echo "(keine Daten)\n\n"; continue; }
        foreach ($st[$k]['slots'] as $ts => $ct) {
            printf("%s  %8.3f ct/kWh\n", date('H:i', (int) $ts), $ct);
        }
        printf("Min %02d:%02d %.3f | Max %02d:%02d %.3f | Schnitt %.3f (%d Viertelstunden)\n\n",
            $st[$k]['minh'], $st[$k]['minm'], $st[$k]['minp'],
            $st[$k]['maxh'], $st[$k]['maxm'], $st[$k]['maxp'],
            $st[$k]['avg'], $st[$k]['n']);
    }
}

/* ---------- Eine Zeile fuer die Befehlserkennung ---------- */
$zeile = 'OCTOPUS';
foreach (oc_werte($st) as $k => $v) {
    $zeile .= ';' . strtoupper($k) . '=' . (is_float($v) ? sprintf('%.3f', $v) : $v);
}
echo $zeile . "\n";

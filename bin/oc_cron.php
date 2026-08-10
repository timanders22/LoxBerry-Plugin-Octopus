<?php
/**
 * Octopus Dynamic - minuetlicher Lauf (aus cron/cron.01min)
 *
 * 1. Zustand aktualisieren (Preise-Cache 15 min, Zustand 4 min)
 * 2. Stuendliche Ansage und Meldung "Preise fuer morgen sind da"
 * 3. MQTT bei Aenderung, mindestens alle 30 Minuten
 * 4. Tageswerte kurz vor Mitternacht fortschreiben
 * 5. Monatsbericht am Monatsersten
 *
 * Laeuft ueber die Kommandozeile. Die Ausgabe leitet der Cron in die
 * Logdatei um - deshalb steht hier nur eine Zeile auf stdout.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/* Die Bibliothek liegt im unangemeldeten Webbereich, weil der Endpunkt fuer
   Loxone sie ebenfalls braucht. Der Platzhalter wird bei der Installation
   ersetzt; die beiden Rueckfaelle greifen im Archiv und wenn ein
   Installationslauf den Platzhalter einmal nicht ersetzt hat. */
$oc_lib = 'REPLACELBPHTMLDIR/oc_lib.php';
if (!is_file($oc_lib)) {
    $oc_home = getenv('LBHOMEDIR');
    if (!$oc_home || !is_dir($oc_home)) {
        foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
            if (is_dir($k)) { $oc_home = $k; break; }
        }
    }
    // Eigener Ablageort: <home>/bin/plugins/<ordner>
    $oc_ordner = basename(dirname(__FILE__));
    $oc_lib = $oc_home . '/webfrontend/html/plugins/' . $oc_ordner . '/oc_lib.php';
}
if (!is_file($oc_lib)) {
    $oc_lib = dirname(__DIR__) . '/webfrontend/html/oc_lib.php';   // Archiv
}
if (!is_file($oc_lib)) {
    fwrite(STDERR, "oc_lib.php nicht gefunden - Plugin unvollstaendig installiert?\n");
    exit(1);
}
require_once $oc_lib;

$cfg = oc_config();
if (empty($cfg['enabled'])) {
    echo "AUS\n";
    exit(0);
}

$st = oc_state();
oc_announce_check($st);

/* ---- Monatsbericht am Monatsersten, ab 8 Uhr, genau einmal ----
 *
 * Bis 0.9.1 stand hier:
 *
 *     if ((int) date('j') === 1 && date('H:i') === '08:05') {
 *
 * Das trifft ein Zeitfenster von genau 60 Sekunden. Nachgestellt mit 1000
 * simulierten Monaten und verschieden grossem Cron-Verzug:
 *
 *     Verzug bis 59 s   ->  0 % Ausfall
 *     Verzug bis 65 s   ->  6,5 % der Monate ohne Bericht
 *     Verzug bis 90 s   ->  22 % der Monate ohne Bericht
 *
 * Ein Verzug von ein paar Sekunden schadet also NICHT - der um fuenf
 * Sekunden verspaetete Lauf liegt immer noch bei 08:05:05. Gefaehrlich wird
 * erst, was laenger als eine volle Minute dauert oder den Lauf ganz
 * ausfallen laesst:
 *
 *   - der LoxBerry startet gerade neu oder ist aus,
 *   - ein Update laeuft,
 *   - das Plugin stand in dieser einen Minute auf "aus" (die Pruefung auf
 *     enabled weiter oben beendet das Skript, bevor es hierher kommt).
 *
 * In allen drei Faellen fiel der Bericht fuer den ganzen Monat aus, ohne
 * zweiten Versuch. Deshalb jetzt: 1. des Monats, ab 8 Uhr, und ein Marker,
 * der sagt, dass es schon erledigt ist.
 *
 * WO der Marker liegt, ist nicht gleichgueltig. Der naheliegende Ort
 * /tmp scheidet aus: oc_paths()['tmp'] zeigt auf /tmp/<ordner>, und /tmp
 * ist auf dem LoxBerry eine Ramdisk. Startet der Rechner am Ersten nach
 * dem Bericht neu, waere der Marker fort - und der naechste Lauf meldete
 * den Monatsbericht ein zweites Mal, samt Sprachansage. Der Marker gehoert
 * in den Datenordner, der den Neustart uebersteht.
 */
$oc_marke = oc_datadir() . '/monatsbericht_' . date('Ym') . '.done';
if ((int) date('j') === 1 && (int) date('G') >= 8 && !is_file($oc_marke)) {
    // Marker VOR dem Bericht setzen. Bricht die Auswertung ab, ist der
    // Bericht fuer diesen Monat verloren - eine Endlosschleife aus
    // Fehlversuchen mit Sprachansage waere schlimmer.
    @touch($oc_marke);
    // Marker der Vormonate wegraeumen, damit der Ordner nicht zulaeuft.
    foreach (glob(oc_datadir() . '/monatsbericht_*.done') ?: array() as $oc_alt) {
        if ($oc_alt !== $oc_marke && time() - (int) filemtime($oc_alt) > 40 * 86400) {
            @unlink($oc_alt);
        }
    }
    $mc = oc_month_compare(2);
    array_shift($mc);                 // laufender Monat raus, wir wollen den Vormonat
    $vm = $mc ? reset($mc) : null;
    if ($vm) {
        oc_log('MONATSBERICHT ' . $vm['monat'] . ': dynamisch (gewichtet) ' . $vm['dynp']
            . ' ct, fest ' . $vm['fix'] . ' ct -> '
            . ($vm['diff'] >= 0 ? 'dynamisch waere guenstiger gewesen um ' : 'fester Tarif war guenstiger um ')
            . abs($vm['diff']) . ' ct/kWh (' . abs($vm['euro']) . ' EUR)');
        if (!empty($cfg['notify']['audio'])) {
            $t = str_replace(array('%DYN%', '%FIX%'),
                array(oc_num($vm['dynp'], 1), oc_num($vm['fix'], 1)),
                oc_t('ANSAGE.MONATSBERICHT'));
            $t .= ' ' . str_replace('%D%', oc_num(abs($vm['diff']), 1),
                oc_t($vm['diff'] >= 0 ? 'ANSAGE.MONAT_DYN_BESSER' : 'ANSAGE.MONAT_FIX_BESSER'));
            oc_say($t);
        }
    }
}

/* ---- MQTT: bei Aenderung, mindestens alle 30 Minuten ----
   ann und ptest gehoeren in die Signatur: sie wechseln minutengenau, und
   ohne sie wuerde das Meldefenster erst beim naechsten Stundenschlag
   veroeffentlicht. */
$werte = oc_werte($st);
$sig = json_encode($werte);
$sigf = oc_tmpdir() . '/mqtt_sig.txt';
$beat = oc_tmpdir() . '/mqtt_beat';
$alt = is_file($sigf) ? (string) @file_get_contents($sigf) : '';
if ($sig !== $alt || !is_file($beat) || time() - filemtime($beat) > 1800) {
    if (oc_mqtt_publish($st)) {
        @file_put_contents($sigf, $sig);
        @touch($beat);
    }
}

/* ---- Tageswerte sichern ---- */
if ((int) date('G') === 23 && (int) date('i') >= 50) {
    oc_history_add($st);
}

/* ---- Alte Zwischendateien aufraeumen ---- */
if (rand(0, 60) === 0) {
    foreach (glob(oc_datadir() . '/demo_*.json') ?: array() as $f) {
        if (time() - (int) filemtime($f) > 10 * 86400) { @unlink($f); }
    }
}

echo "OK\n";


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet
 * es nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin
 * abfangen muss).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

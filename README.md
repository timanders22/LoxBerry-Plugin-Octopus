# LoxBerry-Plugin Octopus Dynamic

Holt die **Viertelstundenpreise** des Tarifs *dynamicOctopus* über die
Kraken-Schnittstelle von Octopus Energy und stellt sie dem Loxone Miniserver
zur Verfügung — über MQTT als Regelweg, über einen tokengeschützten
HTTP-Endpunkt als Rückfallebene.

---

## Was 0.9.2 behebt

Zwei Meldungen eines Mitlesers. Eine trifft zu, aber aus einem anderen Grund
als angegeben; die andere hat er selbst schon richtig eingeordnet.

### Der Monatsbericht hatte nur einen Versuch — zutreffend

Gemeldet als „Cronjobs können sich bei hoher Systemlast um einige Sekunden
verschieben". Nachgestellt mit 1000 simulierten Monaten und verschieden
grossem Verzug:

| Cron-Verzug bis | Monate ohne Bericht |
|---|---|
| 59 s | **0 %** |
| 65 s | 6,5 % |
| 90 s | 22 % |

Ein Verzug von ein paar Sekunden schadet also **nicht** — der um fünf
Sekunden verspätete Lauf liegt immer noch bei 08:05:05, und `date('H:i')`
liefert weiterhin `08:05`. Erst ein Verzug über eine volle Minute lässt das
Fenster ausfallen.

Der Befund stimmt trotzdem, nur ist der Weg dorthin ein anderer: Es gibt
keinen zweiten Versuch. Fällt der Lauf um 08:05 am Ersten **ganz** aus, ist
der Bericht für den Monat verloren. Das passiert, wenn der LoxBerry gerade
neu startet oder aus ist, wenn ein Update läuft — oder wenn das Plugin in
genau dieser Minute auf „aus" stand: Die Prüfung auf `enabled` beendet das
Skript, bevor es zum Bericht kommt.

*Jetzt*: 1. des Monats, ab 8 Uhr, mit einem Erledigt-Marker. Nachgewiesen an
vier Fällen — Normalbetrieb, LoxBerry bis 11 Uhr aus, verschluckter Lauf um
08:05, zweiter Tag des Monats: einmal, einmal, einmal, keinmal.

**Der vorgeschlagene Ort für den Marker war allerdings falsch.**
`/tmp/octopus_month_report_YYYYMM.done` — `oc_paths()['tmp']` zeigt auf
`/tmp/<ordner>`, und `/tmp` ist auf dem LoxBerry eine Ramdisk. Startet der
Rechner am Ersten nach dem Bericht neu, wäre der Marker fort und der nächste
Lauf meldete den Monatsbericht ein zweites Mal — samt Sprachansage. Der
Marker liegt deshalb im Datenordner, der den Neustart übersteht. Gesetzt wird
er **vor** der Auswertung: Bricht die ab, ist der Bericht für diesen Monat
verloren — eine Endlosschleife aus Fehlversuchen mit Ansage wäre schlimmer.

### Protokoll leeren ohne Sperre — kosmetisch, wie vermutet

Der Melder ordnet es selbst als „Meckern auf hohem Niveau" ein, und die
Messung gibt ihm recht: vier Sekunden gleichzeitiges Anhängen und Leeren,
**0 unbrauchbare Zeilen**, in beiden Varianten. Zerreissen kann eine Zeile
auch nicht — `FILE_APPEND` bedeutet `O_APPEND`, der Kern setzt vor jedem
Schreiben ans tatsächliche Dateiende.

*Verlieren* kann man eine Zeile aber sehr wohl, und das nicht beim Leeren
über die Oberfläche, sondern beim **Kürzen im minütlichen Lauf**: `oc_log()`
liest bei 512 kB das Endstück ein und schreibt es zurück. Wer in diesem
Fenster anhängt, schreibt in eine Datei, die gleich überschrieben wird. Beide
Stellen laufen jetzt über `oc_log_setzen()` mit `flock` und `ftruncate` —
dieselbe Datei, dieselbe Inode, wer sie offen hat, schreibt weiter hinein.

### Hausstandard

Die Reiter waren schon echte Verweise, aber `sm-active` vergab
ausschliesslich das JavaScript — im ausgelieferten HTML kam die Klasse gar
nicht vor, und ohne JavaScript standen Kopfzeile und Reiterleiste da,
darunter nichts. Jetzt setzt der Server sie; alle sechs Reiter sind über
`?form=…` geprüft. Dazu 17 fehlende `data-role="none"` ergänzt (jetzt 67 von
67) und das Symbol auf das neue Hausmuster gebracht (Kreisscheibe mit zweitem
Ring).

Beide PHP-Fassungen liefern in beiden Sprachen zeichengleiche Ausgabe ohne
eine Meldung. Eine Abweichung von 20 Zeichen, die zwischenzeitlich auftrat,
lag am Prüfstand und nicht am Plugin: Der erste Lauf legt Zustandsdateien an,
die der zweite dann vorfindet. Mit jeweils frischem Datenordner sind die
Ausgaben identisch.

## Neu in 0.9.1: Schaltregeln

Bis 0.9.0 lieferte das Plugin nur **Zahlen** — Startzeit, Minuten bis dahin,
Durchschnittspreis. Daraus „jetzt laden" zu machen war Arbeit im Miniserver.

Eine **Schaltregel** beantwortet die Frage hier und gibt ein fertiges
0/1-Signal aus. Vier Arten stehen zur Wahl:

| Art | Bedeutung |
|---|---|
| Fenster | die N günstigsten Stunden **am Stück** |
| Stunden | die N günstigsten **vollen** Stunden |
| Mittel | Preis X % unter dem Tagesmittel |
| Schwelle | Preis unter einem festen Wert |

Dazu je Regel ein Zeitfenster, ein Horizont und wahlweise ein absolutes,
relatives oder kombiniertes Profil.

**Und eine Anleitung, welcher Loxone-Baustein wozu passt.** Zwei kommen in
Frage, und sie tun *nicht* dasselbe: der **Spot Price Optimizer** rechnet mit
Preisen und schaltet in den günstigsten Stunden; der **Energiemanager**
verteilt Überschuss und kennt überhaupt keinen Preis — dort wirken die Regeln
nur mittelbar. Beim Optimizer ist außerdem zu beachten, dass er Werte aus einem
virtuellen HTTP-Eingang nur benutzt, wenn sie **innerhalb der laufenden
Stunde** aktualisiert wurden.

---

## Was noch nicht geprüft ist

**Dieses Plugin wurde nie gegen einen echten Octopus-Vertrag gefahren.** Der
Autor hat keinen. Geprüft sind:

* PHP-Syntax aller Dateien
* Rendern der Oberfläche per GET und per POST gegen eine Attrappe
* der Endpunkt ohne Token, mit falschem Token, mit unbekannter Aktion und mit
  eingeschleustem Shell-Befehl im Parameter
* die Abdeckung aller Sprachschlüssel in beiden `.ini`-Dateien
* der komplette Datenpfad im **Demo-Modus** (echte Netzabfrage gegen die
  offene aWATTar-Schnittstelle, Auswertung, MQTT, Ansage, Historie)

**Nicht geprüft** sind die beiden Abfragen gegen `api.oeg-kraken.energy`:
die Anmelde-Mutation und die Preisabfrage. Sie sind wortgleich aus der
Anleitung von Octopus übernommen. Wer einen Vertrag hat, prüft sie im Reiter
*Test* mit **Anmeldung prüfen** und **Preise jetzt abrufen** — im Protokoll
steht dann die Antwort im Wortlaut.

Octopus weist ausdrücklich darauf hin, dass sich die Struktur der
Preisinformationen (`TimeOfUseProductUnitRateInformation` innerhalb von
`unitRateInformation`) ändern kann. Das Plugin liest die Antwort deshalb
**nicht auf einem festen Pfad**, sondern sucht rekursiv nach Einträgen mit
`validFrom`/`validTo` und den beiden Preisfeldern. Eine Umbenennung der
Zwischenebenen überlebt es damit; eine Umbenennung der Preisfelder nicht —
in dem Fall meldet der Reiter Test, dass kein Bruttopreis gefunden wurde.

---

## Voraussetzungen

* LoxBerry ab 3.0.0 (der MQTT-Gateway ist seit LoxBerry 3 Bestandteil des
  Systems und muss **nicht** nachinstalliert werden)
* PHP 7.4 oder neuer
* Für den Echtbetrieb: ein Octopus-Kundenkonto mit dem Tarif **dynamicOctopus**
  und einem Smart Meter. Ohne diesen Tarif liefert die Schnittstelle eine
  leere Liste — das ist kein Fehler des Plugins.

Ohne Vertrag lässt sich alles über den **Demo-Modus** durchspielen.

---

## Die Preisrechnung

Octopus liefert mit `latestGrossUnitRateCentsPerKwh` bereits den fertigen
**Brutto-Arbeitspreis in ct/kWh**. Das Plugin schlägt deshalb **nichts** auf:
keine Netzentgelte, keine Umlagen, keine Umsatzsteuer. Ein eigener
Aufschlagsrechner wäre hier eine Fehlerquelle ohne Nutzen — anders als bei
einem Plugin, das den nackten Börsenpreis holt.

Der Nettoanteil (`netUnitRateCentsPerKwh`) wird zusätzlich geführt. Er
entscheidet darüber, ob der Preis als *negativ* gilt.

### Auflösung

Octopus liefert 15-Minuten-Werte (Intraday-Auktion IDA 1). Das Plugin gibt
beides aus:

* `cur`, `next`, `rank`, `fenster_*` — im **Viertelstundenraster**
* `cur_h`, `next_h`, `rank_h` — als **Stundenmittel**, für alle
  Loxone-Bausteine, die auf Stunden ausgelegt sind

Das günstigste zusammenhängende Fenster wird im Viertelstundenraster gesucht
und kann deshalb auch um 13:45 beginnen.

---

## Demo-Modus

Ohne Octopus-Zugang rechnet das Plugin aus den frei verfügbaren
Börsenpreisen (aWATTar, EPEX SPOT) eine Preisliste **derselben Form**:
Börsenpreis zuzüglich eines einstellbaren Aufschlags und der Umsatzsteuer.

**Diese Werte sind simuliert.** Der Aufschlag ist frei gewählt und entspricht
keinem realen Tarif. Der Demo-Modus ist überall gekennzeichnet:

* violetter Kasten über der Oberfläche
* MQTT-Thema `demo` steht auf 1
* Spalte *Quelle* in der Tagesstatistik zeigt „Demo"
* jede Sprachansage beginnt mit dem Hinweis

Da die Börsendaten stündlich sind, bleibt der Wert innerhalb einer Stunde
gleich. Erfundene Schwankungen innerhalb der Stunde wären eine
Falschaussage — deshalb gibt es sie nicht.

---

## Aufbau

    bin/oc_cron.php                    minuetlicher Lauf (Preise, Ansage, MQTT, Historie)
    cron/cron.01min                    ruft oc_cron.php auf
    webfrontend/html/oc_lib.php        gemeinsame Bibliothek
    webfrontend/html/index.php         Endpunkt fuer den Miniserver (Token)
    webfrontend/htmlauth/index.php     Bedienoberflaeche
    webfrontend/htmlauth/oc_test.php   die Aktionen des Reiters Test
    templates/lang/language_de.ini     Sprachdatei Deutsch
    templates/lang/language_en.ini     Sprachdatei Englisch
    templates/help/help.html           Hilfetext hinter dem Fragezeichen

Drei Aufgaben, drei Dateien — nie vermischt: die Oberfläche bedient nur der
Mensch, der Datenabruf läuft über den Cron, und der Endpunkt gehört Loxone.
Ein Klick auf das Plugin löst **keinen** API-Abruf aus.

Die Bibliothek liegt im unangemeldeten Webbereich, weil der Loxone-Endpunkt
sie ebenfalls braucht. Das Arbeitsskript des Cron liegt bewusst **nicht**
dort — es wäre sonst ohne Token über HTTP erreichbar.

---

## Sicherheit

* **Zugangsdaten** stehen in `config/plugins/octopus/zugang.json` mit Rechten
  **0600** — nicht in der Konfiguration, die die Oberfläche anzeigt. Sie
  werden nie über die Kommandozeile übergeben (Argumente stehen in der
  Prozessliste) und nie angezeigt; im Reiter Test steht nur die Länge.
* Das **Kraken-Token** ist eine Stunde gültig, wird 55 Minuten gehalten und
  liegt ebenfalls mit 0600 in `data/plugins/octopus/token.json`.
* Der **Endpunkt** vergleicht sein Token mit `hash_equals`, also in
  gleichbleibender Zeit. Ein einfaches `==` ließe sich über die Antwortzeit
  Zeichen für Zeichen erraten. Unbekannte Aktionen werden abgewiesen, nicht
  zurechtgebogen.
* Ein leeres Passwortfeld **löscht nichts**. Zum Löschen gibt es einen
  eigenen Haken.
* Eine Kundennummer, die nicht zur bekannten Form passt (`A-` gefolgt von
  Ziffern und/oder Buchstaben), wird **abgewiesen und gemeldet** — nicht
  stillschweigend zurechtgeschnitten.

---

## Einbindung in Loxone

Der Reiter *Einbindung in Loxone* führt in sieben Schritten durch die
Einrichtung und enthält eine **komplette Baustein-Liste zum 1:1-Nachbauen**
(22 Zeilen mit Typ, Namensvorschlag, Parametern und Verdrahtung).

Kurzfassung:

1. **MQTT ist der Regelweg.** Im MQTT-Gateway unter *Subscriptions* das Abo
   `octopus/#` eintragen. **Ohne diesen Eintrag kommt am Miniserver nichts
   an** — das ist die häufigste Fehlerursache überhaupt.
2. Virtuelle Eingänge anlegen; die Titel bildet der Gateway selbst
   (`octopus_cur`, `octopus_rank`, …). Eine fertige Vorlage lässt sich im
   Reiter herunterladen.
3. Ausfallerkennung über `alter` (Minuten seit dem letzten erfolgreichen
   Abruf). Virtuelle Eingänge behalten ihren letzten Wert — ohne diese Größe
   sieht in der App alles normal aus, obwohl die Preise von gestern sind.
   Schwelle deutlich über den Abholtakt legen, Vorschlag 90 Minuten.

Wer den Gateway nicht nutzen will: der Endpunkt liefert alle Werte in einer
Zeile.

    http://<loxberry>/plugins/octopus/index.php?token=<TOKEN>&aktion=status

Weitere Aktionen: `json`, `debug`, `refresh`, `say`, `saytomorrow`, `ptest`.

---

## MQTT-Themen

Alle Themen unter dem einstellbaren Präfix (Vorgabe `octopus`). Die
vollständige Tabelle mit Bedeutung, Einheit und aktuellem Wert steht im
Reiter *MQTT*. Die wichtigsten:

| Thema | Bedeutung |
|---|---|
| `cur` | Endpreis der laufenden Viertelstunde (ct/kWh) |
| `cur_h` | Endpreis der laufenden Stunde |
| `rank` | Rang in den nächsten 24 h, 1 = günstigste Viertelstunde |
| `level` | 1 günstig, 2 normal, 3 teuer |
| `fenster_in` | in wie vielen Minuten das günstigste Fenster beginnt |
| `neg` | 1, wenn der Nettopreis negativ ist |
| `ok` | 1, sobald gültige Preise vorliegen |
| `alter` | Alter der Preisdaten in Minuten |
| `demo` | 1, wenn die Preise simuliert sind |

---

## Kostenvergleich

Das Plugin schreibt täglich kurz vor Mitternacht Tageswerte fort (Schnitt,
Minimum, Maximum, lastprofil-gewichteter Schnitt, CO₂). Daraus rechnet der
Reiter *Kostenvergleich* einen Vollkostenvergleich gegen einen festen Tarif —
mit Grundpreisen, Rabatt und Boni, erstes Jahr und Folgejahre getrennt.

Die Gewichtung erfolgt über ein vereinfachtes Haushalts-Lastprofil. Ohne
echte Verbrauchsdaten wäre ein glatter Mittelwert zu optimistisch: die teuren
Stunden sind gerade die, in denen ein Haushalt viel verbraucht.

**Ohne Historie ist das Ergebnis eine Momentaufnahme.** Es wird mit jedem
erfassten Tag belastbarer. Die Anzahl der zugrunde liegenden Monate steht
über der Tabelle.

---

## Release

Das Auto-Update ist eingeschaltet und zeigt auf **dieses** Repository — nicht
auf einen fremden Stand, denn sonst böte LoxBerry irgendwann ein Downgrade an.

Die Fassung bleibt bewusst **unter 1.0.0**, solange die beiden Cloud-Abfragen
nicht an einem echten Vertrag erprobt sind. Wer 0.9.0 installiert hat, bekommt
0.9.1 angeboten; sobald `1.0.0` erscheint, greift die Aktualisierung ebenso.

Bei jedem Release müssen **drei Stellen** zusammenpassen, sonst greift das
Auto-Update nicht:

1. `plugin.cfg` → `VERSION`
2. `release.cfg` → `VERSION` **und beide Adressen** auf den neuen Tag
3. auf GitHub ein Release mit genau diesem Tag (`vX.Y.Z`)

Die `prerelease.cfg` wird mitgezogen.

---

## Quellen

* Octopus Energy, *Dynamisch sparen leicht gemacht — so holst du dir die
  Preise für deine Geräte direkt per API*,
  <https://octopusenergy.de/blog/tipps-tricks/dynamisch-sparen-per-api>
* Kraken GraphQL: <https://api.oeg-kraken.energy/v1/graphql/>
* CO₂-Intensität: Fraunhofer ISE, Energy-Charts,
  <https://api.energy-charts.info/co2eq> (frei, ohne Konto)
* Demo-Modus: aWATTar Marktdaten,
  <https://api.awattar.de/v1/marketdata> (frei, ohne Konto)

Das Plugin steht in keiner Verbindung zu Octopus Energy Germany GmbH und
verwendet keine fremden Wortmarken oder Logos.

---


## Fassung 1.0.0 — Fahrplaner

Bis 0.9.2 rechnete jede Schaltregel für sich. Wärmepumpe, Wallbox und
Waschmaschine fanden dieselbe günstigste Viertelstunde und schalteten gleichzeitig.
Drei Dinge kommen dazu — **alle drei ab Werk aus**, wer nichts einstellt,
bekommt das Verhalten der Fassung davor:

**Frist und Energiemenge.** Eine Regel kann jetzt sagen „7 kWh bei 3,7 kW,
fertig bis 7 Uhr". Daraus rechnet der Planer die nötige Laufzeit und sucht
nur bis zur Frist — auch wenn es danach billiger wäre. Ist die Uhrzeit heute
schon vorbei, ist morgen gemeint.

**Rangfolge und Leistungsbudget.** Jede Regel bekommt einen Rang und eine
Leistungsangabe, das Plugin ein Gesamtbudget in kW. Geplant wird in
Rangfolge: Rang 1 sucht sich die günstigen Viertelstunden zuerst aus, was er belegt
hat, steht den anderen nicht mehr zur Verfügung. Das ist ein gieriges
Verfahren, kein optimales — dafür in einem Satz erklärbar: *wer vorne steht,
sucht sich zuerst aus.* Wer um drei Uhr nachts wissen will, warum die Wallbox
nicht lädt, bekommt mit `VERD` die Zahl der weggenommenen Viertelstunden und sieht
es sofort.

**PV-Prognose und Speicherstand.** Für jede Viertelstunde mit Sonnenprognose wird
eine Gutschrift vom Preis abgezogen — damit gewinnt die sonnige
Mittagsstunde gegen die billige Nachtstunde. Die Gutschrift steigt linear bis
zu einer Schwelle; eine reine Ja/Nein-Grenze wäre eine Klippe, an der der
Fahrplan bei minimal geänderter Prognose um Stunden springt. Dazu zwei
Sperren je Regel: „nicht laden, wenn morgen mehr als X kWh vom Dach kommen"
und „nur zwischen diesen beiden Speicherständen".

Als Quelle taugt **forecast.solar** (kostenlos, ohne Konto) oder jede eigene
Adresse, die JSON liefert — als Objekt Zeit→Wert oder als Liste von Objekten
mit frei benennbaren Feldern. Für den Speicherstand genügt eine Adresse und
ein Pfad. Beides wird höchstens alle 15 Minuten geholt.

### Der Planer steckt in einer eigenen Datei

`webfrontend/html/planer.php` ist in **diesem und im Spotpreis-aWATTar-Plugin
byteweise gleich** — dieselbe Rechnung, dieselben Prüffälle. Deshalb trägt
sie das neutrale Kürzel `plan_` statt des Plugin-Kürzels; das ist die einzige
Ausnahme von der Kürzelregel und bewusst gemacht: zwei auseinanderlaufende
Kopien derselben Rechnung wären schlimmer als ein zweites Kürzel.

Sie ist reine Rechnung — kein Netz, keine Dateien, keine Uhr außer dem
übergebenen Zeitpunkt. Deshalb lässt sie sich vollständig durchprüfen:
**53 Fälle, jeder von Hand nachgerechnet**, unter PHP 7.4 und 8.2 alle grün.
Darunter die Verdrängung durch das Budget, die Frist über Mitternacht, die
Einheitenumrechnung Wh/W/kW und der Fall „PV-Gutschrift lässt die
Sonnenstunde gegen die billigste Stunde gewinnen".

**Was das nicht beweist:** dass die Prognosequelle so antwortet, wie sie
soll. Das entscheidet der Dienst am anderen Ende. Der Reiter Einstellungen
zeigt deshalb an, was zuletzt geholt wurde, und nennt den Grund, wenn nichts
ankam.

### Viertelstunden statt Stunden

Der Planer rechnet in Zeitscheiben, nicht in Stunden — deshalb passt dieselbe
Datei für beide Plugins. „2 Stunden am Stück" sind hier acht Zeitscheiben,
und `IN` und `REST` zählen wie bisher in Minuten. Bei der Regelart *die N
günstigsten Einzelstunden* wird weiterhin auf **volle** Stunden gemittelt:
sonst schaltete die Wallbox im Viertelstundentakt an und aus.

## Lizenz

MIT — siehe `LICENSE`.

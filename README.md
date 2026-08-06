# LoxBerry-Plugin Octopus Dynamic

Holt die **Viertelstundenpreise** des Tarifs *dynamicOctopus* über die
Kraken-Schnittstelle von Octopus Energy und stellt sie dem Loxone Miniserver
zur Verfügung — über MQTT als Regelweg, über einen tokengeschützten
HTTP-Endpunkt als Rückfallebene.

---

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

## Lizenz

MIT — siehe `LICENSE`.

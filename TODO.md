# AlfenWB – Testliste und Ideen

## Tests an einer echten Wallbox

Praxistest 11.11.2026 (Eve Single S-line 11 kW, Fiat 500e): Lesen, Strom, Vorgabe bleibt gültig, Pause/Weiterladen, Watt-Vorgabe, Phasenumschaltung von Hand und Automatik, Energie Manager Aktiv/Inaktiv = **ok**. Offen sind die Zeilen unten mit Status „offen“.

| Was | Wie prüfen | Wann | Status |
|---|---|---|---|
| Verbindung und Lesen | IP eintragen, „Verbindung testen“; Spannungen ~230 V, Status passt zum Fahrzeug | sofort | ok 11.11. |
| Registeradressen / Wortreihenfolge | Debug-Fenster: keine Meldung „Register nicht vorhanden“; Werte plausibel | sofort | ok 11.11. |
| Zählerstand | Wert wie in der Alfen-App; bleibt er leer, liefert der Zähler ihn nicht (dann wird die Energie gerechnet) | sofort | offen |
| Name/Firmware/Temperatur (Adresse 200) | Titel „Einrichtung (… · Firmware …)“, Variable Temperatur | sofort | offen |
| Übernahme beim Freigeben | Steuerung freigeben: Laden läuft unverändert weiter, „Laden erlaubt“ und Ladestrom passen | Fahrzeug steckt | offen |
| Vorgabe bleibt gültig | 10 Minuten mit 10 A laden: kein Abfall auf den sicheren Strom, „Vorgabe gültig noch“ springt immer wieder hoch | Fahrzeug steckt | ok 11.11. |
| Pause | „Laden erlaubt“ aus: Status „… Wallbox gibt nicht frei“, bleibt so (auch nach 5 Minuten) | Fahrzeug steckt | ok 11.11. |
| Weiterladen nach Pause | „Laden erlaubt“ an: Fahrzeug lädt von selbst wieder? Fiat 500e ok (ca. 5 s), Tesla offen | Fahrzeug steckt | teilweise |
| Sicherer Strom | ⚠-Hinweis im Formular, solange in ACE mehr als 0 A eingestellt sind | sofort | offen |
| Energie Manager: Aktiv / Inaktiv | Modus umschalten: lädt voll / lädt nicht | Fahrzeug steckt | ok 11.11. |
| Energie Manager: Automatisch | Was schreibt er in „Ladeleistung (Soll)“ und „Laden erlaubt“ (Archiv/Debug)? Folgt die Ladeleistung dem Überschuss? | Sonne | offen |
| Phasenumschaltung | Phasenwahl 1-phasig → Pause, Umschalten, lädt 1-phasig; zurück auf 3-phasig | Fahrzeug steckt, in ACE freigegeben | ok 11.11. |
| Phasen-Automatik | Vorgabe 2000 W → 1-phasig; 6000 W → nach Wartezeit 3-phasig | Fahrzeug steckt | ok 11.11. |
| Symcon aus | Symcon stoppen: nach der Gültigkeitsdauer nimmt die Wallbox den sicheren Strom | Fahrzeug steckt | offen |
| Meldung bei zu kleiner Leistung (Build 2) | 3000 W, Phasenwahl 3-phasig → „Letzte Meldung“ nennt den Grund; 6000 W → „Leistung reicht wieder“ | Fahrzeug steckt | offen |
| Phasenwechsel von Hand während des Ladens | 6000 W: 3-phasig → 1-phasig (16 A, ca. 3450 W) → 3-phasig (8,6 A, ca. 6040 W) | Fahrzeug steckt | ok 12.11. |
| Netzwerk weg (Build 3) | Netzwerkstecker ziehen: „Wallbox nicht erreichbar“; wieder stecken: „Wallbox wieder erreichbar“, Vorgabe wird neu gesendet | sofort | offen |
| Anzeige in der Symcon-App (Build 4) | iPhone/iPad: „Fahrzeug angesteckt“, „Lädt“, „Vorgabe wird berücksichtigt“, „Erreichbar“ zeigen Ja/Nein statt roter Fehlermeldung | sofort | offen |
| Zweiter Modbus-Client | Läuft die Symcon-Vorlage (ModBus-Gateway) parallel ohne Störung? | sofort | offen |

## Ideen für später

- Kachel (Leistung, Phasen, Ladevorgang, Schalter)
- Liste der letzten Ladevorgänge mit Dauer und Kosten
- Lademodi im Modul für Betrieb ohne Energie Manager (Sofort, Min + PV, nur Überschuss)
- Werte auswählen (einzelne Variablen abwählen), Englisch (locale.json)

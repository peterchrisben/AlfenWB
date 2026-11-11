# AlfenWB – Testliste und Ideen

## Tests an einer echten Wallbox (Build 1 ist nur simuliert)

| Was | Wie prüfen | Wann | Status |
|---|---|---|---|
| Verbindung und Lesen | IP eintragen, „Verbindung testen“; Spannungen ~230 V, Status passt zum Fahrzeug | sofort | offen |
| Registeradressen / Wortreihenfolge | Debug-Fenster: keine Meldung „Register nicht vorhanden“; Werte plausibel | sofort | offen |
| Zählerstand | Wert wie in der Alfen-App; bleibt er leer, liefert der Zähler ihn nicht (dann wird die Energie gerechnet) | sofort | offen |
| Name/Firmware/Temperatur (Adresse 200) | Titel „Einrichtung (… · Firmware …)“, Variable Temperatur | sofort | offen |
| Übernahme beim Freigeben | Steuerung freigeben: Laden läuft unverändert weiter, „Laden erlaubt“ und Ladestrom passen | Fahrzeug steckt | offen |
| Vorgabe bleibt gültig | 10 Minuten mit 10 A laden: kein Abfall auf den sicheren Strom, „Vorgabe gültig noch“ springt immer wieder hoch | Fahrzeug steckt | offen |
| Pause | „Laden erlaubt“ aus: Status „… Wallbox gibt nicht frei“, bleibt so (auch nach 5 Minuten) | Fahrzeug steckt | offen |
| Weiterladen nach Pause | „Laden erlaubt“ an: Fahrzeug lädt von selbst wieder? (Fiat 500e, Tesla) | Fahrzeug steckt | offen |
| Sicherer Strom | ⚠-Hinweis im Formular, solange in ACE mehr als 0 A eingestellt sind | sofort | offen |
| Energie Manager: Aktiv / Inaktiv | Modus umschalten: lädt voll / lädt nicht | Fahrzeug steckt | offen |
| Energie Manager: Automatisch | Was schreibt er in „Ladeleistung (Soll)“ und „Laden erlaubt“ (Archiv/Debug)? Folgt die Ladeleistung dem Überschuss? | Sonne | offen |
| Phasenumschaltung | Phasenwahl 1-phasig → Pause, Umschalten, lädt 1-phasig; zurück auf 3-phasig | Fahrzeug steckt, in ACE freigegeben | offen |
| Phasen-Automatik | Vorgabe 2000 W → 1-phasig; 6000 W → nach Wartezeit 3-phasig | Fahrzeug steckt | offen |
| Symcon aus | Symcon stoppen: nach der Gültigkeitsdauer nimmt die Wallbox den sicheren Strom | Fahrzeug steckt | offen |
| Zweiter Modbus-Client | Läuft die Symcon-Vorlage (ModBus-Gateway) parallel ohne Störung? | sofort | offen |

## Ideen für später

- Kachel (Leistung, Phasen, Ladevorgang, Schalter)
- Liste der letzten Ladevorgänge mit Dauer und Kosten
- Lademodi im Modul für Betrieb ohne Energie Manager (Sofort, Min + PV, nur Überschuss)
- Werte auswählen (einzelne Variablen abwählen), Englisch (locale.json)

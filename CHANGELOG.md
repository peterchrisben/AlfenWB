# Changelog

## Version 1.0

### Build 4 (12.11.2026)

#### Behoben
- Symcon-App (iPhone/iPad): Ja/Nein-Werte („Fahrzeug angesteckt“, „Lädt“, „Vorgabe wird berücksichtigt“, „Erreichbar“) zeigten „Invalid Configuration: type 'Null' is not a subtype of type 'bool'“. Den Optionen der Wertanzeige fehlte der Schalter für die Farbe. Im Browser war die Anzeige in Ordnung.

### Build 3 (12.11.2026)

#### Neu
- Meldung „Wallbox wieder erreichbar“, sobald die Verbindung nach einem Ausfall zurück ist (vorher blieb „Wallbox nicht erreichbar“ in „Letzte Meldung“ stehen)

#### Dokumentation
- Die ACE-Option für die Phasenumschaltung heißt je nach Version „Allow single-/multiphase charging“ oder „Allow 1- and 3-phased charging“ – im Formular und in der Anleitung stehen jetzt beide Namen
- Hinweis, was der sichere Strom bei einem Ausfall von Symcon oder Netzwerk bewirkt

### Build 2 (12.11.2026)

#### Neu
- „Letzte Meldung“ nennt den Grund, wenn wegen zu kleiner Leistung pausiert wird (z. B. „Ladepause: 3.000 W reichen nicht für 3 Phasen (mindestens 4.140 W)“), und meldet, wenn die Leistung wieder reicht

#### Behoben
- „Phasen (Wallbox)“ zeigte „0“, solange die Wallbox noch nichts gemeldet hat – jetzt „unbekannt“

#### Dokumentation
- Ergebnisse des ersten Praxistests an einer Eve Single S-line; was der Energie Manager in den Modi schreibt; Hinweis zu Zeitstempeln

### Build 1 (11.11.2026)

#### Neu
- Erste Version: Alfen Eve Single / Eve Double lokal über Modbus TCP (eigene kurze Verbindung je Abfrage, kein Gateway nötig)
- Zustand im Klartext, Leistung, Ströme, Spannungen, Zählerstand, Temperatur, Energie je Ladevorgang
- Steuerung (nach Freigabe): Laden erlaubt, Ladeleistung (Soll) in Watt, Ladestrom (Soll) in Ampere
- Vorgabe wird laufend erneuert, damit sie in der Wallbox nicht abläuft (auch 0 A = Pause)
- Phasenumschaltung 1-/3-phasig: Automatik, fest 1- oder 3-phasig; Hochschalten mit Wartezeit
- Hinweise im Formular: sicherer Strom größer 0, Vorgabe wird nicht berücksichtigt, Gültigkeitsdauer zu kurz
- Hilfe im Formular für die Einrichtung im Energie Manager (Variablen mit ID)

# Changelog

## Version 1.0

### Build 1 (11.11.2026)

#### Neu
- Erste Version: Alfen Eve Single / Eve Double lokal über Modbus TCP (eigene kurze Verbindung je Abfrage, kein Gateway nötig)
- Zustand im Klartext, Leistung, Ströme, Spannungen, Zählerstand, Temperatur, Energie je Ladevorgang
- Steuerung (nach Freigabe): Laden erlaubt, Ladeleistung (Soll) in Watt, Ladestrom (Soll) in Ampere
- Vorgabe wird laufend erneuert, damit sie in der Wallbox nicht abläuft (auch 0 A = Pause)
- Phasenumschaltung 1-/3-phasig: Automatik, fest 1- oder 3-phasig; Hochschalten mit Wartezeit
- Hinweise im Formular: sicherer Strom größer 0, Vorgabe wird nicht berücksichtigt, Gültigkeitsdauer zu kurz
- Hilfe im Formular für die Einrichtung im Energie Manager (Variablen mit ID)

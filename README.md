# AlfenWB – Alfen Wallbox für Symcon

Aktuelle Version: 1.0 (Build 1)

Bindet eine **Alfen Eve Single (S-line, Pro-line)** oder **Eve Double** lokal über Modbus TCP in Symcon ein – ohne Cloud.
Das Modul liest Zustand und Messwerte und stellt einfache Stellgrößen bereit, die der **Energie Manager** von Symcon direkt bedienen kann.

> Stand Build 1: gegen eine nachgebaute Wallbox getestet (Simulation), **noch nicht an einer echten Alfen**. Rückmeldungen sind willkommen.

## Was das Modul macht

- **Vorgabe am Leben halten:** Die Alfen verwirft eine Stromvorgabe nach ihrer Gültigkeitsdauer (Standard 60 s) und fällt auf den „sicheren Strom“ zurück. Das Modul sendet die Vorgabe deshalb laufend neu – auch die Vorgabe „0 A“ (Pause).
- **Leistung statt Ampere:** Der Energie Manager gibt eine Leistung in Watt vor, das Modul rechnet in Strom um (230 V je Phase) und schaltet auf Wunsch selbst zwischen 1- und 3-phasig um.
- **Klartext:** Status („Lädt“, „Angesteckt – Wallbox gibt nicht frei“ …), Hinweise zur Einrichtung der Wallbox direkt im Formular.
- **Ladevorgang:** Energie von Anstecken bis Abstecken, dazu der letzte Ladevorgang.
- **Sicher bei Ausfall:** Fällt Symcon aus, läuft die Vorgabe in der Wallbox ab und sie nimmt ihren sicheren Strom (0 A = lädt nicht).

## Voraussetzungen

- Symcon ab Version 8.0
- Wallbox per **Netzwerkkabel** im Heimnetz, am besten mit fester IP-Adresse
- Lizenz **„Active Load Balancing“** in der Wallbox
- Einstellungen im **ACE Service Installer** (Installateur-Zugang):
  1. *Load balancing → Active balancing*: „Active Load Balancing“ an, Data Source **Energy Management System**
  2. *TCP/IP EMS*: Modus **Socket**, Lesen und Schreiben der Maximalströme erlauben, Validity time 60 s (oder mehr)
  3. **Safe current**: Strom, mit dem die Wallbox ohne gültige Vorgabe lädt. Soll ohne Symcon **nicht** geladen werden: **0 A**.
  4. Optional: „Allow 1- and 3-phased charging“, wenn das Modul die Phasen umschalten soll
- Die Wallbox erlaubt höchstens zwei Modbus-Verbindungen gleichzeitig. Das Modul öffnet je Abfrage eine kurze Verbindung.

## Einrichtung in Symcon

1. Modul installieren, Instanz **„Alfen Wallbox“** anlegen.
2. Unter **Einrichtung** die IP-Adresse eintragen (Port 502, Ladepunkt 1), übernehmen, **Verbindung testen**.
3. Werte prüfen. Bis hierhin wird nur gelesen.
4. Unter **Steuerung** „Steuerung freigeben“ anhaken, größten Ladestrom einstellen (11 kW = 16 A). Beim ersten Freigeben übernimmt das Modul den Zustand der Wallbox, es ändert sich also zunächst nichts.
5. Steht oben im Formular ein ⚠-Hinweis, die genannte Einstellung in ACE prüfen.

## Energie Manager von Symcon

Im Energie Manager ein **Elektrofahrzeug** mit Vorlage „Benutzerdefiniert“ anlegen. Das Formular der Wallbox-Instanz zeigt unter „Energie Manager einrichten“ die passenden Variablen mit ID:

| Feld im Energie Manager | Variable der Wallbox |
|---|---|
| Leistung (Soll) | **Ladeleistung (Soll)**, Einheit Watt |
| Variable für An/Aus | **Laden erlaubt** |
| Aktueller Verbrauch | **Ladeleistung** |
| Phasen | 3, „Setze Leistung: Gesamt“ (mit Phasenumschaltung: zusätzlich Phasenumschaltung an) |
| Min./Max. Ladestrom pro Phase | 6 A / 16 A (wie im Modul eingestellt) |
| Verzögerung | Empfehlung 30–60 s |

Damit gilt: **Automatisch** = Überschussladen, **Aktiv** = Laden mit voller Leistung, **Inaktiv** = kein Laden.
Wer lieber in Ampere regelt, nimmt stattdessen **Ladestrom (Soll)** mit Einheit Ampere.

Liegt die Leistung unter dem kleinsten Ladestrom (3-phasig 4140 W, 1-phasig 1380 W), pausiert das Modul.

## Phasenumschaltung

Nur mit Häkchen „Wallbox darf zwischen 1- und 3-phasig umschalten“ (und Freigabe in ACE). Die Variable **Phasenwahl** bietet Automatik, 1-phasig, 3-phasig.

Automatik (bei Vorgabe in Watt):
- unter 4140 W → sofort 1-phasig (1380–3680 W)
- ab 4140 W → 3-phasig, aber erst, wenn die Leistung die eingestellte Zeit lang gereicht hat (Standard 180 s); bis dahin 1-phasig mit vollem Strom
- Ablauf einer Umschaltung: Ladepause (0 A) → etwa 10 s warten → Phasen umschalten → weiterladen
- Lehnt die Wallbox die Umschaltung ab, lädt das Modul mit der vorhandenen Phasenzahl weiter und meldet das.

## Variablen

| Variable | Bedeutung |
|---|---|
| Laden erlaubt | Schalter; aus = Pause (0 A) |
| Ladeleistung (Soll) | Vorgabe in Watt |
| Ladestrom (Soll) | Vorgabe in Ampere je Phase |
| Phasenwahl | nur mit Phasenumschaltung |
| Status | Zustand im Klartext |
| Fahrzeug angesteckt / Lädt | Ja/Nein |
| Ladeleistung | gemessene Leistung in Watt |
| Energie Ladevorgang / letzter Ladevorgang | kWh von Anstecken bis Abstecken |
| Zählerstand | Zähler der Wallbox in kWh |
| Ladestrom (wirksam) | Strom, den die Wallbox gerade zulässt |
| Phasen (Wallbox) | 1- oder 3-phasig laut Wallbox |
| Vorgabe wird berücksichtigt | Nein = Einstellung in ACE prüfen |
| Vorgabe gültig noch | Restzeit bis zum Rückfall auf den sicheren Strom |
| Sicherer Strom (ohne Vorgabe) | Einstellung der Wallbox (nur Anzeige) |
| Zustand (Mode 3) | Rohwert A, B1, B2, C1, C2, E, F |
| Temperatur Platine, Strom/Spannung L1–L3 | Messwerte |
| Erreichbar, Letzte Meldung | Verbindung und Klartext-Meldungen |

Die letzte Vorgabe (Strom **oder** Leistung) gilt; die jeweils andere Variable wird zur Anzeige nachgeführt.

## Funktionen

```php
ALFEN_Update(int $InstanzID): bool                          // jetzt abfragen
ALFEN_SetChargingAllowed(int $InstanzID, bool $Allowed): bool
ALFEN_SetTargetPower(int $InstanzID, float $Watt): bool     // 0 = Pause
ALFEN_SetTargetCurrent(int $InstanzID, float $Ampere): bool // 0 = Pause
ALFEN_SetPhaseMode(int $InstanzID, int $Mode): bool         // 0 Automatik, 1, 3
```

## Hinweise

- Karten/RFID und die Ladefreigabe der Wallbox selbst bleiben unberührt; das Modul gibt nur den Strom vor.
- Ob ein Fahrzeug nach einer Pause von selbst weiterlädt, hängt vom Fahrzeug ab.
- Eve Double: je Ladepunkt eine Instanz (Ladepunkt 1 und 2).

## Lizenz und Marken

CC BY-NC-SA 4.0. Alfen und Eve sind Marken ihres jeweiligen Inhabers. AlfenWB ist ein privates Projekt und steht in keiner Verbindung zum Hersteller.

Autor: Peter Chrisben

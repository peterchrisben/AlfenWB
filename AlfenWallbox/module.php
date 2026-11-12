<?php

declare(strict_types=1);

/**
 * AlfenWB – Alfen Wallbox (Eve Single / Eve Double) lokal über Modbus TCP
 * Autor: Peter Chrisben
 *
 * Liest Messwerte und Zustand, hält die Ladevorgabe am Leben (die Wallbox verwirft sie nach
 * ihrer Gültigkeitsdauer) und bietet einfache Stellgrößen für den Energie Manager von Symcon.
 */

require_once __DIR__ . '/../libs/AlfenWBModbus.php';

class AlfenWallbox extends IPSModule
{
    private const VOLTAGE = 230;            // Rechenwert je Phase (wie der Energie Manager)
    private const STATION_UNIT = 200;       // Modbus-Adresse der Station (Name, Temperatur)
    private const FAIL_LIMIT = 3;           // Fehlschläge in Folge, bis „nicht erreichbar“ gemeldet wird
    private const PHASE_PAUSE = 10;         // Sekunden Ladepause vor einer Phasenumschaltung
    private const PHASE_PAUSE_MAX = 40;     // spätestens dann wird umgeschaltet
    private const PHASE_BLOCK = 600;        // Sekunden Sperre, wenn die Wallbox die Umschaltung ablehnt

    private const STATUS_TEXT = [
        0 => 'Kein Fahrzeug',
        1 => 'Angesteckt – Wallbox gibt nicht frei',
        2 => 'Angesteckt – Fahrzeug lädt nicht',
        3 => 'Fahrzeug bereit – Wallbox gibt nicht frei',
        4 => 'Lädt',
        5 => 'Fahrzeug bereit (Lüftung) – Wallbox gibt nicht frei',
        6 => 'Lädt (Lüftung)',
        7 => 'Störung',
        8 => 'Nicht verfügbar',
        9 => 'Unbekannt'
    ];
    private const MODE3 = ['A' => 0, 'B1' => 1, 'B2' => 2, 'C1' => 3, 'C2' => 4, 'D1' => 5, 'D2' => 6, 'E' => 7, 'F' => 8];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 502);
        $this->RegisterPropertyInteger('Socket', 1);
        $this->RegisterPropertyInteger('Interval', 5);

        $this->RegisterPropertyBoolean('AllowControl', false);
        $this->RegisterPropertyInteger('MinCurrent', 6);
        $this->RegisterPropertyInteger('MaxCurrent', 16);
        $this->RegisterPropertyInteger('Phases', 3);
        $this->RegisterPropertyBoolean('PhaseSwitching', false);
        $this->RegisterPropertyInteger('PhaseUpDelay', 180);

        $this->RegisterAttributeString('Target', '');
        $this->RegisterAttributeString('Session', '');
        $this->RegisterAttributeString('Info', '');
        $this->RegisterAttributeInteger('Validity', 60);

        $this->RegisterTimer('Update', 0, 'ALFEN_Update($_IPS[\'TARGET\']);');
        $this->RegisterTimer('Apply', 0, 'ALFEN_ApplyTarget($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->MaintainVariables();
        $this->SetBuffer('Run', '');
        $this->SetTimerInterval('Apply', 0);

        if (trim($this->ReadPropertyString('Host')) === '') {
            $this->SetTimerInterval('Update', 0);
            $this->SetStatusIfChanged(104);
            return;
        }
        $this->SetTimerInterval('Update', max(2, $this->ReadPropertyInteger('Interval')) * 1000);
        if ($this->GetStatus() !== 201) {
            $this->SetStatusIfChanged(102);
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'ChargingAllowed':
                $this->SetChargingAllowed((bool) $Value);
                break;
            case 'TargetCurrent':
                $this->SetTargetCurrent((float) $Value);
                break;
            case 'TargetPower':
                $this->SetTargetPower((float) $Value);
                break;
            case 'PhaseMode':
                $this->SetPhaseMode((int) $Value);
                break;
            default:
                throw new Exception('Ungültiger Ident: ' . $Ident);
        }
    }

    // ------------------------------------------------------------ öffentliche Funktionen

    /** Laden erlauben (true) oder pausieren (false). */
    public function SetChargingAllowed(bool $Allowed): bool
    {
        $this->RequireControl();
        $this->SetValue('ChargingAllowed', $Allowed);
        $this->ScheduleApply();
        return true;
    }

    /** Ladestrom je Phase in Ampere vorgeben. 0 = pausieren. */
    public function SetTargetCurrent(float $Ampere): bool
    {
        $this->RequireControl();
        $min = (float) $this->MinCurrent();
        $max = (float) $this->MaxCurrent();
        $ampere = $Ampere < $min ? 0.0 : min($max, round($Ampere, 1));
        $target = $this->Target();
        $target['known'] = true;
        $target['mode'] = 'current';
        $target['current'] = $ampere;
        $this->SaveTarget($target);
        $this->SetValue('TargetCurrent', $ampere);
        $this->ScheduleApply();
        return true;
    }

    /** Ladeleistung in Watt vorgeben; Strom und (falls freigegeben) Phasen wählt das Modul. 0 = pausieren. */
    public function SetTargetPower(float $Watt): bool
    {
        $this->RequireControl();
        $watt = max(0.0, round($Watt));
        $target = $this->Target();
        $target['known'] = true;
        $target['mode'] = 'power';
        $target['power'] = $watt;
        $this->SaveTarget($target);
        $this->SetValue('TargetPower', $watt);
        $this->ScheduleApply();
        return true;
    }

    /** Phasenwahl: 0 = Automatik, 1 = 1-phasig, 3 = 3-phasig. */
    public function SetPhaseMode(int $Mode): bool
    {
        $this->RequireControl();
        if (!$this->ReadPropertyBoolean('PhaseSwitching')) {
            throw new Exception('Die Phasenumschaltung ist im Modul nicht freigegeben.');
        }
        if (!in_array($Mode, [0, 1, 3], true)) {
            throw new Exception('Erlaubt sind 0 (Automatik), 1 oder 3.');
        }
        $this->SetValue('PhaseMode', $Mode);
        $this->ScheduleApply();
        return true;
    }

    /** Wird vom Timer kurz nach einer neuen Vorgabe aufgerufen (fasst mehrere Vorgaben zusammen). */
    public function ApplyTarget(): void
    {
        $this->SetTimerInterval('Apply', 0);
        $this->Update();
    }

    /** Knopf im Formular: einmal abfragen und das Ergebnis im Formular anzeigen. */
    public function TestConnection(): void
    {
        $ok = $this->Update();
        $text = $ok ? '✔ ' . $this->OverviewText() : '✖ ' . $this->GetValue('LastMessage');
        $this->UpdateFormField('Result', 'caption', $text);
        $this->UpdateFormField('Overview', 'caption', $this->OverviewText());
    }

    /** Fragt die Wallbox ab und erneuert bei freigegebener Steuerung die Vorgabe. */
    public function Update(): bool
    {
        if (trim($this->ReadPropertyString('Host')) === '') {
            return false;
        }
        $key = 'AlfenWB' . $this->InstanceID . '_Update';
        if (!IPS_SemaphoreEnter($key, 4000)) {
            return false;
        }
        try {
            return $this->Cycle();
        } finally {
            IPS_SemaphoreLeave($key);
        }
    }

    // ------------------------------------------------------------ Abfrage

    private function Cycle(): bool
    {
        $run = $this->Run();
        $unit = max(1, $this->ReadPropertyInteger('Socket'));
        $modbus = $this->CreateClient();
        try {
            $modbus->Open();
            $measure = $modbus->ReadHolding($unit, 306, 40);    // 306–345: Spannungen, Ströme, Frequenz, Leistung
            $state = $modbus->ReadHolding($unit, 1200, 16);     // 1200–1215: Zustand und Vorgabe
            $energy = $this->ReadOptional($modbus, $unit, 374, 4);

            $run['swap'] = $this->DetectWordOrder($measure, (bool) $run['swap']);
            $data = $this->Decode($measure, $state, $energy, (bool) $run['swap']);
            $this->ReadStation($modbus, $run);

            $run['fails'] = 0;
            if ($this->GetStatus() === 201) {
                $this->Message('Wallbox wieder erreichbar.', KL_MESSAGE);
            }
            $this->Publish($data, $run);
            if ($this->ReadPropertyBoolean('AllowControl')) {
                $this->Control($modbus, $data, $run);
            }
            $this->SetValueIfChanged('Online', true);
            $this->SetStatusIfChanged(102);
            $this->SaveRun($run);
            return true;
        } catch (Throwable $e) {
            $run['fails'] = (int) $run['fails'] + 1;
            $run['lastAmps'] = null;    // nach einer Störung die Vorgabe sofort neu senden
            $this->SaveRun($run);
            $this->SendDebug('Fehler', $e->getMessage(), 0);
            if ($run['fails'] >= self::FAIL_LIMIT) {
                $first = $this->GetValue('Online') === true || $this->GetStatus() !== 201;
                $this->SetValueIfChanged('Online', false);
                $this->SetStatusIfChanged(201);
                $this->Message('Wallbox nicht erreichbar: ' . $e->getMessage(), $first ? KL_WARNING : 0);
            }
            return false;
        } finally {
            $modbus->Close();
        }
    }

    /** Eigene Methode, damit Tests einen anderen Client einsetzen können. */
    protected function CreateClient(): AlfenWBModbus
    {
        return new AlfenWBModbus(trim($this->ReadPropertyString('Host')), $this->ReadPropertyInteger('Port'), 3.0);
    }

    private function ReadOptional(AlfenWBModbus $modbus, int $unit, int $address, int $quantity): ?string
    {
        try {
            return $modbus->ReadHolding($unit, $address, $quantity);
        } catch (Exception $e) {
            if ($e->getCode() === 0) {
                throw $e;   // Verbindungsfehler: Abfrage abbrechen
            }
            $this->SendDebug('Register ' . $address, $e->getMessage(), 0);
            return null;
        }
    }

    /** Name, Firmware, Seriennummer (stündlich) und Temperatur (minütlich) von der Station. */
    private function ReadStation(AlfenWBModbus $modbus, array &$run): void
    {
        $now = time();
        if ($now - (int) $run['lastInfo'] >= 3600) {
            $run['lastInfo'] = $now;
            $block = $this->ReadOptional($modbus, self::STATION_UNIT, 100, 68);
            if ($block !== null) {
                $info = [
                    'name'     => AlfenWBModbus::Text($block, 100, 100, 17),
                    'firmware' => AlfenWBModbus::Text($block, 100, 123, 17),
                    'platform' => AlfenWBModbus::Text($block, 100, 140, 17),
                    'serial'   => AlfenWBModbus::Text($block, 100, 157, 11)
                ];
                if (json_encode($info) !== $this->ReadAttributeString('Info')) {
                    $this->WriteAttributeString('Info', json_encode($info));
                }
                $this->SendDebug('Station', json_encode($info), 0);
            }
        }
        if ($now - (int) $run['lastStation'] >= 60) {
            $run['lastStation'] = $now;
            $block = $this->ReadOptional($modbus, self::STATION_UNIT, 1100, 4);
            if ($block !== null) {
                $temperature = AlfenWBModbus::Float32($block, 1100, 1102, (bool) $run['swap']);
                if (!is_nan($temperature) && $temperature > -50 && $temperature < 150) {
                    $this->SetValueIfChanged('Temperature', round($temperature, 1));
                }
            }
        }
    }

    /**
     * Die Alfen-Unterlagen widersprechen sich bei der Wortreihenfolge. Geprüft wird an der
     * Netzfrequenz (bzw. Spannung L1): Was plausibel aussieht, gilt.
     */
    private function DetectWordOrder(string $measure, bool $current): bool
    {
        foreach ([[336, 45.0, 65.0], [306, 90.0, 300.0]] as [$register, $min, $max]) {
            $normal = AlfenWBModbus::Float32($measure, 306, $register, false);
            $swapped = AlfenWBModbus::Float32($measure, 306, $register, true);
            $normalOk = !is_nan($normal) && $normal >= $min && $normal <= $max;
            $swappedOk = !is_nan($swapped) && $swapped >= $min && $swapped <= $max;
            if ($normalOk !== $swappedOk) {
                if ($swappedOk !== $current) {
                    $this->SendDebug('Wortreihenfolge', $swappedOk ? 'vertauscht' : 'normal', 0);
                }
                return $swappedOk;
            }
        }
        return $current;
    }

    private function Decode(string $measure, string $state, ?string $energy, bool $swap): array
    {
        $f = function (int $register) use ($measure, $swap): float {
            return AlfenWBModbus::Float32($measure, 306, $register, $swap);
        };
        $s = function (int $register) use ($state, $swap): float {
            return AlfenWBModbus::Float32($state, 1200, $register, $swap);
        };
        $phasePower = [$f(338), $f(340), $f(342)];
        $power = $f(344);
        if (is_nan($power)) {
            $valid = array_filter($phasePower, fn ($v) => !is_nan($v));
            $power = count($valid) > 0 ? array_sum($valid) : NAN;
        }
        $currents = [$f(320), $f(322), $f(324)];
        if (is_nan($power)) {
            // Zähler liefert keine Leistung: aus Strom und Spannung schätzen
            $power = 0.0;
            foreach ([306, 308, 310] as $i => $register) {
                $u = $f($register);
                $power += (is_nan($u) ? self::VOLTAGE : $u) * (is_nan($currents[$i]) ? 0.0 : $currents[$i]);
            }
        }
        $mode3 = strtoupper(AlfenWBModbus::Text($state, 1200, 1201, 5));
        $meter = $energy === null ? NAN : AlfenWBModbus::Float64($energy, 374, 374, $swap);
        $phases = AlfenWBModbus::UInt16($state, 1200, 1215);

        return [
            'voltage'   => [$f(306), $f(308), $f(310)],
            'current'   => $currents,
            'power'     => max(0.0, $power),
            'meter'     => (is_nan($meter) || $meter < 0) ? null : $meter / 1000.0,   // kWh
            'available' => AlfenWBModbus::UInt16($state, 1200, 1200) === 1,
            'mode3'     => $mode3,
            'status'    => self::MODE3[$mode3] ?? 9,
            'connected' => in_array($mode3[0] ?? '', ['B', 'C', 'D'], true),
            'charging'  => in_array($mode3, ['C2', 'D2'], true),
            'applied'   => $s(1206),
            'remaining' => AlfenWBModbus::UInt32($state, 1200, 1208, $swap),
            'setpoint'  => $s(1210),
            'safe'      => $s(1212),
            'accepted'  => AlfenWBModbus::UInt16($state, 1200, 1214) === 1,
            'phases'    => in_array($phases, [1, 3], true) ? $phases : 0
        ];
    }

    private function Publish(array $d, array &$run): void
    {
        $this->SetValueIfChanged('Status', $d['status']);
        $this->SetValueIfChanged('Mode3', $d['mode3']);
        $this->SetValueIfChanged('VehicleConnected', $d['connected']);
        $this->SetValueIfChanged('Charging', $d['charging']);
        $this->SetValueIfChanged('Power', round($d['power']));
        foreach ([1, 2, 3] as $i) {
            if (!is_nan($d['current'][$i - 1])) {
                $this->SetValueIfChanged('CurrentL' . $i, round($d['current'][$i - 1], 2));
            }
            if (!is_nan($d['voltage'][$i - 1])) {
                $this->SetValueIfChanged('VoltageL' . $i, round($d['voltage'][$i - 1], 1));
            }
        }
        if ($d['meter'] !== null) {
            $this->SetValueIfChanged('Meter', round($d['meter'], 3));
        }
        if (!is_nan($d['applied'])) {
            $this->SetValueIfChanged('AppliedCurrent', round($d['applied'], 1));
        }
        if (!is_nan($d['safe'])) {
            $this->SetValueIfChanged('SafeCurrent', round($d['safe'], 1));
        }
        if ($d['phases'] > 0) {
            $this->SetValueIfChanged('ActivePhases', $d['phases']);
        }
        $this->SetValueIfChanged('ValidRemaining', min(86400, $d['remaining']));
        $this->SetValueIfChanged('SetpointAccepted', $d['accepted']);

        $this->UpdateSession($d, $run);
    }

    /** Ein Ladevorgang läuft von Anstecken bis Abstecken. */
    private function UpdateSession(array $d, array &$run): void
    {
        $session = json_decode($this->ReadAttributeString('Session'), true);
        if (!is_array($session)) {
            $session = ['active' => false, 'start' => null, 'energy' => 0.0];
        }
        $before = $session;
        $now = time();
        $step = 0.0;    // kWh seit der letzten Abfrage, falls der Zähler nichts liefert
        if ((int) $run['lastPoll'] > 0) {
            $seconds = min(60, max(0, $now - (int) $run['lastPoll']));
            $step = $d['power'] * $seconds / 3600000.0;
        }
        $run['lastPoll'] = $now;

        if ($d['connected'] && !$session['active']) {
            $session = ['active' => true, 'start' => $d['meter'], 'energy' => 0.0];
        } elseif ($d['connected']) {
            if ($d['meter'] !== null && $session['start'] !== null && $d['meter'] >= $session['start']) {
                $session['energy'] = $d['meter'] - $session['start'];
            } else {
                $session['energy'] = (float) $session['energy'] + $step;
                if ($d['meter'] !== null) {
                    $session['start'] = $d['meter'] - $session['energy'];
                }
            }
        } elseif ($session['active']) {
            $session['active'] = false;
            $this->SetValueIfChanged('LastSessionEnergy', round((float) $session['energy'], 2));
        }
        if ($session['active'] || $before['active']) {
            $this->SetValueIfChanged('SessionEnergy', round((float) $session['energy'], 2));
        }
        // Attribut nur bei spürbarer Änderung schreiben (schont die Einstellungsdatei)
        if ($session['active'] !== $before['active'] || abs((float) $session['energy'] - (float) $before['energy']) >= 0.01) {
            $this->WriteAttributeString('Session', json_encode($session));
        }
    }

    // ------------------------------------------------------------ Steuerung

    private function Control(AlfenWBModbus $modbus, array $d, array &$run): void
    {
        $now = time();
        $unit = max(1, $this->ReadPropertyInteger('Socket'));
        $min = (float) $this->MinCurrent();
        $max = (float) $this->MaxCurrent();
        $interval = max(2, $this->ReadPropertyInteger('Interval'));

        // Gültigkeitsdauer der Wallbox lernen: Restzeit direkt nach dem Schreiben
        if (!empty($run['justWrote']) && $d['remaining'] > 0 && $d['remaining'] < 86400) {
            $validity = $d['remaining'] + $interval;
            if (abs($validity - $this->ReadAttributeInteger('Validity')) > $interval + 2) {
                $this->WriteAttributeInteger('Validity', $validity);
                $this->SendDebug('Gültigkeitsdauer', $validity . ' s', 0);
            }
        }
        // Wurde die letzte Vorgabe übernommen?
        if (!empty($run['justWrote']) && $run['lastAmps'] !== null && !is_nan($d['setpoint'])
            && abs($d['setpoint'] - (float) $run['lastAmps']) > 0.15) {
            $this->Message(sprintf('Wallbox meldet %.1f A statt der Vorgabe %.1f A zurück.', $d['setpoint'], $run['lastAmps']), KL_WARNING);
        }
        $run['justWrote'] = false;

        // Erste Übernahme: nichts verändern, sondern den Zustand der Wallbox übernehmen
        $target = $this->Target();
        if (!$target['known']) {
            $applied = is_nan($d['applied']) ? 0.0 : $d['applied'];
            $target['known'] = true;
            $target['mode'] = 'current';
            $target['current'] = $applied >= $min ? min($max, round($applied, 1)) : $max;
            $this->SaveTarget($target);
            $this->SetValue('ChargingAllowed', $applied >= $min);
            $this->SetValue('TargetCurrent', $target['current']);
            $this->Message(sprintf('Steuerung übernommen: Laden %s, Ladestrom %.0f A.', $applied >= $min ? 'erlaubt' : 'pausiert', $target['current']), KL_MESSAGE);
        }

        $allowed = (bool) $this->GetValue('ChargingAllowed');
        $switching = $this->ReadPropertyBoolean('PhaseSwitching') && $now >= (int) $run['phaseBlock'];
        $actual = $d['phases'] > 0 ? $d['phases'] : $this->FixedPhases();
        $want = $actual;

        if ($switching && $allowed) {
            $phaseMode = (int) $this->GetValue('PhaseMode');
            if ($phaseMode === 1 || $phaseMode === 3) {
                $want = $phaseMode;
            } elseif ($target['mode'] === 'power') {
                $threshold = $min * 3 * self::VOLTAGE;      // kleinste Leistung auf 3 Phasen
                if ((float) $target['power'] >= $threshold) {
                    if ((int) $run['above'] === 0) {
                        $run['above'] = $now;
                    }
                    $want = ($actual === 3 || $now - (int) $run['above'] >= $this->ReadPropertyInteger('PhaseUpDelay')) ? 3 : 1;
                } else {
                    $run['above'] = 0;
                    $want = 1;
                }
            }
        }
        if (!$switching) {
            $run['switch'] = null;
        }

        // Phasenumschaltung: erst pausieren, dann umschalten, dann weiterladen
        if ($switching && $want !== $actual) {
            $pending = is_array($run['switch']) && $run['switch']['to'] === $want;
            $busy = $d['charging'] || $d['power'] > 200;
            if (!$pending && ($busy || (float) ($run['lastAmps'] ?? 0) > 0)) {
                $run['switch'] = ['to' => $want, 'at' => $now, 'tries' => 0];
                $this->WriteCurrent($modbus, $unit, 0.0, $run);
                $this->Message(sprintf('Wechsel auf %d-phasig: Ladepause.', $want), 0);
                return;
            }
            if (!$pending) {
                $run['switch'] = ['to' => $want, 'at' => $now - self::PHASE_PAUSE, 'tries' => 0];
            }
            $waited = $now - (int) $run['switch']['at'];
            if (($waited >= self::PHASE_PAUSE && !$busy) || $waited >= self::PHASE_PAUSE_MAX) {
                $run['switch']['tries'] = (int) $run['switch']['tries'] + 1;
                $refused = '';
                if ($run['switch']['tries'] > 3) {
                    $refused = 'Wallbox übernimmt die Umschaltung nicht';
                } else {
                    try {
                        $modbus->WriteMultiple($unit, 1215, pack('n', $want));
                        $this->SendDebug('Phasen', 'geschrieben: ' . $want, 0);
                    } catch (Exception $e) {
                        if ($e->getCode() === 0) {
                            throw $e;
                        }
                        $refused = $e->getMessage();
                    }
                }
                if ($refused !== '') {
                    $run['switch'] = null;
                    $run['phaseBlock'] = $now + self::PHASE_BLOCK;
                    $this->Message('Phasenumschaltung abgelehnt (' . $refused . '). In ACE die Option „Allow 1- and 3-phased charging“ bzw. „Allow single-/multiphase charging“ prüfen. Es wird mit ' . $actual . ' Phase(n) weitergeladen.', KL_WARNING);
                    $want = $actual;
                }
            }
            if ($want !== $actual) {
                $this->WriteCurrent($modbus, $unit, 0.0, $run);     // Pause halten, bis die Wallbox umgeschaltet hat
                return;
            }
        } elseif (is_array($run['switch'])) {
            $this->Message(sprintf('Wallbox lädt jetzt %d-phasig.', $actual), 0);
            $run['switch'] = null;
        }

        // Strom berechnen
        $phases = $this->ReadPropertyBoolean('PhaseSwitching') ? $actual : $this->FixedPhases();
        if ($target['mode'] === 'power') {
            $ampere = floor((float) $target['power'] / (self::VOLTAGE * $phases) * 10) / 10;
            $ampere = $ampere < $min ? 0.0 : min($max, $ampere);
            $this->SetValueIfChanged('TargetCurrent', $ampere);
        } else {
            $ampere = (float) $target['current'] < $min ? 0.0 : min($max, (float) $target['current']);
            $this->SetValueIfChanged('TargetPower', round($ampere * self::VOLTAGE * $phases));
        }
        // Klartext, wenn die vorgegebene Leistung für die aktuelle Phasenzahl nicht reicht
        $tooLow = $allowed && $target['mode'] === 'power' && (float) $target['power'] > 0 && $ampere === 0.0;
        if ($tooLow) {
            $text = sprintf('Ladepause: %s W reichen nicht für %s (mindestens %s W).',
                number_format((float) $target['power'], 0, ',', '.'),
                $phases === 1 ? '1 Phase' : '3 Phasen',
                number_format($min * self::VOLTAGE * $phases, 0, ',', '.'));
            if ($phases === 3 && $this->ReadPropertyBoolean('PhaseSwitching') && (int) $this->GetValue('PhaseMode') === 3) {
                $text .= ' Die Phasenwahl steht fest auf 3-phasig.';
            } elseif ($phases === 3 && !$this->ReadPropertyBoolean('PhaseSwitching')) {
                $text .= ' Die Phasenumschaltung ist im Modul nicht freigegeben.';
            }
            $this->Message($text, 0);
        } elseif (!empty($run['tooLow']) && $allowed && $ampere > 0) {
            $this->Message(sprintf('Leistung reicht wieder: Vorgabe %s A, %d-phasig.', number_format($ampere, 1, ',', ''), $phases), 0);
        }
        $run['tooLow'] = $tooLow;

        if (!$allowed) {
            $ampere = 0.0;
        }

        // Schreiben bei Änderung und regelmäßig vor Ablauf der Gültigkeitsdauer
        $refresh = max($interval, min(30, intdiv(max(10, $this->ReadAttributeInteger('Validity')), 3)));
        $changed = $run['lastAmps'] === null || abs((float) $run['lastAmps'] - $ampere) >= 0.05;
        if ($changed || $now - (int) $run['lastWrite'] >= $refresh) {
            $this->WriteCurrent($modbus, $unit, $ampere, $run);
        }
    }

    private function WriteCurrent(AlfenWBModbus $modbus, int $unit, float $ampere, array &$run): void
    {
        try {
            $modbus->WriteMultiple($unit, 1210, AlfenWBModbus::PackFloat32($ampere, (bool) $run['swap']));
        } catch (Exception $e) {
            if ($e->getCode() === 0) {
                throw $e;
            }
            $run['lastAmps'] = null;
            $this->Message('Wallbox nimmt die Vorgabe nicht an (' . $e->getMessage() . '). In ACE unter „TCP/IP EMS“ das Schreiben der Maximalströme erlauben.', KL_WARNING);
            return;
        }
        if ($run['lastAmps'] === null || abs((float) $run['lastAmps'] - $ampere) >= 0.05) {
            $this->SendDebug('Vorgabe', sprintf('%.1f A', $ampere), 0);
        }
        $run['lastAmps'] = $ampere;
        $run['lastWrite'] = time();
        $run['justWrote'] = true;
    }

    // ------------------------------------------------------------ Variablen

    private function MaintainVariables(): void
    {
        $control = $this->ReadPropertyBoolean('AllowControl');
        $min = $this->MinCurrent();
        $max = $this->MaxCurrent();
        $maxPower = $max * self::VOLTAGE * ($this->ReadPropertyBoolean('PhaseSwitching') ? 3 : $this->FixedPhases());

        // Stellgrößen
        $this->RegisterVariableBoolean('ChargingAllowed', 'Laden erlaubt',
            $control ? ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH] : $this->BoolText('Nein', 'Ja'), 10);
        $this->RegisterVariableFloat('TargetPower', 'Ladeleistung (Soll)', $control ? [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER, 'MIN' => 0, 'MAX' => $maxPower, 'STEP_SIZE' => 10,
            'SUFFIX' => ' W', 'DIGITS' => 0, 'USAGE_TYPE' => 5
        ] : $this->Number(' W', 0), 20);
        $this->RegisterVariableFloat('TargetCurrent', 'Ladestrom (Soll)', $control ? [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER, 'MIN' => 0, 'MAX' => $max, 'STEP_SIZE' => 0.1,
            'SUFFIX' => ' A', 'DIGITS' => 1, 'USAGE_TYPE' => 5
        ] : $this->Number(' A', 1), 30);
        $this->MaintainAction('ChargingAllowed', $control);
        $this->MaintainAction('TargetPower', $control);
        $this->MaintainAction('TargetCurrent', $control);

        $phaseTexts = [0 => 'Automatik', 1 => '1-phasig', 3 => '3-phasig'];
        $showPhaseMode = $this->ReadPropertyBoolean('PhaseSwitching');
        $this->MaintainVariable('PhaseMode', 'Phasenwahl', VARIABLETYPE_INTEGER,
            $control ? $this->Enumeration($phaseTexts) : $this->IntegerText($phaseTexts), 40, $showPhaseMode);
        if ($showPhaseMode) {
            $this->MaintainAction('PhaseMode', $control);
        }

        // Zustand
        $this->RegisterVariableInteger('Status', 'Status', $this->IntegerText(self::STATUS_TEXT), 100);
        $this->RegisterVariableBoolean('VehicleConnected', 'Fahrzeug angesteckt', $this->BoolText('Nein', 'Ja'), 110);
        $this->RegisterVariableBoolean('Charging', 'Lädt', $this->BoolText('Nein', 'Ja'), 120);
        $this->RegisterVariableFloat('Power', 'Ladeleistung', $this->Number(' W', 0), 130);
        $this->RegisterVariableFloat('SessionEnergy', 'Energie Ladevorgang', $this->Number(' kWh', 2), 140);
        $this->RegisterVariableFloat('LastSessionEnergy', 'Energie letzter Ladevorgang', $this->Number(' kWh', 2), 150);
        $this->RegisterVariableFloat('Meter', 'Zählerstand', $this->Number(' kWh', 3), 160);

        // Wallbox
        $this->RegisterVariableFloat('AppliedCurrent', 'Ladestrom (wirksam)', $this->Number(' A', 1), 200);
        $this->RegisterVariableInteger('ActivePhases', 'Phasen (Wallbox)', $this->IntegerText([0 => 'unbekannt', 1 => '1-phasig', 3 => '3-phasig']), 210);
        $this->RegisterVariableBoolean('SetpointAccepted', 'Vorgabe wird berücksichtigt', $this->BoolText('Nein', 'Ja'), 220);
        $this->RegisterVariableInteger('ValidRemaining', 'Vorgabe gültig noch', $this->Number(' s', 0), 230);
        $this->RegisterVariableFloat('SafeCurrent', 'Sicherer Strom (ohne Vorgabe)', $this->Number(' A', 1), 240);
        $this->RegisterVariableString('Mode3', 'Zustand (Mode 3)', '', 250);
        $this->RegisterVariableFloat('Temperature', 'Temperatur Platine', $this->Number(' °C', 1), 260);

        // Messwerte
        foreach ([1, 2, 3] as $i) {
            $this->RegisterVariableFloat('CurrentL' . $i, 'Strom L' . $i, $this->Number(' A', 2), 300 + $i);
            $this->RegisterVariableFloat('VoltageL' . $i, 'Spannung L' . $i, $this->Number(' V', 1), 310 + $i);
        }

        $this->RegisterVariableBoolean('Online', 'Erreichbar', $this->BoolText('Nein', 'Ja'), 900);
        $this->RegisterVariableString('LastMessage', 'Letzte Meldung', '', 910);

        // Grenzen können sich geändert haben
        if ($control) {
            $current = (float) $this->GetValue('TargetCurrent');
            if ($current > $max || ($current > 0 && $current < $min)) {
                $this->SetValue('TargetCurrent', $current > $max ? (float) $max : 0.0);
            }
        }
    }

    private function Number(string $suffix, int $digits): array
    {
        return ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => $suffix, 'DIGITS' => $digits];
    }

    /**
     * Boolean ohne Aktion: Wertanzeige mit Optionen. Jeder Eintrag braucht alle Schalter
     * (Icon/Farbe überschreiben) – fehlt einer, zeigt die Symcon-App „Invalid Configuration“.
     */
    private function BoolText(string $false, string $true): array
    {
        $options = [];
        foreach ([[false, $false], [true, $true]] as [$value, $caption]) {
            $options[] = [
                'Value' => $value, 'Caption' => $caption,
                'IconActive' => false, 'IconValue' => '',
                'ColorActive' => false, 'ColorValue' => -1,
                'ContentColorActive' => false, 'ContentColorValue' => -1,
                'Color' => -1
            ];
        }
        return ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'OPTIONS' => json_encode($options)];
    }

    /** Integer mit Aktion: Aufzählung. */
    private function Enumeration(array $texts): array
    {
        $options = [];
        foreach ($texts as $value => $caption) {
            $options[] = ['Value' => $value, 'Caption' => $caption, 'IconActive' => false, 'IconValue' => '', 'Color' => -1];
        }
        return ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION, 'OPTIONS' => json_encode($options)];
    }

    /** Integer ohne Aktion: Wertanzeige mit Intervallen (je Wert ein fester Text). */
    private function IntegerText(array $texts): array
    {
        $intervals = [];
        foreach ($texts as $value => $text) {
            $intervals[] = [
                'IntervalMinValue' => $value, 'IntervalMaxValue' => $value,
                'ConstantActive' => true, 'ConstantValue' => $text,
                'ConversionFactor' => 1,
                'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '',
                'DigitsActive' => false, 'DigitsValue' => 0,
                'IconActive' => false, 'IconValue' => '',
                'ColorActive' => false, 'ColorValue' => -1,
                'ContentColorActive' => false, 'ContentColorValue' => -1
            ];
        }
        return [
            'PRESENTATION'     => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'INTERVALS_ACTIVE' => true,
            'INTERVALS'        => json_encode($intervals)
        ];
    }

    // ------------------------------------------------------------ Formular

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $configured = trim($this->ReadPropertyString('Host')) !== '';
        $control = $this->ReadPropertyBoolean('AllowControl');
        $info = json_decode($this->ReadAttributeString('Info'), true);

        $setup = 'Einrichtung';
        if ($configured) {
            $setup .= ' (' . trim($this->ReadPropertyString('Host'));
            if (is_array($info) && ($info['name'] ?? '') !== '') {
                $setup .= ' · ' . $info['name'] . ' · Firmware ' . ($info['firmware'] ?? '?');
            }
            $setup .= ')';
        }
        $energyManager = $control
            ? "So trägst du die Wallbox im Energie Manager ein (Elektrofahrzeug, Vorlage „Benutzerdefiniert“):\n"
                . '• Leistung (Soll): „Ladeleistung (Soll)“ (ID ' . $this->VariableID('TargetPower') . "), Einheit Watt\n"
                . '• Variable für An/Aus: „Laden erlaubt“ (ID ' . $this->VariableID('ChargingAllowed') . ")\n"
                . '• Aktueller Verbrauch: „Ladeleistung“ (ID ' . $this->VariableID('Power') . ")\n"
                . '• Phasen: ' . ($this->ReadPropertyBoolean('PhaseSwitching') ? '3, Setze Leistung: Gesamt, Phasenumschaltung: an' : $this->FixedPhases() . ($this->FixedPhases() > 1 ? ', Setze Leistung: Gesamt' : '')) . "\n"
                . '• Min./Max. Ladestrom pro Phase: ' . $this->MinCurrent() . ' A / ' . $this->MaxCurrent() . " A\n"
                . 'Das Modul rechnet die Leistung selbst in Strom' . ($this->ReadPropertyBoolean('PhaseSwitching') ? ' und Phasen' : '') . ' um und erneuert die Vorgabe laufend.'
            : 'Erst oben „Steuerung freigeben“ anhaken und übernehmen – dann steht hier, welche Variablen der Energie Manager braucht.';

        $fill = [
            'Overview'       => ['caption' => $this->OverviewText()],
            'Hints'          => ['caption' => implode("\n", $this->Hints()), 'visible' => count($this->Hints()) > 0],
            'PanelSetup'     => ['caption' => $setup, 'expanded' => !$configured],
            'PanelControl'   => ['expanded' => $configured],
            'PanelEM'        => ['expanded' => $configured && $control],
            'EnergyManager'  => ['caption' => $energyManager],
            'PhaseUpDelay'   => ['visible' => $this->ReadPropertyBoolean('PhaseSwitching')],
            'Phases'         => ['visible' => !$this->ReadPropertyBoolean('PhaseSwitching')]
        ];
        $walk = function (array &$items) use (&$walk, $fill): void {
            foreach ($items as &$item) {
                if (isset($item['name'], $fill[$item['name']])) {
                    $item = array_merge($item, $fill[$item['name']]);
                }
                if (isset($item['items']) && is_array($item['items'])) {
                    $walk($item['items']);
                }
            }
        };
        $walk($form['elements']);
        $walk($form['actions']);
        return json_encode($form);
    }

    private function OverviewText(): string
    {
        if (trim($this->ReadPropertyString('Host')) === '') {
            return '○ Noch nicht eingerichtet – unter „Einrichtung“ die IP-Adresse der Wallbox eintragen.';
        }
        if ($this->GetStatus() === 201 || !$this->GetValue('Online')) {
            $message = (string) $this->GetValue('LastMessage');
            return $this->GetStatus() === 201 ? '● Nicht erreichbar  ·  ' . $message : '○ Noch keine Antwort der Wallbox';
        }
        $parts = ['● Verbunden', self::STATUS_TEXT[(int) $this->GetValue('Status')] ?? 'Unbekannt'];
        if ($this->GetValue('Charging')) {
            $parts[] = number_format((float) $this->GetValue('Power'), 0, ',', '.') . ' W';
        }
        if ($this->ReadPropertyBoolean('AllowControl')) {
            $parts[] = $this->GetValue('ChargingAllowed')
                ? 'Vorgabe ' . number_format((float) $this->GetValue('TargetCurrent'), 1, ',', '') . ' A'
                : 'Laden pausiert';
        } else {
            $parts[] = 'nur Anzeige (Steuerung nicht freigegeben)';
        }
        return implode('  ·  ', $parts);
    }

    /** Hinweise zur Einrichtung der Wallbox, die sich aus den gelesenen Werten ergeben. */
    private function Hints(): array
    {
        $hints = [];
        if (trim($this->ReadPropertyString('Host')) === '' || !$this->GetValue('Online')) {
            return $hints;
        }
        $safe = (float) $this->GetValue('SafeCurrent');
        if ($safe > 0) {
            $hints[] = sprintf('⚠ In der Wallbox ist ein sicherer Strom von %s A eingestellt: Ohne gültige Vorgabe (Symcon aus, Steuerung nicht freigegeben) lädt sie damit von selbst. Soll ohne Symcon nicht geladen werden, in ACE auf 0 A stellen.', number_format($safe, 0, ',', ''));
        }
        if ($this->ReadPropertyBoolean('AllowControl')) {
            if (!$this->GetValue('SetpointAccepted')) {
                $hints[] = '⚠ Die Wallbox berücksichtigt die Vorgabe nicht. In ACE prüfen: Active Load Balancing an, Datenquelle „Energy Management System“, unter „TCP/IP EMS“ Modus „Socket“ und Schreiben erlaubt.';
            }
            $validity = $this->ReadAttributeInteger('Validity');
            if ($validity < 3 * max(2, $this->ReadPropertyInteger('Interval'))) {
                $hints[] = sprintf('⚠ Die Gültigkeitsdauer der Wallbox (%d s) ist sehr kurz. Empfohlen sind 60 s oder mehr.', $validity);
            }
        }
        return $hints;
    }

    // ------------------------------------------------------------ Helfer

    private function RequireControl(): void
    {
        if (!$this->ReadPropertyBoolean('AllowControl')) {
            throw new Exception('Die Steuerung ist nicht freigegeben (Häkchen „Steuerung freigeben“ in der Instanz).');
        }
    }

    private function ScheduleApply(): void
    {
        // kurz warten: der Energie Manager schreibt oft mehrere Variablen nacheinander
        $this->SetTimerInterval('Apply', 300);
    }

    private function MinCurrent(): int
    {
        return max(6, min(32, $this->ReadPropertyInteger('MinCurrent')));
    }

    private function MaxCurrent(): int
    {
        return max($this->MinCurrent(), min(32, $this->ReadPropertyInteger('MaxCurrent')));
    }

    private function FixedPhases(): int
    {
        return $this->ReadPropertyInteger('Phases') === 1 ? 1 : 3;
    }

    private function Target(): array
    {
        $target = json_decode($this->ReadAttributeString('Target'), true);
        if (!is_array($target)) {
            $target = [];
        }
        return array_merge(['known' => false, 'mode' => 'current', 'current' => 0.0, 'power' => 0.0], $target);
    }

    private function SaveTarget(array $target): void
    {
        $json = json_encode($target);
        if ($json !== $this->ReadAttributeString('Target')) {
            $this->WriteAttributeString('Target', $json);
        }
    }

    private function Run(): array
    {
        $run = json_decode($this->GetBuffer('Run'), true);
        if (!is_array($run)) {
            $run = [];
        }
        return array_merge([
            'fails' => 0, 'swap' => false, 'lastPoll' => 0, 'lastInfo' => 0, 'lastStation' => 0,
            'lastWrite' => 0, 'lastAmps' => null, 'justWrote' => false,
            'above' => 0, 'switch' => null, 'phaseBlock' => 0, 'tooLow' => false
        ], $run);
    }

    private function SaveRun(array $run): void
    {
        $this->SetBuffer('Run', json_encode($run));
    }

    private function VariableID(string $ident): int
    {
        return (int) @$this->GetIDForIdent($ident);
    }

    /** Meldung in „Letzte Meldung“ (nur bei Änderung), optional zusätzlich ins Meldungsfenster. */
    private function Message(string $text, int $logType): void
    {
        if ($this->GetValue('LastMessage') === $text) {
            return;
        }
        $this->SetValue('LastMessage', $text);
        $this->SendDebug('Meldung', $text, 0);
        if ($logType > 0) {
            $this->LogMessage($text, $logType);
        }
    }

    private function SetValueIfChanged(string $ident, $value): void
    {
        $id = @$this->GetIDForIdent($ident);
        if (!$id) {
            return;
        }
        if (is_float($value) && IPS_GetVariable($id)['VariableType'] === VARIABLETYPE_INTEGER) {
            $value = (int) round($value);
        }
        if (is_int($value) && IPS_GetVariable($id)['VariableType'] === VARIABLETYPE_FLOAT) {
            $value = (float) $value;
        }
        if ($this->GetValue($ident) !== $value || IPS_GetVariable($id)['VariableUpdated'] === 0) {
            $this->SetValue($ident, $value);
        }
    }

    private function SetStatusIfChanged(int $status): void
    {
        if ($this->GetStatus() !== $status) {
            $this->SetStatus($status);
        }
    }
}

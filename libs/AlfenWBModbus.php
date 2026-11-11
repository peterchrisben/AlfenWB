<?php

declare(strict_types=1);

/**
 * Kleiner Modbus-TCP-Client für die Alfen-Wallbox (Holding Register lesen, mehrere Register schreiben).
 * Autor: Peter Chrisben
 *
 * Fehler werden als Exception gemeldet:
 *  - Code > 0: Die Wallbox hat mit einem Modbus-Fehler geantwortet (Verbindung ist weiter nutzbar).
 *  - Code 0:   Verbindungsfehler oder ungültige Antwort (Verbindung nicht mehr nutzbar).
 */
class AlfenWBModbus
{
    private string $host;
    private int $port;
    private float $timeout;
    /** @var resource|null */
    private $socket = null;
    private int $transaction = 0;

    public function __construct(string $host, int $port = 502, float $timeout = 3.0)
    {
        $this->host = $host;
        $this->port = $port;
        $this->timeout = $timeout;
    }

    public function Open(): void
    {
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client('tcp://' . $this->host . ':' . $this->port, $errno, $errstr, $this->timeout);
        if ($socket === false) {
            throw new Exception('Keine Verbindung zu ' . $this->host . ':' . $this->port . ' (' . trim($errstr) . ')', 0);
        }
        $seconds = (int) floor($this->timeout);
        stream_set_timeout($socket, $seconds, (int) (($this->timeout - $seconds) * 1000000));
        $this->socket = $socket;
    }

    public function Close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    /** Liest Holding Register (Funktion 3) und liefert die Rohdaten (2 Byte je Register). */
    public function ReadHolding(int $unit, int $address, int $quantity): string
    {
        $data = $this->Request($unit, 3, pack('nn', $address, $quantity));
        $count = strlen($data) > 0 ? ord($data[0]) : 0;
        if ($count !== $quantity * 2 || strlen($data) !== $count + 1) {
            throw new Exception('Antwort hat die falsche Länge (Register ' . $address . ')', 0);
        }
        return substr($data, 1);
    }

    /** Schreibt mehrere Register in einer Anfrage (Funktion 16). $bytes: 2 Byte je Register. */
    public function WriteMultiple(int $unit, int $address, string $bytes): void
    {
        $quantity = intdiv(strlen($bytes), 2);
        $this->Request($unit, 16, pack('nnC', $address, $quantity, strlen($bytes)) . $bytes);
    }

    private function Request(int $unit, int $function, string $payload): string
    {
        if (!is_resource($this->socket)) {
            throw new Exception('Verbindung ist nicht geöffnet', 0);
        }
        $this->transaction = ($this->transaction % 65535) + 1;
        $frame = pack('nnnCC', $this->transaction, 0, strlen($payload) + 2, $unit, $function) . $payload;
        if (@fwrite($this->socket, $frame) !== strlen($frame)) {
            throw new Exception('Senden fehlgeschlagen', 0);
        }
        // Antworten mit fremder Transaktionsnummer (verspätete Antworten) überspringen
        for ($try = 0; $try < 3; $try++) {
            $header = unpack('ntid/nproto/nlen/Cunit/Cfunc', $this->Receive(8));
            if ($header['len'] < 2 || $header['len'] > 260) {
                throw new Exception('Ungültige Antwort', 0);
            }
            $data = $this->Receive($header['len'] - 2);
            if ($header['tid'] !== $this->transaction) {
                continue;
            }
            if (($header['func'] & 0x80) !== 0) {
                $code = strlen($data) > 0 ? ord($data[0]) : 255;
                throw new Exception('Wallbox meldet Modbus-Fehler ' . $code . ' (' . self::ExceptionText($code) . ')', max(1, $code));
            }
            if ($header['func'] !== $function) {
                throw new Exception('Unerwartete Antwort (Funktion ' . $header['func'] . ')', 0);
            }
            return $data;
        }
        throw new Exception('Antwort passt nicht zur Anfrage', 0);
    }

    private function Receive(int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = @fread($this->socket, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->socket);
                throw new Exception(!empty($meta['timed_out']) ? 'Keine Antwort (Zeitüberschreitung)' : 'Verbindung wurde getrennt', 0);
            }
            $data .= $chunk;
        }
        return $data;
    }

    public static function ExceptionText(int $code): string
    {
        switch ($code) {
            case 1: return 'Funktion nicht erlaubt';
            case 2: return 'Register nicht vorhanden';
            case 3: return 'Wert nicht erlaubt';
            case 4: return 'Gerätefehler';
            case 6: return 'Gerät beschäftigt';
            default: return 'unbekannt';
        }
    }

    // ------------------------------------------------------------ Umrechnung

    /** 32-Bit-Kommazahl aus einem gelesenen Block. $swap = Wortreihenfolge vertauscht. */
    public static function Float32(string $block, int $base, int $register, bool $swap = false): float
    {
        $raw = substr($block, ($register - $base) * 2, 4);
        if (strlen($raw) < 4) {
            return NAN;
        }
        if ($swap) {
            $raw = substr($raw, 2, 2) . substr($raw, 0, 2);
        }
        return (float) unpack('G', $raw)[1];
    }

    public static function Float64(string $block, int $base, int $register, bool $swap = false): float
    {
        $raw = substr($block, ($register - $base) * 2, 8);
        if (strlen($raw) < 8) {
            return NAN;
        }
        if ($swap) {
            $raw = substr($raw, 6, 2) . substr($raw, 4, 2) . substr($raw, 2, 2) . substr($raw, 0, 2);
        }
        return (float) unpack('E', $raw)[1];
    }

    public static function UInt16(string $block, int $base, int $register): int
    {
        $raw = substr($block, ($register - $base) * 2, 2);
        return strlen($raw) < 2 ? 0xFFFF : (int) unpack('n', $raw)[1];
    }

    public static function UInt32(string $block, int $base, int $register, bool $swap = false): int
    {
        $raw = substr($block, ($register - $base) * 2, 4);
        if (strlen($raw) < 4) {
            return 0;
        }
        if ($swap) {
            $raw = substr($raw, 2, 2) . substr($raw, 0, 2);
        }
        return (int) unpack('N', $raw)[1];
    }

    public static function Text(string $block, int $base, int $register, int $registers): string
    {
        $raw = substr($block, ($register - $base) * 2, $registers * 2);
        $end = strpos($raw, "\0");
        if ($end !== false) {
            $raw = substr($raw, 0, $end);
        }
        return trim(preg_replace('/[^\x20-\x7E]/', '', $raw) ?? '');
    }

    public static function PackFloat32(float $value, bool $swap = false): string
    {
        $raw = pack('G', $value);
        return $swap ? substr($raw, 2, 2) . substr($raw, 0, 2) : $raw;
    }
}

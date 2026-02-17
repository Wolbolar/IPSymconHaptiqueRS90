<?php
declare(strict_types=1);


/**
 * Utility class for WebSocket encoding and decoding according to RFC 6455
 *
 */
class WebSocketUtils
{
    public const OPCODE_MASK = 0x80;
    public static array $OpCodeMap = [
        0x0 => 'continuation',
        0x1 => 'text',
        0x2 => 'binary',
        0x8 => 'close',
        0x9 => 'ping',
        0xA => 'pong'
    ];

    public const OPCODE_CONTINUATION   = 0x0;
    public const OPCODE_TEXT   = 0x1;
    public const OPCODE_BINARY = 0x2;
    public const OPCODE_CLOSE  = 0x8;
    public const OPCODE_PING   = 0x9;
    public const OPCODE_PONG   = 0xA;
    public static function PackData(string $text): string
    {
        // Hinweis: Wir nutzen pack('NN', 0, $len) statt 'J' für bessere Kompatibilität
        $len = strlen($text);
        $head = chr(0x81); // FIN + text opcode (0x1)

        if ($len <= 125) {
            $head .= chr($len);
        } elseif ($len <= 65535) {
            $head .= chr(126) . pack('n', $len);
        } else {
            $head .= chr(127) . pack('NN', 0, $len);
        }

        return $head . $text;
    }

    public static function PackPong(): string
    {
        return WebSocketUtils::BuildFrame(self::OPCODE_PONG);
    }

    public static function DebugTest(?callable $debug = null): void
    {
        if ($debug !== null) {
            $debug(__FUNCTION__, '🚨 Test-Debug-Meldung aus WebSocketUtils');
        }
    }

    public static function IsPingFrame(string $data, ?callable $debug = null): bool
    {
        $unpacked = self::UnpackData($data, $debug);
        if ($unpacked === null) {
            if ($debug !== null) {
                $debug(__FUNCTION__, 'UnpackData() → null');
            }
            return false;
        }
        if ($debug !== null) {
            $debug(__FUNCTION__, 'Opcode erkannt: ' . $unpacked['opcode']);
        }
        return $unpacked['opcode'] === 0x9;
    }

    public static function UnpackData(string $data, ?callable $debug = null): ?array
    {
        try {
            $len = strlen($data);
            if ($len < 2) {
                if ($debug !== null) {
                    $debug(__FUNCTION__, 'Frame zu kurz');
                }
                return null;
            }

            $firstByte = ord($data[0]);
            if ($debug !== null) {
                $debug(__FUNCTION__, 'Raw First Byte (hex): ' . bin2hex($data[0]));
            }
            $secondByte = ord($data[1]);
            if ($debug !== null) {
                $debug(__FUNCTION__, 'Raw Second Byte (hex): ' . bin2hex($data[1]));
            }

            $fin = ($firstByte & 0x80) !== 0;
            $opcode = $firstByte & 0x0F;
            $masked = ($secondByte & 0x80) !== 0;
            $payloadLen = $secondByte & 0x7F;
            if ($debug !== null) {
                $debug(__FUNCTION__, 'PayloadLen: ' . $payloadLen);
            }

            $offset = 2;

            if ($payloadLen === 126) {
                if ($len < 4) {
                    if ($debug !== null) {
                        $debug(__FUNCTION__, 'Frame zu kurz für 126 Payload-Länge');
                    }
                    return null;
                }
                $payloadLen = unpack('n', substr($data, 2, 2))[1];
                $offset += 2;
                if ($debug !== null) {
                    $debug(__FUNCTION__, 'PayloadLen: ' . $payloadLen);
                }
            } elseif ($payloadLen === 127) {
                if ($len < 10) {
                    if ($debug !== null) {
                        $debug(__FUNCTION__, 'Frame zu kurz für 127 Payload-Länge');
                    }
                    return null;
                }
                $payloadLen = unpack('J', substr($data, 2, 8))[1];
                $offset += 8;
                if ($debug !== null) {
                    $debug(__FUNCTION__, 'PayloadLen: ' . $payloadLen);
                }
            }

            $maskKey = '';
            if ($masked) {
                if ($len < $offset + 4) {
                    if ($debug !== null) {
                        $debug(__FUNCTION__, 'Frame zu kurz für Mask Key');
                    }
                    return null;
                }
                $maskKey = substr($data, $offset, 4);
                $offset += 4;
            }

            if ($len < $offset + $payloadLen) {
                if ($debug !== null) {
                    $debug(__FUNCTION__, 'Frame zu kurz für Payload');
                }
                return null;
            }

            $payload = substr($data, $offset, $payloadLen);
            if ($debug !== null) {
                $debug(__FUNCTION__, 'Payload (raw): ' . bin2hex($payload));
            }

            if ($masked) {
                $unmasked = '';
                for ($i = 0; $i < $payloadLen; ++$i) {
                    $unmasked .= $payload[$i] ^ $maskKey[$i % 4];
                }
                $payload = $unmasked;
                if ($debug !== null) {
                    $debug(__FUNCTION__, 'Demaskierter Payload (hex): ' . bin2hex($payload));
                    $debug(__FUNCTION__, 'Demaskierter Payload (Klartext): ' . $payload);
                }
            }

            return [
                'fin' => $fin,
                'opcode' => $opcode,
                'masked' => $masked,
                'payloadLen' => $payloadLen,
                'payload' => $payload,
                'opcode_name' => self::$OpCodeMap[$opcode] ?? 'unknown',
                'length' => $offset + $payloadLen,
                'raw' => $data,
            ];
        } catch (\Throwable $e) {
            if ($debug !== null) {
                $debug(__FUNCTION__, '💥 Exception: ' . $e->getMessage());
            }
            return null;
        }
    }

    private static function BuildFrame(int $opcode, string $payload = '', bool $fin = true, bool $masked = false): string
    {
        $frame = '';

        $byte1 = ($fin ? 0x80 : 0x00) | ($opcode & 0x0F);
        $frame .= chr($byte1);

        $length = strlen($payload);
        if ($length <= 125) {
            $frame .= chr($masked ? ($length | 0x80) : $length);
        } elseif ($length <= 65535) {
            $frame .= chr($masked ? 126 | 0x80 : 126) . pack('n', $length);
        } else {
            $frame .= chr($masked ? 127 | 0x80 : 127) . pack('J', $length);
        }

        if ($masked) {
            $mask = random_bytes(4);
            $frame .= $mask;
            for ($i = 0; $i < $length; $i++) {
                $frame .= $payload[$i] ^ $mask[$i % 4];
            }
        } else {
            $frame .= $payload;
        }

        return $frame;
    }
}


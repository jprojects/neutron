<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

// País de una IP sin servicios externos (la IP de los visitantes no sale del servidor), con la base de lib/geoip/:
// IP to Country Lite de DB-IP (CC BY 4.0), convertida por tools/geoip.py. Ficheros de registros de tamaño fijo
// ordenados (prefijo de la IP + 1 byte de país, cuyo código es la línea de codes.txt): búsqueda binaria con fseek,
// sin cargar la base en memoria.

/** Código ISO del país de la IP ('ES', 'FR'…), o '' si no se sabe (IP privada, local o sin base) */
function neutron_geoip_country(string $ip): string {
    $bin = @inet_pton($ip);
    if ($bin === false) return '';
    if (strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) $bin = substr($bin, 12);  // ::ffff:1.2.3.4
    [$file, $size] = strlen($bin) === 4 ? ['ipv4.bin', 3] : ['ipv6.bin', 5];
    $key = substr($bin, 0, $size);
    $path = __DIR__ . '/geoip/' . $file;
    $fh = @fopen($path, 'rb');
    if (!$fh) return '';
    $rec = $size + 1;
    $lo = 0; $hi = (int)(filesize($path) / $rec) - 1; $found = -1;
    while ($lo <= $hi) {                                        // el último registro con inicio ≤ clave
        $mid = ($lo + $hi) >> 1;
        fseek($fh, $mid * $rec);
        $start = (string)fread($fh, $size);
        if (strcmp($start, $key) <= 0) { $found = $mid; $lo = $mid + 1; } else { $hi = $mid - 1; }
    }
    $code = 0;
    if ($found >= 0) {
        fseek($fh, $found * $rec + $size);
        $code = ord((string)fread($fh, 1));
    }
    fclose($fh);
    if ($code === 0) return '';
    static $codes = null;
    $codes ??= @file(__DIR__ . '/geoip/codes.txt', FILE_IGNORE_NEW_LINES) ?: [];
    $cc = (string)($codes[$code] ?? '');
    return preg_match('/^[A-Z]{2}$/', $cc) && $cc !== 'ZZ' ? $cc : '';
}

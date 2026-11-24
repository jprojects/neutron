#!/usr/bin/env python3
# Base de países de las visitas (lib/geoip/, la lee lib/geoip.php). Herramienta de desarrollo, no es del núcleo:
#
#   python3 tools/geoip.py [dbip-country-lite-AAAA-MM.csv.gz]
#
# Sin fichero, descarga el del mes de DB-IP «IP to Country Lite» (CC BY 4.0: hay que citar la fuente, y el panel
# la cita). Escribe, ordenados por el inicio de cada rango:
#   lib/geoip/ipv4.bin  registros de 4 bytes: los 24 primeros bits de la IP (bloques /24) + 1 byte de país
#   lib/geoip/ipv6.bin  registros de 6 bytes: los 40 primeros bits (prefijos /40) + 1 byte de país
#   lib/geoip/codes.txt los códigos ISO de país, uno por línea (el byte es el número de línea, 0 = ZZ, desconocido)
# El país de una IP es el del último registro con inicio ≤ IP. Se pierde algo de precisión en los rangos más pequeños
# que un /24 o un /40 (se queda el último país del bloque): para unas estadísticas orientativas basta.
# Conviene regenerarla de vez en cuando (cada pocos meses) y publicarla con una versión.
import datetime, gzip, ipaddress, re, sys, tempfile, urllib.request
from pathlib import Path

OUT = Path(__file__).resolve().parent.parent / 'lib' / 'geoip'
V4_BITS, V6_BITS = 24, 40

if len(sys.argv) > 1:
    src = Path(sys.argv[1])
    m = re.search(r'\d{4}-\d{2}', src.name)
    month = m.group(0) if m else src.name
else:
    month = datetime.date.today().strftime('%Y-%m')
    url = f'https://download.db-ip.com/free/dbip-country-lite-{month}.csv.gz'
    src = Path(tempfile.mkdtemp()) / 'dbip.csv.gz'
    print('Descargando', url)
    urllib.request.urlretrieve(url, src)

opener = gzip.open if src.suffix == '.gz' else open
rows = {4: [], 6: []}
with opener(src, 'rt') as f:
    for line in f:
        parts = line.strip().split(',')
        if len(parts) < 3:
            continue
        ip = ipaddress.ip_address(parts[0])
        rows[ip.version].append((int(ip), parts[2].strip().upper()[:2] or 'ZZ'))

codes = ['ZZ'] + sorted({c for r in rows.values() for _, c in r} - {'ZZ'})
if len(codes) > 256:
    sys.exit('Más de 256 países: no caben en un byte')
index = {c: i for i, c in enumerate(codes)}


def pack(items, total_bits, keep_bits):
    shift, size = total_bits - keep_bits, keep_bits // 8
    out = []                                         # [(prefijo, país)]
    for start, cc in sorted(items):
        p = start >> shift
        if out and out[-1][0] == p:
            out[-1] = (p, cc)                        # mismo bloque: se queda el último
            if len(out) > 1 and out[-2][1] == cc:
                out.pop()
            continue
        if out and out[-1][1] == cc:
            continue                                 # sigue el mismo país
        out.append((p, cc))
    return b''.join(p.to_bytes(size, 'big') + bytes([index[c]]) for p, c in out), len(out)


OUT.mkdir(parents=True, exist_ok=True)
v4, n4 = pack(rows[4], 32, V4_BITS)
v6, n6 = pack(rows[6], 128, V6_BITS)
(OUT / 'ipv4.bin').write_bytes(v4)
(OUT / 'ipv6.bin').write_bytes(v6)
(OUT / 'codes.txt').write_text('\n'.join(codes) + '\n')
(OUT / 'LEEME.txt').write_text(
    'Base de países de las visitas del panel (lib/geoip.php). La genera tools/geoip.py.\n'
    f'Datos: IP to Country Lite de DB-IP ({month}), https://db-ip.com, con licencia CC BY 4.0\n'
    '(https://creativecommons.org/licenses/by/4.0/). Convertidos a un formato binario compacto.\n')
print(f'IPv4: {n4} rangos ({len(v4) // 1024} KB) · IPv6: {n6} rangos ({len(v6) // 1024} KB) · {len(codes)} países → {OUT}')

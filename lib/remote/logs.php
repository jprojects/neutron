<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

// Lee las últimas entradas de data/logs/{canal}-AAAA-MM-DD.log para Cosmo.
// Solo mira el final de cada fichero, así que es barato aunque los logs sean grandes.

const NEUTRON_LOG_TAIL_BYTES = 1048576;
const NEUTRON_LOG_LINE = '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\.\d+)?\] ([A-Z]+) \[([^\]]*)\] (.*)$/s';

/** Entradas de nivel >= $minLevel de los últimos $days días, la más reciente primero. */
function neutron_logs_recent(string $minLevel = 'warning', int $limit = 100, int $days = 7): array {
    $min = NEUTRON_LOG_LEVELS[$minLevel] ?? NEUTRON_LOG_LEVELS['warning'];
    $since = date('Y-m-d H:i:s', time() - $days * 86400);
    $entries = [];
    foreach (neutron_logs_files($days) as $channel => $files) {
        foreach ($files as $file) {
            foreach (neutron_logs_parse($file, $channel) as $e) {
                if ($e['time'] >= $since && (NEUTRON_LOG_LEVELS[strtolower($e['level'])] ?? 0) >= $min) {
                    $entries[] = $e;
                }
            }
        }
    }
    usort($entries, static fn(array $a, array $b) => strcmp($b['time'], $a['time']));
    return array_slice($entries, 0, $limit);
}

/** @return array{error:int,warning:int} entradas de las últimas 24 h (error incluye critical) */
function neutron_logs_counts24h(): array {
    $since = date('Y-m-d H:i:s', time() - 86400);
    $counts = ['error' => 0, 'warning' => 0];
    foreach (neutron_logs_files(2) as $channel => $files) {
        foreach ($files as $file) {
            foreach (neutron_logs_parse($file, $channel) as $e) {
                if ($e['time'] < $since) continue;
                if ($e['level'] === 'ERROR' || $e['level'] === 'CRITICAL') $counts['error']++;
                elseif ($e['level'] === 'WARNING') $counts['warning']++;
            }
        }
    }
    return $counts;
}

/** @return array<string,list<string>> canal => ficheros de los últimos $days días */
function neutron_logs_files(int $days): array {
    $out = [];
    $oldest = date('Y-m-d', time() - $days * 86400);
    foreach (glob(NEUTRON_DATA . '/logs/*-????-??-??.log') ?: [] as $file) {
        if (preg_match('/^([a-z0-9_-]+)-(\d{4}-\d{2}-\d{2})\.log$/i', basename($file), $m) && $m[2] >= $oldest) {
            $out[$m[1]][] = $file;
        }
    }
    return $out;
}

function neutron_logs_parse(string $file, string $channel): array {
    $size = (int)filesize($file);
    $fh = fopen($file, 'rb');
    if (!$fh) return [];
    $truncated = $size > NEUTRON_LOG_TAIL_BYTES;
    if ($truncated) fseek($fh, -NEUTRON_LOG_TAIL_BYTES, SEEK_END);
    $text = (string)stream_get_contents($fh);
    fclose($fh);
    if ($truncated) {
        // La primera línea puede estar cortada
        $text = (string)substr($text, (int)strpos($text, "\n") + 1);
    }

    $entries = [];
    $current = null;
    foreach (explode("\n", $text) as $line) {
        if (preg_match(NEUTRON_LOG_LINE, $line, $m)) {
            if ($current !== null) $entries[] = $current;
            $current = [
                'time' => $m[1],
                'level' => $m[2],
                'channel' => $channel,
                'context' => $m[3],
                'message' => mb_substr($m[4], 0, 2000),
                'detail' => '',
            ];
        } elseif ($current !== null && $line !== '' && strlen($current['detail']) < 8000) {
            // Traza de una excepción (líneas sangradas tras la entrada)
            $current['detail'] .= $line . "\n";
        }
    }
    if ($current !== null) $entries[] = $current;
    return $entries;
}

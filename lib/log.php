<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

// Log en data/logs/neutron-AAAA-MM-DD.log, con el mismo formato de línea que Quasar
// (así Cosmo lo lee igual):
//   [2026-10-23 11:30:12.123] ERROR [req:3fa9c1 ip:10.0.0.4 admin] Mensaje {"contexto":…}
// {clave} en el mensaje se sustituye por el valor del contexto; las claves que parecen secretas
// (password, token, secret…) se escriben como ***.

const NEUTRON_LOG_LEVELS = ['debug' => 100, 'info' => 200, 'notice' => 250, 'warning' => 300, 'error' => 400, 'critical' => 500];

function neutron_log(string $level, string $message, array $context = []): void {
    $level = strtolower($level);
    $min = NEUTRON_LOG_LEVELS[neutron_config()['log']['level'] ?? 'info'] ?? 200;
    if ((NEUTRON_LOG_LEVELS[$level] ?? 0) < $min) return;

    static $req = null;
    $req ??= bin2hex(random_bytes(3));

    $detail = '';
    if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
        $e = $context['exception'];
        $context['exception'] = get_class($e) . ': ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine();
        $detail = "\n    " . str_replace("\n", "\n    ", $e->getTraceAsString());
    }
    $context = neutron_log_redact($context);

    $message = preg_replace_callback('/\{([a-z0-9_.]+)\}/i', function ($m) use ($context) {
        $v = $context[$m[1]] ?? null;
        return is_scalar($v) ? (string)$v : $m[0];
    }, $message);

    $tags = 'req:' . $req . ' ip:' . neutron_client_ip();
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['neutron_admin'])) {
        $tags .= ' admin';
    }
    $time = DateTime::createFromFormat('U.u', sprintf('%.6F', microtime(true)));
    $time->setTimezone(new DateTimeZone(date_default_timezone_get()));

    $line = '[' . $time->format('Y-m-d H:i:s.v') . '] ' . strtoupper($level) . ' [' . $tags . '] '
        . str_replace(["\r", "\n"], ' ', $message)
        . ($context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) : '')
        . $detail . "\n";

    $dir = NEUTRON_DATA . '/logs';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return;
    @file_put_contents($dir . '/neutron-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);

    // De vez en cuando, borrar los ficheros más antiguos que log.keep_days
    if (random_int(1, 200) === 1) neutron_log_prune($dir);
}

function neutron_log_redact(array $context): array {
    foreach ($context as $k => $v) {
        if (is_string($k) && preg_match('/pass|token|secret|api_?key|authorization|recaptcha_response/i', $k)) {
            $context[$k] = '***';
        } elseif (is_array($v)) {
            $context[$k] = neutron_log_redact($v);
        }
    }
    return $context;
}

function neutron_log_prune(string $dir): void {
    $keep = max(1, (int)(neutron_config()['log']['keep_days'] ?? 30));
    $oldest = date('Y-m-d', time() - $keep * 86400);
    foreach (glob($dir . '/*-????-??-??.log') ?: [] as $file) {
        if (preg_match('/-(\d{4}-\d{2}-\d{2})\.log$/', $file, $m) && $m[1] < $oldest) {
            @unlink($file);
        }
    }
}

// Warnings, excepciones y errores fatales de PHP al log (respetando @).
function neutron_log_register_handlers(): void {
    set_error_handler(function (int $no, string $msg, string $file, int $line): bool {
        if (!(error_reporting() & $no)) return false;
        $level = in_array($no, [E_WARNING, E_USER_WARNING, E_CORE_WARNING, E_COMPILE_WARNING], true) ? 'warning'
            : (in_array($no, [E_USER_ERROR, E_RECOVERABLE_ERROR], true) ? 'error' : 'notice');
        neutron_log($level, 'PHP: ' . $msg, ['file' => $file . ':' . $line]);
        return false; // que PHP siga con su gestión normal
    });
    set_exception_handler(function (Throwable $e): void {
        neutron_log('error', 'Excepción no controlada: ' . $e->getMessage(), ['exception' => $e, 'url' => $_SERVER['REQUEST_URI'] ?? null]);
        if (!headers_sent()) http_response_code(500);
        echo neutron_config()['debug'] ? htmlspecialchars((string)$e) : 'Error interno';
    });
    register_shutdown_function(function (): void {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            neutron_log('critical', 'PHP fatal: ' . $e['message'], ['file' => $e['file'] . ':' . $e['line']]);
        }
    });
}

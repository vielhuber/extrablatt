<?php
declare(strict_types=1);

namespace vielhuber\extrablatt;

final readonly class PodcastStatus
{
    /**
     * Keep the browser payload free of provider metadata.
     */
    public function __construct(
        public string $date,
        public string $status,
        public string $message,
        public bool $hasAudio
    ) {}
}

final class Podcast
{
    /**
     * Bind the optional Python process to one deployment's private runtime.
     */
    public function __construct(
        private readonly string $rootDirectory,
        private readonly string $python,
        #[\SensitiveParameter] private readonly string $authentication
    ) {}

    /**
     * Keep the optional Python integration disabled until it has private credentials.
     */
    public function configured(): bool
    {
        return $this->authentication !== '' && is_executable($this->python);
    }

    /**
     * Expose only fixed messages, never raw worker output or Google session data.
     */
    public function status(string $date): PodcastStatus
    {
        $directory = $this->rootDirectory . '/.data/podcast/' . $date;
        $file = $directory . '/status.json';
        $state = is_file($file)
            ? json_decode(json: (string) file_get_contents($file), associative: true)
            : ['stage' => 'idle'];
        $stage = is_array($state) ? $state['stage'] ?? 'error' : 'error';
        $hasAudio = $stage === 'completed' && is_file($directory . '/audio.m4a');
        $status = match ($stage) {
            'idle' => 'idle',
            'starting', 'source', 'generating', 'downloading' => 'running',
            'completed' => $hasAudio ? 'completed' : 'error',
            default => 'error'
        };
        $message = match ($stage) {
            'idle' => 'Noch keine Tagesfolge verfügbar.',
            'starting' => 'Google-Anmeldung wird geprüft …',
            'source' => 'Die vollständige Zeitung wird vorbereitet …',
            'generating' => 'Google erstellt die Tagesfolge. Das kann mehrere Minuten dauern.',
            'downloading' => 'Die fertige Audiodatei wird heruntergeladen …',
            'completed' => $hasAudio ? 'Die Tagesfolge ist bereit.' : 'Die Audiodatei fehlt.',
            'auth_error'
                => 'Google-Anmeldung fehlgeschlagen. NOTEBOOKLM_AUTH_JSON in der Production-Konfiguration erneuern.',
            'quota_error'
                => 'Das Google-Kontingent reicht derzeit nicht aus. Heute wird kein zweiter Auftrag gestartet.',
            default
                => 'Die Tagesfolge konnte nicht abgeschlossen werden. Den gespeicherten Auftrag prüfen; keine automatische Wiederholung.'
        };
        return new PodcastStatus(date: $date, status: $status, message: $message, hasAudio: $hasAudio);
    }

    /**
     * Freeze the complete newspaper once per Berlin calendar day and launch its worker.
     */
    public function generateDaily(string $newspaperHtml, int $generatedAt): bool
    {
        if (!$this->configured() || trim($newspaperHtml) === '') {
            return false;
        }
        $date = (new \DateTimeImmutable('@' . $generatedAt))
            ->setTimezone(new \DateTimeZone('Europe/Berlin'))
            ->format('Y-m-d');
        $directory = $this->rootDirectory . '/.data/podcast';
        if (!is_dir($directory)) {
            mkdir(directory: $directory, permissions: 0o700, recursive: true);
        }
        $lock = fopen(filename: $directory . '/generation.lock', mode: 'c+');
        if ($lock === false) {
            return false;
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return false;
        }
        try {
            $directory .= '/' . $date;
            if (is_file($directory . '/status.json')) {
                return false;
            }
            $text = (string) preg_replace(
                pattern: '~<(script|style|svg)\b[^>]*>.*?</\1>~is',
                replacement: '',
                subject: $newspaperHtml
            );
            $text = (string) preg_replace_callback(
                pattern: '~<a\b[^>]*href="([^"]+)"[^>]*>(.*?)</a>~is',
                callback: static fn(array $match): string => strip_tags($match[2]) . ' (' . $match[1] . ')',
                subject: $text
            );
            $text = (string) preg_replace(
                pattern: '~</(?:p|h[1-6]|div|li|section)>|<br\s*/?>~i',
                replacement: "\n",
                subject: $text
            );
            $text = html_entity_decode(string: strip_tags($text), flags: ENT_QUOTES | ENT_HTML5, encoding: 'UTF-8');
            $text = trim((string) preg_replace(pattern: '/[ \t]+/u', replacement: ' ', subject: $text));
            if ($text === '') {
                return false;
            }
            mkdir(directory: $directory, permissions: 0o700, recursive: true);
            file_put_contents(
                filename: $directory . '/source.txt',
                data: 'Zeitung vom ' . $date . " (Europe/Berlin)\n\n" . $text
            );
            file_put_contents(
                filename: $directory . '/status.json',
                data: json_encode(['date' => $date, 'generated_at' => $generatedAt, 'stage' => 'starting'])
            );
            $process = proc_open(
                command: [
                    '/bin/sh',
                    '-c',
                    '"$0" "$1" "$2" "$3" > /dev/null 2>&1 < /dev/null &',
                    $this->python,
                    __DIR__ . '/../bin/extrablatt-podcast.py',
                    $this->rootDirectory,
                    $date
                ],
                descriptor_spec: [
                    0 => ['file', '/dev/null', 'r'],
                    1 => ['file', '/dev/null', 'w'],
                    2 => ['file', '/dev/null', 'w']
                ],
                pipes: $pipes,
                cwd: $this->rootDirectory,
                env_vars: [
                    'PATH' => (string) (getenv('PATH') ?: '/usr/bin:/bin'),
                    'LANG' => 'C.UTF-8',
                    'HOME' => $directory,
                    'NOTEBOOKLM_HOME' => $directory . '/state',
                    'NOTEBOOKLM_AUTH_JSON' => $this->authentication
                ]
            );
            if (!is_resource($process) || proc_close($process) !== 0) {
                file_put_contents(filename: $directory . '/status.json', data: json_encode(['stage' => 'error']));
                return false;
            }
            return true;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Retain every dated episode, newest first, without exposing provider identifiers.
     *
     * @return PodcastStatus[]
     */
    public function episodes(): array
    {
        $files = glob($this->rootDirectory . '/.data/podcast/????-??-??/status.json') ?: [];
        rsort($files);
        $episodes = [];
        foreach ($files as $file) {
            $date = basename(dirname($file));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1) {
                continue;
            }
            $episodes[] = $this->status($date);
        }
        return $episodes;
    }

    /**
     * Stream only validated daily artifacts through the application's auth gate.
     */
    public function serveAudio(string $date): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1 || !$this->status($date)->hasAudio) {
            http_response_code(404);
            return;
        }
        $file = $this->rootDirectory . '/.data/podcast/' . $date . '/audio.m4a';
        $size = filesize($file);
        $start = 0;
        $end = $size - 1;
        header('Content-Type: audio/mp4');
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        if (preg_match('/^bytes=(\d*)-(\d*)$/D', $_SERVER['HTTP_RANGE'] ?? '', $range)) {
            $start = $range[1] === '' ? max(0, $size - (int) $range[2]) : (int) $range[1];
            $end = $range[1] !== '' && $range[2] !== '' ? min($end, (int) $range[2]) : $end;
            if ($start > $end || ($range[1] === '' && $range[2] === '')) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                return;
            }
            http_response_code(206);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        }
        header('Content-Length: ' . ($end - $start + 1));
        $stream = fopen(filename: $file, mode: 'rb');
        $output = fopen(filename: 'php://output', mode: 'wb');
        fseek($stream, $start);
        stream_copy_to_stream($stream, $output, $end - $start + 1);
        fclose($stream);
        fclose($output);
    }

    /**
     * Present the daily archive without starting quota-consuming work from page views.
     */
    public function render(): string
    {
        $episodes = $this->episodes();
        $running = 'false';
        $items = '';
        foreach ($episodes as $episode) {
            $dateLabel = (new \DateTimeImmutable($episode->date))->format('d.m.Y');
            $message = $episode->hasAudio ? '' : '<p>' . htmlspecialchars($episode->message, ENT_QUOTES) . '</p>';
            $audio = $episode->hasAudio
                ? '<audio controls preload="none" aria-label="Zeitung vom ' .
                    $dateLabel .
                    '" src="/?podcast_audio=1&amp;date=' .
                    $episode->date .
                    '">Audio wird von diesem Browser nicht unterstützt.</audio>'
                : '';
            $items .=
                '<article class="podcast__episode"><h3><time datetime="' .
                $episode->date .
                '">' .
                $dateLabel .
                '</time></h3>' .
                $message .
                $audio .
                '</article>';
            if ($episode->status === 'running') {
                $running = 'true';
            }
        }
        $configuration = !$this->configured()
            ? '<p>NOTEBOOKLM_PYTHON und NOTEBOOKLM_AUTH_JSON müssen in der privaten .env konfiguriert sein.</p>'
            : '';
        if ($items === '') {
            $items = '<p>Noch keine Tagesfolgen vorhanden.</p>';
        }
        $scriptVersion = substr(
            string: hash_file(algo: 'sha256', filename: __DIR__ . '/../js/podcast.js'),
            offset: 0,
            length: 12
        );
        return <<<HTML
        <section class="podcast" data-podcast-running="{$running}">
            <h2>Podcast</h2>
            {$configuration}
            <div class="podcast__episodes">{$items}</div>
        </section>
        <script src="/?asset=js/podcast.js&amp;v={$scriptVersion}" defer></script>
        HTML;
    }
}

<?php
declare(strict_types=1);

namespace vielhuber\extrablatt;

final readonly class PodcastStatus
{
    /**
     * Keep the browser payload free of provider metadata.
     */
    public function __construct(public string $status, public string $message, public bool $hasAudio) {}
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
    public function status(): PodcastStatus
    {
        $directory = $this->rootDirectory . '/.data/podcast';
        $file = $directory . '/status.json';
        $state = is_file($file)
            ? json_decode(json: (string) file_get_contents($file), associative: true)
            : ['stage' => 'idle'];
        $stage = is_array($state) ? $state['stage'] ?? 'error' : 'error';
        $hasAudio = $stage === 'completed' && is_file($directory . '/test.m4a');
        $status = match ($stage) {
            'idle' => 'idle',
            'starting', 'source', 'generating', 'downloading' => 'running',
            'completed' => $hasAudio ? 'completed' : 'error',
            default => 'error'
        };
        $message = match ($stage) {
            'idle' => 'Bereit für einen deutschen Testpodcast über die Erstellung von KI-Podcasts.',
            'starting' => 'Google-Anmeldung wird geprüft …',
            'source' => 'Das Testbriefing wird vorbereitet …',
            'generating' => 'Google erstellt den Testpodcast. Das kann mehrere Minuten dauern.',
            'downloading' => 'Die fertige Audiodatei wird heruntergeladen …',
            'completed' => $hasAudio ? 'Der Testpodcast ist bereit.' : 'Die Audiodatei fehlt.',
            'auth_error'
                => 'Google-Anmeldung fehlgeschlagen. NOTEBOOKLM_AUTH_JSON in der Production-Konfiguration erneuern.',
            'quota_error'
                => 'Das Google-Kontingent reicht derzeit nicht aus. Der Auftrag wird nicht automatisch wiederholt.',
            default
                => 'Der Test konnte nicht abgeschlossen werden. Den gespeicherten Auftrag prüfen, bevor erneut generiert wird.'
        };
        return new PodcastStatus(status: $status, message: $message, hasAudio: $hasAudio);
    }

    /**
     * Run exactly one test while keeping credentials out of command arguments and logs.
     */
    public function generate(): void
    {
        if (!$this->configured()) {
            http_response_code(503);
            return;
        }
        $directory = $this->rootDirectory . '/.data/podcast';
        if (!is_dir($directory)) {
            mkdir(directory: $directory, permissions: 0o700, recursive: true);
        }
        $lock = fopen(filename: $directory . '/generation.lock', mode: 'c+');
        if ($lock === false) {
            http_response_code(503);
            return;
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            http_response_code(409);
            return;
        }
        try {
            if ($this->status()->status !== 'idle') {
                return;
            }
            file_put_contents(filename: $directory . '/status.json', data: '{"stage":"starting"}');
            ignore_user_abort(true);
            set_time_limit(0);
            $process = proc_open(
                command: [$this->python, __DIR__ . '/../bin/extrablatt-podcast.py', $this->rootDirectory],
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
            $exitCode = is_resource($process) ? proc_close($process) : 1;
            if ($exitCode !== 0 && $this->status()->status === 'running') {
                $state = json_decode(json: (string) file_get_contents($directory . '/status.json'), associative: true);
                $state['stage'] = 'error';
                file_put_contents($directory . '/status.json', json_encode($state));
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Stream the fixed private test artifact through the application's auth gate.
     */
    public function serveAudio(): void
    {
        if (!$this->status()->hasAudio) {
            http_response_code(404);
            return;
        }
        $file = $this->rootDirectory . '/.data/podcast/test.m4a';
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
     * Keep the first integration limited to a manual test and its audio player.
     */
    public function render(#[\SensitiveParameter] string $token): string
    {
        $state = $this->status();
        $message = htmlspecialchars($state->message, ENT_QUOTES);
        $escapedToken = htmlspecialchars($token, ENT_QUOTES);
        $disabled = !$this->configured() || $token === '' || $state->status !== 'idle' ? ' disabled' : '';
        $configuration =
            !$this->configured() || $token === ''
                ? '<p>NOTEBOOKLM_PYTHON, NOTEBOOKLM_AUTH_JSON und AUTH_PASSWORD müssen in der privaten .env konfiguriert sein.</p>'
                : '';
        $audio = $state->hasAudio
            ? '<audio controls preload="metadata" src="/?podcast_audio=1">Audio wird von diesem Browser nicht unterstützt.</audio>'
            : '';
        return <<<HTML
        <section class="podcast" data-podcast-status="{$state->status}">
            <h2>Podcast-Test</h2>
            <p>Ein deutscher Testpodcast über die Erstellung von KI-Podcasts, erzeugt mit dem vorhandenen Google-Konto. Keine automatische Veröffentlichung.</p>
            {$configuration}
            <p class="podcast__status" role="status" aria-live="polite">{$message}</p>
            <form id="podcastTest" method="post" action="/?view=podcast">
                <input type="hidden" name="podcast_test" value="1">
                <input type="hidden" name="podcast_token" value="{$escapedToken}">
                <button type="submit"{$disabled}>Testpodcast erstellen</button>
            </form>
            {$audio}
            <p class="podcast__note">Die Generierung nutzt das Google-Kontingent und dauert mehrere Minuten. Reloads starten keinen weiteren Auftrag. KI-Inhalte vor einer Veröffentlichung prüfen. Die Google-Anbindung ist inoffiziell.</p>
        </section>
        <script src="/?asset=js/podcast.js" defer></script>
        HTML;
    }
}

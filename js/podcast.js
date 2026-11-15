let $podcast = document.querySelector('.podcast');
let $form = document.getElementById('podcastTest');
let $status = $podcast.querySelector('.podcast__status');
let $button = $form.querySelector('button');
let timer;

let refresh = async () => {
    try {
        let response = await fetch('/?podcast_status=1', { cache: 'no-store' });
        if (!response.ok) {
            throw new Error('Status unavailable');
        }
        let state = await response.json();
        $status.textContent = state.message;
        if (state.status !== 'running') {
            clearInterval(timer);
            window.location.reload();
        }
    } catch {
        $status.textContent = 'Status derzeit nicht erreichbar. Die Seite kann neu geladen werden.';
    }
};

if ($podcast.dataset.podcastStatus === 'running') {
    timer = setInterval(refresh, 5000);
}

$form.addEventListener('submit', async event => {
    event.preventDefault();
    $button.disabled = true;
    $status.textContent = 'Der Podcast-Test wird gestartet …';
    timer = setInterval(refresh, 5000);
    try {
        await fetch($form.action, { method: 'POST', body: new FormData($form) });
        await refresh();
    } catch {
        $status.textContent = 'Verbindung unterbrochen. Der gespeicherte Auftrag wird weiter geprüft.';
    }
});

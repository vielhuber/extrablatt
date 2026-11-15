let $podcast = document.querySelector('.podcast');

if ($podcast.dataset.podcastRunning === 'true') {
    let timer = setInterval(async () => {
        try {
            let response = await fetch('/?podcast_status=1', { cache: 'no-store' });
            if (!response.ok) {
                return;
            }
            let episodes = await response.json();
            if (!episodes.some(episode => episode.status === 'running')) {
                clearInterval(timer);
                window.location.reload();
            }
        } catch {
            return;
        }
    }, 5000);
}

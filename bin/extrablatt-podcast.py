import asyncio
import fcntl
import json
import logging
import os
import sys
from pathlib import Path

from notebooklm import AudioFormat, AudioLength, NotebookLMClient
from notebooklm.exceptions import AuthError, NotebookLMError, RateLimitError


def write_status(directory, state, stage):
    state['stage'] = stage
    temporary = directory / 'status.tmp'
    temporary.write_text(json.dumps(state), encoding='utf-8')
    temporary.replace(directory / 'status.json')


async def generate(root, date):
    directory = root / '.data' / 'podcast' / date
    directory.mkdir(mode=0o700, parents=True, exist_ok=True)
    state = {'date': date}
    lock = (directory / 'worker.lock').open('a')
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        lock.close()
        return 0
    try:
        state = json.loads((directory / 'status.json').read_text(encoding='utf-8'))
        if state.get('stage') != 'starting':
            return 0
        if not os.environ.get('NOTEBOOKLM_AUTH_JSON'):
            write_status(directory, state, 'auth_error')
            return 1
        async with NotebookLMClient.from_storage() as client:
            write_status(directory, state, 'source')
            notebook = await client.notebooks.create(f'Extrablatt: Zeitung {date}')
            state['notebook_id'] = notebook.id
            write_status(directory, state, 'source')
            briefing = (directory / 'source.txt').read_text(encoding='utf-8')
            source = await client.sources.add_text(notebook.id, f'Vollständige Zeitung {date}', briefing, wait=True)
            state['source_id'] = source.id
            write_status(directory, state, 'generating')
            task = await client.artifacts.generate_audio(
                notebook.id,
                source_ids=[source.id],
                language='de',
                instructions='Erstelle einen deutschen Nachrichtenpodcast mit zwei Moderatoren für etwa 15 Minuten (ungefähr 2000 gesprochene Wörter). Die Quelle ist die vollständige Zeitung der angegebenen Tagesausgabe. Behandle ALLE darin genannten Ereignisse und sämtliche Rubriken in ihrer Reihenfolge, nicht nur die Hauptthemen: Meldung des Tages, Wochenmeldungen, Lokales, Kryptowährungen, Wetter, TV-Talkshows, Serien, Filme, Kino, Musik und Gaming, soweit vorhanden. Verteile die Zeit entsprechend der Informationsmenge. Fasse jedes Ereignis knapp und verständlich zusammen, damit nichts ausgelassen wird. Behalte Zahlen, Namen, Daten, Unsicherheiten und den Zeitraum der Quelle bei. Erfinde keine Fakten, Zitate oder neuen Ereignisse. Lies keine URLs oder Quellenlisten vor. Kurzer Einstieg und Abschluss, keine Werbung, keine langen Abschweifungen, keine Wiederholungen.',
                audio_format=AudioFormat.DEEP_DIVE,
                audio_length=AudioLength.DEFAULT,
            )
            state['task_id'] = task.task_id
            write_status(directory, state, 'generating')
            completed = await client.artifacts.wait_for_completion(notebook.id, task.task_id, timeout=1200)
            if not completed.is_complete:
                write_status(directory, state, 'error')
                return 1
            write_status(directory, state, 'downloading')
            temporary = directory / 'audio.tmp.m4a'
            await client.artifacts.download_audio(notebook.id, str(temporary), artifact_id=task.task_id)
            if temporary.stat().st_size == 0:
                write_status(directory, state, 'error')
                return 1
            temporary.replace(directory / 'audio.m4a')
            write_status(directory, state, 'completed')
            return 0
    except AuthError:
        write_status(directory, state, 'auth_error')
    except RateLimitError:
        write_status(directory, state, 'quota_error')
    except (NotebookLMError, OSError, ValueError, TimeoutError):
        write_status(directory, state, 'error')
    finally:
        lock.close()
    return 1


if __name__ == '__main__':
    os.umask(0o077)
    logging.disable(logging.CRITICAL)
    sys.exit(asyncio.run(generate(Path(sys.argv[1]), sys.argv[2])))

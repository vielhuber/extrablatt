import asyncio
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


async def generate(root):
    directory = root / '.data' / 'podcast'
    directory.mkdir(mode=0o700, parents=True, exist_ok=True)
    state = {}
    try:
        if not os.environ.get('NOTEBOOKLM_AUTH_JSON'):
            write_status(directory, state, 'auth_error')
            return 1
        async with NotebookLMClient.from_storage() as client:
            write_status(directory, state, 'source')
            notebook = await client.notebooks.create('Extrablatt: Podcast-Test')
            state['notebook_id'] = notebook.id
            write_status(directory, state, 'source')
            briefing = (Path(__file__).parent / 'podcast-test.txt').read_text(encoding='utf-8')
            source = await client.sources.add_text(notebook.id, 'Testbriefing', briefing, wait=True)
            state['source_id'] = source.id
            write_status(directory, state, 'generating')
            task = await client.artifacts.generate_audio(
                notebook.id,
                source_ids=[source.id],
                language='de',
                instructions='Ein kurzer deutscher Dialog mit zwei Moderatoren. Nur Aussagen aus dem Briefing, keine erfundenen Fakten oder Zitate. Drei Kernaussagen und ein knappes Fazit. Keine Werbung und keine Wiederholungen.',
                audio_format=AudioFormat.DEEP_DIVE,
                audio_length=AudioLength.SHORT,
            )
            state['task_id'] = task.task_id
            write_status(directory, state, 'generating')
            completed = await client.artifacts.wait_for_completion(notebook.id, task.task_id, timeout=1200)
            if not completed.is_complete:
                write_status(directory, state, 'error')
                return 1
            write_status(directory, state, 'downloading')
            temporary = directory / 'test.tmp.m4a'
            await client.artifacts.download_audio(notebook.id, str(temporary), artifact_id=task.task_id)
            if temporary.stat().st_size == 0:
                write_status(directory, state, 'error')
                return 1
            temporary.replace(directory / 'test.m4a')
            write_status(directory, state, 'completed')
            return 0
    except AuthError:
        write_status(directory, state, 'auth_error')
    except RateLimitError:
        write_status(directory, state, 'quota_error')
    except (NotebookLMError, OSError, ValueError, TimeoutError):
        write_status(directory, state, 'error')
    return 1


if __name__ == '__main__':
    os.umask(0o077)
    logging.disable(logging.CRITICAL)
    sys.exit(asyncio.run(generate(Path(sys.argv[1]))))

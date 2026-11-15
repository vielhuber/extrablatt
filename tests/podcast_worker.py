import importlib.util
import json
import os
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import AsyncMock, patch

specification = importlib.util.spec_from_file_location('podcast_worker', Path(__file__).parents[1] / 'bin' / 'extrablatt-podcast.py')
worker = importlib.util.module_from_spec(specification)
specification.loader.exec_module(worker)


class PodcastWorkerTest(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='extrablatt-podcast-worker-')
        self.root = Path(self.temporary.name)
        self.date = '2026-11-15'
        self.directory = self.root / '.data' / 'podcast' / self.date
        self.directory.mkdir(parents=True)
        (self.directory / 'status.json').write_text(json.dumps({'date': self.date, 'stage': 'starting'}))
        (self.directory / 'source.txt').write_text('Lead, weekly, local, crypto, weather, TV, series, films, cinema, music and games.')
        self.client = SimpleNamespace(
            notebooks=SimpleNamespace(create=AsyncMock(return_value=SimpleNamespace(id='notebook-id'))),
            sources=SimpleNamespace(add_text=AsyncMock(return_value=SimpleNamespace(id='source-id'))),
            artifacts=SimpleNamespace(
                generate_audio=AsyncMock(return_value=SimpleNamespace(task_id='task-id')),
                wait_for_completion=AsyncMock(return_value=SimpleNamespace(is_complete=True)),
                download_audio=AsyncMock(side_effect=self.download),
            ),
        )
        context = AsyncMock()
        context.__aenter__.return_value = self.client
        self.storage = patch.object(worker.NotebookLMClient, 'from_storage', return_value=context)
        self.from_storage = self.storage.start()
        self.environment = patch.dict(os.environ, {'NOTEBOOKLM_AUTH_JSON': 'private-test-session'})
        self.environment.start()

    async def asyncTearDown(self):
        self.storage.stop()
        self.environment.stop()
        self.temporary.cleanup()

    async def download(self, notebook_id, output_path, artifact_id):
        self.assertEqual('notebook-id', notebook_id)
        self.assertEqual('task-id', artifact_id)
        Path(output_path).write_bytes(b'fixture audio')

    async def test_generation_and_download_use_the_exact_task(self):
        async def wait(notebook_id, task_id, timeout):
            saved = json.loads((self.directory / 'status.json').read_text())
            self.assertEqual('task-id', saved['task_id'])
            self.assertEqual('generating', saved['stage'])
            return SimpleNamespace(is_complete=True)

        self.client.artifacts.wait_for_completion.side_effect = wait
        self.assertEqual(0, await worker.generate(self.root, self.date))
        state = json.loads((self.directory / 'status.json').read_text())
        self.assertEqual('completed', state['stage'])
        self.assertEqual(b'fixture audio', (self.directory / 'audio.m4a').read_bytes())
        self.assertNotIn('private-test-session', json.dumps(state))
        self.assertFalse((self.directory / 'state').exists())
        arguments = self.client.artifacts.generate_audio.call_args
        self.assertEqual('de', arguments.kwargs['language'])
        self.assertEqual(['source-id'], arguments.kwargs['source_ids'])
        self.assertTrue(self.client.sources.add_text.call_args.kwargs['wait'])
        self.assertEqual(worker.AudioLength.DEFAULT, arguments.kwargs['audio_length'])
        self.assertIn('15 Minuten', arguments.kwargs['instructions'])
        self.assertEqual((self.directory / 'source.txt').read_text(), self.client.sources.add_text.call_args.args[2])
        self.client.artifacts.generate_audio.assert_awaited_once()
        await worker.generate(self.root, self.date)
        self.client.artifacts.generate_audio.assert_awaited_once()

    async def test_missing_authentication_never_calls_google(self):
        os.environ.pop('NOTEBOOKLM_AUTH_JSON')
        self.assertEqual(1, await worker.generate(self.root, self.date))
        self.assertEqual('auth_error', json.loads((self.directory / 'status.json').read_text())['stage'])
        self.from_storage.assert_not_called()

    async def test_authentication_error_does_not_expose_the_session(self):
        self.client.notebooks.create.side_effect = worker.AuthError('private-test-session')
        self.assertEqual(1, await worker.generate(self.root, self.date))
        self.assertEqual({'date': self.date, 'stage': 'auth_error'}, json.loads((self.directory / 'status.json').read_text()))
        self.client.artifacts.generate_audio.assert_not_awaited()

    async def test_rate_limit_is_not_retried(self):
        self.client.artifacts.generate_audio.side_effect = worker.RateLimitError('private-test-session')
        self.assertEqual(1, await worker.generate(self.root, self.date))
        state = json.loads((self.directory / 'status.json').read_text())
        self.assertEqual('quota_error', state['stage'])
        self.assertNotIn('private-test-session', json.dumps(state))
        self.client.artifacts.generate_audio.assert_awaited_once()

    async def test_uncertain_completion_preserves_the_task_without_retry(self):
        self.client.artifacts.wait_for_completion.side_effect = TimeoutError('private-test-session')
        self.assertEqual(1, await worker.generate(self.root, self.date))
        state = json.loads((self.directory / 'status.json').read_text())
        self.assertEqual('task-id', state['task_id'])
        self.assertEqual('error', state['stage'])
        self.client.artifacts.generate_audio.assert_awaited_once()
        self.client.artifacts.download_audio.assert_not_awaited()

    async def test_an_uncertain_existing_task_is_not_generated_again(self):
        state = {'date': self.date, 'stage': 'generating', 'task_id': 'existing-task'}
        (self.directory / 'status.json').write_text(json.dumps(state))
        self.assertEqual(0, await worker.generate(self.root, self.date))
        self.from_storage.assert_not_called()
        self.assertEqual(state, json.loads((self.directory / 'status.json').read_text()))

    async def test_a_second_worker_does_not_use_google(self):
        with (self.directory / 'worker.lock').open('a') as lock:
            worker.fcntl.flock(lock, worker.fcntl.LOCK_EX | worker.fcntl.LOCK_NB)
            self.assertEqual(0, await worker.generate(self.root, self.date))
            self.from_storage.assert_not_called()


if __name__ == '__main__':
    unittest.main()

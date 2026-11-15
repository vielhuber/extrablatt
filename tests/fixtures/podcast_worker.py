#!/usr/bin/env python3
import json
import os
import sys
from pathlib import Path

directory = Path(sys.argv[2]) / '.data' / 'podcast' / sys.argv[3]
assert os.environ['NOTEBOOKLM_AUTH_JSON'] == 'private-test-session'
(directory / 'invocations').write_text('1' + ((directory / 'invocations').read_text() if (directory / 'invocations').exists() else ''))
(directory / 'audio.m4a').write_bytes(b'fixture audio')
(directory / 'status.json').write_text(json.dumps({'stage': 'completed'}))

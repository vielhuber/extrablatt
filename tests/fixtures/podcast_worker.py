#!/usr/bin/env python3
import json
import os
import sys
from pathlib import Path

directory = Path(sys.argv[2]) / '.data' / 'podcast'
assert os.environ['NOTEBOOKLM_AUTH_JSON'] == 'private-test-session'
(directory / 'invocations').write_text('1')
(directory / 'test.m4a').write_bytes(b'fixture audio')
(directory / 'status.json').write_text(json.dumps({'stage': 'completed'}))

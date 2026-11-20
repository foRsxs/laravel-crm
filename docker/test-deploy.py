"""Exercise deployment failure handling without a server or registry."""

import gzip
import json
import os
from pathlib import Path
import shutil
import signal
import subprocess
import tempfile
import time


with tempfile.TemporaryDirectory(prefix="krayin-deploy-test-") as directory:
    root = Path(directory)
    (root / "docker").mkdir()
    for name in ("deploy.sh", "backup.sh"):
        shutil.copyfile(Path(__file__).parent / name, root / "docker" / name)
    binary = root / "bin"
    binary.mkdir()
    stub = binary / "docker"
    stub.write_text(
        "#!/usr/bin/env python3\n"
        "import gzip,json,sys\n"
        "with open('calls.jsonl','a') as f: f.write(json.dumps(sys.argv[1:])+'\\n')\n"
        "if 'migrate' in sys.argv: sys.exit(42)\n"
        "if 'mysqldump' in ' '.join(sys.argv): print('-- backup SQL')\n"
        "if '-czf' in sys.argv: sys.stdout.buffer.write(gzip.compress(b'backup'))\n"
    )
    stub.chmod(0o755)
    environment = os.environ.copy()
    environment["PATH"] = str(binary) + os.pathsep + environment["PATH"]
    environment.pop("APP_ENV_FILE", None)
    (root / ".env.prod").write_text("APP_KEY=fixture-key\n")
    previous = "RELEASE_SHA=" + "a" * 40 + "\nAPP_IMAGE=previous\nWEB_IMAGE=previous\n"
    (root / "release.env").write_text(previous)
    candidate = root / "candidate.env"
    candidate.write_text("RELEASE_SHA=invalid\nAPP_IMAGE=invalid\nWEB_IMAGE=invalid\n")

    def deploy():
        return subprocess.run(
            ["bash", "docker/deploy.sh", "candidate.env"],
            cwd=root,
            env=environment,
            capture_output=True,
            text=True,
        )

    assert deploy().returncode != 0
    assert not (root / "calls.jsonl").exists(), "Invalid release reached Docker"
    candidate.write_text(
        "RELEASE_SHA=" + "b" * 40
        + "\nAPP_IMAGE=ghcr.io/forsxs/laravel-crm-app@sha256:" + "c" * 64
        + "\nWEB_IMAGE=ghcr.io/forsxs/laravel-crm-web@sha256:" + "d" * 64 + "\n"
    )
    result = deploy()
    assert result.returncode == 42, result.stdout + result.stderr
    calls = [json.loads(line) for line in (root / "calls.jsonl").read_text().splitlines()]
    assert not any(call[-3:] == ["php", "artisan", "up"] for call in calls)
    assert calls[-1][-3:] == ["stop", "worker", "scheduler"]
    assert calls[-2][-3:] == ["php", "artisan", "down"]
    assert (root / "release.env").read_text() == previous
    assert not (root / ".deploy.lock").exists()
    backup = next((root / "backups").iterdir())
    assert (backup / "database.sql").read_text().startswith("-- backup SQL")
    assert gzip.decompress((backup / "storage.tar.gz").read_bytes()) == b"backup"
    assert (backup / "app.env").read_text() == "APP_KEY=fixture-key\n"
    (binary / "date").write_text("#!/bin/sh\nprintf '59\\n'\n")
    (binary / "date").chmod(0o755)
    (binary / "php").write_text(
        "#!/usr/bin/env python3\n"
        "from pathlib import Path\nimport time\n"
        "Path('scheduled-running').touch()\ntime.sleep(2)\nPath('scheduled-finished').touch()\n"
    )
    (binary / "php").chmod(0o755)
    scheduler = subprocess.Popen(
        ["sh", str(Path(__file__).parent.resolve() / "scheduler.sh")],
        cwd=root,
        env=environment,
    )
    deadline = time.monotonic() + 5
    while not (root / "scheduled-running").exists() and time.monotonic() < deadline:
        time.sleep(0.05)
    assert (root / "scheduled-running").exists()
    scheduler.send_signal(signal.SIGTERM)
    assert scheduler.wait(timeout=5) == 0
    assert (root / "scheduled-finished").exists(), "Scheduler interrupted an active task"
    print("Deployment checks passed: invalid release rejected, failed migration retains maintenance, active release and backup.")
    print("Scheduler stop waits for its active task.")

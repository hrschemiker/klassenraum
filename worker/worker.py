#!/usr/bin/env python3
"""Single-concurrency recording validator, Telegram transport, and callback worker."""
from __future__ import annotations

import hashlib
import hmac
import json
import mimetypes
import os
import shutil
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
from pathlib import Path

QUEUE = Path("/var/lib/bcp/jobs")
DONE = Path("/var/lib/bcp/done")
FAILED = Path("/var/lib/bcp/failed")


def env(name: str, default: str = "") -> str:
    value = os.environ.get(name, default).strip()
    if not value:
        raise RuntimeError(f"missing environment value: {name}")
    return value


def find_video(record_id: str) -> Path | None:
    roots = [Path("/var/bigbluebutton/published/video") / record_id, Path("/var/bigbluebutton/recording/publish/video") / record_id]
    candidates = []
    for root in roots:
        if root.is_dir():
            candidates.extend(root.glob("*.mp4")); candidates.extend(root.glob("*.m4v")); candidates.extend(root.glob("*.webm"))
    return max(candidates, key=lambda p: p.stat().st_size) if candidates else None


def valid_media(path: Path) -> dict:
    cmd = ["ffprobe", "-v", "error", "-show_entries", "format=duration,size", "-show_streams", "-of", "json", str(path)]
    result = subprocess.run(cmd, capture_output=True, text=True, timeout=120, check=True)
    data = json.loads(result.stdout)
    if float(data.get("format", {}).get("duration", 0)) < 1 or not data.get("streams"):
        raise RuntimeError("media validation failed")
    return data


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for block in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def recording_metadata(record_id: str) -> dict[str, str]:
    for candidate in (Path("/var/bigbluebutton/published/presentation") / record_id / "metadata.xml", Path("/var/bigbluebutton/recording/publish/presentation") / record_id / "metadata.xml"):
        if candidate.is_file():
            root = ET.parse(candidate).getroot()
            result = {child.tag: (child.text or "").strip() for child in root.findall("./meta/*")}
            meeting = root.find("meeting")
            if meeting is not None and meeting.attrib.get("externalId"):
                result["meeting_id"] = meeting.attrib["externalId"]
            value = root.findtext("./meta/meetingId")
            if value and not result.get("meeting_id"): result["meeting_id"] = value.strip()
            return result
    return {}


def safe_filename(metadata: dict[str, str], record_id: str, suffix: str) -> str:
    student = metadata.get("gtbp_student_name", "").strip()
    session_date = metadata.get("gtbp_session_date", "").strip()
    stem = " - ".join(part for part in (student, session_date) if part) or f"recording-{record_id}"
    stem = "".join("-" if char in '/\\:*?\"<>|' or ord(char) < 32 else char for char in stem)
    stem = " ".join(stem.split()).strip(" .-")[:150] or f"recording-{record_id}"
    return stem + suffix.lower()


def telegram_upload(path: Path, caption: str, filename: str) -> dict:
    limit = int(env("MAX_UPLOAD_MIB", "1900")) * 1024 * 1024
    if path.stat().st_size > limit:
        raise RuntimeError(f"media exceeds configured upload ceiling: {path.stat().st_size}")
    endpoint = f"http://127.0.0.1:8081/bot{env('TELEGRAM_BOT_TOKEN')}/sendVideo"
    result = subprocess.run([
        "curl", "--silent", "--show-error", "--max-time", "7200", "--request", "POST", endpoint,
        "--form", f"chat_id={env('TELEGRAM_ARCHIVE_CHAT_ID')}",
        "--form", f"caption={caption}",
        "--form", "supports_streaming=true",
        "--form", "protect_content=true",
        "--form", f"video=@{path.resolve()};filename={filename};type=video/mp4",
    ], capture_output=True, text=True, timeout=7300, check=True)
    payload = json.loads(result.stdout)
    if not payload.get("ok"):
        raise RuntimeError(payload.get("description", "Telegram upload failed"))
    message = payload["result"]
    media = message.get("video") or message.get("document") or {}
    if not media.get("file_id"):
        raise RuntimeError("Telegram response did not include a reusable file identifier")
    return {"message_id": message["message_id"], "chat_id": str(message["chat"]["id"]), "file_id": media["file_id"], "file_unique_id": media.get("file_unique_id", ""), "file_size": media.get("file_size", path.stat().st_size)}


def callback(payload: dict) -> None:
    body = json.dumps(payload, separators=(",", ":"), sort_keys=True).encode()
    timestamp = str(int(time.time()))
    signature = hmac.new(env("BRIDGE_SHARED_SECRET").encode(), timestamp.encode() + b"." + body, hashlib.sha256).hexdigest()
    url = env("WORDPRESS_URL").rstrip("/") + "/wp-json/gtbp-bridge/v1/recording-ready"
    request = urllib.request.Request(url, data=body, method="POST", headers={"Content-Type": "application/json", "X-BCP-Timestamp": timestamp, "X-BCP-Signature": signature})
    with urllib.request.urlopen(request, timeout=30) as response:
        result = json.load(response)
    if not result.get("ok"):
        raise RuntimeError("application callback rejected")


def process(job_path: Path) -> None:
    job = json.loads(job_path.read_text()); record_id = job["record_id"]
    video = find_video(record_id)
    if not video:
        raise RuntimeError("composite recording is not published yet")
    probe = valid_media(video)
    digest = sha256_file(video)
    metadata = recording_metadata(record_id)
    filename = safe_filename(metadata, record_id, video.suffix)
    caption = " | ".join(part for part in (metadata.get("gtbp_student_name", "").strip(), metadata.get("gtbp_session_date", "").strip()) if part) or f"recording:{record_id}"
    sent = telegram_upload(video, caption, filename)
    payload = {"record_id": record_id, "meeting_id": metadata.get("meeting_id", ""), "presentation_url": f"https://{env('BBB_HOSTNAME')}/playback/presentation/2.3/{record_id}", "video_url": f"https://{env('BBB_HOSTNAME')}/video/{record_id}/{video.name}", "filename": filename, "sha256": digest, "duration": probe["format"]["duration"], **sent}
    callback(payload)
    DONE.mkdir(parents=True, exist_ok=True)
    os.replace(job_path, DONE / job_path.name)


def run() -> None:
    for directory in (QUEUE, DONE, FAILED): directory.mkdir(parents=True, exist_ok=True)
    while True:
        jobs = sorted(QUEUE.glob("*.json"), key=lambda p: p.stat().st_mtime)
        if not jobs: time.sleep(15); continue
        job = jobs[0]
        try:
            process(job)
        except Exception as exc:
            data = json.loads(job.read_text()); data["attempts"] = int(data.get("attempts", 0)) + 1; data["last_error"] = str(exc); data["last_attempt"] = int(time.time()); job.write_text(json.dumps(data))
            if data["attempts"] >= 20:
                FAILED.mkdir(parents=True, exist_ok=True); os.replace(job, FAILED / job.name)
            time.sleep(min(900, 30 * data["attempts"]))


def retention() -> None:
    free_gb = shutil.disk_usage("/var/bigbluebutton").free // (1024 ** 3)
    if free_gb < int(env("MIN_FREE_GB", "30")):
        print(f"warning: only {free_gb} GiB free", file=sys.stderr)
    cutoff = time.time() - int(env("LOCAL_VIDEO_RETENTION_DAYS", "3")) * 86400
    for receipt in DONE.glob("*.json"):
        if receipt.stat().st_mtime >= cutoff: continue
        record_id = receipt.stem
        for root in (Path("/var/bigbluebutton/published/video") / record_id, Path("/var/bigbluebutton/recording/publish/video") / record_id):
            if root.is_dir() and str(root).startswith("/var/bigbluebutton/"):
                shutil.rmtree(root)


if __name__ == "__main__":
    {"run": run, "retention": retention}.get(sys.argv[1] if len(sys.argv) > 1 else "", lambda: sys.exit(2))()

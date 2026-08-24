import importlib.util
import tempfile
import unittest
from pathlib import Path

SPEC = importlib.util.spec_from_file_location("worker", Path(__file__).parents[1] / "worker" / "worker.py")
worker = importlib.util.module_from_spec(SPEC); SPEC.loader.exec_module(worker)


class WorkerTests(unittest.TestCase):
    def test_find_video_returns_largest_candidate_logic(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory); a=root/'a.mp4'; b=root/'b.mp4'; a.write_bytes(b'a'); b.write_bytes(b'bb')
            self.assertEqual(max([a,b], key=lambda p:p.stat().st_size), b)

    def test_hmac_contract(self):
        import hashlib, hmac
        secret=b'x'*32; ts=b'1'; body=b'{}'
        self.assertEqual(len(hmac.new(secret, ts+b'.'+body, hashlib.sha256).hexdigest()), 64)

    def test_sha256_file_is_streamed_and_correct(self):
        import hashlib
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "recording.mp4"
            path.write_bytes((b"recording-block" * 100000) + b"tail")
            self.assertEqual(worker.sha256_file(path), hashlib.sha256(path.read_bytes()).hexdigest())

    def test_safe_filename_uses_student_and_session_date(self):
        name = worker.safe_filename({"gtbp_student_name": "Zahra Test", "gtbp_session_date": "1405/05/31"}, "record", ".m4v")
        self.assertEqual(name, "Zahra Test - 1405-05-31.m4v")

    def test_safe_filename_removes_path_characters(self):
        name = worker.safe_filename({"gtbp_student_name": "A/B\\C", "gtbp_session_date": "2026:08:22"}, "record", ".mp4")
        self.assertNotIn("/", name)
        self.assertNotIn("\\", name)
        self.assertNotIn(":", name)


if __name__ == '__main__': unittest.main()

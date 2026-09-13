"""Opt-in network benchmark; never changes app state or invokes AI providers.

python scripts/experiments/audio-acquisition.py --output <new JSON path> [--limit 1]
Requires installed yt-dlp, ffmpeg, ffprobe. Results contain no media URLs/headers.
"""
import argparse
import array
import concurrent.futures
import csv
import json
import math
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import time
import urllib.parse

ROOT = Path(__file__).resolve().parents[2]
WORK = ROOT / 'app/backend/storage/app/acquisition-experiments'
FORMAT = 'bestaudio[ext=m4a]/bestaudio[ext=webm]/bestaudio/best'
FLAGS = subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0


def command(args):
    result = subprocess.run(args, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                            stderr=subprocess.PIPE, timeout=45, creationflags=FLAGS)
    if result.returncode:
        # Provider stderr can contain signed URLs; keep it out of evidence.
        raise RuntimeError(f'{Path(args[0]).name} exit {result.returncode}')
    return result.stdout


def allowed_url(url):
    parsed = urllib.parse.urlparse(url)
    if (parsed.scheme != 'https' or not (parsed.hostname or '').endswith('.googlevideo.com')
            or parsed.username or parsed.password or parsed.port not in (None, 443)):
        raise ValueError('Unexpected media URL')
    return url


def direct_download(info, target):
    command(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-nostdin', '-y',
             *remote_options(info), '-i', allowed_url(info['url']), '-vn', '-c:a', 'copy', str(target)])
    if not target.stat().st_size:
        raise RuntimeError('Empty media')


def remote_options(info):
    headers = info.get('http_headers', {})
    if any('\r' in str(v) or '\n' in str(v) for v in headers.values()):
        raise ValueError('Invalid media header')
    return ['-rw_timeout', '20000000', '-headers', ''.join(f'{k}: {v}\r\n' for k, v in headers.items())]


def opening(source, target, info=None):
    args = ['ffmpeg', '-hide_banner', '-loglevel', 'error', '-nostdin', '-y']
    if info is not None:
        allowed_url(source)
        compressed = target.with_name(target.stem + '-opening.' + info['ext'])
        command(args + remote_options(info) + ['-i', source, '-t', '17', '-vn', '-c:a', 'copy', str(compressed)])
        opening(compressed, target)
        return
    command(args + ['-ss', '0', '-t', '17', '-i', str(source), '-vn', '-ac', '1', '-ar', '16000', '-c:a', 'flac', str(target)])


def pcm(path):
    data = command(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-i', str(path),
                    '-f', 's16le', '-ac', '1', '-ar', '16000', 'pipe:1'])
    if abs(len(data) / 32000 - 17) > .1:
        raise RuntimeError('Opening duration differs from 17s')
    return data


def run_case(case, position):
    started = time.perf_counter()
    info = json.loads(command(['yt-dlp', '--dump-single-json', '--no-warnings', '--no-playlist',
                              '--skip-download', '--format', FORMAT, 'https://www.youtube.com/watch?v=' + case['video']]))
    metadata_s = time.perf_counter() - started
    resolved = time.perf_counter()
    if (info.get('id') != case['video'] or info.get('availability') != 'public'
            or info.get('is_live') is not False or not 17 <= info.get('duration', 0) <= 3600
            or info.get('vcodec') != 'none' or info.get('ext') not in ('m4a', 'webm')):
        raise ValueError('Unsupported source metadata')
    allowed_url(info['url'])
    info.pop('webpage_url', None)
    result = dict(baseline_id=int(case['id']), video=case['video'], language=case['language'],
                  sequence=position + 1, metadata_s=metadata_s, format_id=info['format_id'],
                  ext=info['ext'], duration_s=info['duration'], modes={})
    with tempfile.TemporaryDirectory(prefix='case-', dir=WORK) as tmp:
        folder = Path(tmp).resolve()
        # Verify cleanup stays inside the experiment's controlled workspace.
        if not folder.is_relative_to(WORK.resolve()):
            raise RuntimeError('Temporary workspace escaped experiment directory')
        metadata = folder / 'info.json'
        metadata.write_text(json.dumps(info), encoding='utf-8')
        outputs = {}
        modes = ['baseline', 'direct_full', 'opening_parallel']
        modes = modes[position % 3:] + modes[:position % 3]
        for mode in modes + ['prefetched_metadata', 'prefetched_direct', 'prefetched_opening']:
            print(json.dumps(dict(mode=mode, sequence=position + 1)), flush=True)
            if mode.startswith('prefetched_'):
                time.sleep(max(0, 10 - (time.perf_counter() - resolved)))
            age = time.perf_counter() - resolved
            source = folder / (mode + '.' + info['ext'])
            ready = folder / (mode + '.flac')
            tick = time.perf_counter()
            if mode in ('baseline', 'prefetched_metadata'):
                command(['yt-dlp', '--no-playlist', '--no-warnings', '--format', FORMAT,
                         '--output', str(source), '--load-info-json', str(metadata)])
                full_s = time.perf_counter() - tick
                opening(source, ready)
                ready_s = time.perf_counter() - tick
            elif mode in ('direct_full', 'prefetched_direct'):
                direct_download(info, source)
                full_s = time.perf_counter() - tick
                opening(source, ready)
                ready_s = time.perf_counter() - tick
            else:
                # Both requests run together; measure opening availability before joining the full download.
                def background():
                    direct_download(info, source)
                    return time.perf_counter() - tick
                with concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:
                    full = pool.submit(background)
                    opening(info['url'], ready, info)
                    ready_s = time.perf_counter() - tick
                    full_s = full.result()
            outputs[mode] = pcm(ready)
            probe = json.loads(command(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'json', str(source)]))
            if abs(float(probe['format']['duration']) - info['duration']) > 2:
                raise RuntimeError('Full media duration mismatch')
            result['modes'][mode] = dict(after_resolution_ready_s=ready_s,
                                        click_to_ready_s=ready_s + (0 if mode.startswith('prefetched_') else metadata_s),
                                        full_download_s=full_s, metadata_age_s=age,
                                        full_bytes=source.stat().st_size)
        reference = outputs['baseline']
        for mode, data in outputs.items():
            a, b = array.array('h', reference), array.array('h', data)
            count = min(len(a), len(b))
            rmse = math.sqrt(sum((a[i] - b[i]) ** 2 for i in range(count)) / count)
            differences = [i for i in range(count) if a[i] != b[i]]
            result['modes'][mode].update(pcm_identical=data == reference, pcm_rmse=rmse, samples=len(b),
                                        differing_samples=len(differences),
                                        first_difference_s=differences[0] / 16000 if differences else None,
                                        last_difference_s=differences[-1] / 16000 if differences else None)
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--limit', type=int, default=12)
    parser.add_argument('--baseline-id', type=int, help='Repeat one of the known baseline videos for diagnosis')
    args = parser.parse_args()
    if not 1 <= args.limit <= 12:
        parser.error('limit must be 1..12')
    if args.output.exists():
        parser.error('Output exists; refusing accidental rerun')
    for binary in ['yt-dlp', 'ffmpeg', 'ffprobe']:
        if not shutil.which(binary):
            parser.error('Missing ' + binary)
    WORK.mkdir(parents=True, exist_ok=True)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    with (ROOT / 'docs/exec-plans/evidence/2026-09-12-generation-timing-baseline.csv').open() as f:
        cases = [r for r in csv.DictReader(f) if r['cached'] == 'False']
    cases += [next(r for r in cases if r['id'] == str(i)) for i in [152, 154, 156]]
    if args.baseline_id is not None:
        cases = [r for r in cases[:9] if int(r['id']) == args.baseline_id]
        if not cases:
            parser.error('Unknown fresh baseline ID')
    results = []
    for position, case in enumerate(cases[:args.limit]):
        print(json.dumps(dict(started=case['id'], sequence=position + 1)), flush=True)
        try:
            row = run_case(case, position)
        except Exception as error:
            # Avoid exception strings: network/process errors may contain signed URLs.
            row = dict(baseline_id=int(case['id']), sequence=position + 1, error=type(error).__name__)
        results.append(row)
        args.output.write_text(json.dumps(results, indent=2), encoding='utf-8')
        print(json.dumps(row), flush=True)
        if 'error' in row:
            raise SystemExit('Experiment failed; remaining requests stopped. Inspect code without logging URLs.')


if __name__ == '__main__':
    main()

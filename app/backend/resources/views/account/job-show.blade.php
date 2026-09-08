@extends('layouts.site')

@section('content')
    <section class="workspace narrow-workspace">
        <div class="workspace-heading">
            <div>
                <p class="eyebrow">Subtitle job</p>
                <h1>{{ $job->public_id }}</h1>
                <p>Public-safe support details. Generated subtitle text and provider payloads are not shown here.</p>
                <p>Snapshot at {{ now()->toIso8601String() }}. This page does not update automatically.</p>
            </div>
            <div class="action-stack horizontal-actions">
                <a class="button button-secondary" href="{{ route('dashboard.jobs.show', ['jobId' => $job->public_id]) }}">Refresh status</a>
                <a class="button button-secondary" href="{{ route('dashboard') }}">Back to dashboard</a>
            </div>
        </div>

        <x-ui.panel title="Status" description="{{ $job->status }} during {{ $job->stage }}">
            <dl class="metric-grid detail-grid">
                <div>
                    <dt>Source video</dt>
                    <dd><a class="text-link" href="https://www.youtube.com/watch?v={{ rawurlencode($job->youtube_video_id) }}" rel="noopener noreferrer">{{ $job->youtube_video_id }}</a></dd>
                </div>
                <div>
                    <dt>Status</dt>
                    <dd><x-ui.status-pill :status="$job->status" /></dd>
                </div>
                <div>
                    <dt>Stage</dt>
                    <dd>{{ $job->stage }}</dd>
                </div>
                <div>
                    <dt>Progress</dt>
                    <dd>{{ $job->progress_percent }}%</dd>
                </div>
                <div>
                    <dt>Language pair</dt>
                    <dd>{{ $languagePair }}</dd>
                </div>
                <div>
                    <dt>Estimated video minutes</dt>
                    <dd>{{ $usage['estimatedMinutes'] ?? 'Unknown' }}</dd>
                </div>
                <div>
                    <dt>Reserved minutes</dt>
                    <dd>{{ $usage['reservedMinutes'] }}</dd>
                </div>
                <div>
                    <dt>Charged minutes</dt>
                    <dd>{{ $usage['chargedMinutes'] }}</dd>
                </div>
                <div>
                    <dt>Released minutes</dt>
                    <dd>{{ $usage['releasedMinutes'] }}</dd>
                </div>
                <div>
                    <dt>Video duration</dt>
                    <dd>{{ $job->video_duration_seconds ?? 'Unknown' }} seconds</dd>
                </div>
                <div>
                    <dt>Created</dt>
                    <dd>{{ $job->created_at->toJSON() }}</dd>
                </div>
                <div>
                    <dt>Updated</dt>
                    <dd>{{ $job->updated_at->toJSON() }}</dd>
                </div>
                <div>
                    <dt>Generation tier</dt>
                    <dd>{{ $job->generation_tier }}</dd>
                </div>
                <div>
                    <dt>Feature mode</dt>
                    <dd>{{ $job->enrichment_mode }}</dd>
                </div>
            </dl>
        </x-ui.panel>

        @if (in_array($job->status, ['failed', 'cancelled'], true))
            <x-ui.panel class="failure-panel" title="Failure" description="Share this stable failure code with support.">
                <dl class="metric-grid">
                    <div>
                        <dt>Failure code</dt>
                        <dd>{{ $job->error_code ?? 'unknown' }}</dd>
                    </div>
                    <div>
                        <dt>Public message</dt>
                        <dd>{{ $job->error_message ?? 'Generation failed.' }}</dd>
                    </div>
                </dl>
            </x-ui.panel>
        @endif

        @if ($track)
            <x-ui.panel title="Generated track" description="Track metadata only. Subtitle cue text is intentionally hidden.">
                <dl class="metric-grid detail-grid">
                    <div>
                        <dt>Track ID</dt>
                        <dd>{{ $track->public_id }}</dd>
                    </div>
                    <div>
                        <dt>Cues</dt>
                        <dd>{{ count($track->cues ?? []) }}</dd>
                    </div>
                    <div>
                        <dt>Generated</dt>
                        <dd>{{ $track->generated_at->toJSON() }}</dd>
                    </div>
                    <div>
                        <dt>Expires</dt>
                        <dd>{{ $track->expires_at->toJSON() }}</dd>
                    </div>
                </dl>
            </x-ui.panel>
        @endif
    </section>
@endsection

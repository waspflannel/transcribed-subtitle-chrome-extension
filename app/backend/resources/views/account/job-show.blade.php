@extends('layouts.site')

@section('content')
    <section class="workspace narrow-workspace">
        <div class="workspace-heading">
            <div>
                <p class="eyebrow">Subtitle job</p>
                <h1>{{ $job->public_id }}</h1>
                <p>Public-safe support details. Generated subtitle text and provider payloads are not shown here.</p>
            </div>
            <a class="button button-secondary" href="{{ route('dashboard') }}">Back to dashboard</a>
        </div>

        <section class="panel">
            <div class="panel-heading">
                <h2>Status</h2>
                <p>{{ $job->status }} during {{ $job->stage }}</p>
            </div>
            <dl class="metric-grid detail-grid">
                <div>
                    <dt>Status</dt>
                    <dd><span class="status-pill status-{{ $job->status }}">{{ $job->status }}</span></dd>
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
                    <dt>Billable minutes</dt>
                    <dd>{{ $billableMinutes }}</dd>
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
        </section>

        @if ($job->status === 'failed')
            <section class="panel failure-panel">
                <div class="panel-heading">
                    <h2>Failure</h2>
                    <p>Share this stable failure code with support.</p>
                </div>
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
            </section>
        @endif

        @if ($track)
            <section class="panel">
                <div class="panel-heading">
                    <h2>Generated track</h2>
                    <p>Track metadata only. Subtitle cue text is intentionally hidden.</p>
                </div>
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
            </section>
        @endif
    </section>
@endsection

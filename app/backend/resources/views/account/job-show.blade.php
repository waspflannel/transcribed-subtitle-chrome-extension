@extends('layouts.site')

@section('content')
    <section class="workspace narrow-workspace">
        <div class="workspace-heading">
            <div>
                <p class="eyebrow">{{ __('Subtitle job') }}</p>
                <h1>{{ $job->public_id }}</h1>
                <p>{{ __('Public-safe support details. Generated subtitle text and provider payloads are not shown here.') }}</p>
                <p>{!! strtr(e(__('Snapshot at :slot1:. This page does not update automatically.')), [':slot1:' => e(now()->toIso8601String())]) !!}</p>
                <p>{!! strtr(e(__(':slot1:Contact support:slot2: and include the Support ID above when reporting a problem.')), [':slot1:' => '<a class="text-link strong-link" href="'.e(\App\Support\WebsiteLocale::route('marketing.support')).'">', ':slot2:' => '</a>']) !!}</p>
            </div>
            <div class="action-stack horizontal-actions">
                <a class="button button-secondary" href="{{ route('dashboard.jobs.show', ['jobId' => $job->public_id]) }}">{{ __('Refresh status') }}</a>
                <a class="button button-secondary" href="{{ route('dashboard') }}">{{ __('Back to dashboard') }}</a>
            </div>
        </div>

        <x-ui.panel title="{{ __('Status') }}" description="{{ __(':status during :stage', ['status' => __($job->status), 'stage' => __($job->stageLabel())]) }}">
            <dl class="metric-grid detail-grid">
                <div>
                    <dt>{{ __('Source video') }}</dt>
                    <dd><a class="text-link" href="https://www.youtube.com/watch?v={{ rawurlencode($job->youtube_video_id) }}" rel="noopener noreferrer">{{ $job->youtube_video_id }}</a></dd>
                </div>
                <div>
                    <dt>{{ __('Status') }}</dt>
                    <dd><x-ui.status-pill :status="$job->status" /></dd>
                </div>
                <div>
                    <dt>{{ __('Stage') }}</dt>
                    <dd>{{ __($job->stageLabel()) }}</dd>
                </div>
                <div>
                    <dt>{{ __('Progress') }}</dt>
                    <dd>{{ $job->progress_percent }}%</dd>
                </div>
                <div>
                    <dt>{{ __('Language pair') }}</dt>
                    <dd>{{ $languagePair }}</dd>
                </div>
                <div>
                    <dt>{{ __('Estimated video minutes') }}</dt>
                    <dd>{{ $usage['estimatedMinutes'] ?? __('Unknown') }}</dd>
                </div>
                <div>
                    <dt>{{ __('Reserved minutes') }}</dt>
                    <dd>{{ $usage['reservedMinutes'] }}</dd>
                </div>
                <div>
                    <dt>{{ __('Charged minutes') }}</dt>
                    <dd>{{ $usage['chargedMinutes'] }}</dd>
                </div>
                <div>
                    <dt>{{ __('Released minutes') }}</dt>
                    <dd>{{ $usage['releasedMinutes'] }}</dd>
                </div>
                <div>
                    <dt>{{ __('Video duration') }}</dt>
                    <dd>{!! strtr(e(__(':slot1: seconds')), [':slot1:' => e($job->video_duration_seconds ?? 'Unknown')]) !!}</dd>
                </div>
                <div>
                    <dt>{{ __('Created') }}</dt>
                    <dd>{{ $job->created_at->toJSON() }}</dd>
                </div>
                <div>
                    <dt>{{ __('Updated') }}</dt>
                    <dd>{{ $job->updated_at->toJSON() }}</dd>
                </div>
                <div>
                    <dt>{{ __('Generation tier') }}</dt>
                    <dd>{{ $job->generation_tier }}</dd>
                </div>
            </dl>
        </x-ui.panel>

        @if (in_array($job->status, ['failed', 'cancelled'], true))
            <x-ui.panel
                class="failure-panel"
                title="{{ $job->status === 'cancelled' ? __('Cancellation') : __('Failure') }}"
                description="{{ $job->status === 'cancelled' ? __('Generation was cancelled; share this outcome with support.') : __('Share this stable failure code with support.') }}"
            >
                <dl class="metric-grid">
                    <div>
                        <dt>{{ $job->status === 'cancelled' ? __('Cancellation code') : __('Failure code') }}</dt>
                        <dd>{{ $job->error_code ?? 'unknown' }}</dd>
                    </div>
                    <div>
                        <dt>{{ __('Public message') }}</dt>
                        <dd>{{ __($job->error_message ?? 'Generation failed.') }}</dd>
                    </div>
                </dl>
            </x-ui.panel>
        @endif

        @if ($track)
            <x-ui.panel title="{{ __('Generated track') }}" description="{{ __('Track metadata only. Subtitle cue text is intentionally hidden.') }}">
                <dl class="metric-grid detail-grid">
                    <div>
                        <dt>{{ __('Track ID') }}</dt>
                        <dd>{{ $track->public_id }}</dd>
                    </div>
                    <div>
                        <dt>{{ __('Cues') }}</dt>
                        <dd>{{ count($track->cues ?? []) }}</dd>
                    </div>
                    <div>
                        <dt>{{ __('Generated') }}</dt>
                        <dd>{{ $track->generated_at->toJSON() }}</dd>
                    </div>
                    <div>
                        <dt>{{ __('Expires') }}</dt>
                        <dd>{{ $track->expires_at->toJSON() }}</dd>
                    </div>
                </dl>
            </x-ui.panel>
        @endif
    </section>
@endsection

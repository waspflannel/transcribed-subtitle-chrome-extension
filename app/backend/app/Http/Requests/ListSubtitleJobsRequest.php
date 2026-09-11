<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListSubtitleJobsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['youtubeVideoId' => ['sometimes', 'required', 'string', 'regex:/^[A-Za-z0-9_-]{11}$/']];
    }
}

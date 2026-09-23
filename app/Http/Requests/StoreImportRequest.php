<?php

namespace App\Http\Requests;

use App\Models\Offer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreImportRequest extends FormRequest
{
    /**
     * ISO 8601 with an explicit offset and whole seconds, as in the task: `strtotime` words
     * like `now` would break resend detection, and the columns do not keep fractions.
     */
    private const string MOMENT = 'date_format:Y-m-d\\TH:i:s\\Z,Y-m-d\\TH:i:sP';

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Everything the job reads is checked here, so a bad offer is a 422, not a failed import.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'supplier' => ['required', 'string', 'exists:suppliers,slug'],
            'external_import_id' => ['required', 'string', 'max:255'],
            'sent_at' => ['required', self::MOMENT],
            'offers' => ['required', 'list', 'min:1', 'max:1000'],
            'offers.*' => ['required', 'array'],
            // `ignore_case` mirrors the case-insensitive unique key.
            'offers.*.external_id' => ['required', 'string', 'max:255', 'distinct:ignore_case'],
            'offers.*.property' => ['required', 'array'],
            'offers.*.property.code' => ['required', 'string', 'max:255'],
            'offers.*.property.name' => ['required', 'string', 'max:255'],
            'offers.*.property.City' => ['required', 'string', 'max:255'],
            'offers.*.check_in' => ['required', 'date_format:Y-m-d'],
            'offers.*.check_out' => ['required', 'date_format:Y-m-d', 'after:offers.*.check_in'],
            'offers.*.max_guests' => ['required', 'integer:strict', 'min:1', 'max:65535'],
            'offers.*.price' => ['required', 'integer:strict', 'min:1'],
            'offers.*.currency' => ['required', 'string', Rule::in([Offer::CURRENCY])],
            'offers.*.available_units' => ['required', 'integer:strict', 'min:0', 'max:65535'],
            'offers.*.expires_at' => ['required', self::MOMENT],
        ];
    }
}

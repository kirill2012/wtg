<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreImportRequest extends FormRequest
{
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
     * Every offer field is required: the job reads the payload as a plain array, so a
     * missing one would fail the import in the queue instead of giving a 422 here. `City`
     * keeps the supplier contract's capital letter.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'supplier' => ['required', 'string', 'exists:suppliers,slug'],
            'external_import_id' => ['required', 'string', 'max:255'],
            'sent_at' => ['required', 'date'],
            // The whole payload lands in a JSON column and in the job's memory, hence the cap.
            'offers' => ['required', 'list', 'min:1', 'max:1000'],
            'offers.*' => ['required', 'array'],
            // Duplicates within one payload are a supplier error, not something to collapse
            // silently; `ignore_case` mirrors the case-insensitive unique key in the database.
            'offers.*.external_id' => ['required', 'string', 'max:255', 'distinct:ignore_case'],
            'offers.*.property' => ['required', 'array'],
            'offers.*.property.code' => ['required', 'string', 'max:255'],
            'offers.*.property.name' => ['required', 'string', 'max:255'],
            'offers.*.property.City' => ['required', 'string', 'max:255'],
            // Calendar dates, not moments: the search compares them with `=` against a DATE column.
            'offers.*.check_in' => ['required', 'date_format:Y-m-d'],
            'offers.*.check_out' => ['required', 'date_format:Y-m-d', 'after:offers.*.check_in'],
            // Here and on available_units the cap gives a 422 instead of an out-of-range
            // failure in the job.
            'offers.*.max_guests' => ['required', 'integer', 'min:1', 'max:65535'],
            'offers.*.price' => ['required', 'integer', 'min:0'],
            'offers.*.currency' => ['required', 'string', 'size:3', 'alpha:ascii'],
            'offers.*.available_units' => ['required', 'integer', 'min:0', 'max:65535'],
            'offers.*.expires_at' => ['required', 'date'],
        ];
    }
}

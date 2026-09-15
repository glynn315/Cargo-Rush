<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Requests;

use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Uploading the company's logo.
 *
 * One field, and no dimensions among the rules. Nothing here asks for 64×64 or
 * refuses an image that is not square — `LogoStore` crops and resamples every
 * upload to the stored size, so demanding it of the person uploading would be
 * asking them to do by hand what the server does anyway, and refusing their
 * only copy of the logo over it.
 *
 * `max` bounds what is *accepted*, not what is kept. The stored file is a 64px
 * PNG a few kilobytes long whatever arrives; the limit is here so a mis-picked
 * 40MB scan is turned away before it is decoded rather than after.
 */
class CompanyLogoRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'logo' => [
                'required',
                'image',
                // Named rather than left to `image` alone: the list is what the
                // store can actually decode, and SVG in particular is not on it
                // — it is a document that can carry script, not a bitmap, and
                // GD cannot rasterise one.
                'mimes:jpeg,jpg,png,gif,webp,bmp',
                'max:'.(int) config('cargo.company.logo_max_kb'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'logo.required' => 'Choose an image file to use as the logo.',
            'logo.image' => 'The logo has to be an image — a PNG, JPEG, GIF or WebP.',
            'logo.mimes' => 'That format is not supported. Use a PNG, JPEG, GIF or WebP.',
            'logo.max' => 'That file is too large. Anything up to a few megabytes is fine.',
        ];
    }

    public function logo(): UploadedFile
    {
        /** @var UploadedFile $file */
        $file = $this->file('logo');

        return $file;
    }
}

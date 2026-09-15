<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Services;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Where a company's logo is kept, and the one shape it is kept in.
 *
 * The same outline as `PhotoStore` and `ProofStore` — store a path, derive the
 * URL on read — with one thing neither of those does: **it rewrites the file**.
 * A staff photograph is kept as it was taken, because it is a record. A logo is
 * a piece of chrome rendered in a 32px box on every page load by everybody in
 * the company, and what somebody picks off their desktop is a 2400px PNG or a
 * scan of a business card.
 *
 * So every upload is decoded, centre-cropped to a square, resampled to
 * `cargo.company.logo_px` and re-encoded as PNG. What is stored is never the
 * bytes that arrived, and that is deliberate:
 *
 *   **Size.** Four megabytes to draw a thumbnail, times every page load, times
 *   everyone in the office. The stored file is a few kilobytes.
 *
 *   **One known shape.** No client has to guess what it is about to render or
 *   handle a 3:1 banner in a square box.
 *
 *   **Safety.** An uploaded file is only an image because a client said so.
 *   Decode-and-re-encode means whatever is written to disk was produced by GD
 *   from pixels, so a payload dressed up as a PNG does not survive the round
 *   trip. The `image` validation rule checks the header; this checks that the
 *   whole thing is really an image by making a new one out of it.
 *
 * PNG rather than the format that arrived, because a logo has a transparent
 * background more often than not, and re-encoding one as JPEG puts a black or
 * white box behind a mark that was drawn without one.
 */
class LogoStore
{
    /**
     * Normalise an upload and keep it. Returns the stored path.
     *
     * @throws ValidationException When the file cannot be decoded as an image.
     */
    public function store(UploadedFile $file): string
    {
        $source = $this->decode($file);

        try {
            $square = $this->square($source, $this->size());
        } finally {
            imagedestroy($source);
        }

        try {
            $path = sprintf('%s/%s.png', $this->directory(), Str::ulid());

            Storage::disk($this->disk())->put($path, $this->encode($square));
        } finally {
            imagedestroy($square);
        }

        return $path;
    }

    /**
     * Replace a company's logo, removing the one it supersedes.
     *
     * The delete matters here in a way it does not for proof of delivery: a
     * logo is re-uploaded every time somebody does not like how it sits, and
     * without this every attempt stays on disk forever with nothing pointing
     * at it.
     */
    public function replace(?string $existing, UploadedFile $file): string
    {
        $path = $this->store($file);

        if ($existing !== null) {
            $this->remove($existing);
        }

        return $path;
    }

    public function remove(?string $path): void
    {
        if ($path === null) {
            return;
        }

        Storage::disk($this->disk())->delete($path);
    }

    /** Read back what a stored path resolves to, for a client to fetch. */
    public function url(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        return Storage::disk($this->disk())->url($path);
    }

    /**
     * The uploaded bytes, as something GD will work on.
     *
     * `imagecreatefromstring` sniffs the format itself, which covers PNG, JPEG,
     * GIF, WebP and BMP without a branch per type — and answers false for
     * anything that is not one of them, which is the check that matters.
     *
     * A 422 rather than a 500: "that file is not an image the server can read"
     * is a thing the person uploading can act on, and a stack trace is not.
     *
     * @throws ValidationException
     */
    private function decode(UploadedFile $file): GdImage
    {
        $bytes = @file_get_contents($file->getRealPath());

        $image = $bytes === false ? false : @imagecreatefromstring($bytes);

        if ($image === false) {
            throw ValidationException::withMessages([
                'logo' => ['That file could not be read as an image. Try a PNG, JPEG, GIF or WebP.'],
            ]);
        }

        return $image;
    }

    /**
     * A square of `$size` pixels, cropped from the middle and resampled.
     *
     * **Cropped, not squashed.** A wide logo letterboxed into a square is
     * mostly empty space at 32px, and one stretched to fit is a logo the
     * company would not recognise. Taking the middle square is the one that
     * looks like a deliberate crop rather than a bug.
     *
     * Transparency survives, which is the whole reason for the four lines
     * around the copy: a true-colour canvas starts out opaque black, so the
     * destination is filled with a fully transparent colour and blending is
     * turned *off* before the copy, so alpha is carried across rather than
     * composited onto the black.
     */
    private function square(GdImage $source, int $size): GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);

        $canvas = imagecreatetruecolor($size, $size);

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefilledrectangle(
            $canvas, 0, 0, $size - 1, $size - 1,
            (int) imagecolorallocatealpha($canvas, 255, 255, 255, 127),
        );

        imagecopyresampled(
            $canvas, $source,
            0, 0,
            intdiv($width - $side, 2), intdiv($height - $side, 2),
            $size, $size,
            $side, $side,
        );

        return $canvas;
    }

    /** PNG bytes, compressed hard — this is written once and read constantly. */
    private function encode(GdImage $image): string
    {
        ob_start();
        imagepng($image, null, 9);

        return (string) ob_get_clean();
    }

    private function size(): int
    {
        return max(16, (int) config('cargo.company.logo_px', 64));
    }

    private function disk(): string
    {
        return (string) config('cargo.company.disk');
    }

    private function directory(): string
    {
        return trim((string) config('cargo.company.directory'), '/');
    }
}

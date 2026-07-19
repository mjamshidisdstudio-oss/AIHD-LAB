<?php

namespace Tests\Concerns;

/**
 * Real, minimal, content-sniffable bytes per media type -- for fixtures that
 * need genuine fileinfo-detectable content (Phase L4's media validation is
 * checked against the file's REAL sniffed mime, never a claimed one, so a
 * placeholder string like "IMG-BYTES" no longer passes as an "image").
 */
trait GeneratesFakeMedia
{
    /**
     * A tiny real PNG, GD-generated -- sniffs as image/png.
     */
    protected function fakePngBytes(): string
    {
        ob_start();
        imagepng(imagecreatetruecolor(2, 2));

        return ob_get_clean();
    }

    protected function fakePngBase64(): string
    {
        return base64_encode($this->fakePngBytes());
    }

    /**
     * A minimal valid MP4 "ftyp" box -- not a playable video, but a genuine
     * ISO-BMFF header that sniffs as video/mp4 (verified against this
     * environment's fileinfo/libmagic).
     */
    protected function fakeMp4Bytes(): string
    {
        return pack('N', 24).'ftyp'.'isom'.pack('N', 512).'isom'.'mp41';
    }

    protected function fakeMp4Base64(): string
    {
        return base64_encode($this->fakeMp4Bytes());
    }
}

<?php

namespace App\Enums;

/**
 * How a reusable signature was produced. All three are rasterized to a PNG client-side and
 * placed as a `signature` overlay, so the bake pipeline treats every signature as an image;
 * this enum is metadata for the saved-signatures list (label/icon).
 */
enum SignatureType: string
{
    case Draw = 'draw';     // hand-drawn on a canvas pad
    case Type = 'type';     // typed text rendered in a signature font
    case Upload = 'upload'; // an uploaded image (e.g. a photographed signature)
}

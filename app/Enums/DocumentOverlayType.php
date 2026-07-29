<?php

namespace App\Enums;

/**
 * The kinds of non-destructive overlay edits placed on top of a rendered PDF page.
 *
 * Every overlay stores its geometry in PDF user space (points, bottom-left origin) so the
 * Python service can bake it deterministically (ARCHITECTURE.md §3). `signature` and
 * `form_field` are reserved for Phase 4 and are not baked yet.
 */
enum DocumentOverlayType: string
{
    case Text = 'text';           // a whiteout-free text box drawn on top
    case Whiteout = 'whiteout';   // an opaque (usually white) rectangle that hides content
    case Highlight = 'highlight'; // a translucent filled rectangle
    case Underline = 'underline'; // a line along the bottom of a rectangle
    case Strike = 'strike';       // a line through the middle of a rectangle
    case Shape = 'shape';         // rectangle / ellipse / line vector shape
    case Freehand = 'freehand';   // a free-drawn polyline
    case Image = 'image';         // a raster image placed in a rectangle
    case Signature = 'signature'; // reserved for Phase 4
    case FormField = 'form_field'; // reserved for Phase 4
}

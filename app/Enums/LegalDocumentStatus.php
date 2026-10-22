<?php

namespace App\Enums;

enum LegalDocumentStatus: string
{
    // Uploaded and previewable by admins; never public.
    case Draft = 'draft';
    // The one public version for its audience and type.
    case Published = 'published';
    // Was published and then taken down by an admin without a replacement.
    case Unpublished = 'unpublished';
    // Was published and then replaced by a newer published version.
    case Superseded = 'superseded';
}

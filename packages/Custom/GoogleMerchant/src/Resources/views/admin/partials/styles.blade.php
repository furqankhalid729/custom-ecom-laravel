{{-- Utilities not present in Bagisto's compiled admin CSS (its Tailwind build only keeps classes used in Webkul views). --}}
@pushOnce('styles')
    <style>
        .gmc-badge { display: inline-block; border-radius: 9999px; padding: 2px 10px; font-size: 12px; font-weight: 600; line-height: 18px; white-space: nowrap; }
        .gmc-ok { background: #dcfce7; color: #166534; }
        .gmc-warn { background: #fef3c7; color: #92400e; }
        .gmc-bad { background: #fee2e2; color: #991b1b; }
        .gmc-info { background: #dbeafe; color: #1e40af; }
        .gmc-muted { background: #f3f4f6; color: #374151; }
        .dark .gmc-ok { background: #14532d; color: #dcfce7; }
        .dark .gmc-warn { background: #78350f; color: #fef3c7; }
        .dark .gmc-bad { background: #7f1d1d; color: #fee2e2; }
        .dark .gmc-info { background: #1e3a8a; color: #dbeafe; }
        .dark .gmc-muted { background: #374151; color: #f3f4f6; }
        .gmc-side { width: 380px; }
        .gmc-stat { min-width: 140px; }
        @media (max-width: 1280px) { .gmc-side { width: 100%; } }
    </style>
@endPushOnce

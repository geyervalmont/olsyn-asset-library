@props(['name'])

<svg {{ $attributes->class('ui-nav-icon') }} viewBox="0 0 24 24" fill="none" aria-hidden="true">
    @switch($name)
        @case('materials')
            <rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>
            @break
        @case('studio')
            <path d="m12 3 2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5Z"/><path d="M20 2v4M18 4h4"/>
            @break
        @case('import')
            <path d="M12 15V3m-4 4 4-4 4 4M4 14v5a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-5"/>
            @break
        @case('connect')
            <rect x="3" y="3" width="18" height="13" rx="2"/><path d="M8 21h8m-4-5v5m-4-11 3 3 5-6"/>
            @break
        @case('jobs')
            <circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>
            @break
        @case('quality')
            <path d="m12 3 8 3v6c0 4-5 8-8 9-3-1-8-5-8-9V6Z"/><path d="m8 12 3 3 5-6"/>
            @break
        @case('drive')
            <path d="m3 14 2-9a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2l2 9"/><rect x="3" y="13" width="18" height="8" rx="2"/><path d="M7 17h.01M16 17h2"/>
            @break
        @case('health')
            <path d="M3 12h4l3-8 4 16 3-8h4"/>
            @break
        @case('team')
            <circle cx="9" cy="8" r="3"/><path d="M3 21v-2a6 6 0 0 1 12 0v2m2-16a3 3 0 0 1 0 6m4 10v-2a6 6 0 0 0-4-5"/>
            @break
        @case('settings')
            <path d="M4 7h16M4 17h16"/><circle cx="9" cy="7" r="3" fill="currentColor" stroke="none"/><circle cx="15" cy="17" r="3" fill="currentColor" stroke="none"/>
            @break
        @case('workspaces')
            <rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 10h18M9 10v11M8 4V2m8 2V2"/>
            @break
        @case('code')
            <path d="m8 6-6 6 6 6m8-12 6 6-6 6m-3-15-2 18"/>
            @break
        @case('sidebar')
            <rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16m7-11-3 3 3 3"/>
            @break
        @case('chevron')
            <path d="m9 5 7 7-7 7"/>
            @break
        @case('logout')
            <path d="M9 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4m6-13 5 5-5 5m-8-5h13"/>
            @break
    @endswitch
</svg>

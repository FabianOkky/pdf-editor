{{--
    The Lapis mark: two stacked pages — the immutable original behind, the working copy in
    front — with the edit layer knocked out of the front sheet. It is drawn in `currentColor`
    so it inherits whatever surface it sits on.
--}}
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" fill="none" {{ $attributes }}>
    <path
        d="M15.5 2h9A3.5 3.5 0 0 1 28 5.5v15a3.5 3.5 0 0 1-3.5 3.5h-2v-2h2a1.5 1.5 0 0 0 1.5-1.5v-15A1.5 1.5 0 0 0 24.5 4h-9A1.5 1.5 0 0 0 14 5.5v2h-2v-2A3.5 3.5 0 0 1 15.5 2Z"
        fill="currentColor"
        opacity=".45"
    />
    <path
        fill="currentColor"
        fill-rule="evenodd"
        clip-rule="evenodd"
        d="M7.5 8h10A3.5 3.5 0 0 1 21 11.5v15a3.5 3.5 0 0 1-3.5 3.5h-10A3.5 3.5 0 0 1 4 26.5v-15A3.5 3.5 0 0 1 7.5 8ZM8 14.25c0-.414.336-.75.75-.75h7.5a.75.75 0 0 1 0 1.5h-7.5a.75.75 0 0 1-.75-.75Zm.75 3.75a.75.75 0 0 0 0 1.5h7.5a.75.75 0 0 0 0-1.5h-7.5Zm0 4.5a.75.75 0 0 0 0 1.5h4.5a.75.75 0 0 0 0-1.5h-4.5Z"
    />
</svg>

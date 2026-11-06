@if (request()->routeIs('shop.cms.page'))
    <style id="store-policy-page-styles">
        #main > .container h1 { font-size: 2rem; line-height: 1.25; font-weight: 600; margin: 0 0 1.25rem; }
        #main > .container h2 { font-size: 1.5rem; line-height: 1.3; font-weight: 600; margin: 2rem 0 0.75rem; }
        #main > .container h3 { font-size: 1.125rem; line-height: 1.4; font-weight: 600; margin: 1.5rem 0 0.5rem; }
        #main > .container p { margin: 0 0 1rem; line-height: 1.65; }
        #main > .container ul,
        #main > .container ol { margin: 0 0 1rem 1.5rem; line-height: 1.65; }
        #main > .container ul { list-style: disc; }
        #main > .container ol { list-style: decimal; }
        #main > .container a { text-decoration: underline; }
        #main > .container img { max-width: 240px; height: auto; }
    </style>
@endif

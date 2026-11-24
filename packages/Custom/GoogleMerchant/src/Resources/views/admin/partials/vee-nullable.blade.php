{{-- Configure-page rules are shared by Laravel and vee-validate; vee-validate has no `nullable`
     and throws "No such validator", which silently blocks Save Configuration. --}}
<script>
    window.addEventListener('load', function () {
        if (window.defineRule) {
            window.defineRule('nullable', function () { return true; });
        }
    });
</script>

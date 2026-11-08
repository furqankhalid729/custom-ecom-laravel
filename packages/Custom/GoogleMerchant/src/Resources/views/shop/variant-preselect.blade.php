{{--
    Google Shopping variant links are "<product url>?variant=<sku>". Bagisto's
    product page cannot pre-select a variant from the URL, so this replays the
    shopper's clicks on the super-attribute inputs (select or swatch), in order.
--}}
@php
    $steps = [];
    $sku = request()->query('variant');

    if (is_string($sku) && $sku !== '' && $product->type === 'configurable') {
        $variant = $product->variants()->where('sku', $sku)->first();

        if ($variant) {
            $config = app(\Webkul\Product\Helpers\ConfigurableOption::class)->getConfigurationConfig($product);

            $options = $config['index'][$variant->id] ?? [];

            foreach ($config['attributes'] as $attribute) {
                if (isset($options[$attribute['id']])) {
                    $steps[] = [(int) $attribute['id'], (int) $options[$attribute['id']]];
                }
            }
        }
    }
@endphp

@if ($steps)
    <script id="gmc-variant-preselect">
        (function () {
            var steps = @json($steps);
            var i = 0, tries = 0;

            function next() {
                if (i >= steps.length || tries > 100) return;

                var name = 'super_attribute[' + steps[i][0] + ']';
                var value = String(steps[i][1]);

                var select = document.querySelector('select[name="' + name + '"]');
                var radio = document.querySelector('input[type="radio"][name="' + name + '"][value="' + value + '"]');

                if (select && Array.prototype.some.call(select.options, function (o) { return o.value === value; })) {
                    select.value = value;
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                } else if (radio) {
                    radio.click();
                } else {
                    tries++;
                    return setTimeout(next, 100); // Vue has not rendered this attribute's options yet
                }

                i++;
                tries = 0;
                setTimeout(next, 60);
            }

            window.addEventListener('load', function () { setTimeout(next, 150); });
        })();
    </script>
@endif

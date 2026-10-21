jQuery(document).ready(function ($) {
    'use strict';

    // Keep the calendar range valid while allowing dates in either direction.
    var $dateFrom = $('#ftwcc-date-from');
    var $dateTo = $('#ftwcc-date-to');

    function syncDateRangeLimits() {
        $dateFrom.attr('max', $dateTo.val() || null);
        $dateTo.attr('min', $dateFrom.val() || null);
    }

    $dateFrom.add($dateTo).on('change', syncDateRangeLimits);
    syncDateRangeLimits();

    var $productMultiselect = $('.wco-product-multiselect');
    var $productToggle = $productMultiselect.find('.wco-product-toggle');
    var $productPanel = $productMultiselect.find('.wco-product-options-panel');
    var $productSearch = $productMultiselect.find('.wco-product-search');

    function updateProductSummary() {
        var $selectedProducts = $productMultiselect.find('.wco-product-checkbox:checked');
        var summary = 'All products';

        if ($selectedProducts.length === 1) {
            summary = $selectedProducts.first().closest('.wco-product-option')
                .find('.wco-product-option-name').text();
        } else if ($selectedProducts.length > 1) {
            summary = $selectedProducts.length + ' products selected';
        }

        $productMultiselect.find('#ftwcc-products-summary').text(summary);
    }

    function closeProductDropdown(returnFocus) {
        $productPanel.prop('hidden', true);
        $productToggle.attr('aria-expanded', 'false');
        $productSearch.val('');
        $productMultiselect.find('.wco-product-option').show();
        if (returnFocus) {
            $productToggle.trigger('focus');
        }
    }

    $productToggle.on('click', function () {
        var willOpen = $productPanel.prop('hidden');
        $productPanel.prop('hidden', !willOpen);
        $productToggle.attr('aria-expanded', willOpen ? 'true' : 'false');
        if (willOpen) {
            $productSearch.trigger('focus');
        }
    });

    $productMultiselect.on('click', function (event) {
        event.stopPropagation();
    });

    $(document).on('click', function () {
        closeProductDropdown(false);
    });

    $(document).on('keydown', function (event) {
        if (event.key === 'Escape' && !$productPanel.prop('hidden')) {
            closeProductDropdown(true);
        }
    });

    $productMultiselect.on('change', '.wco-product-checkbox', updateProductSummary);

    $productMultiselect.find('.wco-product-clear').on('click', function () {
        $productMultiselect.find('.wco-product-checkbox').prop('checked', false);
        updateProductSummary();
    });

    $productSearch.on('input', function () {
        var query = $(this).val().toLowerCase().trim();
        $productMultiselect.find('.wco-product-option').each(function () {
            $(this).toggle($(this).text().toLowerCase().indexOf(query) !== -1);
        });
    });

    updateProductSummary();

    var columnPrefsCookie = 'ftwcc_column_visibility';

    function readColumnPrefsCookie() {
        var cookiePrefix = columnPrefsCookie + '=';
        var cookies = document.cookie ? document.cookie.split(';') : [];

        for (var i = 0; i < cookies.length; i++) {
            var cookie = cookies[i].trim();
            if (cookie.indexOf(cookiePrefix) !== 0) {
                continue;
            }

            try {
                var value = JSON.parse(decodeURIComponent(cookie.substring(cookiePrefix.length)));
                return value && typeof value === 'object' ? value : null;
            } catch (error) {
                return null;
            }
        }

        return null;
    }

    function writeColumnPrefsCookie(preferences) {
        var cookie = columnPrefsCookie + '=' + encodeURIComponent(JSON.stringify(preferences)) +
            '; Max-Age=31536000; Path=/; SameSite=Lax';

        if (window.location.protocol === 'https:') {
            cookie += '; Secure';
        }

        document.cookie = cookie;
    }

    function collectColumnPreferences() {
        var preferences = {};

        $('.usr-column-toggle').each(function () {
            preferences[$(this).data('column')] = $(this).is(':checked');
        });

        return preferences;
    }

    function applyColumnPreferences(preferences) {
        $.each(preferences, function (column, visible) {
            var $checkbox = $('.usr-column-toggle').filter(function () {
                return $(this).data('column') === column;
            });
            if (!$checkbox.length || typeof visible !== 'boolean') {
                return;
            }

            $checkbox.prop('checked', visible);
            $('.usr-col-' + column).toggle(visible);
        });
    }

    // ── Column visibility toggles ─────────────────────────────────────────
    $('.usr-column-toggle').on('change', function () {
        var $checkbox  = $(this);
        var column     = $checkbox.data('column');
        var visible    = $checkbox.is(':checked');
        var colClass   = '.usr-col-' + column;

        // Show / hide all cells (th + td) for this column
        if (visible) {
            $(colClass).show();
        } else {
            $(colClass).hide();
        }

        writeColumnPrefsCookie(collectColumnPreferences());

    });

    // ── Apply saved preferences on page load ─────────────────────────────
    var cookiePreferences = readColumnPrefsCookie();

    if (cookiePreferences) {
        applyColumnPreferences(cookiePreferences);
    } else {
        writeColumnPrefsCookie(collectColumnPreferences());
    }
});

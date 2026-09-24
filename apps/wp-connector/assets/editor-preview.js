/*
 * Elementor editor preview: every time an AISG widget (re-)renders, initialise
 * its Alpine components (accordions, galleries, menus). spec §6.
 */
(function ($) {
    'use strict';

    function init($scope) {
        var el = $scope && $scope[0];

        if (!el || !window.Alpine || !window.Alpine.initTree) {
            return;
        }

        try {
            if (window.Alpine.destroyTree) {
                window.Alpine.destroyTree(el);
            }
            window.Alpine.initTree(el);
        } catch (e) {
            // A broken widget must never break the editor.
            window.console && console.warn('[aisg] Alpine init failed', e);
        }
    }

    $(window).on('elementor/frontend/init', function () {
        window.elementorFrontend.hooks.addAction('frontend/element_ready/widget', function ($scope) {
            var type = ($scope.data('widget_type') || '').toString();

            if (type.indexOf('aisg-') === 0) {
                init($scope);
            }
        });
    });
})(window.jQuery);

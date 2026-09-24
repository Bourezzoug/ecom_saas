/*
 * AISG section runtime: Alpine components referenced by name from templates
 * (x-data="aisgAccordion"). Load this BEFORE alpine.min.js so the components are
 * registered on alpine:init. Templates never contain inline JS logic.
 */
(function () {
    'use strict';

    function register(Alpine) {
        // Header mobile menu and other show/hide toggles.
        Alpine.data('aisgDisclosure', function () {
            return {
                open: false,
                toggle: function () {
                    this.open = !this.open;
                },
                close: function () {
                    this.open = false;
                },
            };
        });

        // Product gallery: one large image, thumbnails switch it (1-based like _index).
        Alpine.data('aisgGallery', function () {
            return {
                active: 1,
                select: function (index) {
                    this.active = index;
                },
                isActive: function (index) {
                    return this.active === index;
                },
            };
        });

        // Sticky add-to-cart: appears once the visitor scrolls past the first screen.
        Alpine.data('aisgSticky', function () {
            return {
                visible: false,
                init: function () {
                    var self = this;
                    var always = this.$el.getAttribute('data-always') === '1';
                    var update = function () {
                        self.visible = always || window.scrollY > window.innerHeight * 0.6;
                    };
                    update();
                    window.addEventListener('scroll', update, { passive: true });
                },
            };
        });

        // FAQ: one item open at a time.
        Alpine.data('aisgAccordion', function () {
            return {
                active: null,
                toggle: function (index) {
                    this.active = this.active === index ? null : index;
                },
                isOpen: function (index) {
                    return this.active === index;
                },
            };
        });
    }

    if (window.Alpine && window.Alpine.version) {
        // Alpine already started (another plugin shipped it): register now.
        register(window.Alpine);
    } else {
        document.addEventListener('alpine:init', function () {
            register(window.Alpine);
        });
    }
})();

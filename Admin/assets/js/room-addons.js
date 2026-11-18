
(function () {
    'use strict';

    var section = document.getElementById('spaAddonsSection');
    if (!section) return;

    var apiBase = document.body.getAttribute('data-api') || '';
    var grid = document.getElementById('addonGrid');
    var form = document.getElementById('addonForm');
    var modal = document.getElementById('addonModal');
    var search = document.getElementById('addonSearch');
    var statusFilter = document.getElementById('addonStatusFilter');

    var addons = [];

    function $(selector) {
        return section.querySelector(selector) ||
            document.querySelector(selector);
    }

    function make(tag, className, text) {
        var node = document.createElement(tag);

        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;

        return node;
    }

    function money(value) {
        return new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
            minimumFractionDigits: 2
        }).format(Number(value) || 0);
    }

    function showToast(message) {
        var toast = document.getElementById('toast');

        if (!toast) {
            window.alert(message);
            return;
        }

        toast.textContent = message;
        toast.hidden = false;

        window.clearTimeout(showToast.timer);

        showToast.timer = window.setTimeout(function () {
            toast.hidden = true;
        }, 3500);
    }

    function request(url, options) {
        return fetch(apiBase + url, Object.assign({
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json'
            }
        }, options || {}))
        .then(function (response) {
            return response.json().catch(function () {
                return {
                    ok: false,
                    error: 'Unexpected server response.'
                };
            }).then(function (data) {
                if (!response.ok && !data.error) {
                    data.error = 'Request failed (' +
                        response.status + ').';
                }

                return data;
            });
        });
    }

    function openModal(addon) {
        form.reset();

        $('#addonError').hidden = true;

        $('#addonAction').value = addon ? 'update' : 'create';
        $('#addonId').value = addon ? String(addon.id) : '';

        $('#addonModalTitle').textContent = addon
            ? 'Edit Spa Ritual Add-on'
            : 'New Spa Ritual Add-on';

        $('#addonName').value = addon ? addon.name : '';
        $('#addonDescription').value = addon ? addon.description : '';

        $('#addonPrice').value = addon
            ? Number(addon.price).toFixed(2)
            : '';

        $('#addonSort').value = addon
            ? String(addon.sort_order)
            : '0';

        $('#addonCommissionable').checked = addon
            ? addon.commissionable
            : false;

        $('#addonIsActive').checked = addon
            ? addon.active
            : true;

        modal.hidden = false;

        document.body.classList.add('modal-open');

        $('#addonName').focus();
    }

    function closeModal() {
        modal.hidden = true;
        document.body.classList.remove('modal-open');
    }

    function updateSummary() {
        var active = addons.filter(function (addon) {
            return addon.active;
        }).length;

        $('#addonTotal').textContent = String(addons.length);
        $('#addonActive').textContent = String(active);

        $('#addonInactive').textContent =
            String(addons.length - active);
    }

    function render() {
        grid.innerHTML = '';

        updateSummary();

        var query = (search.value || '').trim().toLowerCase();
        var filter = statusFilter.value;

        var filtered = addons.filter(function (addon) {
            var matchesText = !query ||
                (addon.name + ' ' + addon.description)
                    .toLowerCase()
                    .indexOf(query) !== -1;

            var matchesStatus = filter === 'all' ||
                (filter === 'active'
                    ? addon.active
                    : !addon.active);

            return matchesText && matchesStatus;
        });

        if (!filtered.length) {
            var empty = make('div', 'addons-empty');

            empty.appendChild(
                make('span', 'addons-empty-mark', '✦')
            );

            empty.appendChild(
                make(
                    'strong',
                    '',
                    addons.length
                        ? 'No add-ons match these filters'
                        : 'No spa ritual add-ons yet'
                )
            );

            empty.appendChild(
                make(
                    'p',
                    '',
                    addons.length
                        ? 'Try another search or status filter.'
                        : 'Add your first enhancement to make it available on the customer Services page.'
                )
            );

            if (!addons.length) {
                var cta = make(
                    'button',
                    'btn btn-primary btn-sm',
                    'Add First Add-on'
                );

                cta.type = 'button';

                cta.addEventListener('click', function () {
                    openModal(null);
                });

                empty.appendChild(cta);
            }

            grid.appendChild(empty);
            return;
        }

        filtered.forEach(function (addon) {
            var card = make(
                'article',
                'addon-card' +
                (addon.active ? '' : ' is-inactive')
            );

            var top = make('div', 'addon-card-top');

            top.appendChild(
                make('span', 'addon-card-symbol', '✧')
            );

            top.appendChild(
                make(
                    'span',
                    'addon-status ' +
                    (addon.active ? 'is-active' : 'is-inactive'),
                    addon.active ? 'Active' : 'Inactive'
                )
            );

            card.appendChild(top);

            card.appendChild(
                make('h3', 'addon-card-name', addon.name)
            );

            card.appendChild(
                make(
                    'p',
                    'addon-card-description',
                    addon.description || 'No description provided.'
                )
            );

            var priceRow = make('div', 'addon-price-row');

            priceRow.appendChild(
                make('strong', 'addon-price', money(addon.price))
            );

            priceRow.appendChild(
                make(
                    'span',
                    'addon-order',
                    'Display order ' + addon.sort_order
                )
            );

            card.appendChild(priceRow);

            var tags = make('div', 'addon-tags');

            tags.appendChild(
                make(
                    'span',
                    'addon-tag',
                    addon.commissionable
                        ? 'Commissionable'
                        : 'No commission'
                )
            );

            tags.appendChild(
                make(
                    'span',
                    'addon-tag',
                    addon.active
                        ? 'Visible to guests'
                        : 'Hidden from guests'
                )
            );

            card.appendChild(tags);

            var actions = make('div', 'addon-actions');

            var edit = make(
                'button',
                'btn btn-outline btn-sm',
                'Edit'
            );

            edit.type = 'button';

            edit.addEventListener('click', function () {
                openModal(addon);
            });

            actions.appendChild(edit);

            var toggle = make(
                'button',
                addon.active
                    ? 'btn btn-soft-danger btn-sm'
                    : 'btn btn-soft-success btn-sm',
                addon.active ? 'Deactivate' : 'Activate'
            );

            toggle.type = 'button';

            toggle.addEventListener('click', function () {
                setActive(addon, !addon.active);
            });

            actions.appendChild(toggle);
            card.appendChild(actions);
            grid.appendChild(card);
        });
    }

    function load() {
        grid.innerHTML = '';

        grid.appendChild(
            make(
                'p',
                'addons-loading',
                'Loading Spa Ritual Add-ons…'
            )
        );

        request('/service-addons.php')
        .then(function (data) {
            if (!data.ok) {
                grid.innerHTML = '';

                var error = make('div', 'addons-setup');

                error.appendChild(
                    make(
                        'strong',
                        '',
                        data.setup_required
                            ? 'Add-ons database setup required'
                            : 'Unable to load add-ons'
                    )
                );

                error.appendChild(
                    make('p', '', data.error || 'Unknown error.')
                );

                if (data.setup_required) {
                    error.appendChild(
                        make(
                            'code',
                            'addons-sql-path',
                            'database/service-addons-extension.sql'
                        )
                    );

                    error.appendChild(
                        make(
                            'p',
                            '',
                            'Open phpMyAdmin, select bella_vista_oasis, choose Import, and run the SQL file.'
                        )
                    );
                }

                grid.appendChild(error);
                return;
            }

            addons = Array.isArray(data.addons)
                ? data.addons
                : [];

            render();
        })
        .catch(function () {
            grid.innerHTML = '';

            grid.appendChild(
                make(
                    'p',
                    'addons-setup',
                    'Connection error. Please refresh the page and try again.'
                )
            );
        });
    }

    function setActive(addon, active) {
        var token = form.querySelector(
            'input[name="csrf"]'
        );

        var body = new FormData();

        body.set('csrf', token ? token.value : '');
        body.set('action', 'set_active');
        body.set('id', String(addon.id));
        body.set('is_active', active ? '1' : '0');

        request('/service-addons.php', {
            method: 'POST',
            body: body
        })
        .then(function (data) {
            if (!data.ok) {
                showToast(data.error || 'Unable to update add-on.');
                return;
            }

            showToast(data.message || 'Add-on status updated.');

            load();
        })
        .catch(function () {
            showToast('Unable to update this add-on.');
        });
    }

    // Add new add-on
    document.getElementById('addonAddBtn')
        .addEventListener('click', function () {
            openModal(null);
        });

    // Close modal
    document.querySelectorAll('[data-addon-close]')
        .forEach(function (button) {
            button.addEventListener('click', closeModal);
        });

    modal.addEventListener('mousedown', function (event) {
        if (event.target === modal) {
            closeModal();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.hidden) {
            closeModal();
        }
    });

    // Search and filters
    search.addEventListener('input', render);
    statusFilter.addEventListener('change', render);

    // Save add-on
    form.addEventListener('submit', function (event) {
        event.preventDefault();

        $('#addonError').hidden = true;

        var button = $('#addonSaveBtn');

        button.disabled = true;
        button.textContent = 'Saving…';

        request('/service-addons.php', {
            method: 'POST',
            body: new FormData(form)
        })
        .then(function (data) {
            if (!data.ok) {
                $('#addonError').textContent =
                    data.error || 'Unable to save add-on.';

                $('#addonError').hidden = false;
                return;
            }

            closeModal();

            showToast(data.message || 'Add-on saved successfully.');

            load();
        })
        .catch(function () {
            $('#addonError').textContent =
                'Unable to save this add-on. Please try again.';

            $('#addonError').hidden = false;
        })
        .finally(function () {
            button.disabled = false;
            button.textContent = 'Save Add-on';
        });
    });

    // Initial load
    load();

}());

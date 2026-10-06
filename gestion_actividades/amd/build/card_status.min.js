define([], function() {
    function apply(action, status) {
        action.classList.remove('btn-primary', 'btn-secondary', 'btn-success', 'btn-warning', 'disabled');
        action.style.borderColor = '';
        action.style.backgroundColor = '';
        action.style.color = '';
        action.removeAttribute('aria-disabled');
        if (status.enrolled) {
            action.classList.add('btn', 'disabled');
            action.style.backgroundColor = '#dff3e4';
            action.style.borderColor = '#9fd3ad';
            action.style.color = '#1f6b35';
            action.setAttribute('aria-disabled', 'true');
            action.removeAttribute('href');
        } else if (status.closed) {
            action.classList.add('btn', 'disabled');
            action.style.backgroundColor = '#fff0d5';
            action.style.borderColor = '#efbd68';
            action.style.color = '#8a4b00';
            action.setAttribute('aria-disabled', 'true');
            action.removeAttribute('href');
        } else {
            action.classList.add('btn', 'btn-primary');
            var editionid = action.getAttribute('data-editionid');
            var root = (window.M && M.cfg && M.cfg.wwwroot) ? M.cfg.wwwroot : '';
            var key = (window.M && M.cfg && M.cfg.sesskey) ? M.cfg.sesskey : '';
            if (editionid && key) {
                action.setAttribute('href', root + '/local/gestion_actividades/enrol.php?id='
                    + encodeURIComponent(editionid) + '&sesskey=' + encodeURIComponent(key));
            }
        }
    }

    function update(courseid, attempt) {
        attempt = attempt || 0;
        var cards = document.querySelectorAll('.local-ga-card-actions[data-editionid]');
        if (!cards.length) {
            if (attempt < 40) {
                window.setTimeout(function() { update(courseid, attempt + 1); }, 250);
            }
            return;
        }
        var root = (window.M && M.cfg && M.cfg.wwwroot) ? M.cfg.wwwroot : '';
        fetch(root + '/local/gestion_actividades/card_status.php?courseid=' + encodeURIComponent(courseid) + '&_=' + Date.now(), {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {'Accept': 'application/json'}
        }).then(function(response) {
            return response.ok ? response.json() : null;
        }).then(function(data) {
            if (!data || !data.statuses) {
                return;
            }
            cards.forEach(function(card) {
                var status = data.statuses[card.getAttribute('data-editionid')];
                var action = card.querySelector('.local-ga-enrol-status') || (card.classList.contains('local-ga-enrol-status') ? card : null);
                if (status && action) {
                    action.textContent = status.label;
                    apply(action, status);
                }
            });
        }).catch(function() {});
    }

    return {
        init: function(courseid) {
            var run = function() { update(courseid, 0); };
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', run, {once: true});
            } else {
                run();
            }
        }
    };
});

/* NIVA WP Guard — admin page script */
        (function () {
            var I18N = window.NIVAWPG_I18N || {};
            var form = document.getElementById('nhg-form');
            var $ = function (s, r) { return (r || document).querySelector(s); };
            var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

            /* ── تب‌ها (با یادآوری تب باز در آدرس) ── */
            var scope = 'rel';
            function openTab(name) {
                $$('.nhg-tab').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-tab') === name); });
                $$('.nhg-panel').forEach(function (p) { p.classList.toggle('on', p.getAttribute('data-panel') === name); });
                try { history.replaceState(null, '', '#tab=' + name); } catch (e) {}
            }
            $$('.nhg-tab').forEach(function (b) { b.addEventListener('click', function () { openTab(b.getAttribute('data-tab')); }); });
            var m = location.hash.match(/tab=(\w+)/);
            if (m && $('.nhg-panel[data-panel="' + m[1] + '"]')) { openTab(m[1]); }
            $$('#nhg-scope .nhg-stab').forEach(function (b) {
                b.addEventListener('click', function () {
                    scope = b.getAttribute('data-scope');
                    $$('#nhg-scope .nhg-stab').forEach(function (x) { x.classList.toggle('on', x === b); });
                    filterKnown();
                });
            });

            /* ── فیلتر و جستجوی جدول شناخته‌شده ── */
            var q = $('#nhg-q'), activeType = '';
            function filterKnown() {
                var term = (q.value || '').toLowerCase().trim(), shown = 0;
                var rows = $$('#nhg-known tr[data-row]');
                rows.forEach(function (tr) {
                    var ok = (!term || tr.getAttribute('data-q').indexOf(term) > -1) &&
                             (scope === 'all' || tr.getAttribute('data-rel') === '1') &&
                             (!activeType || (activeType === '__seen' ? tr.getAttribute('data-seen') === '1' : tr.getAttribute('data-type') === activeType));
                    tr.classList.toggle('nhg-hid', !ok);
                    if (ok) { shown++; }
                });
                // عنوان گروه فقط وقتی دست‌کم یک ردیف زیرش پیداست
                $$('#nhg-known tr[data-src]').forEach(function (h) {
                    var n = h.nextElementSibling, any = false;
                    while (n && !n.hasAttribute('data-src')) { if (!n.classList.contains('nhg-hid')) { any = true; } n = n.nextElementSibling; }
                    h.classList.toggle('nhg-hid', !any);
                });
                $('#nhg-qcount').textContent = I18N.items.replace('%s', shown);
            }
            if (q) {
                q.addEventListener('input', filterKnown);
                $$('#nhg-types .nhg-fchip').forEach(function (c) {
                    c.addEventListener('click', function () {
                        $$('#nhg-types .nhg-fchip').forEach(function (x) { x.classList.remove('on'); });
                        c.classList.add('on'); activeType = c.getAttribute('data-type'); filterKnown();
                    });
                });
                filterKnown();
            }

            function simpleFilter(inputId, selector, attrOwner) {
                var inp = $(inputId); if (!inp) { return; }
                inp.addEventListener('input', function () {
                    var t = inp.value.toLowerCase().trim();
                    $$(selector).forEach(function (el) {
                        var hit = !t || (el.getAttribute('data-q') || '').indexOf(t) > -1;
                        (attrOwner === 'label' ? el : el).classList.toggle(attrOwner === 'label' ? 'hid' : 'nhg-hid', !hit);
                    });
                });
            }
            simpleFilter('#nhg-q2', '#nhg-disc tr[data-row]', 'row');
            simpleFilter('#nhg-q3', '#nhg-offline label[data-q]', 'label');

            /* ── انتخاب پیشنهادی / برداشتن همه ── */
            var rec = $('#nhg-recommend'), none = $('#nhg-none');
            if (rec) { rec.addEventListener('click', function () { $$('#nhg-known tr:not(.nhg-hid) .nhg-block[data-rec="1"]').forEach(function (c) { c.checked = true; }); track(); }); }
            if (none) { none.addEventListener('click', function () { $$('#nhg-known tr:not(.nhg-hid) .nhg-block').forEach(function (c) { c.checked = false; }); track(); }); }

            /* ── هشدار برای مسدود کردن موارد حساس ── */
            var risky = {license: I18N.riskLicense, service: I18N.riskService};
            form.addEventListener('change', function (e) {
                var el = e.target;
                if (el.classList && el.classList.contains('nhg-block') && el.checked) {
                    var msg = risky[el.getAttribute('data-type')];
                    if (msg && !confirm(msg)) { el.checked = false; }
                }
                track();
            });

            /* ── شمارنده‌ی تغییرات ذخیره‌نشده ── */
            var initial = {};
            function key(el, i) { return (el.name || 'x') + '#' + (el.type === 'checkbox' || el.type === 'radio' ? el.value : i); }
            var controls = $$('#nhg-form input[name], #nhg-form textarea[name], #nhg-form select[name]').filter(function (el) { return el.type !== 'hidden'; });
            controls.forEach(function (el, i) { initial[key(el, i)] = (el.type === 'checkbox' || el.type === 'radio') ? el.checked : el.value; });
            var dirty = $('#nhg-dirty'), dirtyN = 0;
            function track() {
                dirtyN = 0;
                controls.forEach(function (el, i) { var v = (el.type === 'checkbox' || el.type === 'radio') ? el.checked : el.value; if (initial[key(el, i)] !== v) { dirtyN++; } });
                dirty.textContent = dirtyN ? I18N.unsaved.replace('%s', dirtyN) : I18N.noChange;
                dirty.classList.toggle('on', dirtyN > 0);
            }
            form.addEventListener('input', track);
            window.addEventListener('beforeunload', function (e) { if (dirtyN > 0 && !form.__saving) { e.preventDefault(); e.returnValue = ''; } });
            form.addEventListener('submit', function () { form.__saving = true; });

            /* ── دکمه‌های کپی ── */
            var ce = document.getElementById('nhg-cron-every'), cs = document.getElementById('nhg-cron-sch');
            if (ce && cs) {
                ce.addEventListener('change', function () {
                    cs.value = ce.options[ce.selectedIndex].getAttribute('data-sched') || cs.value;
                    $$('.nhg-cronline').forEach(function (t) { t.value = cs.value + ' ' + t.getAttribute('data-cmd'); });
                    var sp = cs.value.split(' ');
                    $$('.nhg-cronpart').forEach(function (p) { p.value = sp[parseInt(p.getAttribute('data-i'), 10)] || ''; });
                });
            }

            $$('.nhg-copycmd').forEach(function (b) {
                b.addEventListener('click', function () {
                    var src = document.getElementById(b.getAttribute('data-target')), tmp = document.createElement('textarea');
                    if (!src) { return; }
                    tmp.value = src.getAttribute('data-cmd'); document.body.appendChild(tmp); tmp.select(); document.execCommand('copy'); document.body.removeChild(tmp);
                    b.textContent = I18N.copied;
                });
            });

            $$('.nhg-copy').forEach(function (b) {
                b.addEventListener('click', function () {
                    var t = document.getElementById(b.getAttribute('data-target'));
                    if (t) { t.select(); document.execCommand('copy'); b.textContent = I18N.copied; }
                });
            });

            /* ── اکاردئون اسکن کد ── */
            $$('tr.nhg-acc').forEach(function (tr) {
                function tg() { var b = tr.nextElementSibling, o = b.hasAttribute('hidden'); if (o) { b.removeAttribute('hidden'); } else { b.setAttribute('hidden', ''); } tr.classList.toggle('open', o); tr.setAttribute('aria-expanded', o ? 'true' : 'false'); }
                tr.addEventListener('click', tg);
                tr.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); tg(); } });
            });

            /* ── فیلد عددی (کم/زیاد و مقدارهای آماده) ── */
            $$('.nhg-stepper').forEach(function (box) {
                var inp = $('input', box), min = +box.getAttribute('data-min'), max = +box.getAttribute('data-max');
                var presets = $$('.nhg-preset');
                function set(v) {
                    v = Math.max(min, Math.min(max, parseInt(v, 10) || min));
                    inp.value = v;
                    presets.forEach(function (p) { p.classList.toggle('on', +p.getAttribute('data-val') === v); });
                    inp.dispatchEvent(new Event('input', {bubbles: true}));
                }
                $$('.nhg-st-btn', box).forEach(function (b) { b.addEventListener('click', function () { set((parseInt(inp.value, 10) || min) + (+b.getAttribute('data-step'))); }); });
                presets.forEach(function (p) { p.addEventListener('click', function () { set(p.getAttribute('data-val')); }); });
                inp.addEventListener('change', function () { set(inp.value); });
                presets.forEach(function (p) { p.classList.toggle('on', +p.getAttribute('data-val') === (parseInt(inp.value, 10) || 0)); });
            });

            /* ── کپی گزارش، پاک کردن لاگ، محو شدن پیام ── */
            var cp = $('#nhg-copy');
            if (cp) { cp.addEventListener('click', function () { var t = $('#nhg-report'); t.select(); document.execCommand('copy'); cp.textContent = I18N.copied; }); }
            var cl = $('#nhg-clearlink');
            if (cl) { cl.addEventListener('click', function (e) { e.preventDefault(); if (confirm(I18N.confirmClear)) { form.__saving = true; $('#nhg-clearform').submit(); } }); }
            var fl = $('#nhg-flash');
            if (fl) { setTimeout(function () { fl.style.transition = 'opacity .4s'; fl.style.opacity = '0'; setTimeout(function () { fl.remove(); }, 400); }, 5000); }
            try { var u = new URL(location.href); ['saved', 'cleared', 'loader', 'scanned', 'brk', 'cron'].forEach(function (k) { u.searchParams.delete(k); }); history.replaceState(null, '', u.toString()); } catch (e) {}
        })();

{{-- The page script: plain JavaScript, no jQuery or Bootstrap. All text is set with textContent. --}}
<script>
    (function () {
        'use strict';

        const root = document.getElementById('sst');
        if (!root) {
            return;
        }

        const RANGES = ['1h', '24h', '7d', '90d'];
        const LEVEL_ICONS = {
            operational: 'fas fa-check',
            degraded: 'fas fa-exclamation',
            partial: 'fas fa-exclamation-triangle',
            major: 'fas fa-times',
            maintenance: 'fas fa-tools',
            loading: 'fas fa-circle-notch',
        };
        const i18n = {
            lastChecked: @json(__('system-status::status.status_last_checked', ['time' => ':time'])),
            checksFailing: @json(__('system-status::status.status_checks_failing_since', ['time' => ':time', 'error' => ':error'])),
            uptime: @json(__('system-status::status.status_uptime', ['value' => ':value'])),
            noData: @json(__('system-status::status.status_no_data')),
            noIncidents: @json(__('system-status::status.status_no_incidents')),
            ongoing: @json(__('system-status::status.status_ongoing')),
            resolved: @json(__('system-status::status.status_resolved')),
            duration: @json(__('system-status::status.status_duration_minutes', ['minutes' => ':minutes'])),
            outageMinutes: @json(__('system-status::status.status_outage_minutes', ['minutes' => ':minutes'])),
            degradedMinutes: @json(__('system-status::status.status_degraded_minutes', ['minutes' => ':minutes'])),
            noTraffic: @json(__('system-status::status.status_no_traffic')),
            signals: @json(__('system-status::status.status_signals_24h', ['failures' => ':failures', 'calls' => ':calls'])),
            lastFailure: @json(__('system-status::status.status_last_failure', ['reason' => ':reason', 'time' => ':time'])),
            loadFailed: @json(__('system-status::status.status_load_failed')),
            requests: @json(__('system-status::status.status_requests')),
            errors: @json(__('system-status::status.status_errors')),
            groupSummary: @json(__('system-status::status.status_group_summary', ['ok' => ':ok', 'total' => ':total'])),
            nextRefresh: @json(__('system-status::status.status_next_refresh', ['seconds' => ':seconds'])),
            operational: @json(__('system-status::status.status_operational')),
            degraded: @json(__('system-status::status.status_degraded')),
            majorOutage: @json(__('system-status::status.status_major_outage')),
            maintenance: @json(__('system-status::status.status_maintenance')),
            maintenanceMinutes: @json(__('system-status::status.status_maintenance_minutes', ['minutes' => ':minutes'])),
            maintenanceActive: @json(__('system-status::status.status_maintenance_active')),
            maintenanceUpcoming: @json(__('system-status::status.status_maintenance_upcoming')),
            maintenanceEnd: @json(__('system-status::status.status_maintenance_end')),
            maintenanceCancel: @json(__('system-status::status.status_maintenance_cancel')),
            affects: @json(__('system-status::status.status_affects', ['components' => ':components'])),
            by: @json(__('system-status::status.status_by', ['name' => ':name'])),
            saveFailed: @json(__('system-status::status.status_save_failed')),
            slaTarget: @json(__('system-status::status.status_sla_target', ['target' => ':target'])),
            manual: @json(__('system-status::status.status_source_manual')),
            updateFor: @json(__('system-status::status.status_update_for', ['title' => ':title'])),
            windowPreview: @json(__('system-status::status.status_window_preview', ['start' => ':start', 'end' => ':end'])),
            selectedCount: @json(__('system-status::status.status_selected_count', ['count' => ':count'])),
            chooseComponents: @json(__('system-status::status.status_choose_components')),
            toastIncident: @json(__('system-status::status.status_toast_incident_posted')),
            toastUpdate: @json(__('system-status::status.status_toast_update_posted')),
            toastPostmortem: @json(__('system-status::status.status_toast_saved')),
            toastMaintenance: @json(__('system-status::status.status_toast_maintenance_scheduled')),
            toastMaintenanceEnded: @json(__('system-status::status.status_toast_maintenance_ended')),
            today: @json(__('system-status::status.status_today')),
            now: @json(__('system-status::status.status_now')),
            deploy: @json(__('system-status::status.status_deploy', ['version' => ':version'])),
            afterDeploy: @json(__('system-status::status.status_after_deploy', ['minutes' => ':minutes', 'version' => ':version'])),
            lastDeploy: @json(__('system-status::status.status_last_deploy', ['version' => ':version', 'ago' => ':ago'])),
            deployWorse: @json(__('system-status::status.status_deploy_worse', ['components' => ':components'])),
            deployOk: @json(__('system-status::status.status_deploy_ok')),
            incidentsShown: @json(__('system-status::status.status_incidents_shown', ['shown' => ':shown', 'total' => ':total'])),
        };
        const numberFormat = new Intl.NumberFormat(document.documentElement.lang || undefined);
        const timeFormat = new Intl.DateTimeFormat(document.documentElement.lang || undefined, {weekday: 'short', hour: '2-digit', minute: '2-digit'});
        const canManage = root.dataset.canManage === '1';

        // Real time refreshes every 20 s (checks run every minute); the other ranges every 60 s.
        const REFRESH = @json((array) config('system-status.refresh_seconds', ['1h' => 20, 'default' => 60]));

        const state = {filter: 'all', search: '', range: readRange(), data: null, countdown: 0, loading: false};
        // Past incidents: what is shown, and whether more pages exist.
        const incidents = {list: [], total: 0, perPage: 10, hasMore: false, signature: '', loadingMore: false};

        /* ---------- helpers ---------- */
        function $(id) {
            return document.getElementById(id);
        }

        // Delegated events: on('click', '.selector', handler) with `this` = the matched element.
        function on(type, selector, handler, options) {
            document.addEventListener(type, function (event) {
                const target = event.target instanceof Element ? event.target.closest(selector) : null;
                if (target) {
                    handler.call(target, event);
                }
            }, options);
        }

        function storage(key, value) {
            try {
                if (value === undefined) {
                    return localStorage.getItem(key);
                }
                localStorage.setItem(key, value);
            } catch (e) {
                // Remembering view settings is only a convenience.
            }
            return null;
        }

        function readRange() {
            const saved = storage('sst.range');
            return RANGES.indexOf(saved) !== -1 ? saved : '90d';
        }

        function refreshSeconds() {
            return Math.max(5, parseInt(REFRESH[state.range] || REFRESH.default || 60, 10));
        }

        function fill(template, values) {
            return Object.keys(values).reduce(function (text, key) {
                return text.split(':' + key).join(values[key]);
            }, template);
        }

        function setText(parent, selector, text) {
            const node = parent.querySelector(selector);
            const value = text == null ? '' : String(text);
            if (node && node.textContent !== value) {
                node.textContent = value;
            }
            return node;
        }

        function setPill(node, status, label) {
            if (node.dataset.status !== status) {
                node.dataset.status = status;
            }
            if (node.textContent !== label) {
                node.textContent = label;
            }
        }

        function isProblem(component) {
            return component.configured && component.status !== 'operational' && component.status !== 'maintenance';
        }

        // Maintenance only shows for a group when nothing in it is worse.
        function worstStatus(components) {
            const order = {operational: 0, maintenance: 0.5, degraded: 1, major_outage: 2};
            return components.reduce(function (worst, c) {
                return c.configured && (order[c.status] || 0) > order[worst] ? c.status : worst;
            }, 'operational');
        }

        function statusLabel(status) {
            return {operational: i18n.operational, degraded: i18n.degraded, major_outage: i18n.majorOutage, maintenance: i18n.maintenance}[status] || status;
        }

        function getJson(url, params) {
            const query = params ? '?' + new URLSearchParams(params).toString() : '';
            return fetch(url + query, {headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, credentials: 'same-origin'})
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }
                    return response.json();
                });
        }

        // Resolves with the JSON body; rejects with {status, body} so forms can show errors.
        function send(method, url, data) {
            return fetch(url, {
                method: method,
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': root.dataset.csrf || '',
                },
                body: data ? JSON.stringify(data) : undefined,
            }).then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (body) {
                    if (!response.ok) {
                        throw {status: response.status, body: body};
                    }
                    return body;
                });
            });
        }

        /* ---------- dialogs: slide-over panels and the detail modal ---------- */
        let lastFocus = null;

        function openDialog(dialog) {
            if (!dialog || dialog.open) {
                return;
            }
            lastFocus = document.activeElement;
            closeMenu();
            dialog.showModal();
            document.documentElement.classList.add('sst-scroll-lock');
            requestAnimationFrame(function () {
                dialog.classList.add('is-open');
            });
            const focus = dialog.querySelector('[data-autofocus]');
            if (focus) {
                setTimeout(function () { focus.focus(); }, 60);
            }
        }

        function closeDialog(dialog) {
            if (!dialog || !dialog.open || dialog.dataset.closing) {
                return;
            }
            dialog.dataset.closing = '1';
            dialog.classList.remove('is-open');
            const finish = function () {
                delete dialog.dataset.closing;
                dialog.close();
                if (!document.querySelector('dialog[open]')) {
                    document.documentElement.classList.remove('sst-scroll-lock');
                }
                hideTooltip();
                if (lastFocus && document.contains(lastFocus)) {
                    lastFocus.focus();
                }
            };
            const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            reduced ? finish() : setTimeout(finish, 220);
        }

        document.querySelectorAll('dialog.sst-dialog, dialog.sst-sheet').forEach(function (dialog) {
            // Esc animates out instead of closing at once.
            dialog.addEventListener('cancel', function (event) {
                event.preventDefault();
                closeDialog(dialog);
            });
            // A click on the backdrop (the dialog box itself, outside its content) closes it.
            dialog.addEventListener('mousedown', function (event) {
                dialog._sstBackdropDown = event.target === dialog;
            });
            dialog.addEventListener('click', function (event) {
                if (event.target === dialog && dialog._sstBackdropDown) {
                    closeDialog(dialog);
                }
            });
        });

        on('click', '[data-close-dialog]', function () {
            closeDialog(this.closest('dialog'));
        });

        /* ---------- toasts ---------- */
        function toast(message, tone) {
            const box = $('sstToasts');
            if (!box) {
                return;
            }
            const item = document.createElement('div');
            item.className = 'sst-toast';
            item.dataset.tone = tone || 'ok';
            const icon = document.createElement('i');
            icon.className = tone === 'error' ? 'fas fa-exclamation-circle' : 'fas fa-check-circle';
            icon.setAttribute('aria-hidden', 'true');
            const text = document.createElement('span');
            text.textContent = message;
            item.append(icon, text);
            box.appendChild(item);
            requestAnimationFrame(function () { item.classList.add('is-visible'); });
            setTimeout(function () {
                item.classList.remove('is-visible');
                setTimeout(function () { item.remove(); }, 300);
            }, 3200);
        }

        /* ---------- tooltip ---------- */
        const tooltip = $('sstTooltip');

        function showTooltip(target, lines) {
            tooltip.replaceChildren();
            lines.forEach(function (line, index) {
                const row = document.createElement(index === 0 ? 'strong' : 'span');
                row.textContent = line;
                tooltip.appendChild(row);
            });
            // Inside an open dialog the tooltip must live in the top layer too.
            const dialog = target.closest('dialog');
            (dialog || root).appendChild(tooltip);
            tooltip.hidden = false;

            const rect = target.getBoundingClientRect();
            const tip = tooltip.getBoundingClientRect();
            const left = Math.min(Math.max(8, rect.left + rect.width / 2 - tip.width / 2), window.innerWidth - tip.width - 8);
            const top = rect.top - tip.height - 8 < 8 ? rect.bottom + 8 : rect.top - tip.height - 8;
            tooltip.style.left = left + 'px';
            tooltip.style.top = top + 'px';
        }

        function hideTooltip() {
            tooltip.hidden = true;
        }

        function slotLines(slot) {
            const lines = [slot.label];
            lines.push(slot.level === 'none' ? i18n.noData : fill(i18n.uptime, {value: slot.uptime}));
            if (slot.outage_minutes > 0) {
                lines.push(fill(i18n.outageMinutes, {minutes: slot.outage_minutes}));
            }
            if (slot.degraded_minutes > 0) {
                lines.push(fill(i18n.degradedMinutes, {minutes: slot.degraded_minutes}));
            }
            if (slot.maintenance_minutes > 0) {
                lines.push(fill(i18n.maintenanceMinutes, {minutes: slot.maintenance_minutes}));
            }
            if (slot.requests !== null && slot.requests !== undefined) {
                lines.push(i18n.requests + ': ' + numberFormat.format(slot.requests) + ' · ' + i18n.errors + ': ' + numberFormat.format(slot.errors || 0));
            }
            return lines;
        }

        on('mouseover', '.sst-bar, .sst-deploy-marker', function (event) {
            // The lines are built on hover, not for every bar on every refresh.
            const lines = this._sstLines || (this._sstSlot && slotLines(this._sstSlot));
            if (lines && !this.contains(event.relatedTarget)) {
                showTooltip(this, lines);
            }
        });
        on('mouseout', '.sst-bar, .sst-deploy-marker', function (event) {
            if (!this.contains(event.relatedTarget)) {
                hideTooltip();
            }
        });
        window.addEventListener('scroll', hideTooltip, {passive: true});

        /* ---------- rendering ---------- */
        // Reuses the existing bars when the count is the same and only changes what differs.
        function renderBars(container, days) {
            const bars = container.querySelectorAll(':scope > .sst-bar');
            if (bars.length === days.length) {
                days.forEach(function (day, index) {
                    const bar = bars[index];
                    if (bar.dataset.level !== day.level) {
                        bar.dataset.level = day.level;
                    }
                    bar._sstSlot = day;
                });
                return;
            }

            const fragment = document.createDocumentFragment();
            days.forEach(function (day) {
                const bar = document.createElement('span');
                bar.className = 'sst-bar';
                bar.dataset.level = day.level;
                bar._sstSlot = day;
                fragment.appendChild(bar);
            });
            // Keep the deploy lines that sit on top of the bars.
            fragment.append.apply(fragment, Array.prototype.slice.call(container.querySelectorAll(':scope > .sst-deploy-marker')));
            container.replaceChildren(fragment);
        }

        /* ---------- deploys ---------- */
        function deployLines(deploy) {
            const lines = [fill(i18n.deploy, {version: deploy.label}), deploy.at + (deploy.deployed_by ? ' · ' + fill(i18n.by, {name: deploy.deployed_by}) : '')];
            if (deploy.note) {
                lines.push(deploy.note);
            }
            if (deploy.worse && deploy.worse.length) {
                lines.push(fill(i18n.deployWorse, {components: deploy.worse.map(function (w) { return w.label; }).join(', ')}));
            } else if (deploy.worse) {
                lines.push(i18n.deployOk);
            }
            return lines;
        }

        // Lines at each deploy's position (in % of the bars or chart). Red when this component
        // (component = its key; none = the list is already for one component) got worse after it.
        function renderDeployMarkers(container, deploys, component) {
            container.querySelectorAll(':scope > .sst-deploy-marker').forEach(function (marker) { marker.remove(); });
            (deploys || []).forEach(function (deploy) {
                if (deploy.position === undefined || deploy.position < 0 || deploy.position > 100) {
                    return;
                }
                const marker = document.createElement('span');
                marker.className = 'sst-deploy-marker';
                marker.style.insetInlineStart = deploy.position + '%';
                const worse = (deploy.worse || []).some(function (w) { return !component || w.component === component; });
                marker.dataset.worse = worse ? '1' : '0';
                marker.setAttribute('aria-label', fill(i18n.deploy, {version: deploy.label}));
                marker._sstLines = deployLines(deploy);
                container.appendChild(marker);
            });
        }

        function renderDeploys(list) {
            $('sstDeploysPanel').hidden = !list.length;
            const fragment = document.createDocumentFragment();

            list.forEach(function (deploy) {
                const item = document.createElement('li');
                item.className = 'sst-deploy';

                const version = document.createElement('span');
                version.className = 'sst-deploy-version';
                const icon = document.createElement('i');
                icon.className = 'fas fa-rocket';
                icon.setAttribute('aria-hidden', 'true');
                version.append(icon, document.createTextNode(deploy.label));

                const meta = document.createElement('span');
                meta.className = 'sst-deploy-meta';
                meta.textContent = deploy.at + ' · ' + deploy.ago
                    + (deploy.deployed_by ? ' · ' + fill(i18n.by, {name: deploy.deployed_by}) : '')
                    + (deploy.note ? ' · ' + deploy.note : '');
                item.append(version, meta);

                if (deploy.worse) {
                    const result = document.createElement('span');
                    result.className = 'sst-deploy-result';
                    result.dataset.worse = deploy.worse.length ? '1' : '0';
                    result.textContent = deploy.worse.length
                        ? fill(i18n.deployWorse, {components: deploy.worse.map(function (w) { return w.label; }).join(', ')})
                        : i18n.deployOk;
                    item.appendChild(result);
                }
                fragment.appendChild(item);
            });

            const box = $('sstDeploys');
            box.replaceChildren(fragment);
        }

        function renderHero(data) {
            const level = data.stale ? 'major' : data.overall.level;
            $('sstHero').dataset.level = level;
            $('sstHeroIcon').className = LEVEL_ICONS[level] || LEVEL_ICONS.loading;
            $('sstHeroTitle').textContent = data.overall.label;
            const lastDeploy = (data.recent_deploys || [])[0];
            $('sstHeroMeta').textContent = (data.last_run_ago
                ? fill(i18n.lastChecked, {time: data.last_run_ago}) + (data.last_run_at ? ' · ' + data.last_run_at : '')
                : '') + (lastDeploy ? ' · ' + fill(i18n.lastDeploy, {version: lastDeploy.label, ago: lastDeploy.ago}) : '')
                + (data.checks_failing ? ' · ' + fill(i18n.checksFailing, {time: data.checks_failing.since, error: data.checks_failing.error || '?'}) : '');
        }

        function allComponents(data) {
            return data.groups.reduce(function (list, group) { return list.concat(group.components); }, []);
        }

        function renderKpis(data) {
            const configured = allComponents(data).filter(function (c) { return c.configured; });
            const withUptime = configured.filter(function (c) { return c.uptime !== null; });
            const uptime = withUptime.length
                ? withUptime.reduce(function (sum, c) { return sum + c.uptime; }, 0) / withUptime.length
                : null;
            const problems = configured.filter(isProblem).length;

            $('sstKpiUptime').textContent = uptime === null ? '—' : uptime.toFixed(2) + '%';
            $('sstKpiUptimeLabel').textContent = data.range.uptime_label;
            const open = data.incidents_meta ? data.incidents_meta.open : data.incidents.filter(function (i) { return i.ongoing; }).length;
            $('sstKpiIncidents').textContent = numberFormat.format(open);
            $('sstKpiAffected').textContent = numberFormat.format(problems);
            $('sstKpiComponents').textContent = numberFormat.format(configured.length);
            const problemCount = $('sstProblemCount');
            problemCount.textContent = numberFormat.format(problems);
            problemCount.dataset.zero = problems === 0 ? '1' : '0';
        }

        function fillGroup(groupNode, group) {
            const configured = group.components.filter(function (c) { return c.configured; });
            const ok = configured.filter(function (c) { return c.status === 'operational'; }).length;
            const worst = worstStatus(group.components);

            groupNode.dataset.status = worst;
            setText(groupNode, '.sst-group-title', group.label);
            setText(groupNode, '.sst-group-summary', fill(i18n.groupSummary, {ok: ok, total: configured.length}));
            setPill(groupNode.querySelector('.sst-group-pill'), worst, statusLabel(worst));
        }

        function fillComponent(node, component) {
            // Nothing to do when this component's data did not change since the last refresh.
            // Deploy lines change on their own (a new deploy, or the range moving on).
            const bars = node.querySelector('.sst-bars');
            if (node._sstDeploys !== state.deploySignature) {
                node._sstDeploys = state.deploySignature;
                renderDeployMarkers(bars, state.data.deploys, component.key);
            }

            const signature = JSON.stringify(component) + state.data.range.key;
            if (node._sstSignature === signature) {
                return;
            }
            node._sstSignature = signature;

            const status = component.configured ? component.status : 'not_configured';
            node.dataset.status = status;
            node.dataset.problem = isProblem(component) ? '1' : '0';
            node.dataset.search = (component.label + ' ' + component.key).toLowerCase();

            const name = setText(node, '.sst-component-name', component.label);
            name.dataset.component = component.key;
            setPill(node.querySelector('.sst-pill'), status, component.status_label);

            const uptime = setText(node, '.sst-component-uptime', component.uptime === null ? '' : fill(i18n.uptime, {value: component.uptime}));
            uptime.dataset.slaMissed = component.sla_missed ? '1' : '0';
            uptime.title = component.sla_target ? fill(i18n.slaTarget, {target: component.sla_target}) : '';

            const menu = node.querySelector('.sst-component-menu');
            if (menu) {
                menu.dataset.component = component.key;
                menu.dataset.label = component.label;
                menu.setAttribute('aria-label', component.label);
            }

            const message = setText(node, '.sst-component-message', component.message || '');
            message.hidden = !component.message;

            renderBars(bars, component.days);
            setText(node, '.sst-range-start', state.data.range.start_label);
            setText(node, '.sst-range-end', state.data.range.key === '90d' ? i18n.today : i18n.now);
        }

        // Group and component keys in order: when they stay the same, the page is updated in place.
        function layoutKey(groups) {
            return groups.map(function (group) {
                return group.key + ':' + group.components.map(function (c) { return c.key; }).join(',');
            }).join('|');
        }

        function renderGroups(groups) {
            const container = $('sstGroups');
            const key = layoutKey(groups);

            if (container.dataset.layout === key) {
                const groupNodes = container.querySelectorAll(':scope > .sst-group');
                groups.forEach(function (group, groupIndex) {
                    const groupNode = groupNodes[groupIndex];
                    fillGroup(groupNode, group);
                    const nodes = groupNode.querySelectorAll('.sst-component');
                    group.components.forEach(function (component, index) {
                        fillComponent(nodes[index], component);
                    });
                });
                applyFilter();
                return;
            }

            const groupTemplate = $('sstGroupTemplate');
            const componentTemplate = $('sstComponentTemplate');
            const fragment = document.createDocumentFragment();
            const collapsed = readCollapsed();

            groups.forEach(function (group) {
                const groupNode = groupTemplate.content.firstElementChild.cloneNode(true);
                groupNode.dataset.group = group.key;
                fillGroup(groupNode, group);

                const isCollapsed = collapsed.indexOf(group.key) !== -1;
                groupNode.classList.toggle('is-collapsed', isCollapsed);
                groupNode.querySelector('.sst-group-toggle').setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');

                const list = groupNode.querySelector('.sst-components');
                group.components.forEach(function (component) {
                    const node = componentTemplate.content.firstElementChild.cloneNode(true);
                    fillComponent(node, component);
                    list.appendChild(node);
                });

                fragment.appendChild(groupNode);
            });

            container.replaceChildren(fragment);
            container.dataset.layout = key;
            applyFilter();
        }

        // manage: show the update / postmortem buttons (not inside the detail dialog).
        // append: add to the incidents already shown (the "Show more" button).
        function renderIncidents(container, incidents, manage, append) {
            if (!incidents.length && !append) {
                const empty = document.createElement('p');
                empty.className = 'sst-empty-state';
                const icon = document.createElement('i');
                icon.className = 'fas fa-check-circle';
                icon.setAttribute('aria-hidden', 'true');
                const text = document.createElement('span');
                text.textContent = i18n.noIncidents;
                empty.append(icon, text);
                container.replaceChildren(empty);
                return;
            }

            const template = $('sstIncidentTemplate');
            const fragment = document.createDocumentFragment();
            const showActions = manage && canManage;

            incidents.forEach(function (incident, index) {
                const node = template.content.firstElementChild.cloneNode(true);
                node.dataset.ongoing = incident.ongoing ? '1' : '0';
                node.dataset.status = incident.severity;
                node.dataset.id = incident.id;
                setText(node, '.sst-incident-component', incident.component_label);
                setPill(node.querySelector('.sst-incident-severity'), incident.severity, incident.severity_label);
                setText(node, '.sst-incident-state', incident.ongoing ? (incident.state_label || i18n.ongoing) : i18n.resolved);
                const source = setText(node, '.sst-incident-source', incident.source === 'manual' ? i18n.manual : '');
                source.hidden = incident.source !== 'manual';
                const title = setText(node, '.sst-incident-title', incident.title || '');
                title.hidden = !incident.title;
                // The first timeline entry repeats the message of a manual incident.
                const showMessage = incident.message && !(incident.updates || []).length;
                const message = setText(node, '.sst-incident-message', showMessage ? incident.message : '');
                message.hidden = !showMessage;
                setText(node, '.sst-incident-time',
                    incident.started_at + ' → ' + (incident.resolved_at || '…') + ' · ' + fill(i18n.duration, {minutes: incident.duration_minutes})
                    + (incident.created_by ? ' · ' + fill(i18n.by, {name: incident.created_by}) : ''));

                const deployNote = node.querySelector('.sst-incident-deploy');
                deployNote.hidden = !incident.deploy;
                if (incident.deploy) {
                    deployNote.lastElementChild.textContent = fill(i18n.afterDeploy, {minutes: incident.deploy.minutes, version: incident.deploy.label});
                }

                const timeline = node.querySelector('.sst-timeline');
                (incident.updates || []).forEach(function (update) {
                    const item = document.createElement('li');
                    item.dataset.state = update.state;
                    const head = document.createElement('strong');
                    head.textContent = update.state_label;
                    const text = document.createElement('span');
                    text.textContent = update.message;
                    const meta = document.createElement('small');
                    meta.textContent = update.at + (update.author ? ' · ' + update.author : '');
                    item.append(head, text, meta);
                    timeline.appendChild(item);
                });
                timeline.hidden = !timeline.children.length;

                setText(node, '.sst-postmortem-text', incident.postmortem || '');
                node.querySelector('.sst-postmortem').hidden = !incident.postmortem;

                const addUpdate = node.querySelector('.sst-add-update');
                const editPostmortem = node.querySelector('.sst-edit-postmortem');
                addUpdate.hidden = !(showActions && incident.ongoing);
                editPostmortem.hidden = !(showActions && !incident.ongoing);
                node.querySelector('.sst-incident-actions').hidden = addUpdate.hidden && editPostmortem.hidden;
                node._sstIncident = incident;
                if (append) {
                    node.classList.add('is-new');
                    node.style.setProperty('--sst-i', index);
                }

                fragment.appendChild(node);
            });

            append ? container.appendChild(fragment) : container.replaceChildren(fragment);
        }

        /* ---------- past incidents: first page with the summary, more on request ---------- */
        function renderIncidentList(list, meta) {
            incidents.list = list;
            incidents.total = meta.total;
            incidents.hasMore = meta.has_more;
            incidents.perPage = meta.per_page || incidents.perPage;

            // Skip the redraw when nothing changed, so a refresh does not reset the list.
            const signature = JSON.stringify(list);
            if (signature !== incidents.signature) {
                incidents.signature = signature;
                renderIncidents($('sstIncidents'), list, true);
            }
            renderIncidentsFooter();
        }

        function renderIncidentsFooter() {
            $('sstIncidentsMore').hidden = incidents.total <= incidents.perPage && !incidents.hasMore;
            $('sstIncidentsShown').textContent = fill(i18n.incidentsShown, {
                shown: numberFormat.format(incidents.list.length),
                total: numberFormat.format(incidents.total),
            });
            const button = $('sstIncidentsMoreButton');
            button.hidden = !incidents.hasMore;
            button.disabled = incidents.loadingMore;
        }

        // Called on every refresh. When more pages were opened, they are reloaded together.
        function refreshIncidents(data) {
            const meta = data.incidents_meta || {total: data.incidents.length, has_more: false, per_page: data.incidents.length || 10};
            const shown = incidents.list.length;

            if (shown <= meta.per_page) {
                renderIncidentList(data.incidents, meta);
                return Promise.resolve();
            }

            return getJson(root.dataset.incidentsUrl, {page: 1, per_page: Math.min(100, shown)})
                .then(function (response) {
                    renderIncidentList(response.data, {
                        total: response.meta.total,
                        has_more: response.meta.has_more,
                        per_page: meta.per_page,
                    });
                })
                .catch(function () {
                    renderIncidentList(data.incidents, meta);
                });
        }

        $('sstIncidentsMoreButton').addEventListener('click', function () {
            if (incidents.loadingMore || !incidents.hasMore) {
                return;
            }
            incidents.loadingMore = true;
            renderIncidentsFooter();

            const page = Math.floor(incidents.list.length / incidents.perPage) + 1;
            getJson(root.dataset.incidentsUrl, {page: page, per_page: incidents.perPage})
                .then(function (response) {
                    // New incidents may have shifted the pages: skip any already shown.
                    const seen = {};
                    incidents.list.forEach(function (incident) { seen[incident.id] = true; });
                    const added = response.data.filter(function (incident) { return !seen[incident.id]; });

                    incidents.list = incidents.list.concat(added);
                    incidents.signature = JSON.stringify(incidents.list);
                    incidents.total = response.meta.total;
                    incidents.hasMore = response.meta.has_more;
                    renderIncidents($('sstIncidents'), added, true, true);
                })
                .catch(function () {
                    toast(i18n.loadFailed, 'error');
                })
                .finally(function () {
                    incidents.loadingMore = false;
                    renderIncidentsFooter();
                });
        });

        function renderMaintenances(list) {
            $('sstMaintenancePanel').hidden = !list.length;

            const template = $('sstMaintenanceTemplate');
            const fragment = document.createDocumentFragment();

            list.forEach(function (item) {
                const node = template.content.firstElementChild.cloneNode(true);
                node.dataset.active = item.active ? '1' : '0';
                node.dataset.status = 'maintenance';
                setText(node, '.sst-maintenance-title', item.title);
                setText(node, '.sst-maintenance-state', item.active ? i18n.maintenanceActive : i18n.maintenanceUpcoming);
                setText(node, '.sst-maintenance-components', fill(i18n.affects, {
                    components: item.components.map(function (c) { return c.label; }).join(', '),
                }));
                const message = setText(node, '.sst-maintenance-message', item.message || '');
                message.hidden = !item.message;
                setText(node, '.sst-maintenance-time', item.starts_at + ' → ' + item.ends_at
                    + (item.created_by ? ' · ' + fill(i18n.by, {name: item.created_by}) : ''));

                const end = node.querySelector('.sst-maintenance-end');
                if (canManage) {
                    end.hidden = false;
                    end.textContent = item.active ? i18n.maintenanceEnd : i18n.maintenanceCancel;
                    end.dataset.id = item.id;
                }
                fragment.appendChild(node);
            });

            $('sstMaintenances').replaceChildren(fragment);
        }

        /* ---------- filter, search, collapse ---------- */
        function applyFilter() {
            let visible = 0;
            document.querySelectorAll('#sstGroups .sst-group').forEach(function (groupNode) {
                let groupVisible = 0;
                groupNode.querySelectorAll('.sst-component').forEach(function (node) {
                    const show = (state.filter === 'all' || node.dataset.problem === '1')
                        && (!state.search || node.dataset.search.indexOf(state.search) !== -1);
                    node.hidden = !show;
                    groupVisible += show ? 1 : 0;
                });
                groupNode.hidden = groupVisible === 0;
                visible += groupVisible;
            });
            $('sstNoMatches').hidden = visible > 0 || !state.data;
        }

        function readCollapsed() {
            try {
                return JSON.parse(storage('sst.collapsed') || '[]');
            } catch (e) {
                return [];
            }
        }

        on('click', '.sst-segment[data-filter]', function () {
            state.filter = this.dataset.filter;
            document.querySelectorAll('.sst-segment[data-filter]').forEach(function (button) {
                button.setAttribute('aria-pressed', button.dataset.filter === state.filter ? 'true' : 'false');
            });
            applyFilter();
        });

        function markRange() {
            document.querySelectorAll('.sst-segment[data-range]').forEach(function (button) {
                button.setAttribute('aria-pressed', button.dataset.range === state.range ? 'true' : 'false');
            });
            root.dataset.range = state.range;
        }

        on('click', '.sst-segment[data-range]', function () {
            if (this.dataset.range === state.range) {
                return;
            }
            state.range = this.dataset.range;
            storage('sst.range', state.range);
            markRange();
            load();
        });

        $('sstSearch').addEventListener('input', function () {
            state.search = this.value.trim().toLowerCase();
            applyFilter();
        });

        on('click', '.sst-group-toggle', function () {
            const groupNode = this.closest('.sst-group');
            const collapsed = !groupNode.classList.contains('is-collapsed');
            groupNode.classList.toggle('is-collapsed', collapsed);
            this.setAttribute('aria-expanded', collapsed ? 'false' : 'true');

            const list = readCollapsed().filter(function (key) { return key !== groupNode.dataset.group; });
            if (collapsed) {
                list.push(groupNode.dataset.group);
            }
            storage('sst.collapsed', JSON.stringify(list));
        });

        /* ---------- loading ---------- */
        function load() {
            if (state.loading) {
                return;
            }
            state.loading = true;
            root.classList.add('is-refreshing');

            getJson(root.dataset.summaryUrl, {range: state.range})
                .then(function (response) {
                    state.data = response.data;
                    // Without the "2 min ago" text, which changes on every refresh.
                    state.deploySignature = JSON.stringify((state.data.deploys || []).map(function (d) {
                        return [d.id, d.position, d.label, d.note, d.worse];
                    }));
                    renderHero(state.data);
                    renderKpis(state.data);
                    renderGroups(state.data.groups);
                    renderMaintenances(state.data.maintenances || []);
                    renderDeploys(state.data.recent_deploys || []);
                    return refreshIncidents(state.data);
                })
                .catch(function () {
                    $('sstHero').dataset.level = 'major';
                    $('sstHeroIcon').className = LEVEL_ICONS.major;
                    $('sstHeroTitle').textContent = i18n.loadFailed;
                })
                .finally(function () {
                    state.loading = false;
                    state.countdown = refreshSeconds();
                    root.classList.remove('is-refreshing');
                    tick();
                });
        }

        function tick() {
            $('sstCountdown').textContent = fill(i18n.nextRefresh, {seconds: state.countdown});
        }

        setInterval(function () {
            // No refresh while the page is hidden or a dialog is open (it would redraw under it).
            if (document.hidden || state.loading || document.querySelector('dialog.sst-sheet[open]')) {
                return;
            }
            state.countdown -= 1;
            if (state.countdown <= 0) {
                load();
                return;
            }
            tick();
        }, 1000);

        $('sstRefresh').addEventListener('click', load);

        /* ---------- component detail ---------- */
        function row(cells, numericFrom) {
            const tr = document.createElement('tr');
            cells.forEach(function (value, index) {
                const td = document.createElement('td');
                td.textContent = value == null ? '' : String(value);
                if (numericFrom !== undefined && index >= numericFrom) {
                    td.className = 'sst-num';
                }
                tr.appendChild(td);
            });
            return tr;
        }

        function emptyRow(columns, text) {
            const tr = document.createElement('tr');
            const td = document.createElement('td');
            td.colSpan = columns;
            td.className = 'sst-muted sst-center';
            td.textContent = text;
            tr.appendChild(td);
            return tr;
        }

        function emptyChart(container) {
            const empty = document.createElement('p');
            empty.className = 'sst-muted sst-center sst-chart-empty';
            empty.textContent = i18n.noTraffic;
            container.replaceChildren(empty);
        }

        function renderChart(container, hourly) {
            const max = Math.max.apply(null, hourly.map(function (h) { return h.requests; }));
            if (!max) {
                emptyChart(container);
                return;
            }

            const fragment = document.createDocumentFragment();
            hourly.forEach(function (hour, index) {
                const column = document.createElement('span');
                column.className = 'sst-chart-col sst-bar';
                column.style.setProperty('--sst-i', index);
                column._sstLines = [hour.hour, i18n.requests + ': ' + numberFormat.format(hour.requests), i18n.errors + ': ' + numberFormat.format(hour.errors)];

                const total = document.createElement('span');
                total.className = 'sst-chart-total';
                total.style.blockSize = (hour.requests ? Math.max(3, hour.requests / max * 100) : 0) + '%';

                const errors = document.createElement('span');
                errors.className = 'sst-chart-errors';
                errors.style.blockSize = (hour.errors ? Math.max(6, hour.errors / Math.max(hour.requests, 1) * 100) : 0) + '%';

                total.appendChild(errors);
                column.appendChild(total);
                fragment.appendChild(column);
            });
            container.replaceChildren(fragment);
        }

        function renderLatency(container, hourly, limit) {
            if (!hourly.some(function (h) { return h.p95; })) {
                emptyChart(container);
                return;
            }
            const max = Math.max.apply(null, hourly.map(function (h) { return h.p95 || 0; }).concat([limit || 0]));

            const fragment = document.createDocumentFragment();
            hourly.forEach(function (hour, index) {
                const column = document.createElement('span');
                column.className = 'sst-chart-col sst-bar';
                column.style.setProperty('--sst-i', index);
                column._sstLines = hour.p95
                    ? [hour.hour, 'p95: ' + numberFormat.format(hour.p95) + ' ms', 'p50: ' + numberFormat.format(hour.p50) + ' ms']
                    : [hour.hour, i18n.noData];

                const p95 = document.createElement('span');
                p95.className = 'sst-chart-total sst-latency-p95';
                p95.dataset.over = limit && hour.p95 > limit ? '1' : '0';
                p95.style.blockSize = (hour.p95 ? Math.max(3, hour.p95 / max * 100) : 0) + '%';

                const p50 = document.createElement('span');
                p50.className = 'sst-latency-p50';
                p50.style.blockSize = (hour.p95 ? Math.min(100, (hour.p50 || 0) / hour.p95 * 100) : 0) + '%';

                p95.appendChild(p50);
                column.appendChild(p95);
                fragment.appendChild(column);
            });

            if (limit) {
                const line = document.createElement('span');
                line.className = 'sst-chart-limit';
                line.style.insetBlockEnd = (limit / max * 100) + '%';
                fragment.appendChild(line);
            }
            container.replaceChildren(fragment);
        }

        function skeletonRows(tbody, columns) {
            const fragment = document.createDocumentFragment();
            for (let i = 0; i < 3; i++) {
                const tr = document.createElement('tr');
                tr.className = 'sst-skeleton-row';
                const td = document.createElement('td');
                td.colSpan = columns;
                const line = document.createElement('span');
                line.className = 'sst-shimmer';
                td.appendChild(line);
                tr.appendChild(td);
                fragment.appendChild(tr);
            }
            tbody.replaceChildren(fragment);
        }

        // Counts a number up from 0 for a livelier fill; plain text with reduced motion.
        function countUp(node, value, format) {
            const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            if (reduced || !value) {
                node.textContent = format(value);
                return;
            }
            const started = performance.now();
            const duration = 600;
            (function step(now) {
                const progress = Math.min(1, (now - started) / duration);
                const eased = 1 - Math.pow(1 - progress, 3);
                node.textContent = format(progress < 1 ? value * eased : value);
                if (progress < 1 && node.isConnected) {
                    requestAnimationFrame(step);
                }
            })(started);
        }

        // Fills the header from the page's data at once, so the dialog never opens empty.
        function resetDetail(preview) {
            const dialog = $('sstDetail');
            dialog.classList.remove('is-loaded');
            dialog.classList.add('is-loading');

            ['sstDetailRequests', 'sstDetailErrors', 'sstDetailRate', 'sstDetailAvg', 'sstDetailP95'].forEach(function (id) {
                $(id).textContent = '—';
            });
            $('sstDetailTitle').textContent = preview ? preview.label : '';
            $('sstDetailDot').dataset.status = preview ? (preview.configured ? preview.status : 'not_configured') : '';
            setPill($('sstDetailPill'), preview ? (preview.configured ? preview.status : 'not_configured') : '', preview ? preview.status_label : '');
            const message = $('sstDetailMessage');
            message.textContent = preview && preview.message ? preview.message : '';
            message.hidden = !(preview && preview.message);
            $('sstDetailSignal').hidden = true;

            skeletonRows($('sstDetailRoutes'), 7);
            skeletonRows($('sstDetailChecks'), 5);
            const incidentsBox = $('sstDetailIncidents');
            const line = document.createElement('span');
            line.className = 'sst-shimmer';
            line.style.blockSize = '3rem';
            incidentsBox.replaceChildren(line);

            ['sstDetailChart', 'sstDetailLatency'].forEach(function (id) {
                $(id).replaceChildren();
                $(id).classList.remove('is-filled');
                $(id).classList.add('is-loading');
            });
        }

        function findComponent(key) {
            if (!state.data) {
                return null;
            }
            return allComponents(state.data).filter(function (c) { return c.key === key; })[0] || null;
        }

        // Plays the "filled" animation: sections one after another, then the chart bars.
        function revealDetail() {
            const dialog = $('sstDetail');
            Array.prototype.forEach.call(dialog.querySelectorAll('.sst-detail-body > *'), function (node, index) {
                node.style.setProperty('--sst-i', index);
            });
            Array.prototype.forEach.call(dialog.querySelectorAll('.sst-kpi'), function (node, index) {
                node.style.setProperty('--sst-i', index);
            });
            dialog.classList.remove('is-loading');
            dialog.classList.add('is-loaded');
        }

        let detailRequest = 0;

        function openDetail(component) {
            const request = ++detailRequest;
            resetDetail(findComponent(component));
            openDialog($('sstDetail'));

            getJson(root.dataset.componentUrl.replace('__component__', encodeURIComponent(component)))
                .then(function (response) {
                    // A newer dialog was opened meanwhile: drop this answer.
                    if (request !== detailRequest) {
                        return;
                    }
                    const data = response.data;
                    const requests = data.hourly.reduce(function (sum, h) { return sum + h.requests; }, 0);
                    const errors = data.hourly.reduce(function (sum, h) { return sum + h.errors; }, 0);
                    const routeRequests = data.routes.reduce(function (sum, r) { return sum + r.requests; }, 0);
                    const weightedMs = data.routes.reduce(function (sum, r) { return sum + r.avg_ms * r.requests; }, 0);

                    $('sstDetailTitle').textContent = data.label;
                    $('sstDetailDot').dataset.status = data.status;
                    setPill($('sstDetailPill'), data.status, data.status_label);

                    const message = $('sstDetailMessage');
                    message.textContent = data.message || '';
                    message.hidden = !data.message;

                    const whole = function (value) { return numberFormat.format(Math.round(value)); };
                    countUp($('sstDetailRequests'), requests, whole);
                    countUp($('sstDetailErrors'), errors, whole);
                    if (requests) {
                        countUp($('sstDetailRate'), errors / requests * 100, function (value) { return value.toFixed(1) + '%'; });
                    } else {
                        $('sstDetailRate').textContent = '—';
                    }
                    if (routeRequests) {
                        countUp($('sstDetailAvg'), weightedMs / routeRequests, function (value) { return Math.round(value) + ' ms'; });
                    } else {
                        $('sstDetailAvg').textContent = '—';
                    }
                    if (data.latency && data.latency.p95 !== null) {
                        countUp($('sstDetailP95'), data.latency.p95, function (value) { return whole(value) + ' ms'; });
                    } else {
                        $('sstDetailP95').textContent = '—';
                    }

                    ['sstDetailChart', 'sstDetailLatency'].forEach(function (id) {
                        $(id).classList.remove('is-loading');
                        $(id).classList.add('is-filled');
                    });
                    renderChart($('sstDetailChart'), data.hourly);
                    renderLatency($('sstDetailLatency'), data.hourly, data.latency ? data.latency.degraded_p95_ms : null);
                    ['sstDetailChart', 'sstDetailLatency'].forEach(function (id) {
                        if ($(id).querySelector('.sst-chart-col')) {
                            renderDeployMarkers($(id), data.deploys);
                        }
                    });
                    $('sstDetailRoutes').replaceChildren();
                    $('sstDetailChecks').replaceChildren();

                    const signalBox = $('sstDetailSignal');
                    if (data.signal.calls > 0 || data.signal.last_failure) {
                        let text = fill(i18n.signals, {failures: data.signal.failures, calls: data.signal.calls});
                        if (data.signal.last_failure) {
                            text += ' ' + fill(i18n.lastFailure, {reason: data.signal.last_failure.reason, time: data.signal.last_failure.at});
                        }
                        signalBox.textContent = text;
                        signalBox.dataset.failed = data.signal.failures > 0 ? '1' : '0';
                        signalBox.hidden = false;
                    }

                    const routes = $('sstDetailRoutes');
                    if (!data.routes.length) {
                        routes.appendChild(emptyRow(7, i18n.noTraffic));
                    }
                    data.routes.forEach(function (route) {
                        const p95 = route.p95_ms === null || route.p95_ms === undefined ? '—' : route.p95_ms;
                        const tr = row([route.route, numberFormat.format(route.requests), numberFormat.format(route.errors), route.error_rate + '%', route.slow, route.avg_ms, p95], 1);
                        tr.children[0].className = 'sst-route';
                        if (route.errors > 0) {
                            tr.classList.add('has-errors');
                        }
                        routes.appendChild(tr);
                    });

                    const checks = $('sstDetailChecks');
                    if (!data.checks.length) {
                        checks.appendChild(emptyRow(5, i18n.noData));
                    }
                    data.checks.forEach(function (check) {
                        const tr = row([check.checked_at, check.probe, '', check.response_ms, check.message]);
                        tr.children[3].className = 'sst-num';
                        const pill = document.createElement('span');
                        pill.className = 'sst-pill';
                        setPill(pill, check.status === 'skipped' ? 'not_configured' : check.status, check.status_label);
                        tr.children[2].appendChild(pill);
                        checks.appendChild(tr);
                    });

                    renderIncidents($('sstDetailIncidents'), data.incidents, false);
                    revealDetail();
                })
                .catch(function () {
                    if (request !== detailRequest) {
                        return;
                    }
                    $('sstDetailChart').classList.remove('is-loading');
                    $('sstDetailLatency').classList.remove('is-loading');
                    $('sstDetailRoutes').replaceChildren();
                    $('sstDetailChecks').replaceChildren();
                    $('sstDetailIncidents').replaceChildren();
                    const message = $('sstDetailMessage');
                    message.textContent = i18n.loadFailed;
                    message.hidden = false;
                    revealDetail();
                });
        }

        on('click', '.sst-component-name', function () {
            openDetail(this.dataset.component);
        });

        /* ---------- write actions (only for managers) ---------- */
        if (canManage) {
            setupManagement();
        }

        function setupManagement() {
            const panels = {
                incident: $('sstIncidentSheet'),
                maintenance: $('sstMaintenanceSheet'),
                update: $('sstUpdateSheet'),
                postmortem: $('sstPostmortemSheet'),
            };

            function formData(form) {
                const data = {};
                new FormData(form).forEach(function (value, key) {
                    if (key.slice(-2) === '[]') {
                        key = key.slice(0, -2);
                        (data[key] = data[key] || []).push(value);
                    } else {
                        data[key] = value;
                    }
                });
                return data;
            }

            function showError(form, lines) {
                const box = form.querySelector('.sst-form-error');
                box.replaceChildren();
                lines.forEach(function (line) {
                    const item = document.createElement('div');
                    item.textContent = line;
                    box.appendChild(item);
                });
                box.hidden = false;
                box.scrollIntoView({block: 'nearest'});
            }

            function clearError(form) {
                form.querySelector('.sst-form-error').hidden = true;
            }

            function submit(form, method, url, data, success) {
                const button = form.querySelector('.sst-submit');
                clearError(form);
                button.disabled = true;

                send(method, url, data)
                    .then(function () {
                        closeDialog(form.closest('dialog'));
                        toast(success);
                        load();
                    })
                    .catch(function (error) {
                        const errors = error && error.body && error.body.errors;
                        showError(form, errors
                            ? Object.keys(errors).map(function (key) { return errors[key][0]; })
                            : [(error && error.body && error.body.message) || i18n.saveFailed]);
                    })
                    .finally(function () {
                        button.disabled = false;
                    });
            }

            // Ctrl / ⌘ + Enter submits the open panel.
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
                    const form = document.querySelector('dialog.sst-sheet[open] form');
                    if (form) {
                        event.preventDefault();
                        form.requestSubmit();
                    }
                }
            });

            /* --- report incident --- */
            const incidentForm = $('sstIncidentForm');

            function openIncident(component) {
                incidentForm.reset();
                clearError(incidentForm);
                if (component) {
                    incidentForm.elements.component.value = component;
                }
                openDialog(panels.incident);
                if (component) {
                    setTimeout(function () { incidentForm.elements.title.focus(); }, 80);
                }
            }

            incidentForm.addEventListener('submit', function (event) {
                event.preventDefault();
                submit(this, 'POST', root.dataset.incidentUrl, formData(this), i18n.toastIncident);
            });

            /* --- schedule maintenance --- */
            const maintenanceForm = $('sstMaintenanceForm');
            const startInput = maintenanceForm.elements.starts_at_local;
            const endInput = maintenanceForm.elements.ends_at_local;

            function localValue(date) {
                const pad = function (n) { return String(n).padStart(2, '0'); };
                return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate())
                    + 'T' + pad(date.getHours()) + ':' + pad(date.getMinutes());
            }

            // The window from the form, in the viewer's local time.
            function maintenanceWindow() {
                const later = maintenanceForm.elements.start_mode.value === 'later';
                const start = later && startInput.value ? new Date(startInput.value) : new Date();
                const duration = maintenanceForm.elements.duration.value;
                const end = duration === 'custom'
                    ? (endInput.value ? new Date(endInput.value) : null)
                    : new Date(start.getTime() + parseInt(duration, 10) * 60000);
                return {start: start, end: end};
            }

            function updateMaintenanceForm() {
                startInput.hidden = maintenanceForm.elements.start_mode.value !== 'later';
                endInput.hidden = maintenanceForm.elements.duration.value !== 'custom';

                const span = maintenanceWindow();
                maintenanceForm.querySelector('.sst-window-preview').textContent = span.end
                    ? fill(i18n.windowPreview, {start: timeFormat.format(span.start), end: timeFormat.format(span.end)})
                    : '';

                const selected = maintenanceForm.querySelectorAll('input[name="components[]"]:checked').length;
                maintenanceForm.querySelector('.sst-selected-count').textContent = fill(i18n.selectedCount, {count: selected});
            }

            function openMaintenance(component) {
                maintenanceForm.reset();
                clearError(maintenanceForm);
                const now = new Date();
                startInput.value = localValue(now);
                endInput.value = localValue(new Date(now.getTime() + 30 * 60000));
                maintenanceForm.querySelector('.sst-picker-search').value = '';
                filterPicker('');
                if (component) {
                    const box = maintenanceForm.querySelector('input[name="components[]"][value="' + CSS.escape(component) + '"]');
                    if (box) {
                        box.checked = true;
                    }
                }
                updateMaintenanceForm();
                openDialog(panels.maintenance);
            }

            function filterPicker(query) {
                maintenanceForm.querySelectorAll('.sst-picker-group').forEach(function (group) {
                    let shown = 0;
                    group.querySelectorAll('.sst-picker-item').forEach(function (item) {
                        const match = !query || item.dataset.search.indexOf(query) !== -1;
                        item.hidden = !match;
                        shown += match ? 1 : 0;
                    });
                    group.hidden = shown === 0;
                });
            }

            maintenanceForm.addEventListener('input', updateMaintenanceForm);
            maintenanceForm.addEventListener('change', updateMaintenanceForm);
            maintenanceForm.querySelector('.sst-picker-search').addEventListener('input', function () {
                filterPicker(this.value.trim().toLowerCase());
            });

            // "All" toggles the visible components of a group.
            on('click', '.sst-picker-all', function () {
                const boxes = Array.prototype.filter.call(
                    this.closest('.sst-picker-group').querySelectorAll('input[type="checkbox"]'),
                    function (box) { return !box.closest('.sst-picker-item').hidden; },
                );
                const check = boxes.some(function (box) { return !box.checked; });
                boxes.forEach(function (box) { box.checked = check; });
                updateMaintenanceForm();
            });

            maintenanceForm.addEventListener('submit', function (event) {
                event.preventDefault();
                const data = formData(this);
                const span = maintenanceWindow();

                if (!data.components || !data.components.length) {
                    showError(this, [i18n.chooseComponents]);
                    return;
                }

                submit(this, 'POST', root.dataset.maintenanceUrl, {
                    title: data.title,
                    message: data.message,
                    components: data.components,
                    starts_at: span.start.toISOString(),
                    ends_at: span.end ? span.end.toISOString() : '',
                }, i18n.toastMaintenance);
            });

            on('click', '.sst-maintenance-end', function () {
                const button = this;
                button.disabled = true;
                send('DELETE', root.dataset.maintenanceEndUrl.replace('__id__', button.dataset.id))
                    .then(function () {
                        toast(i18n.toastMaintenanceEnded);
                        load();
                    })
                    .catch(function () {
                        button.disabled = false;
                        toast(i18n.saveFailed, 'error');
                    });
            });

            /* --- timeline update --- */
            const updateForm = $('sstUpdateForm');
            const STEPS = ['investigating', 'identified', 'monitoring', 'resolved'];

            on('click', '.sst-add-update', function () {
                const incident = this.closest('.sst-incident')._sstIncident;
                updateForm.reset();
                clearError(updateForm);
                updateForm.elements.incident_id.value = incident.id;
                updateForm.querySelector('.sst-update-for').textContent = fill(i18n.updateFor, {title: incident.title || incident.component_label});

                // Only incidents posted by hand are resolved by a person.
                const resolved = updateForm.querySelector('[data-state-option="resolved"]');
                resolved.hidden = incident.source !== 'manual';
                resolved.querySelector('input').disabled = incident.source !== 'manual';

                // Suggest the next step.
                const current = Math.max(0, STEPS.indexOf(incident.state || 'investigating'));
                const next = STEPS[Math.min(current + 1, incident.source === 'manual' ? 3 : 2)];
                updateForm.querySelector('input[name="state"][value="' + next + '"]').checked = true;

                updateForm.querySelectorAll('.sst-stepper [data-step]').forEach(function (step, index) {
                    step.dataset.reached = index <= current ? '1' : '0';
                });

                openDialog(panels.update);
            });

            updateForm.addEventListener('submit', function (event) {
                event.preventDefault();
                const data = formData(this);
                const url = root.dataset.incidentUpdateUrl.replace('__id__', data.incident_id);
                submit(this, 'POST', url, {state: data.state, message: data.message}, i18n.toastUpdate);
            });

            /* --- postmortem --- */
            const postmortemForm = $('sstPostmortemForm');

            on('click', '.sst-edit-postmortem', function () {
                const incident = this.closest('.sst-incident')._sstIncident;
                clearError(postmortemForm);
                postmortemForm.elements.incident_id.value = incident.id;
                postmortemForm.elements.postmortem.value = incident.postmortem || '';
                postmortemForm.querySelector('.sst-update-for').textContent = (incident.title || incident.component_label) + ' · ' + incident.started_at;
                openDialog(panels.postmortem);
            });

            postmortemForm.addEventListener('submit', function (event) {
                event.preventDefault();
                const data = formData(this);
                const url = root.dataset.incidentEditUrl.replace('__id__', data.incident_id);
                submit(this, 'PATCH', url, {postmortem: data.postmortem}, i18n.toastPostmortem);
            });

            /* --- opening panels --- */
            on('click', '[data-open-panel]', function () {
                this.dataset.openPanel === 'incident' ? openIncident() : openMaintenance();
            });

            /* --- per-component menu --- */
            const menu = $('sstMenu');
            let menuFor = null;

            on('click', '.sst-component-menu', function (event) {
                event.stopPropagation();
                if (menuFor === this && !menu.hidden) {
                    closeMenu();
                    return;
                }
                menuFor = this;
                menu.dataset.component = this.dataset.component;
                setText(menu, '.sst-menu-title', this.dataset.label);
                menu.hidden = false;
                this.setAttribute('aria-expanded', 'true');

                const rect = this.getBoundingClientRect();
                const box = menu.getBoundingClientRect();
                const rtl = getComputedStyle(root).direction === 'rtl';
                const left = rtl ? rect.left : rect.right - box.width;
                menu.style.left = Math.min(Math.max(8, left), window.innerWidth - box.width - 8) + 'px';
                menu.style.top = (rect.bottom + box.height + 8 > window.innerHeight ? rect.top - box.height - 6 : rect.bottom + 6) + 'px';
                menu.querySelector('[role="menuitem"]').focus();
            });

            on('click', '[data-menu-action]', function () {
                const component = menu.dataset.component;
                const action = this.dataset.menuAction;
                closeMenu();
                if (action === 'incident') {
                    openIncident(component);
                } else if (action === 'maintenance') {
                    openMaintenance(component);
                } else {
                    openDetail(component);
                }
            });

            document.addEventListener('click', function (event) {
                const target = event.target instanceof Element ? event.target : null;
                if (!menu.hidden && target && !menu.contains(target) && !target.closest('.sst-component-menu')) {
                    closeMenu();
                }
            });
            document.addEventListener('keydown', function (event) {
                if (menu.hidden) {
                    return;
                }
                const items = Array.prototype.slice.call(menu.querySelectorAll('[role="menuitem"]'));
                const index = items.indexOf(document.activeElement);
                if (event.key === 'Escape') {
                    const opener = menuFor;
                    closeMenu();
                    if (opener) {
                        opener.focus();
                    }
                } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                    event.preventDefault();
                    const step = event.key === 'ArrowDown' ? 1 : -1;
                    items[(index + step + items.length) % items.length].focus();
                }
            });
            window.addEventListener('scroll', closeMenu, {passive: true});
            window.addEventListener('resize', closeMenu);
        }

        function closeMenu() {
            const menu = $('sstMenu');
            if (menu && !menu.hidden) {
                menu.hidden = true;
                document.querySelectorAll('.sst-component-menu[aria-expanded="true"]').forEach(function (button) {
                    button.setAttribute('aria-expanded', 'false');
                });
            }
        }

        markRange();
        load();
    })();
</script>

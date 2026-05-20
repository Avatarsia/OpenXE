var SuperSearch = (function ($) {
    'use strict';

    var me = {

        config: {
            inputBuffer: 300, // in milliseconds

            // Layout-Defaults — sollten ueblicherweise aus den CSS-Custom-Properties
            // auf #supersearch-overlay gelesen werden (siehe me.readMinColumnWidth).
            // Diese Werte greifen nur, wenn das Overlay-Element noch nicht im DOM
            // ist oder die Custom-Property nicht aufgeloest werden kann.
            defaultMinColumnWidth: 280,
            defaultColumnGap: 16,
            defaultWrapperWidth: 900,
            // Viewport-Offset (Sidebar + Padding) — Fallback fuer M4-Reflow-Problem,
            // wenn der Container beim Init noch display:none ist.
            viewportSidebarOffset: 60
        },

        storage: {
            $input: null,
            $overlay: null,
            $details: null,
            $results: null,
            $lastUpdate: null,
            debounceBuffer: null,
            hasResults: false,
            isOpen: false,
            // K3: aktuell laufender Such-XHR, damit veraltete Antworten
            // beim Folge-Tippen abgebrochen werden koennen.
            currentSearchXhr: null
        },

        init: function () {
            me.storage.$input = $('#supersearch-input');
            if (me.storage.$input.length === 0) {
                return;
            }

            me.registerEvents();
        },

        registerEvents: function () {
            me.storage.$input.on('keyup.SuperSearch', me.onKeyUpSearchInput);

            // Overlay anzeigen bei Focus in das Such-Eingabefeld; nur wenn es schon mal geöffnet war
            me.storage.$input.on('focus.SuperSearch', me.onFocusSearchInput);

            // Overlay mit ESC schließen — Document-Level, mit Namespace fuer sauberen Cleanup.
            $(document).off('keydown.SuperSearch').on('keydown.SuperSearch', function (e) {
                if (me.storage.$overlay === null) {
                    return;
                }
                if (me.storage.isOpen !== true) {
                    return;
                }

                // ESC
                if (e.keyCode === 27) {
                    me.hideOverlay();
                }
            });
        },

        /**
         * @return {jQuery}
         */
        getOverlay: function () {
            if (typeof me.storage.$overlay === 'undefined' || me.storage.$overlay === null) {
                me.storage.$overlay = me.createOverlay();
                me.storage.$details = me.storage.$overlay.find('section.detail');
                me.storage.$results = me.storage.$overlay.find('section.result');
                me.storage.$lastUpdate = me.storage.$overlay.find('section.last-update');
            }

            return me.storage.$overlay;
        },

        showOverlay: function () {
            var $overlay = me.getOverlay();
            $overlay.show();
            me.storage.isOpen = true;
            me.showDetails();
        },

        hideOverlay: function () {
            me.getOverlay().hide();
            me.storage.isOpen = false;
            // Accessibility: Focus zurueck zum Such-Eingabefeld, das das Overlay
            // geoeffnet hat. Damit landet der Keyboard-Nutzer nach ESC wieder
            // an einem sinnvollen Punkt.
            if (me.storage.$input !== null && me.storage.$input.length > 0) {
                me.storage.$input.trigger('focus');
            }
        },

        /**
         * @return {jQuery}
         */
        createOverlay: function () {
            var overlaySelector = '#supersearch-overlay';
            if ($(overlaySelector).length > 0) {
                return $(overlaySelector);
            }

            var overlayTemplate =
                // Close-Icon: role=button + tabindex=0 + aria-label fuer Keyboard-Bedienung.
                '<span id="supersearch-icon-close" class="icon icon-close" role="button" tabindex="0" aria-label="Suche schliessen"></span>' +
                '<div class="result-wrapper">' +
                '<section class="empty-message">Keine Suchergebnisse gefunden</section>' +
                '<section class="error-message"></section>' +
                '<section class="result"></section>' +
                '<section class="last-update"></section>' +
                '</div>' +
                '<div class="detail-wrapper">' +
                '<section class="detail"></section>' +
                '</div>';

            var overlayIdAttr = overlaySelector.substr(1);
            var $overlay = $('<div>')
                .attr('id', overlayIdAttr)
                .addClass('supersearch-overlay')
                // Accessibility: pragmatische ARIA-Annotation als Dialog.
                .attr('role', 'dialog')
                .attr('aria-modal', 'true')
                .attr('aria-label', 'Globale Suche')
                .html(overlayTemplate);

            $overlay.off('click.SuperSearch', '#supersearch-icon-close');
            $overlay.on('click.SuperSearch', '#supersearch-icon-close', function (event) {
                event.preventDefault();
                me.hideOverlay();
            });
            // Keyboard-Aktivierung des Close-Icons (Enter/Space).
            $overlay.off('keydown.SuperSearchClose', '#supersearch-icon-close');
            $overlay.on('keydown.SuperSearchClose', '#supersearch-icon-close', function (event) {
                if (event.keyCode === 13 || event.keyCode === 32) {
                    event.preventDefault();
                    me.hideOverlay();
                }
            });

            // K4: Delegiertes Click-Binding fuer alle Result-Items.
            // Item-Daten haengen via .data('supersearchItem', item) am LI-Element
            // (siehe buildDefaultItemResult).
            $overlay.off('click.SuperSearch', '.result-item a');
            $overlay.on('click.SuperSearch', '.result-item a', function (event) {
                event.preventDefault();
                var item = $(event.currentTarget).closest('.result-item').data('supersearchItem');
                if (typeof item === 'undefined' || item === null) {
                    return;
                }
                me.renderItemDetails(item);
            });

            // Accessibility: Focus-Trap. Tab innerhalb des Overlays zirkulieren
            // lassen, damit Keyboard-Nutzer nicht ausserhalb landen waehrend
            // das Overlay als modaler Dialog offen ist. Suchfeld ist
            // ausserhalb des Overlays und gilt als erstes fokussierbares
            // Element (vor dem ersten Overlay-Element).
            $overlay.off('keydown.SuperSearchTrap');
            $overlay.on('keydown.SuperSearchTrap', function (event) {
                if (event.key !== 'Tab' && event.keyCode !== 9) {
                    return;
                }
                var $focusable = me.getFocusableElements($overlay);
                if ($focusable.length === 0) {
                    return;
                }
                var first = $focusable.first()[0];
                var last = $focusable.last()[0];
                var active = document.activeElement;

                if (event.shiftKey) {
                    if (active === first || active === me.storage.$input[0]) {
                        event.preventDefault();
                        last.focus();
                    }
                } else {
                    if (active === last) {
                        event.preventDefault();
                        // Schleife zurueck zum Such-Eingabefeld (logischer Anfang).
                        me.storage.$input.trigger('focus');
                    }
                }
            });

            $overlay.hide();
            $overlay.appendTo('#header');
            me.storage.isOpen = false;

            return $overlay;
        },

        /**
         * @param {Event} event
         */
        onKeyUpSearchInput: function (event) {
            event.preventDefault();
            var controlKeyCodes = [
                 9, // Tab
                13, // Enter
                16, // Shift
                17, // Strg
                18, // Alt
                20, // Caps lock
                27, // ESC
                37, // Cursor Left
                38, // Cursor Up
                39, // Cursor Right
                40  // Cursor Down
            ];
            if ($.inArray(event.keyCode, controlKeyCodes) !== -1) {
                return;
            }

            var that = this;
            me.debounce(function () {
                var searchQuery = $(that).val();
                me.fetchSearchResults(searchQuery).then(me.renderSearchResults);
            }, me.config.inputBuffer);
        },

        /**
         * Overlay anzeigen bei Focus in das Such-Eingabefeld; nur wenn es schon mal geöffnet war
         *
         * @param {Event} event
         */
        onFocusSearchInput: function (event) {
            event.preventDefault();
            if (me.storage.$overlay === null) {
                return;
            }
            if (me.storage.hasResults === false) {
                return;
            }

            me.showOverlay();
        },

        /**
         * @param {string} searchQuery
         *
         * @return {jqXHR}
         */
        fetchSearchResults: function (searchQuery) {
            if (typeof searchQuery !== 'string') {
                searchQuery = '';
            }

            // K3: vorherigen XHR abbrechen, damit eine alte Antwort eine
            // neuere nicht ueberschreibt (Race-Condition beim Tippen).
            if (me.storage.currentSearchXhr !== null &&
                typeof me.storage.currentSearchXhr.abort === 'function') {
                me.storage.currentSearchXhr.abort();
            }

            var xhr = $.ajax({
                url: 'index.php?module=supersearch&action=ajax&cmd=search',
                method: 'post',
                dataType: 'json',
                data: {
                    search_query: searchQuery
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    // Vom Folge-Request abgebrochen — kein Fehler-Alert zeigen.
                    if (textStatus === 'abort') {
                        return;
                    }

                    var errorMessage = 'SuperSearch - Unbekannter Fehler #31: ' + errorThrown;

                    // PHP-Skript hat Fehler geliefert (z.b. 404)
                    if (textStatus === 'error') {
                        errorMessage = 'SuperSearch - Unbekannter Server-Fehler beim Laden der Such-Ergebnisse: ';
                        errorMessage += errorThrown;
                    }

                    // PHP-Skript liefert JSON-Error-Response
                    if (jqXHR.hasOwnProperty('responseJSON') && jqXHR.responseJSON.hasOwnProperty('error')) {
                        errorMessage = 'SuperSearch - Server-Fehler beim Laden der Such-Ergebnisse: ';
                        errorMessage += jqXHR.responseJSON.error;

                        if (jqXHR.responseJSON.hasOwnProperty('data') &&
                            jqXHR.responseJSON.data === 'index-empty') {
                            me.showErrorMessage('Fehler: ' + jqXHR.responseJSON.error);
                            return;
                        }
                    }

                    alert(errorMessage);
                }
            });

            me.storage.currentSearchXhr = xhr;
            xhr.always(function () {
                if (me.storage.currentSearchXhr === xhr) {
                    me.storage.currentSearchXhr = null;
                }
            });

            return xhr;
        },

        /**
         * @param {Object} rawResult Server-Response (JsonResponse mit shape
         *   {success: bool, data: ResultCollection|null|"index-empty"}).
         */
        renderSearchResults: function (rawResult) {
            var $overlay = me.getOverlay();
            var $resultContainer = $overlay.find('section.result');
            $resultContainer.empty();

            // Defensive: Server liefert immer ein Object mit 'data'-Property.
            // Wenn das nicht der Fall ist, ist die Response korrupt.
            if (typeof rawResult !== 'object' || rawResult === null ||
                !rawResult.hasOwnProperty('data')) {
                $resultContainer.text('Fehler: Suche hat fehlerhaftes Ergebnis geliefert.');
                me.storage.hasResults = false;
                me.hideResults();
                return;
            }

            // Overlay ausblenden, wenn Suchbegriff zu kurz
            if (rawResult.data === null) {
                me.storage.hasResults = false;
                me.hideOverlay();
                return;
            }

            var fuzzyMode = rawResult.data.hasOwnProperty('fuzzy') && rawResult.data.fuzzy === true;
            var metaInfo = [];

            if (fuzzyMode) {
                metaInfo.push('Fuzzy search aktiv');
            }

            if (rawResult.data.hasOwnProperty('last_index_update_formatted') &&
                rawResult.data.last_index_update_formatted !== null) {
                var lastIndexUpdate = rawResult.data.last_index_update_formatted;
                metaInfo.push('Such-Index vom ' + lastIndexUpdate);
            }

            if (metaInfo.length > 0) {
                me.storage.$lastUpdate.text(metaInfo.join(' | ')).show();
            } else {
                me.storage.$lastUpdate.text('').hide();
            }

            var resultCount = rawResult.data.count;
            var searchResults = rawResult.data.results;
            if (resultCount === 0) {
                me.storage.hasResults = false;
                me.showEmptyResults();
                return;
            }

            me.storage.$details.empty();
            var wrapperWidth = me.measureResultWrapperWidth($resultContainer);
            var columns = me.buildResultColumns(searchResults, wrapperWidth);
            var $columnsWrapper = $('<div class="result-columns">');
            var minColPx = me.readMinColumnWidth();
            $columnsWrapper.css(
                'grid-template-columns',
                'repeat(' + columns.length + ', minmax(' + minColPx + 'px, 1fr))'
            );

            columns.forEach(function (column) {
                var $column = $('<div class="result-column">');
                column.forEach(function (groupResult) {
                    var $groupHtml = me.buildGroupResult(groupResult.key, groupResult.title, groupResult.items);
                    if (typeof $groupHtml !== 'undefined') {
                        $column.append($groupHtml);
                    }
                });
                if ($column.children().length > 0) {
                    $columnsWrapper.append($column);
                }
            });

            $resultContainer.append($columnsWrapper);

            me.storage.hasResults = true;
            me.showResults();
            me.showOverlay();
        },

        /**
         * @param {string} groupKey
         * @param {string} groupTitle
         * @param {array}  items
         *
         * @return {jQuery}
         */
        buildGroupResult: function (groupKey, groupTitle, items) {
            if (items.length === 0) {
                return;
            }
            if (typeof groupTitle === 'undefined') {
                groupTitle = 'Ergebnis';
            }

            var $resultWrapper = $('<div class="result-group">');
            var $resultList = $('<ul class="result-list">');
            // XSS-Hardening: groupTitle stammt aus Server-Konfiguration (ResultGroup),
            // wird sicherheitshalber als Text gerendert.
            var $listHead = $('<li class="result-head">').text(groupTitle);

            $resultList.append($listHead);
            items.forEach(function (item) {
                item.group = groupKey;
                var itemType = item.type !== null ? item.type : 'default';
                var $listItem;

                switch (itemType) {
                    case 'default':
                    default:
                        $listItem = me.buildDefaultItemResult(item);
                        break;
                }

                $resultList.append($listItem);
            });
            $resultWrapper.append($resultList);

            return $resultWrapper;
        },

        /**
         * Klassifiziert Suchergebnis-Gruppen in vier semantische Buckets.
         *
         * @param {object} searchResults
         * @returns {{leftGroups: Array, middleGroups: Array, otherGroups: Array, appGroups: Array}}
         */
        classifyResultGroups: function (searchResults) {
            var leftKeys = ['offer', 'order'];
            var middleKeys = ['deliverynote', 'invoice'];
            var leftGroups = [];
            var middleGroups = [];
            var otherGroups = [];
            var appGroups = [];

            Object.keys(searchResults).forEach(function (group) {
                var groupResult = searchResults[group];
                if (groupResult.key === 'app' || groupResult.key === 'apps') {
                    appGroups.push(groupResult);
                } else if (leftKeys.indexOf(groupResult.key) !== -1) {
                    leftGroups.push(groupResult);
                } else if (middleKeys.indexOf(groupResult.key) !== -1) {
                    middleGroups.push(groupResult);
                } else {
                    otherGroups.push(groupResult);
                }
            });

            return {
                leftGroups: leftGroups,
                middleGroups: middleGroups,
                otherGroups: otherGroups,
                appGroups: appGroups
            };
        },

        /**
         * Ordnet Suchergebnis-Gruppen dynamisch in Spalten an.
         *
         * Strategie:
         *   - Apps-Gruppen landen immer als letzte Spalte (rechts).
         *   - Bei breitem Layout (>=3 Result-Spalten) wird die alte Avatarsia-
         *     Semantik beibehalten: Spalte 0 = offer/order, Spalte 1 =
         *     deliverynote/invoice, restliche Spalten = uebrige Gruppen
         *     round-robin verteilt. Leere semantische Spalten werden
         *     herausgefiltert.
         *   - Bei schmalem Layout (<3 Result-Spalten) werden alle Gruppen
         *     round-robin auf einer prioritaetssortierten Liste verteilt.
         *   - Die Spaltenanzahl wird an die verfuegbare Breite gekoppelt.
         *
         * @param {object} searchResults
         * @param {number} [wrapperWidth] Verfuegbare Breite des Result-Containers in px
         * @returns {Array}
         */
        buildResultColumns: function (searchResults, wrapperWidth) {
            var buckets = me.classifyResultGroups(searchResults);
            return me.layoutResultColumns(buckets, wrapperWidth);
        },

        /**
         * Layout-Engine: nimmt klassifizierte Buckets + verfuegbare Breite
         * und liefert die fertige Spalten-Struktur.
         *
         * @param {{leftGroups: Array, middleGroups: Array, otherGroups: Array, appGroups: Array}} buckets
         * @param {number} wrapperWidth
         * @returns {Array}
         */
        layoutResultColumns: function (buckets, wrapperWidth) {
            var leftGroups = buckets.leftGroups;
            var middleGroups = buckets.middleGroups;
            var otherGroups = buckets.otherGroups;
            var appGroups = buckets.appGroups;

            var colGap = me.readColumnGap();
            var minColumnWidth = me.readMinColumnWidth() + colGap;
            var fallbackWidth = me.config.defaultWrapperWidth;
            var availableWidth = (wrapperWidth > 0 ? wrapperWidth : fallbackWidth) + colGap;
            var maxColumns = Math.max(1, Math.floor(availableWidth / minColumnWidth));
            var hasApps = appGroups.length > 0;
            var resultColumnBudget = hasApps ? Math.max(1, maxColumns - 1) : maxColumns;

            var orderedGroups = leftGroups.concat(middleGroups).concat(otherGroups);

            if (orderedGroups.length === 0) {
                return hasApps ? [appGroups] : [];
            }

            var resultColumnCount = Math.min(resultColumnBudget, orderedGroups.length);
            var columns = [];

            if (resultColumnCount >= 3 && (leftGroups.length > 0 || middleGroups.length > 0)) {
                columns.push(leftGroups.slice());
                columns.push(middleGroups.slice());

                var otherSlotCount = Math.max(1, resultColumnCount - 2);
                var otherSlots = [];
                for (var i = 0; i < otherSlotCount; i++) {
                    otherSlots.push([]);
                }
                otherGroups.forEach(function (groupResult, idx) {
                    otherSlots[idx % otherSlotCount].push(groupResult);
                });
                otherSlots.forEach(function (slot) {
                    columns.push(slot);
                });

                columns = columns.filter(function (column) {
                    return column.length > 0;
                });
            } else {
                for (var j = 0; j < resultColumnCount; j++) {
                    columns.push([]);
                }
                orderedGroups.forEach(function (groupResult, idx) {
                    columns[idx % resultColumnCount].push(groupResult);
                });
            }

            if (hasApps) {
                columns.push(appGroups);
            }

            return columns;
        },

        /**
         * @param {object} item
         *
         * @return {jQuery}
         */
        buildDefaultItemResult: function (item) {
            var hasSubtitle = item.hasOwnProperty('subtitle') && typeof item.subtitle === 'string';
            var hasAdditionalInfos =
                item.hasOwnProperty('additionalInfos') &&
                typeof item.additionalInfos === 'object' &&
                item.additionalInfos !== null;

            // XSS-Hardening: title, subtitle und additionalInfos werden ausschliesslich
            // ueber DOM-Building mit .text() gerendert. KEIN String-Konkat mit .html().
            var $title = $('<span>').addClass('title');
            $('<span>').addClass('title-main').text(item.title).appendTo($title);
            if (hasSubtitle) {
                $('<span>').addClass('title-sub').text(item.subtitle).appendTo($title);
            }

            var $listItem = $('<li>').addClass('result-item');
            var $itemLink = $('<a>').attr('href', item.link);
            $itemLink.append($title);

            if (hasAdditionalInfos) {
                var $caption = $('<span>').addClass('caption');
                $.each(item.additionalInfos, function (index, additionalInfo) {
                    $('<span>').addClass('additional').text(additionalInfo).appendTo($caption);
                });
                $itemLink.append($caption);
            }

            $itemLink.appendTo($listItem);
            // Item-Daten ans Element haengen fuer delegierten Click-Handler in createOverlay().
            $listItem.data('supersearchItem', item);

            return $listItem;
        },

        /**
         * Rendert Ergebnisdetails
         *
         * @param {object} item
         */
        renderItemDetails: function (item) {
            // Per AJAX ausführliche Inhalte nachladen
            me.fetchItemDetailsDynamicContent(item).then(
                function (data) {
                    me.renderItemDetailsDynamicContent(data, item);
                },
                function (jqXhr) {
                    var error =
                        typeof jqXhr.responseJSON !== 'undefined' &&
                        typeof jqXhr.responseJSON.error !== 'undefined'
                            ? jqXhr.responseJSON.error
                            : 'Unbekannter Fehler';
                    alert('Fehler beim Laden der Detail-Informationen: ' + error);
                }
            );
        },

        /**
         * @param {object} detailResult
         * @param {object} listItem Originales Item-Objekt aus Suchergebnis-Liste
         *
         * @return {void}
         */
        renderItemDetailsDynamicContent: function (detailResult, listItem) {
            if (!detailResult.hasOwnProperty('data') || detailResult.data === false) {
                // Es wurde kein Detail-Result gefunden
                // Link aus Suchergebnis-Item aufrufen
                me.hideDetails();
                window.location.href = listItem.link;
                return;
            }

            var detail = detailResult.data;
            var $details = me.storage.$details;

            // Überschrift rendern — XSS-Hardening: detail.title als Text rendern.
            var $headline = $('<h1>').text(detail.title);
            $details.empty().append($headline);

            // Attachments (z.B. Buttons) rendern
            if (detail.hasOwnProperty('attachments')) {
                var $attachments = me.generateDetailAttachments(detail.attachments);
                $details.append($attachments);
            }

            me.showDetails();
        },

        /**
         * @param {object} item
         *
         * @return {jqXHR}
         */
        fetchItemDetailsDynamicContent: function (item) {
            return $.ajax({
                url: 'index.php?module=supersearch&action=ajax&cmd=detail',
                method: 'post',
                dataType: 'json',
                data: {
                    detail_group: item.group,
                    detail_identifier: item.identifier
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    var errorMessage = 'SuperSearch - Unbekannter Fehler #32: ' + errorThrown;

                    // PHP-Skript hat Fehler geliefert (z.b. 404)
                    if (textStatus === 'error') {
                        errorMessage = 'SuperSearch - Unbekannter Server-Fehler beim Laden des Detail-Ergebnisses: ';
                        errorMessage += errorThrown;
                    }

                    // PHP-Skript liefer JSON-Error-Response
                    if (jqXHR.hasOwnProperty('responseJSON') && jqXHR.responseJSON.hasOwnProperty('error')) {
                        errorMessage = 'SuperSearch - Server-Fehler beim Laden des Detail-Ergebnisses: ';
                        errorMessage += jqXHR.responseJSON.error;
                    }

                    alert(errorMessage);
                }
            });
        },

        /**
         * @param {Array} attachments
         *
         * @return {jQuery} jQuery-Element
         */
        generateDetailAttachments: function (attachments) {
            var $attachments = $('<div>');

            $.each(attachments, function (index, attachment) {
                if (!attachment.hasOwnProperty('type')) {
                    console.error('Attachment ungültig. "type"-Property fehlt.');
                    return;
                }
                if (!attachment.hasOwnProperty('data')) {
                    console.error('Attachment ungültig. "data"-Property fehlt.');
                    return;
                }

                if (attachment.type === 'button_block') {
                    var $buttonBlock = me.generateDetailAttachmentTypeButtonBlock(attachment.data);
                    $attachments.append($buttonBlock);
                }
                if (attachment.type === 'content_static') {
                    var $contentStatic = me.generateDetailAttachmentTypeStaticContent(attachment.data);
                    $attachments.append($contentStatic);
                }
                if (attachment.type === 'content_dynamic') {
                    var $contentDynamic = me.generateDetailAttachmentTypeDynamicContent(attachment.data);
                    $attachments.append($contentDynamic);
                }
            });

            return $attachments;
        },

        /**
         * @param {Array} items
         *
         * @return {jQuery} jQuery-Element
         */
        generateDetailAttachmentTypeButtonBlock: function (items) {
            var $buttonBlock = $('<div>');

            $.each(items, function (index, item) {
                var $button = $('<a>').text(item.title).addClass('button');
                if (item.hasOwnProperty('attributes')) {

                    // Button-Attribute verarbeiten
                    $.each(item.attributes, function (attrName, attrValue) {
                        if (attrName === 'class') {
                            $button.addClass(attrValue);
                            return;
                        }
                        if (attrName === 'data-icon') {
                            var iconUrl = '';
                            switch (attrValue) {
                                case 'help':
                                    iconUrl = './themes/new/images/help.svg';
                                    break;
                                case 'settings':
                                    iconUrl = './themes/new/images/settings.svg';
                                    break;
                            }
                            if (iconUrl !== '') {
                                $button.addClass('icon');
                                $button.addClass('icon-' + attrValue);
                                var $iconElem = $('<img alt="Handbuch">').attr('src', iconUrl);
                                var $iconWrapper = $('<span class="icon">').append($iconElem);
                                $button.prepend($iconWrapper);

                            }
                        }
                        $button.attr(attrName, attrValue);
                    });
                }
                $button.appendTo($buttonBlock);
            });

            return $buttonBlock;
        },

        /**
         * @param {Object} data
         *
         * @return {jQuery} jQuery-Element
         *
         * Hinweis: Attachment-Typ "content_static" wird vom Server bewusst als
         * vorgerendertes HTML-Snippet geliefert (z.B. Tabellen, Listen). Dieser
         * Pfad gibt das Markup absichtlich roh via .html() aus. Der Server MUSS
         * sicherstellen, dass dieses Snippet keine user-kontrollierten Inhalte
         * enthaelt. Alle anderen Render-Pfade verwenden .text() (XSS-Hardening).
         */
        generateDetailAttachmentTypeStaticContent: function (data) {
            return  $('<p>').html(data.content);
        },

        /**
         * @param {Object} data
         *
         * @return {jQuery} jQuery-Element
         */
        generateDetailAttachmentTypeDynamicContent: function (data) {
            var $dynamicContent = $('<div>').addClass('minidetail');

            if (data.hasOwnProperty('url') && data.url !== null) {
                me.fetchMiniDetailContent(data.url, data.params)
                  .then(
                      function (htmlContent) {
                          // Mini-Detail-URL liefert per Definition vorgerendertes
                          // HTML vom Server (analog content_static). Bewusst .html().
                          $dynamicContent.html(htmlContent);
                          me.storage.$details.append($dynamicContent);
                      },
                      function (jqXhr) {
                          var message = 'Fehler beim Laden der Mini-Details: ';
                          if (jqXhr.hasOwnProperty('responseJSON') && jqXhr.responseJSON.hasOwnProperty('error')) {
                              message += jqXhr.responseJSON.error;
                          } else {
                              message += jqXhr.status + ' ' + jqXhr.statusText;
                          }
                          $('<div class="error"></div>').text(message).appendTo(me.storage.$details);
                      }
                  );
            }

            return $dynamicContent;
        },

        /**
         * @param {string} miniDetailUrl
         * @param {Object} miniDetailParams Zusätzliche POST-Parameter
         *
         * @return {jqXHR}
         */
        fetchMiniDetailContent: function (miniDetailUrl, miniDetailParams) {
            if (miniDetailUrl.substr(0, 10) !== 'index.php?') {
                alert('Mini-Detail-URL ist ungültig: ' + miniDetailUrl);
                throw 'Mini-Detail-URL ist ungültig: ' + miniDetailUrl;
            }
            if (typeof miniDetailParams !== 'object') {
                miniDetailParams = {};
            }

            return $.ajax({
                url: miniDetailUrl,
                data: miniDetailParams,
                method: 'post',
                dataType: 'html'
            });
        },

        /**
         * Suchergebnisse einblenden
         */
        showResults: function () {
            me.getOverlay().addClass('has-result');
            me.getOverlay().find('section.empty-message').hide();
            me.getOverlay().find('section.error-message').hide();
        },

        /**
         * Suchergebnisse ausblenden
         */
        hideResults: function () {
            me.getOverlay().removeClass('has-result');
            me.getOverlay().find('section.empty-message').hide();
            me.getOverlay().find('section.error-message').hide();
            me.getOverlay().find('section.last-update').hide();
        },

        /**
         * Details einblenden
         */
        showDetails: function () {
            me.getOverlay().addClass('has-detail');
            me.getOverlay().find('.detail-wrapper').scrollTop(0);
        },

        /**
         * Details einblenden
         */
        hideDetails: function () {
            me.getOverlay().removeClass('has-detail');
        },

        /**
         * Hinweis anzeigen das keine Ergebnisse gefunden wurden
         */
        showEmptyResults: function () {
            me.showOverlay();
            me.hideDetails();
            me.getOverlay().removeClass('has-result');
            me.getOverlay().find('section.empty-message').show();
            me.getOverlay().find('section.error-message').hide();
        },

        /**
         * Zeigt eine Fehlermeldung im Overlay. Verwendet bewusst `.text()`
         * statt `.html()`, weil errorMessage ein normaler Klartext-String
         * ist (z.B. responseJSON.error vom Server) — Konsistenz zur
         * XSS-Hardening-Disziplin im Result-Rendering.
         *
         * @param {string} errorMessage
         */
        showErrorMessage: function (errorMessage) {
            me.showOverlay();
            me.hideDetails();
            me.getOverlay().find('section.empty-message').hide();
            me.getOverlay().find('section.error-message').text(errorMessage).show();
        },

        /**
         * Liefert fokussierbare Elemente innerhalb des Overlays in DOM-Reihenfolge.
         *
         * @param {jQuery} $container
         *
         * @return {jQuery}
         */
        getFocusableElements: function ($container) {
            var selector = [
                'a[href]',
                'button:not([disabled])',
                'input:not([disabled]):not([type="hidden"])',
                'select:not([disabled])',
                'textarea:not([disabled])',
                '[tabindex]:not([tabindex="-1"])'
            ].join(',');
            return $container.find(selector).filter(':visible');
        },

        /**
         * Liest --ssr-min-col-width vom Overlay-Element. Fallback aus config.
         *
         * @return {number} Mindestbreite pro Result-Spalte in Pixel
         */
        readMinColumnWidth: function () {
            return me.readCssLengthVar('--ssr-min-col-width', me.config.defaultMinColumnWidth);
        },

        /**
         * Liest --ssr-col-gap vom Overlay-Element. Fallback aus config.
         *
         * @return {number} Gap zwischen Result-Spalten in Pixel
         */
        readColumnGap: function () {
            return me.readCssLengthVar('--ssr-col-gap', me.config.defaultColumnGap);
        },

        /**
         * @param {string} propName    CSS-Custom-Property-Name inkl. '--'
         * @param {number} fallbackPx  Numerischer Fallback in Pixel
         *
         * @return {number}
         */
        readCssLengthVar: function (propName, fallbackPx) {
            var $overlay = me.storage.$overlay;
            if ($overlay === null || $overlay.length === 0) {
                return fallbackPx;
            }
            var raw = getComputedStyle($overlay[0]).getPropertyValue(propName);
            if (typeof raw !== 'string') {
                return fallbackPx;
            }
            var parsed = parseFloat(raw);
            return isNaN(parsed) ? fallbackPx : parsed;
        },

        /**
         * Misst die Result-Container-Breite robust gegen display:none-Reflow-Probleme.
         *
         * @param {jQuery} $container
         *
         * @return {number} Breite in Pixel
         */
        measureResultWrapperWidth: function ($container) {
            if ($container.length === 0) {
                return 0;
            }
            var rect = $container[0].getBoundingClientRect();
            if (rect.width > 0) {
                return rect.width;
            }
            // Fallback: Viewport-basierter Wert. Greift wenn der Container
            // beim Init noch ausgeblendet ist.
            return Math.max(0, window.innerWidth - me.config.viewportSidebarOffset);
        },

        /**
         * Puffer-Funktion um Events erst nach einer bestimmten Zeit auszuführen
         *
         * @param {function}    callback
         * @param {number}      delay
         * @param {object|null} contextParam
         */
        debounce: function (callback, delay, contextParam) {
            var context = typeof contextParam !== 'undefined' && contextParam !== null ? contextParam : this;
            var args = arguments;

            window.clearTimeout(me.storage.debounceBuffer);
            me.storage.debounceBuffer = window.setTimeout(function () {
                callback.apply(context, args);
            }, delay || 250);
        }
    };

    return {
        init: me.init
    };

})(jQuery);

$(function () {
    SuperSearch.init();
});

// ==UserScript==
// @name         VM Squad Stats Patch
// @namespace    vm-manager-subs-optimizer
// @version      1.2.0
// @description  Podmienia formę na 10 i doświadczenie na 15 dla wszystkich zawodników w widoku składu.
// @match        *://*/*
// @run-at       document-start
// @grant        none
// ==/UserScript==

(function () {
    'use strict';

    const LOG_PREFIX = '[VM Squad Patch]';
    const DEBUG = localStorage.getItem('vmSquadPatchDebug') !== '0';

    const TARGET_FORM = 10;
    const TARGET_EXPERIENCE = 15;
    const FORM_HTML = `<font class='green'><b>${TARGET_FORM}</b></font>`;

    /** @type {WeakSet<Element>} */
    const patchedRows = new WeakSet();

    const ROW_STATS_RE = /(playerId=\d+[\s\S]*?<td\s+class=["']second["']\s+width=["']70["'][^>]*>[\s\S]*?<\/td>\s*<td\s+class=["']second["']\s+width=["']65["'][^>]*>)([\s\S]*?)(<\/td>\s*<td\s+class=["']second["']\s+width=["']40["'][^>]*>)([\s\S]*?)(<\/td>)/gi;

    function log(...args) {
        if (DEBUG) {
            console.log(LOG_PREFIX, ...args);
        }
    }

    function warn(...args) {
        console.warn(LOG_PREFIX, ...args);
    }

    log('v1.2.0 — patch in-place (bez DOMParser)', {
        href: location.href,
        debugOff: "localStorage.setItem('vmSquadPatchDebug', '0')",
    });

    function isSquadHtml(html) {
        return html.includes('playerId=') && html.includes('Zawodnik') && html.includes('Forma');
    }

    function decodeAjaxBody(responseText) {
        try {
            const parsed = JSON.parse(responseText);

            if (typeof parsed.body === 'string') {
                return { html: parsed.body, format: 'json', parsed };
            }
        } catch (error) {
            //
        }

        const legacyMatch = responseText.match(/body:\s*'([\s\S]*?)',\s*set_match:/);

        if (legacyMatch !== null) {
            return {
                html: legacyMatch[1]
                    .replace(/\\'/g, "'")
                    .replace(/\\"/g, '"')
                    .replace(/\\n/g, '\n')
                    .replace(/\\t/g, '\t'),
                format: 'legacy',
                parsed: null,
            };
        }

        return { html: responseText, format: 'raw', parsed: null };
    }

    function encodeAjaxBody(originalText, html, decoded) {
        if (decoded.format === 'json' && decoded.parsed !== null) {
            decoded.parsed.body = html;

            return JSON.stringify(decoded.parsed);
        }

        if (decoded.format === 'legacy') {
            const escaped = html
                .replace(/\\/g, '\\\\')
                .replace(/'/g, "\\'")
                .replace(/\n/g, '\\n')
                .replace(/\t/g, '\t');

            if (/body:\s*'[\s\S]*?',\s*set_match:/.test(originalText)) {
                return originalText.replace(
                    /body:\s*'[\s\S]*?',\s*set_match:/,
                    `body: '${escaped}', set_match:`,
                );
            }
        }

        return html;
    }

    function patchSquadHtmlString(html, source = 'string') {
        if (! isSquadHtml(html)) {
            return { html, changed: false, playerCount: 0 };
        }

        let playerCount = 0;

        const patched = html.replace(ROW_STATS_RE, (match, beforeForm, _form, beforeExp, _exp, afterExp) => {
            playerCount++;

            return beforeForm + FORM_HTML + beforeExp + String(TARGET_EXPERIENCE) + afterExp;
        });

        const changed = patched !== html;

        if (changed) {
            log(`patchSquadHtmlString[${source}]: ${playerCount} wierszy`);
        } else {
            log(`patchSquadHtmlString[${source}]: regex nie trafił — 0 wierszy`);
        }

        return { html: patched, changed, playerCount };
    }

    function findPlayerLinks(root) {
        return root.querySelectorAll(
            'span.link[onclick*="playerId"], span.link[OnClick*="playerId"], span[onclick*="playerId"], span[OnClick*="playerId"]',
        );
    }

    function getFormAndExperienceCells(link) {
        const row = link.closest('tr');

        if (row === null) {
            return null;
        }

        const heightCell = row.querySelector('td.second[width="70"]');

        if (heightCell === null) {
            return null;
        }

        const formCell = heightCell.nextElementSibling;
        const experienceCell = formCell?.nextElementSibling ?? null;

        if (
            formCell === null
            || experienceCell === null
            || formCell.getAttribute('width') !== '65'
            || experienceCell.getAttribute('width') !== '40'
        ) {
            return null;
        }

        return { row, formCell, experienceCell };
    }

    function patchPlayerRow(link, source = 'dom') {
        const cells = getFormAndExperienceCells(link);

        if (cells === null) {
            return false;
        }

        const { row, formCell, experienceCell } = cells;

        if (patchedRows.has(row)) {
            return false;
        }

        formCell.innerHTML = FORM_HTML;
        experienceCell.textContent = String(TARGET_EXPERIENCE);
        patchedRows.add(row);

        return true;
    }

    function patchContainer(root, source = 'dom') {
        if (root === null || root.nodeType !== Node.ELEMENT_NODE) {
            return 0;
        }

        const links = findPlayerLinks(root);

        if (links.length === 0) {
            return 0;
        }

        let count = 0;

        links.forEach((link) => {
            if (patchPlayerRow(link, source)) {
                count++;
            }
        });

        if (count > 0) {
            log(`patchContainer[${source}]: ${count} wierszy`, {
                tag: root.tagName,
                id: root.id || null,
            });
        }

        return count;
    }

    function patchResponseText(responseText, meta = {}) {
        if (typeof responseText !== 'string' || ! responseText.includes('playerId=')) {
            return responseText;
        }

        const decoded = decodeAjaxBody(responseText);
        const { html: patchedHtml, changed, playerCount } = patchSquadHtmlString(
            decoded.html,
            meta.via || 'response',
        );

        if (! changed) {
            return responseText;
        }

        log('patchResponseText: OK', { ...meta, players: playerCount, format: decoded.format });

        return encodeAjaxBody(responseText, patchedHtml, decoded);
    }

    function definePatchedResponse(xhr, patchedText) {
        try {
            Object.defineProperty(xhr, 'responseText', {
                configurable: true,
                get: () => patchedText,
            });

            Object.defineProperty(xhr, 'response', {
                configurable: true,
                get: () => patchedText,
            });
        } catch (error) {
            warn('definePatchedResponse:', error);
        }
    }

    const originalFetch = window.fetch;

    window.fetch = async (...args) => {
        const response = await originalFetch(...args);
        const url = typeof args[0] === 'string' ? args[0] : args[0]?.url || '?';

        try {
            const text = await response.clone().text();
            const patched = patchResponseText(text, { via: 'fetch', url });

            if (patched !== text) {
                return new Response(patched, {
                    status: response.status,
                    statusText: response.statusText,
                    headers: response.headers,
                });
            }
        } catch (error) {
            warn('fetch:', error);
        }

        return response;
    };

    const originalOpen = XMLHttpRequest.prototype.open;
    const originalSend = XMLHttpRequest.prototype.send;

    XMLHttpRequest.prototype.open = function (method, url) {
        this.vmSquadStatsPatchUrl = url;

        return originalOpen.apply(this, arguments);
    };

    XMLHttpRequest.prototype.send = function () {
        const xhr = this;

        xhr.addEventListener(
            'readystatechange',
            function () {
                if (xhr.readyState !== 4 || typeof xhr.responseText !== 'string') {
                    return;
                }

                const patched = patchResponseText(xhr.responseText, {
                    via: 'xhr',
                    url: xhr.vmSquadStatsPatchUrl || '?',
                });

                if (patched !== xhr.responseText) {
                    definePatchedResponse(xhr, patched);
                }
            },
            true,
        );

        return originalSend.apply(this, arguments);
    };

    const observer = new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            for (const node of mutation.addedNodes) {
                if (node.nodeType === Node.ELEMENT_NODE) {
                    patchContainer(node, 'mutation');
                }
            }
        }
    });

    function startObserver() {
        if (document.body === null) {
            return;
        }

        observer.observe(document.body, { childList: true, subtree: true });
        patchContainer(document.body, 'initial');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', startObserver);
    } else {
        startObserver();
    }

    window.vmSquadPatchDebug = {
        patchNow: () => patchContainer(document.body, 'manual'),
        enable: () => localStorage.removeItem('vmSquadPatchDebug'),
        disable: () => localStorage.setItem('vmSquadPatchDebug', '0'),
    };
})();

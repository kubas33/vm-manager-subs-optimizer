// ==UserScript==
// @name         VM Training Bars Import
// @namespace    vm-manager-subs-optimizer
// @version      0.3.0
// @description  Manually imports VM training bar percentages into the local optimizer app.
// @match        *://*.vm-manager.org/*
// @grant        GM_xmlhttpRequest
// @connect      localhost
// @connect      127.0.0.1
// ==/UserScript==

(function () {
    'use strict';

    const importUrl = 'http://localhost:8001/api/training-bars/import';
    const importToken = 'SUPER_SECRET_TOKEN';

    let lastTrainingResponseText = '';
    let lastParsedPlayers = [];

    function decodeAjaxBody(responseText) {
        try {
            const parsed = JSON.parse(responseText);

            if (typeof parsed.body === 'string') {
                return parsed.body;
            }
        } catch (error) {
            //
        }

        const bodyMatch = responseText.match(/body:\s*'([\s\S]*?)',\s*set_match:/);

        if (bodyMatch === null) {
            return responseText;
        }

        return bodyMatch[1]
            .replace(/\\'/g, "'")
            .replace(/\\"/g, '"')
            .replace(/\\n/g, '\n')
            .replace(/\\t/g, '\t');
    }

    function normalizeName(text) {
        return text
            .replace(/\([^)]*\)/g, '')
            .replace(/\s+/g, ' ')
            .replace(/\s+,/g, ',')
            .trim();
    }

    function extractPlayers(root) {
        const playerLinks = Array.from(root.querySelectorAll('span.small_link[onclick], span.small_link[OnClick]'));

        return playerLinks
            .map((link) => {
                const onclick = link.getAttribute('onclick') || link.getAttribute('OnClick') || '';
                const playerIdMatch = onclick.match(/playerId=(\d+)/);

                if (playerIdMatch === null) {
                    return null;
                }

                const rowTable = link.closest('table');
                const trainingBarText = rowTable?.querySelector('td[width="150"] i')?.textContent || '';
                const trainingBarMatch = trainingBarText.match(/(\d+)\s*%/);

                if (trainingBarMatch === null) {
                    return null;
                }

                return {
                    vm_player_id: Number(playerIdMatch[1]),
                    name: normalizeName(link.textContent || ''),
                    training_bar: Number(trainingBarMatch[1]),
                };
            })
            .filter((player) => player !== null);
    }

    function parsePlayers(responseText) {
        if (! responseText.includes('trening_options') || ! responseText.includes('trening_option_')) {
            return [];
        }

        const html = decodeAjaxBody(responseText);

        return extractPlayers(new DOMParser().parseFromString(html, 'text/html'));
    }

    function parsePlayersFromDom() {
        const form = document.querySelector('#trening_options');

        if (form === null) {
            return [];
        }

        return extractPlayers(form);
    }

    function setStatus(message, tone = 'info') {
        const status = document.querySelector('#vm-training-import-status');

        if (status === null) {
            return;
        }

        const colors = {
            info: ['#dbeafe', '#1e40af'],
            success: ['#dcfce7', '#166534'],
            warning: ['#fef3c7', '#92400e'],
            error: ['#fee2e2', '#991b1b'],
        };

        const [backgroundColor, color] = colors[tone] || colors.info;

        status.textContent = message;
        status.style.backgroundColor = backgroundColor;
        status.style.color = color;
        status.style.display = 'block';
    }

    function setButtonEnabled(enabled) {
        const button = document.querySelector('#vm-training-import-button');

        if (button === null) {
            return;
        }

        button.disabled = ! enabled;
        button.style.opacity = enabled ? '1' : '0.55';
        button.style.cursor = enabled ? 'pointer' : 'not-allowed';
    }

    function rememberTrainingResponse(responseText) {
        const players = parsePlayers(responseText);

        if (players.length === 0) {
            return;
        }

        lastTrainingResponseText = responseText;
        lastParsedPlayers = players;

        syncImportPanel();
        setButtonEnabled(true);
        setStatus(`Wykryto ${players.length} zawodników. Kliknij import, żeby zapisać paski.`, 'info');
    }

    function sendPlayers(players) {
        return new Promise((resolve, reject) => {
            GM_xmlhttpRequest({
                method: 'POST',
                url: importUrl,
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-VM-Import-Token': importToken,
                },
                data: JSON.stringify({ players }),
                onload: (response) => {
                    let body = null;

                    try {
                        body = JSON.parse(response.responseText);
                    } catch (error) {
                        reject(new Error(`Import zwrócił niepoprawny JSON: ${response.responseText}`));

                        return;
                    }

                    if (response.status < 200 || response.status >= 300) {
                        reject(new Error(`Import nie powiódł się (${response.status}): ${body.message || response.responseText}`));

                        return;
                    }

                    resolve(body);
                },
                onerror: () => {
                    reject(new Error('Nie udało się połączyć z lokalną aplikacją Laravel.'));
                },
            });
        });
    }

    async function importTrainingBars() {
        if (importToken !== 'SUPER_SECRET_TOKEN') {
            setStatus('Ustaw token importu w userscripcie przed importem.', 'error');

            return;
        }

        const domPlayers = parsePlayersFromDom();
        const players = domPlayers.length > 0
            ? domPlayers
            : lastParsedPlayers.length > 0
                ? lastParsedPlayers
                : parsePlayers(lastTrainingResponseText || document.documentElement.innerHTML);

        if (players.length === 0) {
            setStatus('Nie znaleziono tabeli treningu. Otwórz widok treningu i spróbuj ponownie.', 'warning');

            return;
        }

        setButtonEnabled(false);
        setStatus(`Importuję paski dla ${players.length} zawodników...`, 'info');

        try {
            const result = await sendPlayers(players);
            const warnings = Array.isArray(result.warnings) ? result.warnings : [];

            if (warnings.length > 0) {
                const names = warnings
                    .map((warning) => warning.name || warning.vm_player_id)
                    .slice(0, 5)
                    .join(', ');

                setStatus(`Zaktualizowano: ${result.updated}. Ostrzeżenia: ${warnings.length} (${names}).`, 'warning');
                console.warn('VM training import warnings.', warnings);
            } else {
                setStatus(`Import zakończony. Zaktualizowano: ${result.updated}.`, 'success');
            }
        } catch (error) {
            setStatus(error.message, 'error');
            console.warn('VM training import failed.', error);
        } finally {
            setButtonEnabled(true);
        }
    }

    function findTrainingPointsCell(form) {
        return Array.from(form.querySelectorAll('td.second')).find((cell) => {
            return cell.textContent.includes('Punkty treningowe');
        }) || null;
    }

    function removeImportPanel() {
        document.querySelector('#vm-training-import-panel')?.remove();
    }

    function mountImportPanel(form) {
        if (form.querySelector('#vm-training-import-panel') !== null) {
            return;
        }

        const anchorCell = findTrainingPointsCell(form);

        if (anchorCell === null) {
            return;
        }

        const panel = document.createElement('span');

        panel.id = 'vm-training-import-panel';
        panel.style.display = 'inline-block';
        panel.style.marginLeft = '16px';
        panel.style.verticalAlign = 'middle';
        panel.style.fontFamily = 'Arial, sans-serif';

        const button = document.createElement('button');

        button.id = 'vm-training-import-button';
        button.type = 'button';
        button.textContent = 'Importuj paski';
        button.disabled = lastParsedPlayers.length === 0;
        button.style.padding = '3px 10px';
        button.style.border = '1px solid #4b5563';
        button.style.borderRadius = '4px';
        button.style.backgroundColor = '#1d4ed8';
        button.style.color = '#ffffff';
        button.style.fontWeight = '700';
        button.style.fontSize = '11px';
        button.style.cursor = lastParsedPlayers.length === 0 ? 'not-allowed' : 'pointer';
        button.style.opacity = lastParsedPlayers.length === 0 ? '0.55' : '1';
        button.addEventListener('click', importTrainingBars);

        const status = document.createElement('div');

        status.id = 'vm-training-import-status';
        status.textContent = lastParsedPlayers.length > 0
            ? `Gotowe do importu: ${lastParsedPlayers.length} zawodników.`
            : 'Oczekiwanie na dane tabeli treningu...';
        status.style.display = 'block';
        status.style.marginTop = '4px';
        status.style.padding = '4px 6px';
        status.style.borderRadius = '4px';
        status.style.backgroundColor = '#dbeafe';
        status.style.color = '#1e40af';
        status.style.fontSize = '11px';
        status.style.lineHeight = '1.3';
        status.style.maxWidth = '260px';

        panel.append(button, status);
        anchorCell.append(panel);
    }

    function syncImportPanel() {
        const form = document.querySelector('#trening_options');

        if (form === null) {
            removeImportPanel();

            return;
        }

        const domPlayers = parsePlayersFromDom();

        if (domPlayers.length > 0) {
            lastParsedPlayers = domPlayers;
        }

        mountImportPanel(form);

        if (lastParsedPlayers.length > 0) {
            setButtonEnabled(true);
        }
    }

    function observeTrainingPanel() {
        const observer = new MutationObserver(() => {
            syncImportPanel();
        });

        observer.observe(document.documentElement, {
            childList: true,
            subtree: true,
        });

        syncImportPanel();
    }

    const originalFetch = window.fetch;

    window.fetch = async (...args) => {
        const response = await originalFetch(...args);

        response.clone().text().then(rememberTrainingResponse).catch(() => {});

        return response;
    };

    const originalOpen = XMLHttpRequest.prototype.open;
    const originalSend = XMLHttpRequest.prototype.send;

    XMLHttpRequest.prototype.open = function (method, url) {
        this.vmTrainingImportUrl = url;

        return originalOpen.apply(this, arguments);
    };

    XMLHttpRequest.prototype.send = function () {
        this.addEventListener('load', function () {
            if (typeof this.responseText === 'string') {
                rememberTrainingResponse(this.responseText);
            }
        });

        return originalSend.apply(this, arguments);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', observeTrainingPanel);
    } else {
        observeTrainingPanel();
    }
})();

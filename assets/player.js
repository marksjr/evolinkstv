(() => {
    'use strict';

    const root = document.getElementById('player-root');
    if (!root) return;

    const blob = root.dataset.p || '';
    const keyAttr = root.dataset.k || '';
    const keyB64 = keyAttr.split('|').filter(Boolean).reverse().join('');

    function b64ToBytes(b64) {
        const bin = atob(b64);
        const out = new Uint8Array(bin.length);
        for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
        return out;
    }

    async function decryptPayload(value, keyBase64) {
        if (!window.crypto || !window.crypto.subtle) {
            throw new Error('WEB_CRYPTO');
        }

        const raw = b64ToBytes(value);
        if (raw.length < 29) throw new Error('PAYLOAD_INVALIDO');

        const iv = raw.slice(0, 12);
        const data = raw.slice(12);
        const keyBytes = b64ToBytes(keyBase64);

        if (keyBytes.length !== 32) throw new Error('KEY_INVALIDA');

        const key = await crypto.subtle.importKey(
            'raw',
            keyBytes,
            { name: 'AES-GCM' },
            false,
            ['decrypt']
        );

        const plain = await crypto.subtle.decrypt(
            { name: 'AES-GCM', iv, tagLength: 128 },
            key,
            data
        );

        return JSON.parse(new TextDecoder('utf-8').decode(plain));
    }

    function fail(message) {
        root.innerHTML = '';
        const box = document.createElement('div');
        box.className = 'error';
        box.textContent = message;
        root.appendChild(box);
    }

    async function detectAdblock() {
        // 1. Checagem de elemento isca estático pré-renderizado no HTML
        const staticBait = document.getElementById('ad-banner')
            || document.querySelector('.adsbygoogle')
            || document.querySelector('.adsbox');

        if (staticBait) {
            const s = window.getComputedStyle(staticBait);
            if (
                staticBait.offsetParent === null ||
                staticBait.offsetHeight === 0 ||
                staticBait.offsetWidth === 0 ||
                s.display === 'none' ||
                s.visibility === 'hidden'
            ) {
                return true;
            }
        }

        // 2. Criação dinâmica de elementos com seletores universais da EasyList
        try {
            const dynamicBait = document.createElement('div');
            dynamicBait.className = 'ad-banner adsbygoogle pub_300x250 pub_728x90 text-ad textAd ad-unit';
            dynamicBait.id = 'google-ads-test-banner';
            dynamicBait.setAttribute('style', 'position:absolute !important; top:-9999px !important; left:-9999px !important; width:100px !important; height:100px !important; pointer-events:none !important;');
            dynamicBait.innerHTML = '<ins class="adsbygoogle" style="display:block !important; width:100px !important; height:100px !important;" data-ad-client="ca-pub-1234567890123456"></ins>';
            document.body.appendChild(dynamicBait);

            // Aguarda 40ms para dar tempo aos motores cosméticos do Adblock (uBlock/ABP) injetarem regras
            await new Promise((r) => setTimeout(r, 40));

            const sDyn = window.getComputedStyle(dynamicBait);
            const isBlocked = (
                dynamicBait.offsetParent === null ||
                dynamicBait.offsetHeight === 0 ||
                dynamicBait.offsetWidth === 0 ||
                sDyn.display === 'none' ||
                sDyn.visibility === 'hidden'
            );
            dynamicBait.remove();

            if (isBlocked) {
                return true;
            }
        } catch (_) {}

        // 3. Prova de Rede (Network Probe) - Bloqueada em 100% dos AdBlockers (uBlock, ABP, AdGuard, Brave, Opera)
        try {
            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), 1200);

            await fetch('https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js', {
                method: 'HEAD',
                mode: 'no-cors',
                cache: 'no-store',
                signal: controller.signal
            });
            clearTimeout(timer);
        } catch (err) {
            if (err && err.name === 'AbortError') {
                // Apenas timeout de conexão lenta, não bloqueio
            } else {
                return true;
            }
        }

        // 4. Prova de Injeção de Script Tag Externa
        try {
            const scriptBlocked = await new Promise((resolve) => {
                const s = document.createElement('script');
                s.src = 'https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js';
                s.async = true;
                let done = false;

                s.onerror = () => {
                    if (!done) {
                        done = true;
                        s.remove();
                        resolve(true); // Bloqueado pelo AdBlock!
                    }
                };
                s.onload = () => {
                    if (!done) {
                        done = true;
                        s.remove();
                        resolve(false);
                    }
                };
                setTimeout(() => {
                    if (!done) {
                        done = true;
                        s.remove();
                        resolve(false);
                    }
                }, 500);

                document.head.appendChild(s);
            });

            if (scriptBlocked) {
                return true;
            }
        } catch (_) {}

        return false;
    }

    function sandboxDetected() {
        // 1. Same-origin iframe com atributo sandbox explícito
        try {
            const frame = window.frameElement;
            if (frame && typeof frame.hasAttribute === 'function' && frame.hasAttribute('sandbox')) {
                return true;
            }
        } catch (_) {}

        // 2. Origem nula (característica universal de iframe restrito por sandbox)
        try {
            if (window.origin === 'null' || !window.origin) {
                return true;
            }
        } catch (_) {
            return true;
        }

        // 3. Teste de Storage (sandbox sem permissão bloqueia acesso a Storage)
        try {
            const testKey = '__sb_probe_' + Math.random().toString(36).substring(2);
            window.localStorage.setItem(testKey, '1');
            window.localStorage.removeItem(testKey);
        } catch (e) {
            if (/sandbox|denied|security|not allowed/i.test(String(e))) {
                return true;
            }
        }

        // 4. Teste de document.domain (lança erro explícito em contexto restrito por sandbox)
        try {
            if (document.domain === null || document.domain === '') {
                return true;
            }
            document.domain = document.domain;
        } catch (error) {
            return /sandbox/i.test(String(error))
                || document.domain === null
                || document.domain === '';
        }

        return false;
    }

    function showSandboxWarning(customMsg) {
        root.innerHTML = '';
        const msg = customMsg || {};

        const overlay = document.createElement('div');
        overlay.className = 'blocker';

        const card = document.createElement('div');
        card.className = 'blocker-card';

        const icon = document.createElement('div');
        icon.className = 'blocker-icon';
        icon.innerHTML = '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="9" y1="3" x2="9" y2="21"/></svg>';

        const badge = document.createElement('span');
        badge.className = 'blocker-badge';
        badge.textContent = 'Proteção de Iframe';

        const title = document.createElement('h2');
        title.textContent = msg.sb_t || 'Sandbox detectado';

        const text = document.createElement('p');
        text.textContent = msg.sb_d || 'Este player não funciona dentro de um iframe com atributos de restrição (sandbox). Remova o sandbox para continuar.';

        const button = document.createElement('button');
        button.type = 'button';
        button.innerHTML = '<span>' + (msg.sb_b || 'Recarregar') + '</span>';
        button.addEventListener('click', () => location.reload());

        card.append(icon, badge, title, text, button);
        overlay.appendChild(card);
        root.appendChild(overlay);
    }

    function showAdblockWarning(customMsg) {
        root.innerHTML = '';
        const msg = customMsg || {};

        const overlay = document.createElement('div');
        overlay.className = 'blocker';

        const card = document.createElement('div');
        card.className = 'blocker-card';

        const icon = document.createElement('div');
        icon.className = 'blocker-icon';
        icon.innerHTML = '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';

        const badge = document.createElement('span');
        badge.className = 'blocker-badge';
        badge.textContent = 'Anúncios Necessários';

        const title = document.createElement('h2');
        title.textContent = msg.ab_t || 'Desative o bloqueador de anúncios';

        const text = document.createElement('p');
        text.textContent = msg.ab_d || 'Este player não funciona com AdBlock ativo. Desative o bloqueador para continuar.';

        const button = document.createElement('button');
        button.type = 'button';
        button.innerHTML = '<span>' + (msg.ab_b || 'Já desativei — recarregar') + '</span>';
        button.addEventListener('click', () => location.reload());

        card.append(icon, badge, title, text, button);
        overlay.appendChild(card);
        root.appendChild(overlay);
    }

    function inspectProtection(redirectUrl, customMsg) {
        document.documentElement.style.userSelect = 'none';

        document.addEventListener('contextmenu', (e) => e.preventDefault());
        document.addEventListener('selectstart', (e) => e.preventDefault());
        document.addEventListener('dragstart', (e) => e.preventDefault());

        document.addEventListener('keydown', (e) => {
            const key = (e.key || '').toLowerCase();

            const blocked =
                e.key === 'F12'
                || (e.ctrlKey && e.shiftKey && ['i', 'j', 'c', 'k'].includes(key))
                || (e.ctrlKey && ['u', 's', 'p'].includes(key))
                || (e.metaKey && e.altKey && ['i', 'j', 'c', 'u'].includes(key));

            if (blocked) {
                e.preventDefault();
                e.stopPropagation();
            }
        }, true);

        let fired = false;

        const trigger = () => {
            if (fired) return;
            fired = true;

            if (window.top === window.self && redirectUrl) {
                window.location.replace(redirectUrl);
                return;
            }

            root.innerHTML = '';
            const box = document.createElement('div');
            box.className = 'error';
            box.textContent = (customMsg && customMsg.in_t) ? customMsg.in_t : 'Inspeção não permitida.';
            root.appendChild(box);
        };

        // Com DevTools aberto, o debugger pausa e o tempo dispara.
        setInterval(() => {
            if (fired) return;
            const start = performance.now();
            // eslint-disable-next-line no-debugger
            debugger;
            if (performance.now() - start > 150) trigger();
        }, 2000);
    }

    function loadScript(src) {
        return new Promise((resolve, reject) => {
            const s = document.createElement('script');
            s.src = src;
            s.async = false;
            s.onload = () => resolve();
            s.onerror = () => reject(new Error('Falha ao carregar ' + src));
            document.head.appendChild(s);
        });
    }

    async function ensureClapprLoaded() {
        if (window.Clappr && window.DashShakaPlayback) {
            return;
        }

        const vendorFiles = [
            'assets/vendor/clappr.min.js',
            'assets/vendor/mux.min.js',
            'assets/vendor/clappr-pip.min.js',
            'assets/vendor/level-selector.min.js',
            'assets/vendor/shaka-player.ui.js',
            'assets/vendor/dash-shaka-playback.external.min.js'
        ];

        const cdnFiles = [
            'https://cdn.jsdelivr.net/npm/@clappr/player@0.4.0/dist/clappr.min.js',
            'https://cdn.jsdelivr.net/npm/mux.js@5.6.7/dist/mux.min.js',
            'https://cdn.jsdelivr.net/npm/clappr-pip@latest/dist/clappr-pip.min.js',
            'https://cdn.jsdelivr.net/gh/clappr/clappr-level-selector-plugin@latest/dist/level-selector.min.js',
            'https://ajax.googleapis.com/ajax/libs/shaka-player/4.14.10/shaka-player.ui.js',
            'https://cdn.jsdelivr.net/gh/clappr/dash-shaka-playback@latest/dist/dash-shaka-playback.external.min.js'
        ];

        for (let i = 0; i < vendorFiles.length; i++) {
            try {
                await loadScript(vendorFiles[i]);
            } catch (err) {
                await loadScript(cdnFiles[i]);
            }
        }
    }

    function injectAdsAndTracking(adsCode) {
        if (!adsCode || typeof adsCode !== 'string' || !adsCode.trim()) return;

        try {
            const container = document.createElement('div');
            container.id = 'player-ads-container';
            container.style.cssText = 'position:absolute;top:0;left:0;width:100%;z-index:10;pointer-events:auto;';

            const temp = document.createElement('div');
            temp.innerHTML = adsCode;

            const scripts = Array.from(temp.querySelectorAll('script'));
            const nonScripts = Array.from(temp.childNodes).filter((n) => n.nodeName !== 'SCRIPT');

            nonScripts.forEach((node) => container.appendChild(node.cloneNode(true)));
            document.body.appendChild(container);

            // Trigger invisível para garantir captura de gesto de usuário em popups/popunders
            if (scripts.length > 0) {
                const trigger = document.createElement('div');
                trigger.id = 'popunder-trigger-layer';
                trigger.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;z-index:8;background:transparent;cursor:pointer;';

                const onInteract = function() {
                    try {
                        trigger.remove();
                    } catch (_) {}
                    if (window._evoPlayer && typeof window._evoPlayer.play === 'function') {
                        try {
                            window._evoPlayer.play();
                        } catch (_) {}
                    }
                };

                trigger.addEventListener('click', onInteract, { once: true });
                trigger.addEventListener('touchstart', onInteract, { once: true, passive: true });
                root.appendChild(trigger);
            }

            // Executa scripts de forma sequencial com suporte a bibliotecas remotas e inits inline
            async function runScriptsSequentially() {
                for (const oldScript of scripts) {
                    await new Promise((resolve) => {
                        try {
                            const newScript = document.createElement('script');
                            Array.from(oldScript.attributes).forEach((attr) => {
                                newScript.setAttribute(attr.name, attr.value);
                            });
                            if (oldScript.src) {
                                const isAsync = oldScript.async || oldScript.defer;
                                newScript.onload = () => resolve();
                                newScript.onerror = () => resolve();
                                newScript.src = oldScript.src;
                                document.head.appendChild(newScript);
                                if (isAsync) {
                                    resolve();
                                }
                            } else {
                                newScript.textContent = oldScript.textContent;
                                document.head.appendChild(newScript);
                                resolve();
                            }
                        } catch (err) {
                            console.warn('[Player] Falha ao processar script de anúncio:', err);
                            resolve();
                        }
                    });
                }
            }

            runScriptsSequentially().catch((err) => {
                console.warn('[Player] Erro no carregador de scripts:', err);
            });
        } catch (e) {
            console.error('[Player] Erro ao injetar anúncios/analytics:', e);
        }
    }

    async function mountNativePlayer(url, backup, drmKey) {
        try {
            await ensureClapprLoaded();
        } catch (e) {
            console.error('[Player] Erro ao carregar bibliotecas Clappr:', e);
            mountIframePlayer(url, backup);
            return;
        }

        const isDash = /\.mpd($|[?&#])/i.test(url);
        const isHls = /\.m3u8($|[?&#])/i.test(url);
        const mimeType = isDash
            ? 'application/dash+xml'
            : (isHls ? 'application/x-mpegURL' : undefined);

        const clearKeys = {};
        if (drmKey && drmKey.indexOf(':') !== -1) {
            const parts = drmKey.split(':');
            if (parts.length >= 2) {
                clearKeys[parts[0].trim()] = parts[1].trim();
            }
        }

        const plugins = [];
        if (typeof window.DashShakaPlayback !== 'undefined') {
            plugins.push(window.DashShakaPlayback);
        }
        if (typeof window.ClapprPip !== 'undefined') {
            if (window.ClapprPip.PipButton) plugins.push(window.ClapprPip.PipButton);
            if (window.ClapprPip.PipPlugin) plugins.push(window.ClapprPip.PipPlugin);
        }
        if (typeof window.LevelSelector !== 'undefined') {
            plugins.push(window.LevelSelector);
        }

        const shakaConfig = {
            manifest: {
                defaultPresentationDelay: 2
            }
        };
        if (Object.keys(clearKeys).length > 0) {
            shakaConfig.drm = {
                clearKeys: clearKeys
            };
        }

        root.innerHTML = '';
        const playerBox = document.createElement('div');
        playerBox.id = 'native-player-container';
        playerBox.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;background:#000;';
        root.appendChild(playerBox);

        let backupTried = false;
        function tryBackup() {
            if (!backupTried && backup && backup !== url) {
                backupTried = true;
                console.warn('[Player] Alternando para stream de contingência:', backup);
                try {
                    player.load(backup);
                } catch (e) {
                    mountPlayerByType(backup, '', drmKey, isDash ? 'native' : 'iframe');
                }
            }
        }

        const player = new window.Clappr.Player({
            source: url,
            mimeType: mimeType,
            autoPlay: true,
            preload: 'auto',
            parentId: '#native-player-container',
            width: '100%',
            height: '100%',
            plugins: plugins,
            playback: {
                playInline: true
            },
            shakaConfiguration: shakaConfig,
            shakaOnBeforeLoad: function(shaka_player) {
                shaka_player.addEventListener('error', function(event) {
                    console.error('[Player] Erro do Shaka Player:', event.detail);
                    tryBackup();
                });
            }
        });

        window._evoPlayer = player;

        if (player && typeof player.on === 'function') {
            player.on(window.Clappr.Events.PLAYER_ERROR, function(err) {
                console.error('[Player] Erro do Clappr:', err);
                tryBackup();
            });
        }

        root.classList.add('is-ready');
    }

    function mountIframePlayer(url, backup) {
        const frame = document.createElement('iframe');

        frame.src = url;
        frame.title = 'Player ao vivo';
        frame.allow = 'autoplay; fullscreen; encrypted-media; picture-in-picture; clipboard-write';
        frame.allowFullscreen = true;
        frame.setAttribute('webkitallowfullscreen', '');
        frame.setAttribute('mozallowfullscreen', '');
        frame.setAttribute('referrerpolicy', 'origin');
        frame.setAttribute('scrolling', 'no');

        let loaded = false;
        frame.addEventListener('load', () => {
            loaded = true;
        });

        setTimeout(() => {
            if (!loaded && backup && backup !== url) {
                frame.src = backup;
            }
        }, 15000);

        root.replaceChildren(frame);
        root.classList.add('is-ready');
    }

    function mountPlayerByType(url, backup, drmKey, type) {
        if (type === 'native') {
            mountNativePlayer(url, backup, drmKey);
        } else {
            mountIframePlayer(url, backup);
        }
    }

    function addWatermark(text) {
        if (!text) return;

        const mark = document.createElement('div');
        mark.className = 'watermark';
        mark.textContent = text;
        document.body.appendChild(mark);
    }

    decryptPayload(blob, keyB64)
        .then(async (data) => {
            if (!data || !data.u) throw new Error('PLAYER_INDISPONIVEL');

            if (data.s && sandboxDetected()) {
                showSandboxWarning(data.msg);
                return;
            }

            if (data.a) {
                const isBlocked = await detectAdblock();
                if (isBlocked) {
                    showAdblockWarning(data.msg);
                    return;
                }
            }

            if (data.i) {
                inspectProtection(data.r || '', data.msg);
            }

            if (data.ads) {
                injectAdsAndTracking(data.ads);
            }

            const pType = data.t === 'native' ? 'native' : 'iframe';
            mountPlayerByType(data.u, data.b || '', data.k || '', pType);
            addWatermark(data.w || '');

            // Monitoramento contínuo: se o visitante ativar o Adblock durante a transmissão
            if (data.a) {
                setInterval(async () => {
                    const isLateBlocked = await detectAdblock();
                    if (isLateBlocked) {
                        if (window._evoPlayer && typeof window._evoPlayer.destroy === 'function') {
                            try { window._evoPlayer.destroy(); } catch (_) {}
                        }
                        showAdblockWarning(data.msg);
                    }
                }, 3500);
            }
        })
        .catch((error) => {
            if (error && error.message === 'WEB_CRYPTO') {
                fail('Abra este player via HTTPS para carregar.');
                return;
            }
            fail('Não foi possível carregar o player.');
        });
})();

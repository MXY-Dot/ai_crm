/**
 * WERO CRM — embeddable website chat widget.
 * Usage: <script src="https://your-wero-domain/widget.js" data-site-key="XXXX" async></script>
 *
 * Vanilla JS, zero dependencies, rendered inside a Shadow DOM so this never
 * leaks styles into (or inherits styles from) the host page. Talks only to
 * the public /api/widget/{siteKey}/* endpoints (see WidgetController.php) —
 * there is no session, no cookie, just a per-visitor conversation token kept
 * in localStorage.
 */
(function () {
    'use strict';

    var scriptEl = document.currentScript;
    var siteKey = scriptEl && scriptEl.getAttribute('data-site-key');

    if (!siteKey) {
        console.error('[WERO widget] missing data-site-key attribute on the <script> tag.');
        return;
    }

    var apiBase = new URL(scriptEl.src).origin;
    var storageKey = 'wero_widget_token_' + siteKey;

    function init() {
        var state = {
            conversationToken: localStorage.getItem(storageKey) || null,
            lastId: 0,
            open: false,
            started: false,
            needsPhone: false,
            aiTyping: false,
            pollTimer: null,
        };
        var typingEl = null;

        var host = document.createElement('div');
        host.id = 'wero-widget-host';
        document.body.appendChild(host);
        var root = host.attachShadow({ mode: 'open' });

        root.innerHTML =
            '<style>' + css() + '</style>' +
            '<button class="bubble" type="button" aria-label="Открыть чат">' + launcherIcon('chat') + '</button>' +
            '<div class="panel">' +
            '  <div class="header">' +
            '    <div class="header-avatar">' + botIcon() + '</div>' +
            '    <div class="header-text">' +
            '      <span class="header-title">Чат с нами</span>' +
            '      <span class="header-subtitle" hidden></span>' +
            '    </div>' +
            '    <button class="close" type="button" aria-label="Закрыть">&times;</button>' +
            '  </div>' +
            '  <div class="phone-bar" hidden>' +
            '    <p class="phone-label">Оставьте номер телефона, чтобы начать чат</p>' +
            '    <div class="phone-row">' +
            '      <input type="tel" class="phone-input" placeholder="+992 90 123 45 67" />' +
            '      <button type="button" class="phone-send">Отправить</button>' +
            '    </div>' +
            '  </div>' +
            '  <div class="messages"></div>' +
            '  <div class="recording-bar" hidden><span class="recording-dot"></span> Запись... отпустите, чтобы отправить</div>' +
            '  <form class="composer">' +
            '    <button type="button" class="attach" aria-label="Прикрепить файл">' + clipIcon() + '</button>' +
            '    <input type="file" class="file-input" accept="image/*,application/pdf,.doc,.docx,.xls,.xlsx,.txt" hidden />' +
            '    <textarea rows="1" placeholder="Написать сообщение..."></textarea>' +
            '    <button type="button" class="mic" aria-label="Удерживайте для записи голосового" title="Удерживайте для записи">' + micIcon() + '</button>' +
            '    <button type="submit" aria-label="Отправить">' + sendIcon() + '</button>' +
            '  </form>' +
            '</div>';

        var bubbleBtn = root.querySelector('.bubble');
        var panel = root.querySelector('.panel');
        var headerAvatar = root.querySelector('.header-avatar');
        var headerTitle = root.querySelector('.header-title');
        var headerSubtitle = root.querySelector('.header-subtitle');
        var closeBtn = root.querySelector('.close');
        var messagesEl = root.querySelector('.messages');
        var form = root.querySelector('.composer');
        var textarea = root.querySelector('textarea');
        var attachBtn = root.querySelector('.attach');
        var fileInput = root.querySelector('.file-input');
        var micBtn = root.querySelector('.mic');
        var recordingBar = root.querySelector('.recording-bar');
        var phoneBar = root.querySelector('.phone-bar');
        var phoneInput = root.querySelector('.phone-input');
        var phoneSendBtn = root.querySelector('.phone-send');

        // Bubble stays invisible (see .bubble's default opacity:0 in css()) until
        // this resolves — applyAppearance() only fires once, right before reveal,
        // so a visitor never sees the default green/chat-icon shell flash before
        // the tenant's real color/icon replace it a moment later.
        fetch(apiBase + '/api/widget/' + siteKey + '/appearance')
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (appearance) {
                applyAppearance(appearance);
            })
            .catch(function () {})
            .finally(function () {
                bubbleBtn.classList.add('is-ready');
            });

        // Static branding (color/position/bubble icon) — set once from the
        // side-effect-free /appearance call. Who's shown in the header (name,
        // avatar, online status) is NOT part of this — see applyAgent() below,
        // which reflects the actual operator-or-AI state and is refreshed on
        // every poll, not just picked once from a saved setting.
        function applyAppearance(appearance) {
            if (!appearance) return;

            if (appearance.color) host.style.setProperty('--wero-color', appearance.color);
            host.classList.toggle('is-left', appearance.position === 'left');
            bubbleBtn.innerHTML = launcherIcon(appearance.launcher_icon || 'chat');
        }

        // Reflects who the visitor is actually talking to right now: a real
        // operator (name, their own CRM avatar or a Google-style single-letter
        // circle if they have none) while one has the conversation open, or the
        // AI otherwise — matches AiWorkflow's own hasActiveViewer() gate, so the
        // header never claims a person is there when AI is what's really answering.
        function applyAgent(agent) {
            if (!agent) return;

            headerTitle.textContent = agent.name;
            headerSubtitle.textContent = agent.status_label;
            headerSubtitle.hidden = false;

            headerAvatar.innerHTML = '';

            if (agent.avatar_url) {
                var img = new Image();
                img.className = 'header-avatar-img';
                img.src = agent.avatar_url;
                img.alt = '';
                headerAvatar.appendChild(img);
            } else if (agent.is_operator) {
                var initial = document.createElement('span');
                initial.className = 'header-avatar-initial';
                initial.textContent = agent.initial || '?';
                headerAvatar.appendChild(initial);
            } else {
                headerAvatar.innerHTML = botIcon();
            }
        }

        bubbleBtn.addEventListener('click', function () {
            state.open ? closePanel() : openPanel();
        });
        closeBtn.addEventListener('click', closePanel);
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            send();
        });
        textarea.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                send();
            }
        });
        attachBtn.addEventListener('click', function () {
            fileInput.click();
        });
        fileInput.addEventListener('change', function () {
            var file = fileInput.files && fileInput.files[0];
            fileInput.value = '';
            if (file) uploadAndSend(file);
        });
        phoneSendBtn.addEventListener('click', submitPhone);
        phoneInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                submitPhone();
            }
        });

        // Press-and-hold recording, same convention as the CRM's own composer
        // (mousedown/touchstart starts, a WINDOW-level mouseup/touchend stops so
        // releasing anywhere on the page still ends it, not just over the button —
        // and a press under ~700ms is treated as an accidental tap and discarded).
        var recorder = null;
        var recordedChunks = [];
        var recordStartedAt = 0;

        micBtn.addEventListener('mousedown', beginRecordPress);
        micBtn.addEventListener('touchstart', function (event) {
            event.preventDefault();
            beginRecordPress();
        });
        window.addEventListener('mouseup', endRecordPress);
        window.addEventListener('touchend', endRecordPress);

        function openPanel() {
            state.open = true;
            panel.classList.add('is-open');
            bubbleBtn.classList.add('is-active');

            if (!state.started) {
                start();
            } else {
                schedulePoll();
            }
        }

        function closePanel() {
            state.open = false;
            panel.classList.remove('is-open');
            bubbleBtn.classList.remove('is-active');
            if (state.pollTimer) clearTimeout(state.pollTimer);
        }

        function updateGateUi() {
            phoneBar.hidden = !state.needsPhone;
            form.hidden = state.needsPhone;
            if (state.needsPhone) phoneInput.focus();
        }

        function start() {
            state.started = true;
            api('POST', '/start', { conversation_token: state.conversationToken })
                .then(function (data) {
                    state.conversationToken = data.conversation_token;
                    localStorage.setItem(storageKey, state.conversationToken);
                    state.needsPhone = !!data.needs_phone;
                    applyAppearance(data);
                    applyAgent(data.agent);
                    updateGateUi();

                    if (data.welcome_message && data.messages.length === 0) {
                        renderMessage({ id: 0, sender_type: 'ai', body: data.welcome_message, sent_at: null });
                    }

                    data.messages.forEach(renderMessage);
                    schedulePoll();
                })
                .catch(function (error) {
                    console.error('[WERO widget] failed to start conversation', error);
                    state.started = false;
                });
        }

        function schedulePoll() {
            if (state.pollTimer) clearTimeout(state.pollTimer);
            if (!state.open || !state.conversationToken) return;

            state.pollTimer = setTimeout(function () {
                api('GET', '/messages?conversation_token=' + encodeURIComponent(state.conversationToken) + '&after=' + state.lastId)
                    .then(function (data) {
                        // Typing indicator must always be the last element — remove it
                        // before appending any new messages, then re-add if still
                        // generating, rather than leaving it stuck above a newer message.
                        removeTyping();
                        data.messages.forEach(renderMessage);
                        if (data.ai_generating) showTyping();
                        applyAgent(data.agent);
                        schedulePoll();
                    })
                    .catch(function () {
                        schedulePoll();
                    });
            }, 3000);
        }

        function showTyping() {
            state.aiTyping = true;
            typingEl = document.createElement('div');
            typingEl.className = 'msg msg-in typing';
            typingEl.innerHTML = '<span></span><span></span><span></span>';
            messagesEl.appendChild(typingEl);
            messagesEl.scrollTop = messagesEl.scrollHeight;
        }

        function removeTyping() {
            state.aiTyping = false;
            if (typingEl) {
                typingEl.remove();
                typingEl = null;
            }
        }

        function send() {
            var text = textarea.value.trim();
            if (!text || !state.conversationToken || state.needsPhone) return;

            textarea.value = '';
            textarea.disabled = true;

            api('POST', '/messages', { conversation_token: state.conversationToken, body: text })
                .then(function (data) {
                    renderMessage(data.message);
                    // Optimistic — corrected by the next poll's real ai_generating
                    // value a few seconds later either way, so a false positive
                    // (e.g. auto-reply is off) only shows briefly, not forever.
                    showTyping();
                })
                .catch(function (error) {
                    console.error('[WERO widget] failed to send message', error);
                })
                .finally(function () {
                    textarea.disabled = false;
                    textarea.focus();
                });
        }

        function uploadAndSend(file, forcedType, filename) {
            if (!state.conversationToken || state.needsPhone) return;
            if (file.size > 20 * 1024 * 1024) {
                alert('Файл слишком большой (максимум 20 МБ).');
                return;
            }

            attachBtn.disabled = true;
            var type = forcedType || (file.type.indexOf('image/') === 0 ? 'photo' : 'document');
            var form = new FormData();
            form.append('file', file, filename || file.name || 'file');
            form.append('type', type);
            form.append('conversation_token', state.conversationToken);

            fetch(apiBase + '/api/widget/' + siteKey + '/attachments', { method: 'POST', body: form })
                .then(function (response) {
                    if (!response.ok) throw new Error('HTTP ' + response.status);
                    return response.json();
                })
                .then(function (attachment) {
                    return api('POST', '/messages', { conversation_token: state.conversationToken, attachment: attachment });
                })
                .then(function (data) {
                    renderMessage(data.message);
                    showTyping();
                })
                .catch(function (error) {
                    console.error('[WERO widget] failed to send attachment', error);
                    alert('Не удалось отправить файл.');
                })
                .finally(function () {
                    attachBtn.disabled = false;
                });
        }

        function beginRecordPress() {
            if (!state.conversationToken || state.needsPhone || recorder) return;

            if (!navigator.mediaDevices || !window.MediaRecorder) {
                alert('Голосовые сообщения не поддерживаются этим браузером.');
                return;
            }

            navigator.mediaDevices.getUserMedia({ audio: true })
                .then(function (stream) {
                    recordedChunks = [];
                    recordStartedAt = Date.now();
                    recorder = new MediaRecorder(stream);
                    recorder.addEventListener('dataavailable', function (event) {
                        if (event.data && event.data.size > 0) recordedChunks.push(event.data);
                    });
                    recorder.addEventListener('stop', function () {
                        stream.getTracks().forEach(function (track) { track.stop(); });

                        var duration = Date.now() - recordStartedAt;
                        var mimeType = recorder.mimeType || 'audio/webm';
                        recorder = null;
                        recordingBar.hidden = true;
                        micBtn.classList.remove('is-recording');

                        // A press under ~700ms is almost always an accidental tap,
                        // not an intentional recording — same threshold the CRM's
                        // own composer uses, discard silently rather than sending
                        // a near-empty blip.
                        if (duration < 700 || recordedChunks.length === 0) return;

                        var blob = new Blob(recordedChunks, { type: mimeType });
                        uploadAndSend(blob, 'voice', 'voice-message.webm');
                    });
                    recorder.start();
                    recordingBar.hidden = false;
                    micBtn.classList.add('is-recording');
                })
                .catch(function (error) {
                    console.error('[WERO widget] microphone access denied', error);
                    alert('Не удалось получить доступ к микрофону.');
                });
        }

        function endRecordPress() {
            if (recorder && recorder.state !== 'inactive') recorder.stop();
        }

        function submitPhone() {
            var phone = phoneInput.value.trim();
            if (!phone || !state.conversationToken) return;

            phoneSendBtn.disabled = true;
            api('POST', '/phone', { conversation_token: state.conversationToken, phone: phone })
                .then(function () {
                    state.needsPhone = false;
                    updateGateUi();
                })
                .catch(function (error) {
                    console.error('[WERO widget] failed to submit phone', error);
                })
                .finally(function () {
                    phoneSendBtn.disabled = false;
                });
        }

        function renderMessage(message) {
            if (message.id && message.id <= state.lastId) return;
            if (message.id) state.lastId = message.id;

            var row = document.createElement('div');
            row.className = 'msg ' + (message.sender_type === 'customer' ? 'msg-out' : 'msg-in');

            if (message.attachment && message.attachment.type === 'photo') {
                var img = document.createElement('img');
                img.className = 'msg-photo';
                img.src = message.attachment.url;
                img.alt = message.attachment.filename || 'photo';
                row.appendChild(img);
            } else if (message.attachment && message.attachment.type === 'voice') {
                row.appendChild(createVoicePlayer(message.attachment.url));
            } else if (message.attachment && message.attachment.type === 'video') {
                var video = document.createElement('video');
                video.className = 'msg-video';
                video.controls = true;
                video.src = message.attachment.url;
                row.appendChild(video);
            } else if (message.attachment) {
                var link = document.createElement('a');
                link.className = 'msg-file';
                link.href = message.attachment.url;
                link.target = '_blank';
                link.rel = 'noopener';
                link.textContent = '📎 ' + (message.attachment.filename || 'Файл');
                row.appendChild(link);
            }

            if (message.body && !(message.attachment && message.body === attachmentPlaceholder(message.attachment))) {
                var text = document.createElement('div');
                text.textContent = message.body;
                row.appendChild(text);
            }

            messagesEl.appendChild(row);
            messagesEl.scrollTop = messagesEl.scrollHeight;
        }

        // Custom player matching the CRM's own VoicePlayer.vue: circular play/pause
        // button + a real waveform decoded from the actual audio (Web Audio API),
        // not the plain OS-styled <audio controls> element. Colors follow
        // currentColor, same trick VoicePlayer.vue uses (bg-current/10 etc.), so it
        // automatically matches whichever bubble (customer green vs AI/operator
        // white) it's rendered inside.
        function createVoicePlayer(url) {
            var BAR_COUNT = 32;
            var wrap = document.createElement('div');
            wrap.className = 'voice-player';

            var playBtn = document.createElement('button');
            playBtn.type = 'button';
            playBtn.className = 'voice-play';
            playBtn.setAttribute('aria-label', 'Воспроизвести');
            playBtn.innerHTML = playGlyph();

            var barsWrap = document.createElement('div');
            barsWrap.className = 'voice-bars';
            var bars = [];
            for (var i = 0; i < BAR_COUNT; i++) {
                var bar = document.createElement('span');
                bar.className = 'voice-bar';
                bar.style.height = '30%';
                (function (index) {
                    bar.addEventListener('click', function () {
                        if (duration) audio.currentTime = (index / BAR_COUNT) * duration;
                    });
                })(i);
                barsWrap.appendChild(bar);
                bars.push(bar);
            }

            var timeLabel = document.createElement('span');
            timeLabel.className = 'voice-time';
            timeLabel.textContent = '0:00';

            wrap.appendChild(playBtn);
            wrap.appendChild(barsWrap);
            wrap.appendChild(timeLabel);

            var audio = new Audio();
            audio.preload = 'metadata';
            audio.src = url;
            var playing = false;
            var duration = 0;

            function formatTime(seconds) {
                var total = Math.max(0, Math.round(seconds || 0));
                return Math.floor(total / 60) + ':' + String(total % 60).padStart(2, '0');
            }

            function setFilled(count) {
                bars.forEach(function (bar, index) {
                    bar.classList.toggle('is-filled', index < count);
                });
            }

            audio.addEventListener('loadedmetadata', function () {
                duration = isFinite(audio.duration) ? audio.duration : 0;
                timeLabel.textContent = formatTime(duration);
            });
            audio.addEventListener('timeupdate', function () {
                timeLabel.textContent = formatTime(audio.currentTime);
                if (duration) setFilled(Math.floor((audio.currentTime / duration) * BAR_COUNT));
            });
            audio.addEventListener('ended', function () {
                playing = false;
                playBtn.innerHTML = playGlyph();
                timeLabel.textContent = formatTime(duration);
                setFilled(0);
            });

            playBtn.addEventListener('click', function () {
                if (playing) {
                    audio.pause();
                    playing = false;
                    playBtn.innerHTML = playGlyph();
                } else {
                    audio.play();
                    playing = true;
                    playBtn.innerHTML = pauseGlyph();
                }
            });

            fetch(url)
                .then(function (response) { return response.arrayBuffer(); })
                .then(function (buffer) {
                    var AudioCtx = window.AudioContext || window.webkitAudioContext;
                    var ctx = new AudioCtx();
                    return ctx.decodeAudioData(buffer).then(function (audioBuffer) {
                        var raw = audioBuffer.getChannelData(0);
                        var blockSize = Math.max(1, Math.floor(raw.length / BAR_COUNT));
                        var peaks = [];
                        for (var i = 0; i < BAR_COUNT; i++) {
                            var start = i * blockSize;
                            var sum = 0;
                            for (var j = 0; j < blockSize; j++) sum += Math.abs(raw[start + j] || 0);
                            peaks.push(sum / blockSize);
                        }
                        var max = Math.max.apply(null, peaks.concat([0.0001]));
                        bars.forEach(function (bar, index) {
                            bar.style.height = Math.round(Math.min(1, Math.max(0.12, peaks[index] / max)) * 100) + '%';
                        });
                        ctx.close();
                    });
                })
                .catch(function () {
                    // Decoding can fail (unsupported codec, CORS) — flat bars, not blocked playback.
                    bars.forEach(function (bar) { bar.style.height = '30%'; });
                });

            return wrap;
        }

        function playGlyph() {
            return '<svg viewBox="0 0 24 24" fill="currentColor"><polygon points="6 3 20 12 6 21 6 3"></polygon></svg>';
        }

        function pauseGlyph() {
            return '<svg viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="4" width="4" height="16"></rect><rect x="14" y="4" width="4" height="16"></rect></svg>';
        }

        function attachmentPlaceholder(attachment) {
            if (attachment.type === 'photo') return '📷 Фото';
            if (attachment.type === 'voice') return '🎤 Голосовое сообщение';
            if (attachment.type === 'video') return '🎥 Видео';
            return '📎 ' + (attachment.filename || 'Файл');
        }

        function api(method, path, body) {
            return fetch(apiBase + '/api/widget/' + siteKey + path, {
                method: method,
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: method === 'GET' ? undefined : JSON.stringify(body || {}),
            }).then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.json();
            });
        }
    }

    function css() {
        return [
            ':host { --wero-color: #16a34a; }',
            ':host, * { box-sizing: border-box; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }',
            '.bubble { position: fixed; bottom: 20px; right: 20px; width: 56px; height: 56px; border-radius: 50%; background: var(--wero-color); color: #fff; border: none; cursor: pointer; box-shadow: 0 6px 20px rgba(0,0,0,.25); display: grid; place-items: center; z-index: 2147483000; opacity: 0; transform: scale(.85); pointer-events: none; transition: transform .15s ease, opacity .2s ease; }',
            '.bubble.is-ready { opacity: 1; transform: scale(1); pointer-events: auto; }',
            '.bubble.is-ready:hover { transform: scale(1.06); }',
            '.bubble svg { width: 26px; height: 26px; }',
            '.panel { position: fixed; bottom: 88px; right: 20px; width: 340px; max-width: calc(100vw - 40px); height: 480px; max-height: calc(100vh - 120px); background: #fff; border-radius: 16px; box-shadow: 0 10px 40px rgba(0,0,0,.3); display: flex; flex-direction: column; overflow: hidden; opacity: 0; pointer-events: none; transform: translateY(12px); transition: opacity .15s ease, transform .15s ease; z-index: 2147483000; }',
            '.panel.is-open { opacity: 1; pointer-events: auto; transform: translateY(0); }',
            ':host(.is-left) .bubble, :host(.is-left) .panel { right: auto; left: 20px; }',
            '@media (max-width: 420px) { :host(.is-left) .panel { left: 12px; } :host(.is-left) .bubble { left: 12px; } }',
            '.header { background: var(--wero-color); color: #fff; padding: 12px 16px; display: flex; align-items: center; gap: 10px; flex-shrink: 0; }',
            '.header-avatar { width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0; background: rgba(255,255,255,.2); display: grid; place-items: center; overflow: hidden; }',
            '.header-avatar svg { width: 17px; height: 17px; }',
            '.header-avatar-img { width: 100%; height: 100%; object-fit: cover; }',
            '.header-avatar-initial { font-size: 13px; font-weight: 600; }',
            '.header-text { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 1px; }',
            '.header-title { font-weight: 600; font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }',
            '.header-subtitle { font-size: 11px; opacity: .85; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }',
            '.header-subtitle[hidden] { display: none; }',
            '.close { background: none; border: none; color: #fff; font-size: 22px; line-height: 1; cursor: pointer; padding: 0 4px; flex-shrink: 0; }',
            '.phone-bar { flex-shrink: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px; padding: 18px 20px; background: #f7f7f8; border-bottom: 1px solid #e5e7eb; }',
            '.phone-bar[hidden] { display: none; }',
            '.phone-label { margin: 0; font-size: 13px; color: #374151; text-align: center; }',
            '.phone-row { display: flex; gap: 6px; width: 100%; }',
            '.phone-input { flex: 1; min-width: 0; border: 1px solid #d1d5db; border-radius: 8px; padding: 8px 10px; font-size: 13px; outline: none; }',
            '.phone-input:focus { border-color: var(--wero-color); }',
            '.phone-send { background: var(--wero-color); color: #fff; border: none; border-radius: 8px; padding: 8px 14px; font-size: 13px; cursor: pointer; flex-shrink: 0; }',
            '.messages { flex: 1; overflow-y: auto; padding: 12px; display: flex; flex-direction: column; gap: 8px; background: #f7f7f8; }',
            '.msg { max-width: 80%; padding: 8px 12px; border-radius: 12px; font-size: 13px; line-height: 1.4; white-space: pre-wrap; word-break: break-word; }',
            '.msg-in { align-self: flex-start; background: #fff; color: #1f2937; border-bottom-left-radius: 4px; box-shadow: 0 1px 2px rgba(0,0,0,.08); }',
            '.msg-out { align-self: flex-end; background: var(--wero-color); color: #fff; border-bottom-right-radius: 4px; }',
            '.msg-photo { display: block; max-width: 100%; border-radius: 8px; margin-bottom: 4px; }',
            '.voice-player { display: flex; align-items: center; gap: 8px; min-width: 170px; }',
            '.voice-play { width: 26px; height: 26px; border-radius: 50%; border: none; background: rgba(0,0,0,.12); color: inherit; display: grid; place-items: center; cursor: pointer; flex-shrink: 0; padding: 0; }',
            '.msg-out .voice-play { background: rgba(255,255,255,.25); }',
            '.voice-play svg { width: 11px; height: 11px; }',
            '.voice-bars { flex: 1; height: 24px; display: flex; align-items: center; gap: 2px; }',
            '.voice-bar { width: 3px; min-height: 3px; border-radius: 2px; background: currentColor; opacity: .3; flex-shrink: 0; cursor: pointer; transition: height .1s ease; }',
            '.voice-bar.is-filled { opacity: 1; }',
            '.voice-time { font-size: 10px; opacity: .75; font-variant-numeric: tabular-nums; flex-shrink: 0; width: 30px; text-align: right; }',
            '.msg-video { display: block; max-width: 100%; border-radius: 8px; }',
            '.msg-file { display: block; text-decoration: underline; color: inherit; }',
            '.recording-bar { flex-shrink: 0; display: flex; align-items: center; gap: 8px; padding: 8px 14px; background: #fef2f2; color: #b91c1c; font-size: 12px; border-top: 1px solid #fecaca; }',
            '.recording-bar[hidden] { display: none; }',
            '.recording-dot { width: 8px; height: 8px; border-radius: 50%; background: #dc2626; animation: wero-pulse 1s infinite ease-in-out; flex-shrink: 0; }',
            '@keyframes wero-pulse { 0%, 100% { opacity: 1; } 50% { opacity: .3; } }',
            '.mic.is-recording { background: #dc2626; }',
            '.typing { display: flex; gap: 4px; align-items: center; padding: 10px 14px; }',
            '.typing span { width: 6px; height: 6px; border-radius: 50%; background: #9ca3af; animation: wero-typing 1.2s infinite ease-in-out; }',
            '.typing span:nth-child(2) { animation-delay: .2s; }',
            '.typing span:nth-child(3) { animation-delay: .4s; }',
            '@keyframes wero-typing { 0%, 60%, 100% { transform: translateY(0); opacity: .5; } 30% { transform: translateY(-4px); opacity: 1; } }',
            '.composer { display: flex; gap: 6px; align-items: flex-end; padding: 10px; border-top: 1px solid #e5e7eb; background: #fff; flex-shrink: 0; }',
            '.composer textarea { flex: 1; resize: none; border: 1px solid #e5e7eb; border-radius: 10px; padding: 8px 10px; font-size: 13px; max-height: 80px; outline: none; }',
            '.composer textarea:focus { border-color: var(--wero-color); }',
            '.composer button { background: var(--wero-color); border: none; color: #fff; width: 36px; height: 36px; border-radius: 10px; cursor: pointer; display: grid; place-items: center; flex-shrink: 0; }',
            '.composer .attach { background: none; color: #6b7280; }',
            '.composer button:disabled { opacity: .5; cursor: default; }',
            '.composer button svg { width: 18px; height: 18px; }',
            '@media (max-width: 420px) { .panel { right: 12px; bottom: 84px; } .bubble { right: 12px; } }',
        ].join('\n');
    }

    function launcherIcon(kind) {
        if (kind === 'message') {
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13"></path><path d="M22 2 15 22 11 13 2 9 22 2z"></path></svg>';
        }

        if (kind === 'help') {
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>';
        }

        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path></svg>';
    }

    function botIcon() {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="8" width="18" height="12" rx="2"></rect><circle cx="8.5" cy="14" r="1.2" fill="currentColor" stroke="none"></circle><circle cx="15.5" cy="14" r="1.2" fill="currentColor" stroke="none"></circle><path d="M12 8V4"></path><circle cx="12" cy="3" r="1" fill="currentColor" stroke="none"></circle></svg>';
    }

    function sendIcon() {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>';
    }

    function clipIcon() {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg>';
    }

    function micIcon() {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"></path><path d="M19 10v2a7 7 0 0 1-14 0v-2"></path><line x1="12" y1="19" x2="12" y2="23"></line><line x1="8" y1="23" x2="16" y2="23"></line></svg>';
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

/*
 * TICKETA staff preferences (saved on this device).
 * Place at: staff/assets/staff-prefs.js
 *
 * - TicketaPrefs.get()           -> { sound, autoLogout, camera }
 * - TicketaPrefs.set(patch)      -> saves and returns the new preferences
 * - TicketaPrefs.beep(kind)      -> 'success' or 'error' (respects the Sound Alert setting)
 * - TicketaPrefs.touch()         -> counts as activity (resets the auto logout timer)
 *
 * Auto logout submits the sidebar's existing sign-out form (.logout-form),
 * so every staff page that includes this script and handles the logout POST works.
 */
(function () {
    'use strict';

    var KEY = 'ticketa_staff_prefs';
    var defaults = { sound: true, autoLogout: 30, camera: 'environment' };

    function load() {
        var stored = {};

        try {
            var raw = window.localStorage.getItem(KEY);
            if (raw) stored = JSON.parse(raw) || {};
        } catch (e) {
            stored = {};
        }

        var prefs = {
            sound: stored.sound === undefined ? defaults.sound : !!stored.sound,
            autoLogout: [0, 15, 30, 60].indexOf(Number(stored.autoLogout)) === -1
                ? defaults.autoLogout
                : Number(stored.autoLogout),
            camera: stored.camera === 'user' ? 'user' : 'environment'
        };

        return prefs;
    }

    function store(prefs) {
        try {
            window.localStorage.setItem(KEY, JSON.stringify(prefs));
        } catch (e) { /* storage unavailable: settings last for this page view only */ }
    }

    /* Sound alerts (generated in the browser, no audio files needed) */
    var audioContext = null;

    function beep(kind, force) {
        if (!force && !load().sound) return;

        try {
            var AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) return;

            audioContext = audioContext || new AudioCtx();
            if (audioContext.state === 'suspended') audioContext.resume();

            var now = audioContext.currentTime;
            var notes = kind === 'success'
                ? [[880, 0, 0.12], [1175, 0.14, 0.16]]
                : [[220, 0, 0.18], [165, 0.22, 0.24]];

            notes.forEach(function (note) {
                var osc = audioContext.createOscillator();
                var gain = audioContext.createGain();
                var start = now + note[1];
                var end = start + note[2];

                osc.type = 'sine';
                osc.frequency.value = note[0];

                gain.gain.setValueAtTime(0.0001, start);
                gain.gain.exponentialRampToValueAtTime(0.25, start + 0.01);
                gain.gain.exponentialRampToValueAtTime(0.0001, end);

                osc.connect(gain);
                gain.connect(audioContext.destination);
                osc.start(start);
                osc.stop(end + 0.02);
            });
        } catch (e) { /* audio not available */ }
    }

    /* Idle auto logout */
    var timer = null;
    var lastArm = 0;

    function arm(force) {
        var nowMs = Date.now();
        if (!force && nowMs - lastArm < 1000) return;
        lastArm = nowMs;

        clearTimeout(timer);

        var minutes = load().autoLogout;
        if (!minutes) return;

        timer = setTimeout(function () {
            var form = document.querySelector('.logout-form');
            if (form) form.submit();
        }, minutes * 60 * 1000);
    }

    ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll', 'click'].forEach(function (name) {
        window.addEventListener(name, function () { arm(false); }, { passive: true });
    });

    window.addEventListener('storage', function () { arm(true); });
    arm(true);

    window.TicketaPrefs = {
        get: load,
        set: function (patch) {
            var next = {};
            var current = load();

            Object.keys(current).forEach(function (k) { next[k] = current[k]; });
            Object.keys(patch || {}).forEach(function (k) { next[k] = patch[k]; });

            store(next);
            arm(true);

            return load();
        },
        beep: beep,
        touch: function () { arm(true); }
    };
})();
// `.dark` on <html> means NIGHT, and night is the default: a control room is read in a dim room far more often than a
// bright one. A stored preference wins. This file is a plain (non-module) script in <head> so the class is set before first
// paint, and it is an external file so the page needs no inline script (CSP). The toggle is one delegated listener.
(function () {
    var root = document.documentElement;
    var stored = null;
    try { stored = localStorage.getItem('dot-central-theme'); } catch (e) {}
    if (stored === 'light') { root.classList.remove('dark'); }

    function label(button, night) {
        var text = night ? 'Switch to day' : 'Switch to night';
        var sr = button.querySelector('[data-theme-label]');
        if (sr) { sr.textContent = text; }
        button.title = text;
    }

    document.addEventListener('click', function (event) {
        var button = event.target && event.target.closest ? event.target.closest('[data-theme-toggle]') : null;
        if (!button) { return; }
        var night = root.classList.toggle('dark');
        try { localStorage.setItem('dot-central-theme', night ? 'dark' : 'light'); } catch (e) {}
        label(button, night);
    });

    document.addEventListener('DOMContentLoaded', function () {
        var night = root.classList.contains('dark');
        document.querySelectorAll('[data-theme-toggle]').forEach(function (b) { label(b, night); });
    });
})();

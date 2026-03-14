(function () {
    'use strict';

    var saved = localStorage.getItem('bdta-theme');
    var theme = saved ? saved : (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');

    document.documentElement.setAttribute('data-bs-theme', theme);
}());

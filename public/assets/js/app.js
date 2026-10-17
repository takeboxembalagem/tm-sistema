(function () {
    var layout = document.querySelector('.layout');
    var toggle = document.getElementById('toggle-sidebar');
    if (!layout || !toggle) {
        return;
    }

    var stored = localStorage.getItem('tm-sidebar');
    if (stored === 'collapsed' || stored === 'open') {
        layout.setAttribute('data-sidebar', stored);
    }

    toggle.addEventListener('click', function () {
        var next = layout.getAttribute('data-sidebar') === 'collapsed' ? 'open' : 'collapsed';
        layout.setAttribute('data-sidebar', next);
        localStorage.setItem('tm-sidebar', next);
    });
})();

/*
 * Áreas do menu: uma aberta por vez.
 * O cabeçalho é um link para a página inicial da área: navega quando a área já está aberta,
 * com a barra recolhida (só ícones) ou sem JavaScript; senão apenas abre a área e recolhe as outras.
 */
(function () {
    var layout = document.querySelector('.layout');
    var heads = Array.prototype.slice.call(document.querySelectorAll('.nav-area-head'));
    if (!layout || !heads.length) {
        return;
    }

    function painel(head) {
        return document.getElementById(head.getAttribute('aria-controls'));
    }

    function barraSoIcones() {
        return layout.getAttribute('data-sidebar') === 'collapsed'
            && window.matchMedia('(min-width: 801px)').matches;
    }

    heads.forEach(function (head) {
        head.addEventListener('click', function (ev) {
            if (barraSoIcones() || head.getAttribute('aria-expanded') === 'true') {
                return;
            }
            ev.preventDefault();
            heads.forEach(function (outro) {
                var aberto = outro === head;
                outro.setAttribute('aria-expanded', aberto ? 'true' : 'false');
                var p = painel(outro);
                if (p) {
                    p.hidden = !aberto;
                }
            });
        });
    });
})();
